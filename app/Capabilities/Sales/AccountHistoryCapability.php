<?php

namespace App\Capabilities\Sales;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\LogAction;
use App\Models\Sale;
use App\Models\User;
use App\Support\AuditActionDictionary;

/**
 * Tool: historial_cuenta. Que se vendio en una cuenta y quien toco que.
 *
 * La pregunta real que la origino no es "cuanto sumo la cuenta" (eso ya lo
 * responde el POS) sino "quien saco el chocorramo de la cuenta de Juan": el
 * dueño sospecha de un empleado y necesita el rastro, no el total.
 */
class AccountHistoryCapability implements Capability
{
    use CapsRows;

    /** Eventos de cuenta que registra OpenTabController. */
    private const TAB_ACTIONS = [
        'tab.opened',
        'tab.items_added',
        'tab.items_synced',
        'tab.partial_payment',
        'tab.closed',
        'tab.cancelled',
    ];

    private const DEFAULT_DAYS = 90;

    public function requiredPermission(): ?string
    {
        return 'reports.sales';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function rules(): array
    {
        return [
            'cliente' => ['sometimes', 'nullable', 'string', 'max:150'],
            'venta_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'producto' => ['sometimes', 'nullable', 'string', 'max:200'],
            'dias' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'limite' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $customer = trim((string) ($arguments['cliente'] ?? ''));
        $saleId = isset($arguments['venta_id']) ? (int) $arguments['venta_id'] : null;

        if ($customer === '' && ! $saleId) {
            return ['error' => 'Indica el nombre del cliente o el id de la venta que quieres revisar.'];
        }

        $days = (int) ($arguments['dias'] ?? self::DEFAULT_DAYS);
        $limit = (int) ($arguments['limite'] ?? 5);
        $product = trim((string) ($arguments['producto'] ?? ''));

        $query = Sale::query()->with(['items.product:id,name', 'user:id,name', 'closedByUser:id,name', 'table:id,name']);

        if ($saleId) {
            $query->whereKey($saleId);
        } else {
            $since = now()->subDays($days);
            // Escapa los comodines de LIKE: un nombre con % buscaria cualquier
            // cosa. El nombre viene del texto del usuario via el modelo.
            $like = '%'.addcslashes($customer, '%_\\').'%';

            $query->where('customer_name', 'like', $like)
                ->where(function ($sub) use ($since) {
                    // Una cuenta todavia abierta no tiene closed_at, y filtrar
                    // solo por esa columna la dejaria fuera - que es justo la
                    // que se suele estar preguntando.
                    $sub->where('closed_at', '>=', $since)
                        ->orWhere(fn ($q) => $q->whereNull('closed_at')->where('created_at', '>=', $since));
                });
        }

        $sales = $query->orderByRaw('COALESCE(closed_at, created_at) DESC')->limit($limit)->get();

        if ($sales->isEmpty()) {
            return [
                'resultados' => [],
                'mensaje' => $saleId
                    ? "No hay ninguna venta con el id {$saleId} en este negocio."
                    : "No encontre ninguna cuenta a nombre de \"{$customer}\" en los ultimos {$days} dias.",
            ];
        }

        $auditBySale = $this->auditTrailFor($sales->pluck('id')->all(), $product);

        return [
            'resultados' => $sales->map(fn (Sale $sale) => [
                'venta_id' => $sale->id,
                'estado' => $sale->status === 'open' ? 'abierta' : 'cerrada',
                'cliente' => $sale->customer_name,
                'mesa' => $sale->table?->name,
                'abierta_por' => $sale->user?->name,
                'cerrada_por' => $sale->closedByUser?->name,
                'fecha' => ($sale->closed_at ?? $sale->created_at)->format('Y-m-d H:i'),
                'total' => round((float) $sale->total, 2),
                'items' => $sale->items->map(fn ($item) => [
                    'producto' => $item->product?->name ?? 'Producto eliminado',
                    'cantidad' => (float) $item->quantity,
                    'subtotal' => round((float) $item->subtotal, 2),
                ])->values()->all(),
                'rastro' => $auditBySale[$sale->id] ?? [],
            ])->values()->all(),
        ];
    }

    /**
     * Eventos auditados de cada cuenta, en una sola consulta para todas.
     *
     * @param  list<int>  $saleIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function auditTrailFor(array $saleIds, string $product): array
    {
        $logs = LogAction::query()
            ->whereIn('action', self::TAB_ACTIONS)
            ->whereIn('details->sale_id', $saleIds)
            ->with('user:id,name')
            ->orderBy('created_at')
            ->limit(self::MAX_ROWS)
            ->get();

        $bySale = [];

        foreach ($logs as $log) {
            $saleId = (int) ($log->details['sale_id'] ?? 0);
            if ($saleId === 0) {
                continue;
            }

            $productName = (string) ($log->details['product_name'] ?? '');

            // Los eventos que no son de un producto puntual (abrir, cerrar,
            // sincronizar la cuenta entera) se incluyen siempre, aunque se
            // este filtrando por producto: dan el contexto de la cuenta.
            if ($product !== '' && $productName !== '' && mb_stripos($productName, $product) === false) {
                continue;
            }

            $bySale[$saleId][] = [
                'fecha' => $log->created_at->format('Y-m-d H:i:s'),
                'evento' => AuditActionDictionary::label($log->action),
                'usuario' => $log->user?->name ?? 'usuario eliminado',
                'producto' => $productName !== '' ? $productName : null,
                'cantidad' => isset($log->details['quantity']) ? (float) $log->details['quantity'] : null,
            ];
        }

        return $bySale;
    }
}
