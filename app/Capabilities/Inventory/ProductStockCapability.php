<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\SearchesProducts;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Tool: stock_producto. Existencias de un producto buscado por nombre, como
 * lo diga la persona («reno 14f»), con el detalle por variacion.
 *
 * Antes tomaba el primer LIKE y nada mas: con dos «Camiseta» respondia la que
 * saliera primero. Ahora la mejor coincidencia va arriba (nombre exacto,
 * luego el mas corto) y las demas van en `otras_coincidencias` para que el
 * modelo pregunte cual si hace falta.
 */
class ProductStockCapability implements Capability
{
    use SearchesProducts;

    private const MAX_MATCHES = 8;

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
            'producto' => ['required', 'string', 'max:200'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $search = trim($arguments['producto']);

        $matches = $this->whereProductMatches(Product::query(), $search)
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->with('attributeValues')->withBranchStock()])
            ->withBranchStock()
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (Product $p) => [mb_strtolower($p->name) === mb_strtolower($search) ? 0 : 1, mb_strlen($p->name)])
            ->values();

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages([
                'producto' => "No se encontro un producto que coincida con '{$search}'. Prueba con una parte del nombre.",
            ]);
        }

        $best = $matches->first();

        return array_filter([
            ...$this->describe($best),
            'otras_coincidencias' => $matches->count() > 1
                ? $matches->slice(1, self::MAX_MATCHES)->map(fn (Product $p) => $this->describe($p))->values()->all()
                : null,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function describe(Product $product): array
    {
        $variants = $product->variants;

        return array_filter([
            'nombre' => $product->name,
            'stock' => $variants->isNotEmpty()
                ? (float) $variants->sum(fn (ProductVariant $v) => $v->stockAt())
                : $product->stockAt(),
            'track_stock' => (bool) $product->track_stock,
            'variaciones' => $variants->isNotEmpty()
                ? $variants->map(fn (ProductVariant $v) => [
                    'variacion' => $v->attributeValues->pluck('value')->join(' / ') ?: $v->sku,
                    'stock' => $v->stockAt(),
                ])->values()->all()
                : null,
        ], fn ($value) => $value !== null);
    }
}
