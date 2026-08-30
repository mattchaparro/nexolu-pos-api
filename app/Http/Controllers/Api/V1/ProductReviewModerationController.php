<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductReviewResource;
use App\Models\ProductReview;
use App\Services\ProductReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Moderacion de reseñas, del lado del COMERCIANTE.
 *
 * No hay endpoint para crear ni editar el texto: el comerciante decide que se
 * publica, no que dicen sus clientes. Solo aprobar u ocultar.
 *
 * El aislamiento por negocio lo pone el global scope de BelongsToBusiness a
 * partir del usuario autenticado, como en el resto del POS.
 */
class ProductReviewModerationController extends Controller
{
    public function __construct(private ProductReviewService $reviews) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ProductReview::query()->with('product:id,name');

        // Por defecto la bandeja muestra lo que hay que atender.
        $status = (string) $request->input('status', ProductReview::STATUS_PENDING);
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return ProductReviewResource::collection(
            $query->latest('created_at')->paginate(25)->withQueryString()
        );
    }

    /** Cuantas esperan revision, para el badge de la navegacion. */
    public function pendingCount(): array
    {
        return ['pending' => ProductReview::where('status', ProductReview::STATUS_PENDING)->count()];
    }

    public function update(Request $request, ProductReview $review): ProductReviewResource
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([ProductReview::STATUS_APPROVED, ProductReview::STATUS_HIDDEN])],
        ]);

        return new ProductReviewResource(
            $this->reviews->moderate($review, $validated['status'], $request->user())
        );
    }
}
