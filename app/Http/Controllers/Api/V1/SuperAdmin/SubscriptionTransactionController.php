<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SuperAdmin\SubscriptionCheckoutOrderResource;
use App\Models\SubscriptionCheckoutOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Intentos de cobro de la suscripcion mensual contra el Nexolu Payments Core.
 *
 * A diferencia del listado del legacy, que solo mostraba la tabla, aca
 * importa el resultado del intento: cuales se confirmaron, cuales rechazo la
 * pasarela y cuales se quedaron a medias. El motivo del rechazo solo existe
 * en el payload del webhook (ver PaymentsCoreWebhookController::failSubscription),
 * asi que se expone: sin el, un pago fallido es una fila roja sin explicacion.
 */
class SubscriptionTransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filtered = fn () => SubscriptionCheckoutOrder::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('business_id'), fn ($query) => $query->where('business_id', $request->integer('business_id')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.addcslashes((string) $request->string('search'), '%_\\').'%';
                $query->where(function ($sub) use ($term) {
                    $sub->where('order_key', 'like', $term)
                        ->orWhere('provider_order_id', 'like', $term);
                });
            });

        $transactions = $filtered()->with('business')->orderByDesc('id')->paginate(25)->withQueryString();

        return SubscriptionCheckoutOrderResource::collection($transactions)
            ->additional(['summary' => $this->summaryOf($filtered())]);
    }

    /**
     * Conteo y plata por estado sobre los MISMOS filtros del listado, no
     * sobre el total historico: si se esta mirando un negocio puntual, un
     * resumen global de la plataforma al lado confunde mas de lo que informa.
     *
     * @param  Builder<SubscriptionCheckoutOrder>  $query
     * @return array<string, mixed>
     */
    private function summaryOf($query): array
    {
        $rows = $query->groupBy('status')
            ->selectRaw('status')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(amount_cop) as amount_cop')
            ->get();

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[(string) $row->status] = [
                'orders' => (int) $row->orders,
                'amount_cop' => (int) $row->amount_cop,
            ];
        }

        $confirmed = $byStatus['confirmed']['orders'] ?? 0;
        $failed = $byStatus['failed']['orders'] ?? 0;
        $resolved = $confirmed + $failed;

        return [
            'by_status' => $byStatus,
            'orders' => array_sum(array_column($byStatus, 'orders')),
            // Solo lo confirmado es plata que entro: sumar los pendientes y
            // los rechazados daria un "recaudo" que no existe.
            'collected_cop' => $byStatus['confirmed']['amount_cop'] ?? 0,
            // Sobre los intentos RESUELTOS: incluir los pendientes en el
            // denominador haria bajar la tasa cada vez que alguien abre el
            // checkout y no lo termina, que no es un rechazo de la pasarela.
            'success_rate_pct' => $resolved > 0 ? round(($confirmed / $resolved) * 100, 1) : null,
        ];
    }
}
