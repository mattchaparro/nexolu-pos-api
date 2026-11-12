<?php

namespace App\Capabilities\Sales;

use App\Capabilities\Capability;
use App\Capabilities\Support\ResolvesDateRange;
use App\Capabilities\Support\SearchesProducts;
use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\BranchFilter;
use Illuminate\Validation\ValidationException;

/**
 * Tool: ventas_producto. Como se ha vendido un producto puntual: cuando fue
 * la ultima venta, cuantas unidades y cuanta plata en un periodo, y cuanto
 * queda.
 *
 * Es la pregunta que el asistente no sabia contestar («¿cuando fue la ultima
 * venta del Reno 14F?»): probaba con el historial de un cliente y se rendia.
 */
class ProductSalesCapability implements Capability
{
    use ResolvesDateRange, SearchesProducts;

    private const MAX_PRODUCTS = 8;

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
            'producto' => ['required', 'string', 'max:200'],
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $search = trim($arguments['producto']);
        // Sin fechas, los ultimos 90 dias: «¿como se vende?» casi nunca es solo el mes.
        [$start, $end] = $this->resolveDateRange(
            $arguments['desde'] ?? now()->subDays(89)->toDateString(),
            $arguments['hasta'] ?? null,
        );

        $products = $this->whereProductMatches(Product::query(), $search)
            ->withBranchStock()
            ->get()
            ->sortBy(fn (Product $p) => [mb_strtolower($p->name) === mb_strtolower($search) ? 0 : 1, mb_strlen($p->name)])
            ->take(self::MAX_PRODUCTS)
            ->values();

        if ($products->isEmpty()) {
            throw ValidationException::withMessages([
                'producto' => "No se encontro un producto que coincida con '{$search}'. Prueba con una parte del nombre.",
            ]);
        }

        $closedSales = function ($query) use ($business) {
            $query->where('business_id', $business->id)
                ->where('status', 'closed')
                ->where('is_non_revenue', false);
            // whereHas no pasa por el scope de sede del modelo Sale.
            BranchFilter::apply($query, 'sales');
        };

        return [
            'periodo' => ['desde' => $start->toDateString(), 'hasta' => $end->toDateString()],
            'productos' => $products->map(function (Product $product) use ($closedSales, $start, $end) {
                $inPeriod = SaleItem::query()
                    ->where('product_id', $product->id)
                    ->whereHas('sale', function ($query) use ($closedSales, $start, $end) {
                        $closedSales($query);
                        $query->whereBetween('closed_at', [$start, $end]);
                    })
                    ->selectRaw('COALESCE(SUM(quantity), 0) as units, COALESCE(SUM(subtotal - COALESCE(discount_amount, 0)), 0) as revenue, COUNT(DISTINCT sale_id) as sales')
                    ->first();

                $lastSaleAt = Sale::query()
                    ->where('business_id', $product->business_id)
                    ->where('status', 'closed')
                    ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
                    ->max('closed_at');

                return [
                    'nombre' => $product->name,
                    'unidades_vendidas' => (float) $inPeriod->units,
                    'ingreso' => round((float) $inPeriod->revenue, 2),
                    'numero_de_ventas' => (int) $inPeriod->sales,
                    'ultima_venta' => $lastSaleAt ? substr((string) $lastSaleAt, 0, 16) : null,
                    'stock_actual' => $product->stockAt(),
                ];
            })->all(),
        ];
    }
}
