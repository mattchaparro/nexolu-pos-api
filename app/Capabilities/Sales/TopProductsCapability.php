<?php

namespace App\Capabilities\Sales;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Capabilities\Support\ResolvesDateRange;
use App\Models\Business;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\BranchFilter;

/**
 * Tool: productos_top. Lo que mas (o menos) se vendio en un periodo.
 */
class TopProductsCapability implements Capability
{
    use CapsRows, ResolvesDateRange;

    public function requiredPermission(): ?string
    {
        return 'reports.sales';
    }

    public function requiredFeature(): ?string
    {
        return 'inventory';
    }

    public function rules(): array
    {
        return [
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'orden' => ['sometimes', 'nullable', 'in:mas_vendidos,menos_vendidos'],
            'limite' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        [$start, $end] = $this->resolveDateRange($arguments['desde'] ?? null, $arguments['hasta'] ?? null);

        $order = $arguments['orden'] ?? 'mas_vendidos';
        $limit = (int) ($arguments['limite'] ?? 10);

        $rows = SaleItem::query()
            ->whereHas('sale', function ($query) use ($business, $start, $end) {
                $query->where('business_id', $business->id)
                    ->where('status', 'closed')
                    ->where('is_non_revenue', false)
                    ->where('is_credit', false)
                    ->whereBetween('closed_at', [$start, $end]);

                // whereHas arma una subconsulta sobre `sales` que NO pasa por
                // el global scope de sede del modelo Sale, asi que la sede se
                // aplica a mano. Sin esto el chat mezclaria la rotacion de
                // todas las sedes con las ventas de una sola.
                BranchFilter::apply($query, 'sales');
            })
            // is_single_sale: productos de venta unica (combos puntuales,
            // servicios sueltos) no tienen rotacion que reportar. Mismo
            // criterio que el resumen del dia y BusinessOverviewService.
            ->whereHas('product', fn ($query) => $query->where('is_single_sale', false))
            ->selectRaw('product_id')
            ->selectRaw('SUM(quantity) as units')
            ->selectRaw('SUM(subtotal - COALESCE(discount_amount, 0)) as revenue')
            ->groupBy('product_id')
            ->orderBy('units', $order === 'menos_vendidos' ? 'asc' : 'desc')
            ->limit($limit)
            ->with('product:id,name')
            ->get();

        return [
            'desde' => $start->toDateString(),
            'hasta' => $end->toDateString(),
            'orden' => $order,
            'productos' => $this->capRows($rows->map(fn (SaleItem $item) => [
                'producto' => $item->product?->name ?? 'Producto eliminado',
                'unidades' => round((float) $item->units, 2),
                'ingreso' => round((float) $item->revenue, 2),
            ])->values()->all()),
            'nota' => $order === 'menos_vendidos'
                // Sin esto el modelo presenta la lista como "lo que no se
                // vende", cuando lo que de verdad no se vendio no aparece:
                // un producto con cero ventas no tiene filas en sale_items.
                ? 'Solo incluye productos que SI se vendieron al menos una vez en el periodo. Los '
                    .'que no se vendieron nada no aparecen aqui; para esos usa inventario.'
                : null,
        ];
    }
}
