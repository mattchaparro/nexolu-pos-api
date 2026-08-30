<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una reseña como la ve el COMERCIANTE en su bandeja de moderacion.
 *
 * A diferencia de StorefrontReviewResource, aca si va el estado y el pedido
 * que la habilito: el comerciante necesita poder rastrear de que compra salio
 * antes de decidir si la publica.
 *
 * @mixin ProductReview
 */
class ProductReviewResource extends JsonResource
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
            'status' => $this->status,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
            'order_id' => $this->order_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'moderated_at' => $this->moderated_at?->toIso8601String(),
        ];
    }
}
