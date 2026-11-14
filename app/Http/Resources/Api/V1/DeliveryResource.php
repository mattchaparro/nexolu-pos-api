<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Sale;
use App\Services\DeliveryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un domicilio en la pantalla del domiciliario: a donde va, que lleva y que
 * tiene que cobrar al entregar.
 *
 * @mixin Sale
 */
class DeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->onlineOrder;

        return [
            'id' => $this->id,
            'order_number' => $order?->number,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'address' => $this->delivery_address,
            // Pedido online para recoger: lo muestra Despacho junto a los domicilios.
            'is_pickup' => (bool) ($order?->is_pickup && ! $this->is_delivery),
            // Lo que escribio el comprador ("sin cebolla", "el timbre no
            // sirve"), o la nota de la cuenta si salio del POS.
            'notes' => $order ? $order->shipping_notes : $this->notes,
            'items' => $this->items->map(fn ($item) => [
                'name' => $item->product?->name ?? 'Producto eliminado',
                'quantity' => $item->quantity,
            ])->values(),
            'total' => (float) $this->total,
            // Cuenta abierta: se cobra al entregar y la cierra el cajero.
            'is_open_tab' => $this->status === 'open',
            'payment_method' => $this->payment_method,
            'is_paid_online' => $order?->paid_at !== null,
            'status' => $this->delivery_status,
            'is_ready' => app(DeliveryService::class)->isReady($this->resource, $request->user()->business),
            'courier' => $this->courier ? ['id' => $this->courier->id, 'name' => $this->courier->name] : null,
            'created_at' => $this->created_at?->format('H:i'),
            'taken_at' => $this->delivery_taken_at?->format('H:i'),
            'delivered_at' => $this->delivered_at?->format('H:i'),
        ];
    }
}
