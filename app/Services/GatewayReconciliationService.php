<?php

namespace App\Services;

use App\Models\Business;
use App\Models\GatewayPayment;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cruza lo que la pasarela dice haber cobrado contra lo que el POS registro.
 *
 * Es una conciliacion bancaria, con la misma disciplina: las dos fuentes se
 * comparan, nunca se fusionan. La pasarela no crea ventas y el POS no
 * inventa cobros -- lo unico que se produce aqui es un enlace entre una fila
 * de cada lado, y el señalamiento de lo que quedo sin pareja.
 *
 * Por que hace falta: el QR fisico que Bold deja pegado al datafono cobra
 * sin pasar por la caja. El comerciante cierra el turno viendo solo lo que
 * el mismo tecleo, y la diferencia aparece dias despues en el extracto,
 * cuando ya nadie se acuerda de esa venta.
 */
class GatewayReconciliationService
{
    /**
     * Cuanto puede separarse un cobro de su venta en el tiempo.
     *
     * El webhook de Bold se toma hasta 10 minutos, y entre que el cajero
     * cierra la venta y el comprador termina de pagar pasa otro rato. Media
     * hora cubre el caso real sin llegar a emparejar dos ventas distintas
     * del mismo monto, que es el error que de verdad duele.
     */
    private const WINDOW_MINUTES = 30;

    /** Lo que nunca pasa por una pasarela, se llame como se llame. */
    private const CASH_METHODS = ['cash', 'efectivo'];

    /**
     * Guarda un cobro reportado por la pasarela y trata de emparejarlo.
     *
     * Idempotente por `provider_payment_id`: el Core reintenta hasta 3
     * veces, y un cobro contado dos veces descuadraria justo el numero que
     * esto existe para cuadrar.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(Business $business, array $payload): GatewayPayment
    {
        $providerPaymentId = (string) ($payload['provider_payment_id'] ?? '');

        $payment = GatewayPayment::withoutGlobalScopes()->firstOrNew([
            'business_id' => $business->id,
            'provider_slug' => (string) ($payload['provider'] ?? ''),
            'provider_payment_id' => $providerPaymentId,
        ]);

        // Solo se rellena la primera vez: un reintento no debe pisar un
        // emparejamiento que ya se hizo.
        if (! $payment->exists) {
            $payment->fill([
                'amount' => (float) ($payload['amount_cop'] ?? 0),
                'payment_method' => $payload['payment_method'] ?? null,
                'approval_number' => $payload['approval_number'] ?? null,
                'occurred_at' => $this->parseDate($payload['occurred_at'] ?? null),
                'payload' => $payload,
            ]);
            $payment->save();

            $this->match($payment);
        }

        return $payment->refresh();
    }

    /**
     * Busca la venta que corresponde a este cobro.
     *
     * Por monto exacto y cercania en el tiempo, que es todo lo que hay: el
     * cobro no trae ninguna referencia nuestra (por eso existe este
     * servicio). Ante dos candidatas se toma la mas cercana en el tiempo y
     * NUNCA se roba una venta que ya tiene su propio cobro emparejado --
     * emparejar mal es peor que no emparejar: un descuadre visible se
     * investiga, uno tapado no.
     */
    public function match(GatewayPayment $payment): ?Sale
    {
        if ($payment->isMatched() || $payment->occurred_at === null) {
            return null;
        }

        $desde = $payment->occurred_at->copy()->subMinutes(self::WINDOW_MINUTES);
        $hasta = $payment->occurred_at->copy()->addMinutes(self::WINDOW_MINUTES);

        $yaEmparejadas = GatewayPayment::withoutGlobalScopes()
            ->where('business_id', $payment->business_id)
            ->whereNotNull('sale_id')
            ->pluck('sale_id');

        $sale = Sale::withoutGlobalScopes()
            ->where('business_id', $payment->business_id)
            ->whereBetween('created_at', [$desde, $hasta])
            ->whereRaw('ROUND(total, 2) = ?', [round((float) $payment->amount, 2)])
            ->whereNotIn('id', $yaEmparejadas)
            ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, created_at, ?))', [$payment->occurred_at->toDateTimeString()])
            ->first();

        if ($sale === null) {
            return null;
        }

        $payment->forceFill(['sale_id' => $sale->id, 'matched_at' => now()])->save();

        return $sale;
    }

    /**
     * El cuadre de una franja: que dice el POS, que dice la pasarela, y que
     * no coincide.
     *
     * Se compara contra las ventas cobradas con un medio ELECTRONICO, no
     * contra todas: el efectivo no pasa por la pasarela y meterlo en la
     * comparacion haria que nunca cuadre nada.
     *
     * @return array{
     *     pos: array{count: int, total: float},
     *     gateway: array{count: int, total: float},
     *     balanced: bool,
     *     unmatched_payments: Collection<int, GatewayPayment>,
     *     unmatched_sales: Collection<int, Sale>
     * }
     */
    public function summary(Business $business, Carbon $from, Carbon $to): array
    {
        $payments = GatewayPayment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->get();

        $noElectronicos = $this->nonGatewayMethods($business);

        $sales = Sale::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->whereBetween('created_at', [$from, $to])
            ->where('is_credit', false)
            ->whereNotNull('payment_method')
            ->whereNotIn('payment_method', $noElectronicos)
            ->orderBy('created_at')
            ->get();

        $emparejadas = $payments->pluck('sale_id')->filter()->all();

        $posTotal = round((float) $sales->sum('total'), 2);
        $gatewayTotal = round((float) $payments->sum('amount'), 2);

        return [
            'pos' => ['count' => $sales->count(), 'total' => $posTotal],
            'gateway' => ['count' => $payments->count(), 'total' => $gatewayTotal],
            'balanced' => $sales->count() === $payments->count() && $posTotal === $gatewayTotal,
            // Cobros que la pasarela reporta y el POS no tiene: plata que
            // entro sin quedar registrada como venta.
            'unmatched_payments' => $payments->filter(fn (GatewayPayment $p) => ! $p->isMatched())->values(),
            // Ventas marcadas como electronicas que la pasarela no reporta:
            // o se marcaron mal, o el cobro no entro.
            'unmatched_sales' => $sales->reject(fn (Sale $s) => in_array($s->id, $emparejadas, true))->values(),
        ];
    }

    /**
     * Los medios que NO pasan por una pasarela, para excluirlos.
     *
     * Se define por exclusion y no por lista blanca a proposito. La lista de
     * medios HABILITADOS es la de hoy, y una venta vieja pudo hacerse con un
     * medio que despues se desactivo (lo advierte el propio
     * `Business::allowedPaymentMethodIds`). Filtrar por ella haria que esa
     * venta desapareciera del cuadre en silencio -- y desaparecer de una
     * conciliacion es el peor fallo posible: el descuadre deja de verse
     * justo cuando hay algo que ver.
     *
     * Por eso se parte del catalogo COMPLETO del negocio y se quita lo que
     * sabemos que no pasa por pasarela.
     *
     * @return list<string>
     */
    private function nonGatewayMethods(Business $business): array
    {
        $todos = collect($business->paymentMethods())
            ->pluck('id')
            ->map(fn ($id) => strtolower((string) $id))
            ->filter();

        return $todos
            ->filter(fn (string $id) => in_array($id, self::CASH_METHODS, true)
                || $business->isCreditPaymentMethod($id))
            // `cash` siempre se excluye aunque el negocio lo haya renombrado
            // o retirado del catalogo: nunca paso por una pasarela.
            ->merge(self::CASH_METHODS)
            ->unique()
            ->values()
            ->all();
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
