<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductImage
 */
class ProductImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'url' => $this->url(),
            'thumbnail_url' => $this->thumbnailUrl(),
            'alt' => $this->alt,
            'sort_order' => $this->sort_order,
        ];
    }
}
