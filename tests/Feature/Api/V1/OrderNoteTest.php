<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Order;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Notas de un pedido.
 *
 * Dos cosas distintas conviviendo: lo que el equipo se anota entre si, y lo
 * que se le manda al comprador. Lo segundo SALE de verdad, asi que lo que mas
 * se prueba aca es que el resultado del envio quede registrado tal cual:
 * WhatsApp con texto libre solo se entrega dentro de la ventana de 24h de
 * Meta, y una nota que no llego no puede verse igual que una que si.
 */
class OrderNoteTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);
        BusinessStoreSettings::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'is_business_owner' => true,
        ]);

        config([
            'services.comms_core.base_url' => 'http://comms.test',
            'services.comms_core.api_key' => 'comms-key',
        ]);
    }

    private function pedido(array $attrs = []): Order
    {
        return Order::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'number' => 1,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 20000,
            'shipping_fee' => 0,
            'total' => 20000,
            'customer_name' => 'Compradora',
            'customer_phone' => '3001112233',
            'customer_email' => 'compradora@example.com',
            'is_pickup' => true,
            'public_token' => Str::random(40),
            'expires_at' => now()->addMinutes(20),
            ...$attrs,
        ]);
    }

    private function anotar(Order $order, array $payload): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/notes", $payload);
    }

    /** Lo que el equipo se anota no sale de la tienda. */
    public function test_una_nota_interna_no_le_manda_nada_al_comprador(): void
    {
        Http::fake();
        $order = $this->pedido();

        $response = $this->anotar($order, [
            'body' => 'El cliente pidió que se lo dejen con el portero.',
            'visibility' => 'internal',
        ])->assertOk();

        $this->assertSame('internal', $response->json('notes.0.visibility'));
        $this->assertSame([], $response->json('notes.0.channels'));
        Http::assertNothingSent();
    }

    public function test_una_nota_al_comprador_sale_por_los_canales_elegidos(): void
    {
        Http::fake(['*/notifications/send' => Http::response([
            'reference' => 'pedido_nota:1',
            'business_id' => '1',
            'results' => [
                ['channel' => 'whatsapp', 'status' => 'sent'],
                ['channel' => 'email', 'status' => 'sent'],
            ],
        ])]);

        $order = $this->pedido();

        $response = $this->anotar($order, [
            'body' => 'Se nos acabó el azul, ¿te sirve el negro?',
            'visibility' => 'customer',
            'channels' => ['whatsapp', 'email'],
        ])->assertOk();

        $this->assertSame(['whatsapp', 'email'], $response->json('notes.0.channels'));
        $this->assertSame('sent', $response->json('notes.0.delivery.whatsapp.status'));
        $this->assertSame('sent', $response->json('notes.0.delivery.email.status'));

        Http::assertSent(function ($request) {
            // El texto va tal cual por WhatsApp; el correo lleva la plantilla
            // armada, no el texto pelado.
            return str_contains($request->url(), '/v1/notifications/send')
                && $request['text'] === 'Se nos acabó el azul, ¿te sirve el negro?'
                && str_contains((string) $request['html'], 'te sirve el negro');
        });
    }

    /**
     * El caso que de verdad importa. Meta no entrega texto libre fuera de la
     * ventana de 24 horas, y eso es LO NORMAL con un comprador que nunca le
     * escribio a la tienda. Si el fallo no se guarda, el comerciante da por
     * avisado a alguien que jamas se entero.
     */
    public function test_si_whatsapp_no_entrega_queda_el_motivo_y_el_correo_sigue_contando(): void
    {
        Http::fake(['*/notifications/send' => Http::response([
            'results' => [
                ['channel' => 'whatsapp', 'status' => 'failed', 'error' => 'Fuera de la ventana de 24 horas.'],
                ['channel' => 'email', 'status' => 'sent'],
            ],
        ])]);

        $order = $this->pedido();

        $response = $this->anotar($order, [
            'body' => 'Tu pedido sale mañana.',
            'visibility' => 'customer',
            'channels' => ['whatsapp', 'email'],
        ])->assertOk();

        $this->assertSame('failed', $response->json('notes.0.delivery.whatsapp.status'));
        $this->assertSame('Fuera de la ventana de 24 horas.', $response->json('notes.0.delivery.whatsapp.error'));
        $this->assertSame('sent', $response->json('notes.0.delivery.email.status'));
    }

    /** Con el Core caido la nota se guarda igual, marcada como no entregada. */
    public function test_si_el_servicio_de_mensajeria_no_responde_la_nota_queda_como_fallida(): void
    {
        Http::fake(['*/notifications/send' => Http::response(['detail' => 'boom'], 500)]);

        $order = $this->pedido();

        $response = $this->anotar($order, [
            'body' => 'Tu pedido sale mañana.',
            'visibility' => 'customer',
            'channels' => ['email'],
        ])->assertOk();

        $this->assertSame('failed', $response->json('notes.0.delivery.email.status'));
        $this->assertNotNull($response->json('notes.0.delivery.email.error'));
    }

    /**
     * Comprar como invitado significa que pudo dejar solo el telefono.
     * Aceptar "correo" y fallar despues seria decirle al comerciante que
     * escribio cuando no.
     */
    public function test_no_se_puede_escribir_por_un_medio_que_el_comprador_no_dejo(): void
    {
        Http::fake();
        $order = $this->pedido(['customer_email' => null]);

        $this->anotar($order, [
            'body' => 'Hola',
            'visibility' => 'customer',
            'channels' => ['email'],
        ])->assertUnprocessable()->assertJsonValidationErrors('channels.0');

        Http::assertNothingSent();
    }

    /** Sin canal no hay a donde mandarla: es una nota interna disfrazada. */
    public function test_una_nota_al_comprador_exige_elegir_canal(): void
    {
        Http::fake();

        $this->anotar($this->pedido(), [
            'body' => 'Hola',
            'visibility' => 'customer',
        ])->assertUnprocessable()->assertJsonValidationErrors('channels');
    }

    /** El detalle dice por donde SE PUEDE escribirle, para que la UI no adivine. */
    public function test_el_detalle_dice_que_canales_tiene_el_comprador(): void
    {
        $conCorreo = $this->pedido();
        $sinCorreo = $this->pedido(['number' => 2, 'customer_email' => null, 'public_token' => Str::random(40)]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/orders/{$conCorreo->id}")
            ->assertOk()
            ->assertJsonPath('contact_channels', ['whatsapp', 'email']);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/orders/{$sinCorreo->id}")
            ->assertOk()
            ->assertJsonPath('contact_channels', ['whatsapp']);
    }

    /** Las notas de otro negocio no son alcanzables ni con el id a la mano. */
    public function test_no_se_puede_anotar_el_pedido_de_otro_negocio(): void
    {
        Http::fake();

        $otro = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);
        BusinessStoreSettings::factory()->create(['business_id' => $otro->id, 'is_active' => true]);
        $ajeno = Order::withoutGlobalScopes()->create([
            'business_id' => $otro->id,
            'number' => 1,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 1000, 'shipping_fee' => 0, 'total' => 1000,
            'customer_name' => 'Ajena', 'customer_phone' => '3009998877',
            'is_pickup' => true, 'public_token' => Str::random(40),
        ]);

        $this->anotar($ajeno, ['body' => 'Hola', 'visibility' => 'internal'])->assertNotFound();
    }
}
