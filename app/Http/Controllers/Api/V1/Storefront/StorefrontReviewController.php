<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Storefront\StoreStorefrontReviewRequest;
use App\Http\Resources\Api\V1\Storefront\StorefrontReviewResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Services\ProductReviewService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reseñas del lado del COMPRADOR ANONIMO.
 *
 * Leer es publico; escribir exige el `public_token` del pedido, que es la
 * unica prueba de compra que tiene alguien sin cuenta. Ver
 * ProductReviewService para las reglas.
 */
class StorefrontReviewController extends Controller
{
    public function __construct(private ProductReviewService $reviews) {}

    /** Solo las aprobadas, y solo de un producto publicado. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $business = TenantContext::current();
        abort_unless($business !== null, 404);

        $product = Product::query()
            ->where('is_published', true)
            ->where('is_active', true)
            ->find((int) $request->route('productId'));

        abort_if($product === null, 404);

        $reviews = ProductReview::query()
            ->where('product_id', $product->id)
            ->approved()
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return StorefrontReviewResource::collection($reviews);
    }

    /**
     * Crear una reseña. El token del pedido va en la RUTA, igual que en el
     * seguimiento: es la credencial del comprador.
     */
    public function store(StoreStorefrontReviewRequest $request): JsonResponse
    {
        $business = TenantContext::current();
        abort_unless($business !== null, 404);

        $order = Order::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('public_token', (string) $request->route('token'))
            ->first();

        abort_if($order === null, 404);

        $review = $this->reviews->createFromOrder($order, $request->validated());

        // 201 con la reseña, pero el comprador tiene que saber que todavia no
        // se ve: se publica cuando el comerciante la aprueba.
        return response()->json([
            'data' => new StorefrontReviewResource($review),
            'message' => 'Gracias. Tu opinión se publica cuando la tienda la revise.',
        ], 201);
    }
}
