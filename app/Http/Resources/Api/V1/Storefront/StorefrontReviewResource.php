<?php

namespace App\Http\Resources\Api\V1\Storefront;

use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una reseña como la ve internet.
 *
 * No sale `order_id` ni nada del comprador mas alla del nombre que el mismo
 * escribio en el pedido: publicar la reseña no puede publicar de paso el
 * telefono o la direccion de quien compro.
 *
 * @mixin ProductReview
 */
class StorefrontReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'author_name' => $this->author_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
