<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateBranchProductPricesRequest;
use App\Models\BranchProductPrice;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Precios por sede de un producto y sus variantes.
 *
 * Vive aparte del formulario del producto a proposito: el precio del catalogo
 * es del producto y lo edita quien administra el catalogo; el override por
 * sede es una decision comercial de ese local, se toca mucho menos, y en un
 * negocio monosede la pantalla no existe.
 *
 * Enviar `price: null` (o no enviar la sede) BORRA el override: la ausencia
 * de fila es lo que significa "usa el precio del catalogo". Guardar cero seria
 * un precio de cero.
 */
class BranchProductPriceController extends Controller
{
    public function index(Request $request, Product $product): JsonResponse
    {
        return response()->json([
            'product_id' => $product->id,
            'base_price' => (float) $product->price,
            'branch_prices' => $this->currentOverrides($product),
        ]);
    }

    public function update(UpdateBranchProductPricesRequest $request, Product $product): JsonResponse
    {
        $businessId = (int) $product->business_id;

        DB::transaction(function () use ($request, $product, $businessId) {
            foreach ($request->validated('prices') as $row) {
                $column = ! empty($row['product_variant_id']) ? 'product_variant_id' : 'product_id';
                $targetId = $column === 'product_variant_id' ? (int) $row['product_variant_id'] : $product->id;

                $existing = BranchProductPrice::where('branch_id', $row['branch_id'])
                    ->where($column, $targetId);

                if (($row['price'] ?? null) === null) {
                    $existing->delete();

                    continue;
                }

                BranchProductPrice::updateOrCreate(
                    ['branch_id' => $row['branch_id'], $column => $targetId],
                    ['business_id' => $businessId, 'price' => round((float) $row['price'], 2)],
                );
            }
        });

        return response()->json([
            'product_id' => $product->id,
            'base_price' => (float) $product->price,
            'branch_prices' => $this->currentOverrides($product),
        ]);
    }

    /** @return list<array{branch_id: int, product_variant_id: ?int, price: float}> */
    private function currentOverrides(Product $product): array
    {
        return BranchProductPrice::where(fn ($query) => $query
            ->where('product_id', $product->id)
            ->orWhereIn('product_variant_id', $product->variants()->select('id'))
        )
            ->orderBy('branch_id')
            ->get()
            ->map(fn (BranchProductPrice $row) => [
                'branch_id' => (int) $row->branch_id,
                'product_variant_id' => $row->product_variant_id ? (int) $row->product_variant_id : null,
                'price' => (float) $row->price,
            ])
            ->all();
    }
}
