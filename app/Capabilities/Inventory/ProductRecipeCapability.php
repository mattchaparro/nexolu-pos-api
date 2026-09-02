<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Capabilities\Support\ResolvesProductByName;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;

/** Tool: receta_producto. Que lleva un plato, cuanto cuesta hacerlo y cuanto deja. */
class ProductRecipeCapability implements Capability
{
    use CapsRows, ResolvesProductByName;

    public function requiredPermission(): ?string
    {
        return 'reports.inventory';
    }

    public function requiredFeature(): ?string
    {
        return 'ingredients';
    }

    public function rules(): array
    {
        return [
            'nombre_producto' => ['required', 'string', 'max:200'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        // resolveProductByName exige un unico candidato y, si hay varios, le
        // devuelve al modelo la lista para que pregunte. Es lo correcto aca:
        // "cuanto me cuesta prepararla" sobre el plato equivocado es una
        // respuesta que suena bien y esta mal.
        $resolved = $this->resolveProductByName((string) $arguments['nombre_producto']);

        $product = Product::with('ingredients:id,name,unit,cost_price')->findOrFail($resolved->id);

        $lines = $product->ingredients->map(function ($ingredient) {
            $quantity = (float) $ingredient->pivot->quantity;

            return [
                'ingrediente' => (string) $ingredient->name,
                'cantidad' => round($quantity, 3),
                'unidad' => $ingredient->unit,
                'costo_unitario' => round((float) $ingredient->cost_price, 2),
                'costo_en_receta' => round($quantity * (float) $ingredient->cost_price, 2),
            ];
        })->values()->all();

        $price = (float) $product->price;
        // Sin receta cargada se usa el costo del producto, que es lo que el POS
        // usa en sus propios reportes de margen.
        $cost = $lines === [] ? (float) $product->cost_price : array_sum(array_column($lines, 'costo_en_receta'));

        return [
            'producto' => (string) $product->name,
            'precio_venta' => round($price, 2),
            'costo' => round($cost, 2),
            'origen_del_costo' => $lines === [] ? 'costo_registrado_del_producto' : 'suma_de_ingredientes',
            'margen' => round($price - $cost, 2),
            'margen_porcentaje' => $price > 0 ? round((($price - $cost) / $price) * 100, 1) : null,
            'ingredientes' => $this->capRows($lines),
            'nota' => $lines === []
                ? 'Este producto no tiene receta cargada: el costo sale del campo de costo del producto.'
                : null,
        ];
    }
}
