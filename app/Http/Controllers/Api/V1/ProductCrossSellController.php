<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Services\CrossSellService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Ventas cruzadas del lado del COMERCIANTE.
 *
 * Cuelga del producto y no de un recurso propio porque asi es como se piensa:
 * "a quien lleve esta hamburguesa, ofrecele esto". El binding de `{product}`
 * pasa por el global scope, asi que un producto de otro negocio da 404 sin
 * que haya que comprobarlo aca.
 */
class ProductCrossSellController extends Controller
{
    public function __construct(private CrossSellService $crossSells) {}

    /**
     * Las sugerencias configuradas. Sin `publicOnly`: el cajero puede vender
     * cosas que no estan publicadas en internet.
     */
    public function index(Product $product): AnonymousResourceCollection
    {
        return ProductResource::collection($this->crossSells->forProduct($product));
    }

    /** Reemplaza la lista completa, en el orden recibido. */
    public function update(Request $request, Product $product): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'cross_sell_ids' => ['present', 'array', 'max:'.CrossSellService::MAX_PER_PRODUCT],
            'cross_sell_ids.*' => ['integer'],
        ]);

        $this->crossSells->sync($product, $validated['cross_sell_ids']);

        return ProductResource::collection($this->crossSells->forProduct($product));
    }
}
