<?php

namespace App\Http\Resources\Api\V1\Storefront;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pedido tal y como lo ve el COMPRADOR en la pagina de seguimiento.
 *
 * Sin el id interno: lo unico que identifica su pedido hacia afuera es el
 * `public_token` de la URL y el numero visible ("#14"). Tampoco viaja la
 * bitacora completa de estados, que es informacion de la operacion del
 * negocio.
 *
 * @mixin Order
 */
class StorefrontOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status,
            'token' => $this->public_token,
            'subtotal' => (float) $this->subtotal,
            'shipping_fee' => (float) $this->shipping_fee,
            'total' => (float) $this->total,
            // A donde mandar al comprador a pagar, si el negocio cobra en
            // linea. Nulo = coordina el pago con la tienda por fuera.
            'payment_url' => $this->payment_url,
            'customer_name' => $this->customer_name,
            'is_pickup' => (bool) $this->is_pickup,
            'shipping_address' => $this->shipping_address,
            'shipping_city' => $this->shipping_city,
            'items' => $this->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'variant_label' => $item->variant_label,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
            ])->values(),
            'placed_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
