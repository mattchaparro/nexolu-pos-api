<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pedido para el COMERCIANTE: todo lo que necesita para atenderlo.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'subtotal' => (float) $this->subtotal,
            'shipping_fee' => (float) $this->shipping_fee,
            'total' => (float) $this->total,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_email' => $this->customer_email,
            'is_pickup' => (bool) $this->is_pickup,
            'shipping_address' => $this->shipping_address,
            'shipping_city' => $this->shipping_city,
            'shipping_notes' => $this->shipping_notes,
            'sale_id' => $this->sale_id,
            'client_id' => $this->client_id,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // A donde puede moverse: la UI dibuja los botones desde aca en vez
            // de reimplementar la maquina de estados.
            'available_transitions' => Order::TRANSITIONS[$this->status] ?? [],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'variant_label' => $item->variant_label,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
            ])->values()),
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn ($row) => [
                'from_status' => $row->from_status,
                'to_status' => $row->to_status,
                'note' => $row->note,
                'user' => $row->user?->name,
                'at' => $row->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
