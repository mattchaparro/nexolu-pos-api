<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;

/** Tool: inventario. Listado de inventario, opcionalmente filtrado por categoria. */
class InventoryListCapability implements Capability
{
    private const MAX_ROWS = 100;

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
            'categoria' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $products = Product::with('category')
            ->when($arguments['categoria'] ?? null, function ($query, $categoria) {
                $query->whereHas('category', fn ($q) => $q->where('name', 'like', "%{$categoria}%"));
            })
            ->orderBy('name')
            ->limit(self::MAX_ROWS)
            ->get();

        return [
            'productos' => $products->map(fn (Product $product) => [
                'nombre' => $product->name,
                'categoria' => $product->category?->name,
                'stock' => (float) $product->stock,
                'precio' => (float) $product->price,
            ])->all(),
        ];
    }
}
