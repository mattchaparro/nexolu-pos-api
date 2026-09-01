<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\StockMovementReason;
use App\Support\BranchFilter;
use App\Support\SortableQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class InventoryReportService
{
    /**
     * Valorización del inventario: retail + costo por productos e ingredientes,
     * acotado al negocio para aislamiento de tenant.
     *
     * @return array<string, float>
     */
    public function summary(Business $business): array
    {
        $canInventory = $business->hasFeature('inventory');
        $canIngredients = $business->hasFeature('ingredients');
        $variantsEnabled = $business->hasFeature('variants');
        $id = $business->id;

        // products.stock/price/cost_price quedan "fantasma" para un producto
        // con variantes (ver ProductAvailability) - se excluyen del lado de
        // productos y se suma por separado el valor de las variantes activas,
        // mismo criterio que Product::sumInventoryRetailValueCop(). Un
        // producto convertido a variantes conserva su stock viejo en la
        // columna, asi que sin el whereDoesntHave() se contaria dos veces.
        $baseProducts = Product::where('business_id', $id)
            ->where('track_stock', true)
            ->when($variantsEnabled, fn ($query) => $query->whereDoesntHave('variants'))
            ->toBase();
        $baseVariants = ProductVariant::where('business_id', $id)->where('is_active', true)->toBase();

        return [
            'inventory_retail_cop' => $canInventory
                ? (float) (clone $baseProducts)->selectRaw('COALESCE(SUM(stock * price), 0) as v')->value('v')
                    + ($variantsEnabled ? (float) (clone $baseVariants)->selectRaw('COALESCE(SUM(stock * price), 0) as v')->value('v') : 0.0)
                : 0.0,
            'inventory_cost_products_cop' => $canInventory
                ? (float) (clone $baseProducts)->selectRaw('COALESCE(SUM(stock * cost_price), 0) as v')->value('v')
                    + ($variantsEnabled ? (float) (clone $baseVariants)->selectRaw('COALESCE(SUM(stock * cost_price), 0) as v')->value('v') : 0.0)
                : 0.0,
            'inventory_cost_ingredients_cop' => $canIngredients
                ? (float) Ingredient::where('business_id', $id)->toBase()->selectRaw('COALESCE(SUM(stock * cost_price), 0) as v')->value('v')
                : 0.0,
        ];
    }

    /**
     * Movimientos de inventario paginados con filtros.
     *
     * @param  array<string, mixed>  $filters
     */
    public function movements(Business $business, array $filters): LengthAwarePaginator
    {
        $query = StockMovement::query()
            ->where('business_id', $business->id)
            ->with([
                'product:id,name,category_id',
                'product.category:id,name',
                'ingredient:id,name,unit',
                'reason:id,code,label',
                'user:id,name',
            ]);

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['reason_id'])) {
            $query->where('stock_movement_reason_id', (int) $filters['reason_id']);
        }
        if (! empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['ingredient_id'])) {
            $query->where('ingredient_id', (int) $filters['ingredient_id']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $sort = SortableQuery::resolve($filters['sort'] ?? null, $filters['direction'] ?? null, [
            'date' => 'created_at',
            'type' => 'type',
            'quantity' => 'quantity',
        ]);
        if ($sort) {
            $query->orderBy(...$sort);
        } else {
            $query->orderByDesc('created_at');
        }

        return $query->paginate(40)->through(fn ($m) => $this->mapMovement($m));
    }

    /**
     * Colección de movimientos para exportar CSV (tope 2000).
     *
     * @param  array<string, mixed>  $filters
     */
    public function movementsForExport(Business $business, array $filters): Collection
    {
        $query = StockMovement::query()
            ->where('business_id', $business->id)
            ->with([
                'product:id,name',
                'ingredient:id,name,unit',
                'reason:id,code,label',
                'user:id,name',
            ])
            ->orderByDesc('created_at');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['reason_id'])) {
            $query->where('stock_movement_reason_id', (int) $filters['reason_id']);
        }
        if (! empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['ingredient_id'])) {
            $query->where('ingredient_id', (int) $filters['ingredient_id']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return $query->limit(2000)->get()->map(fn ($m) => $this->mapMovement($m));
    }

    /**
     * Márgenes por producto con ventas opcionales en rango de fechas.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function margins(Business $business, array $filters): array
    {
        $canInventory = $business->hasFeature('inventory');
        $canIngredients = $business->hasFeature('ingredients');
        $businessId = $business->id;

        $categoryId = ! empty($filters['category_id']) ? (int) $filters['category_id'] : null;
        $withSales = (bool) ($filters['with_sales'] ?? false);
        [$dateFrom, $dateTo] = $this->resolveMarginsDateRange($filters);

        $salesAgg = collect();
        $uncostedRows = collect();

        if ($canInventory && $withSales && ($dateFrom || $dateTo)) {
            $salesAgg = SaleItem::query()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->join('products', 'products.id', '=', 'sale_items.product_id')
                ->where('sales.business_id', $businessId)
                ->tap(fn ($query) => BranchFilter::apply($query, 'sales'))
                ->where('sales.status', 'closed')
                ->when($dateFrom, fn ($q) => $q->whereDate('sales.closed_at', '>=', $dateFrom))
                ->when($dateTo, fn ($q) => $q->whereDate('sales.closed_at', '<=', $dateTo))
                ->whereNotNull('sale_items.product_id')
                ->selectRaw('
                    sale_items.product_id as product_id,
                    SUM(sale_items.quantity) as qty_sold,
                    SUM(sale_items.subtotal - COALESCE(sale_items.discount_amount, 0)) as revenue,
                    SUM(sale_items.quantity * COALESCE(sale_items.unit_cost_at_sale, products.cost_price, 0)) as cost_total
                ')
                ->groupBy('sale_items.product_id')
                ->get()
                ->keyBy('product_id');

            $uncostedRows = SaleItem::query()
                ->whereHas('sale', fn ($q) => $q
                    ->where('business_id', $businessId)
                    ->where('status', 'closed')
                    ->when($dateFrom, fn ($q2) => $q2->whereDate('closed_at', '>=', $dateFrom))
                    ->when($dateTo, fn ($q2) => $q2->whereDate('closed_at', '<=', $dateTo))
                )
                ->whereHas('product', fn ($q) => $q->where('cost_price', '<=', 0))
                ->whereNotNull('product_id')
                ->with('product:id,name')
                ->selectRaw('product_id, SUM(quantity) as qty_sold, SUM(subtotal - COALESCE(discount_amount, 0)) as revenue')
                ->groupBy('product_id')
                ->orderByDesc('revenue')
                ->get()
                ->map(fn ($r) => [
                    'name' => $r->product?->name ?? 'Producto eliminado',
                    'qty_sold' => (int) $r->qty_sold,
                    'revenue' => round((float) $r->revenue, 2),
                ]);
        }

        $marginRows = collect();
        if ($canInventory) {
            // Un producto con variantes no tiene precio/costo/stock propios
            // (columnas fantasma, ver ProductAvailability): su fila aqui
            // mostraria margen y "ganancia potencial" calculados sobre datos
            // que no corresponden a nada vendible. Peor en un producto
            // convertido, que conserva el costo y el stock que tenia ANTES de
            // recibir variantes y por eso si pasa el filtro cost_price > 0.
            // Se excluye explicitamente; el desglose de margen por variante
            // queda pendiente como funcionalidad aparte.
            $marginRows = Product::query()
                ->where('track_stock', true)
                ->where('is_active', true)
                ->where('is_single_sale', false)
                ->where('cost_price', '>', 0)
                ->when($business->hasFeature('variants'), fn ($q) => $q->whereDoesntHave('variants'))
                ->with(['category:id,name', 'ingredients:id,stock'])
                ->when($categoryId, fn ($q) => $q->whereIn('category_id', ProductCategory::idsIncludingChildren($categoryId)))
                ->orderBy('name')
                ->get(['id', 'name', 'stock', 'price', 'cost_price', 'category_id'])
                ->map(function (Product $p) use ($canIngredients, $salesAgg, $withSales) {
                    $isRecipe = $canIngredients && $p->ingredients->isNotEmpty();
                    if ($isRecipe) {
                        $batches = PHP_INT_MAX;
                        foreach ($p->ingredients as $ing) {
                            $req = (float) $ing->pivot->quantity;
                            if ($req > 0) {
                                $batches = min($batches, (int) floor((float) $ing->stock / $req));
                            }
                        }
                        $effectiveStock = $batches === PHP_INT_MAX ? 0 : $batches;
                    } else {
                        $effectiveStock = (int) $p->stock;
                    }

                    $margin = (float) $p->price - (float) $p->cost_price;
                    $pct = (float) $p->price > 0 ? round(100 * $margin / (float) $p->price, 1) : null;
                    $agg = $salesAgg->get($p->id);

                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'category' => $p->category?->name,
                        'stock' => $effectiveStock,
                        'price' => (float) $p->price,
                        'cost_price' => (float) $p->cost_price,
                        'margin_cop' => round($margin, 2),
                        'margin_pct' => $pct,
                        'profit_total' => round($margin * $effectiveStock, 2),
                        'qty_sold' => $withSales ? (int) ($agg->qty_sold ?? 0) : null,
                        'profit_from_sales' => $withSales
                            ? round((float) ($agg->revenue ?? 0) - (float) ($agg->cost_total ?? 0), 2)
                            : null,
                        'is_recipe' => $isRecipe,
                    ];
                });
        }

        $categories = ProductCategory::orderBy('name')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]);

        $reasons = StockMovementReason::whereNull('business_id')->orderBy('label')->get(['id', 'code', 'label']);

        $productOptions = $canInventory
            ? Product::orderBy('name')->where('track_stock', true)->limit(500)->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            : collect();

        $ingredientOptions = $canIngredients
            ? Ingredient::orderBy('name')->limit(500)->get(['id', 'name'])->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])
            : collect();

        return [
            'margin_rows' => $marginRows->values()->all(),
            'uncosted_rows' => $uncostedRows->values()->all(),
            'categories' => $categories->values()->all(),
            'reasons' => $reasons->values()->all(),
            'product_options' => $productOptions->values()->all(),
            'ingredient_options' => $ingredientOptions->values()->all(),
            'filters' => [
                'category_id' => $categoryId,
                'date_from' => $withSales ? ($dateFrom ?: '') : '',
                'date_to' => $withSales ? ($dateTo ?: '') : '',
                'with_sales' => $withSales && ($dateFrom || $dateTo),
            ],
        ];
    }

    /**
     * Devuelve el mismo conjunto de márgenes pero en forma de array plano para CSV.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function marginsForExport(Business $business, array $filters): array
    {
        $data = $this->margins($business, $filters);

        return $data['margin_rows'];
    }

    // ─── privados ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolveMarginsDateRange(array $filters): array
    {
        $month = (string) ($filters['month'] ?? '');
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            try {
                $parsed = Carbon::createFromFormat('Y-m', $month);

                return [$parsed->startOfMonth()->toDateString(), $parsed->copy()->endOfMonth()->toDateString()];
            } catch (\Throwable) {
            }
        }

        return [
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMovement(StockMovement $m): array
    {
        return [
            'id' => $m->id,
            'created_at' => $m->created_at?->format('Y-m-d H:i:s'),
            'type' => $m->type,
            'reason_code' => $m->reason?->code,
            'reason_label' => $m->reason?->label,
            'product_id' => $m->product_id,
            'product_name' => $m->product?->name,
            'product_category' => $m->product?->category?->name,
            'ingredient_id' => $m->ingredient_id,
            'ingredient_name' => $m->ingredient ? $m->ingredient->name.' ('.$m->ingredient->unit.')' : null,
            'quantity' => (float) $m->quantity,
            'user_name' => $m->user?->name,
            'notes' => $m->notes,
        ];
    }
}
