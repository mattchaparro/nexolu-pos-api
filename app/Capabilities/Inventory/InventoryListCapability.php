<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\SearchesProducts;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;

/**
 * Tool: inventario. Productos con su existencia, con busqueda por nombre,
 * por categoria y solo los agotados.
 *
 * Antes devolvia los primeros 100 por orden alfabetico y nada mas: en un
 * catalogo de cientos de productos el que preguntaban no aparecia y el
 * asistente contestaba que no existia. Ahora busca, y si hay mas de los que
 * caben lo dice para que el modelo afine la busqueda en vez de concluir.
 */
class InventoryListCapability implements Capability
{
    use SearchesProducts;

    private const MAX_ROWS = 60;

    public function requiredPermission(): ?string
    {
        return 'inventory.view';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function rules(): array
    {
        return [
            'buscar' => ['sometimes', 'nullable', 'string', 'max:200'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:200'],
            'solo_agotados' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $query = Product::query()
            ->with(['category', 'variants' => fn ($q) => $q->where('is_active', true)->with('attributeValues')->withBranchStock()])
            ->withBranchStock()
            ->where('is_active', true)
            ->where('is_service', false)
            ->when($arguments['buscar'] ?? null, fn ($q, $search) => $this->whereProductMatches($q, $search))
            ->when($arguments['categoria'] ?? null, function ($q, $category) {
                $q->whereHas('category', fn ($c) => $c->where('name', 'like', "%{$category}%"));
            })
            ->orderBy('name');

        $products = $query->get();

        $rows = [];
        foreach ($products as $product) {
            $variants = $product->variants;
            $stock = $variants->isNotEmpty()
                ? (float) $variants->sum(fn (ProductVariant $v) => $v->stockAt())
                : $product->stockAt();

            if (! empty($arguments['solo_agotados']) && ! ($product->track_stock && $stock <= 0) && ! $variants->contains(fn (ProductVariant $v) => $v->stockAt() <= 0)) {
                continue;
            }

            $row = [
                'nombre' => $product->name,
                'categoria' => $product->category?->name,
                'controla_stock' => (bool) $product->track_stock,
                'stock' => $stock,
                'precio' => (float) $product->priceAt(),
            ];

            if ($variants->isNotEmpty()) {
                $row['variaciones'] = $variants->map(fn (ProductVariant $v) => [
                    'variacion' => $v->attributeValues->pluck('value')->join(' / ') ?: $v->sku,
                    'stock' => $v->stockAt(),
                    'precio' => $v->priceAt(),
                ])
                    ->when(! empty($arguments['solo_agotados']), fn ($c) => $c->filter(fn ($v) => $v['stock'] <= 0))
                    ->values()
                    ->all();
            }

            $rows[] = $row;
        }

        $total = count($rows);

        return array_filter([
            'total_encontrados' => $total,
            'productos' => array_slice($rows, 0, self::MAX_ROWS),
            'nota' => $total > self::MAX_ROWS
                ? "Solo se muestran {$this->limitText()} de {$total}. Pide un nombre o una categoria para afinar."
                : null,
        ], fn ($value) => $value !== null);
    }

    private function limitText(): string
    {
        return (string) self::MAX_ROWS;
    }
}
