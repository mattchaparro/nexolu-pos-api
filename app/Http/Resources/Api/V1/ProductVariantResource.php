<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            // El de la sede activa: un mismo producto puede costar
            // distinto en el centro comercial que en la fabrica.
            //
            // Formateado a string con 2 decimales, no float: es lo que venia
            // devolviendo el cast decimal:2 de la columna y el front ya lo
            // consume asi. Cambiar el tipo de un campo existente al agregar
            // una funcionalidad nueva rompe a quien no pidio nada.
            'price' => number_format($this->priceAt(), 2, '.', ''),
            // Mismo gate que Product::cost_price en ProductResource: no
            // filtrar el margen del negocio a quien no tenga inventory.view.
            'cost_price' => $this->when($request->user()?->hasBusinessPermission('inventory.view') === true, $this->cost_price),
            'stock' => $this->stockAt(),
            'low_stock_alert_threshold' => $this->low_stock_alert_threshold,
            'is_active' => $this->is_active,
            'attribute_values' => $this->attributeValues->map(fn ($value) => [
                'product_attribute_id' => $value->pivot->product_attribute_id,
                'product_attribute_name' => $value->productAttribute?->name,
                'product_attribute_value_id' => $value->id,
                'value' => $value->value,
            ]),
        ];
    }
}
