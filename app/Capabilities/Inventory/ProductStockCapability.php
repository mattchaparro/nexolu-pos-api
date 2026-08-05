<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Tool: stock_producto. Existencias actuales de un producto puntual por nombre. */
class ProductStockCapability implements Capability
{
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
        $product = Product::where('name', 'like', '%'.$arguments['producto'].'%')->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'producto' => "No se encontro un producto que coincida con '{$arguments['producto']}'.",
            ]);
        }

        return [
            'nombre' => $product->name,
            'stock' => (float) $product->stock,
            'track_stock' => (bool) $product->track_stock,
        ];
    }
}
