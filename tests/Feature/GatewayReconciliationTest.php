<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\GatewayPayment;
use App\Models\Sale;
use App\Models\User;
use App\Services\GatewayReconciliationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Conciliacion de lo que la pasarela dice haber cobrado.
 *
 * El caso que motiva todo: el QR fisico que Bold deja pegado al datafono.
 * El comprador lo escanea y paga sin que nadie toque la caja, asi que el
 * cobro no trae ninguna referencia nuestra. El comerciante cierra el turno
 * viendo solo lo que el mismo tecleo, y la diferencia le aparece dias
 * despues en el extracto, cuando ya nadie se acuerda de esa venta.
 *
 * Lo que estas pruebas defienden es la disciplina de una conciliacion
 * bancaria: las dos fuentes se comparan, nunca se fusionan, y emparejar mal
 * es peor que no emparejar -- un descuadre visible se investiga, uno tapado
 * no.
 */
class GatewayReconciliationTest extends TestCase
{
    use DatabaseTransactions;

    private function business(): Business
    {
        $business = Business::factory()->create();
        User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        return $business;
    }

    private function sale(Business $business, float $total, Carbon $at, string $method = 'card'): Sale
    {
        $user = User::withoutGlobalScopes()->where('business_id', $business->id)->first();

        $sale = Sale::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'total' => $total,
            'subtotal' => $total,
            'payment_method' => $method,
            'is_credit' => false,
        ]);

        // `created_at` es el eje de la ventana de tiempo, asi que se fija a
        // mano en vez de dejar el `now()` del insert.
        $sale->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $sale->refresh();
    }

    /** @param array<string, mixed> $extra */
    private function evento(float $amount, Carbon $at, array $extra = []): array
    {
        return [
            'event' => 'merchant_payment.received',
            'provider' => 'bold',
            'provider_payment_id' => 'bold-'.uniqid(),
            'amount_cop' => $amount,
            'payment_method' => 'CARD',
            'approval_number' => '123456',
            'occurred_at' => $at->toIso8601String(),
            ...$extra,
        ];
    }

    public function test_empareja_el_cobro_con_la_venta_del_mismo_monto(): void
    {
        $business = $this->business();
        $momento = now()->subMinutes(5);
        $sale = $this->sale($business, 17000, $momento);

        $payment = app(GatewayReconciliationService::class)
            ->record($business, $this->evento(17000, $momento->copy()->addMinutes(2)));

        $this->assertSame($sale->id, $payment->sale_id);
        $this->assertNotNull($payment->matched_at);
    }

    public function test_no_empareja_una_venta_fuera_de_la_ventana_de_tiempo(): void
    {
        $business = $this->business();
        $this->sale($business, 17000, now()->subHours(3));

        $payment = app(GatewayReconciliationService::class)
            ->record($business, $this->evento(17000, now()));

        $this->assertNull($payment->sale_id);
    }

    public function test_no_empareja_montos_distintos(): void
    {
        $business = $this->business();
        $momento = now();
        $this->sale($business, 17000, $momento);

        $payment = app(GatewayReconciliationService::class)
            ->record($business, $this->evento(17500, $momento));

        $this->assertNull($payment->sale_id);
    }

    /**
     * Dos ventas del mismo monto en la misma franja: el segundo cobro no
     * puede robarle la venta al primero.
     */
    public function test_dos_cobros_iguales_toman_ventas_distintas(): void
    {
        $business = $this->business();
        $base = now()->subMinutes(10);
        $a = $this->sale($business, 17000, $base);
        $b = $this->sale($business, 17000, $base->copy()->addMinutes(4));

        $servicio = app(GatewayReconciliationService::class);
        $p1 = $servicio->record($business, $this->evento(17000, $base->copy()->addMinute()));
        $p2 = $servicio->record($business, $this->evento(17000, $base->copy()->addMinutes(5)));

        $this->assertNotNull($p1->sale_id);
        $this->assertNotNull($p2->sale_id);
        $this->assertNotSame($p1->sale_id, $p2->sale_id);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], [$p1->sale_id, $p2->sale_id]);
    }

    /** El Core reintenta hasta 3 veces: el mismo cobro no puede contarse dos. */
    public function test_el_mismo_cobro_dos_veces_es_una_sola_fila(): void
    {
        $business = $this->business();
        $momento = now();
        $this->sale($business, 17000, $momento);
        $evento = $this->evento(17000, $momento);

        $servicio = app(GatewayReconciliationService::class);
        $servicio->record($business, $evento);
        $servicio->record($business, $evento);

        $this->assertSame(1, GatewayPayment::withoutGlobalScopes()
            ->where('business_id', $business->id)->count());
    }

    public function test_el_cobro_de_un_negocio_no_toma_la_venta_de_otro(): void
    {
        $business = $this->business();
        $otro = $this->business();
        $momento = now();
        $this->sale($otro, 17000, $momento);

        $payment = app(GatewayReconciliationService::class)
            ->record($business, $this->evento(17000, $momento));

        $this->assertNull($payment->sale_id);
    }

    public function test_el_cuadre_avisa_cuando_todo_coincide(): void
    {
        $business = $this->business();
        $momento = now()->subMinutes(5);
        $this->sale($business, 17000, $momento);

        app(GatewayReconciliationService::class)->record($business, $this->evento(17000, $momento));

        $resumen = app(GatewayReconciliationService::class)
            ->summary($business, now()->subDay(), now()->addDay());

        $this->assertTrue($resumen['balanced']);
        $this->assertSame(1, $resumen['pos']['count']);
        $this->assertSame(1, $resumen['gateway']['count']);
        $this->assertCount(0, $resumen['unmatched_payments']);
        $this->assertCount(0, $resumen['unmatched_sales']);
    }

    /**
     * El caso que justifica todo: la pasarela cobro algo que el POS no
     * tiene. Es plata que entro sin quedar registrada como venta.
     */
    public function test_el_cuadre_señala_el_cobro_que_el_pos_no_tiene(): void
    {
        $business = $this->business();

        app(GatewayReconciliationService::class)->record($business, $this->evento(50000, now()));

        $resumen = app(GatewayReconciliationService::class)
            ->summary($business, now()->subDay(), now()->addDay());

        $this->assertFalse($resumen['balanced']);
        $this->assertCount(1, $resumen['unmatched_payments']);
        $this->assertSame(50000.0, (float) $resumen['unmatched_payments']->first()->amount);
    }

    /** Y al reves: una venta marcada como electronica que la pasarela no reporta. */
    public function test_el_cuadre_señala_la_venta_que_la_pasarela_no_reporta(): void
    {
        $business = $this->business();
        $this->sale($business, 23000, now()->subMinutes(5));

        $resumen = app(GatewayReconciliationService::class)
            ->summary($business, now()->subDay(), now()->addDay());

        $this->assertFalse($resumen['balanced']);
        $this->assertCount(1, $resumen['unmatched_sales']);
    }

    /**
     * El efectivo no pasa por la pasarela: incluirlo en la comparacion
     * haria que ningun negocio cuadre nunca.
     */
    public function test_el_efectivo_no_entra_en_el_cuadre(): void
    {
        $business = $this->business();
        $momento = now()->subMinutes(5);
        $this->sale($business, 9000, $momento, 'cash');
        $this->sale($business, 17000, $momento);

        app(GatewayReconciliationService::class)->record($business, $this->evento(17000, $momento));

        $resumen = app(GatewayReconciliationService::class)
            ->summary($business, now()->subDay(), now()->addDay());

        $this->assertTrue($resumen['balanced'], 'La venta en efectivo no debe descuadrar la pasarela.');
        $this->assertSame(1, $resumen['pos']['count']);
    }

    /**
     * Una venta hecha con un medio que HOY no esta en el catalogo del
     * negocio sigue contando.
     *
     * Es la razon por la que el filtro es por exclusion y no por lista
     * blanca: filtrar por los medios habilitados hoy haria desaparecer del
     * cuadre una venta vieja cuyo medio se desactivo -- y desaparecer de una
     * conciliacion es el peor fallo posible, porque el descuadre deja de
     * verse justo cuando hay algo que ver.
     */
    public function test_una_venta_con_un_medio_ya_retirado_sigue_contando(): void
    {
        $business = $this->business();
        // 'nequi' no esta en el catalogo por defecto (cash/transfer/credit).
        $this->assertNotContains('nequi', $business->allowedPaymentMethodIds());

        $this->sale($business, 31000, now()->subMinutes(5), 'nequi');

        $resumen = app(GatewayReconciliationService::class)
            ->summary($business, now()->subDay(), now()->addDay());

        $this->assertSame(1, $resumen['pos']['count']);
        $this->assertCount(1, $resumen['unmatched_sales']);
    }
}
