<?php

namespace App\Http\Resources\Api\V1\Storefront;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una variante para el comprador. Sin `cost_price` ni el stock exacto - ver
 * el docblock de StorefrontProductResource.
 *
 * @mixin ProductVariant
 */
class StorefrontVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Menos lo que ya retienen pedidos pendientes.
        $stock = (float) $this->stock - StorefrontProductResource::reservedForVariant($this->id);

        return [
            'id' => $this->id,
            // Sin sku: es un codigo interno de inventario, no le dice nada al
            // comprador y filtra volumen de catalogo.
            'label' => $this->attributeValues->pluck('value')->implode(' / '),
            'options' => $this->whenLoaded('attributeValues', fn () => $this->attributeValues->map(fn ($value) => [
                'attribute' => $value->productAttribute?->name,
                'value' => $value->value,
            ])->values()),
            'price' => (float) $this->price,
            'available' => [
                'in_stock' => $stock > 0,
                'quantity' => StorefrontProductResource::visibleStock($stock),
            ],
        ];
    }
}
