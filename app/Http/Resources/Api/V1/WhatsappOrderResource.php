<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsappOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wamid' => $this->wamid,
            'phone' => $this->phone,
            'customer_name' => $this->customer_name,
            'status' => $this->status,
            'sale_id' => $this->sale_id,
            'error' => $this->error,
            // Las lineas crudas de Meta, para que la bandeja muestre que
            // pidieron aunque el mapeo a productos haya fallado.
            'items' => collect($this->raw_order['product_items'] ?? [])->map(fn ($line) => [
                'product_retailer_id' => $line['product_retailer_id'] ?? null,
                'quantity' => $line['quantity'] ?? null,
            ]),
            'note' => $this->raw_order['text'] ?? null,
            'created_at' => $this->created_at,
        ];
    }
}
