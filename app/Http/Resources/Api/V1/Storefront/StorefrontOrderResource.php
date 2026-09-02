<?php

namespace App\Http\Resources\Api\V1\Storefront;

use App\Models\Order;
use App\Services\ProductReviewService;
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
            // El codigo va congelado en el pedido: el cupon pudo vencerse o
            // desactivarse despues, y el total tiene que seguir explicandose.
            'coupon_code' => $this->coupon_code,
            'discount_amount' => (float) $this->discount_amount,
            'total' => (float) $this->total,
            // A donde mandar al comprador a pagar, si el negocio cobra en
            // linea. Nulo = coordina el pago con la tienda por fuera.
            'payment_url' => $this->payment_url,
            // Con que se pago y cuando. Lo que quiere ver quien acaba de
            // pagar es el comprobante, no un estado interno del pedido.
            'payment_provider' => $this->payment_provider,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'customer_name' => $this->customer_name,
            'is_pickup' => (bool) $this->is_pickup,
            'shipping_address' => $this->shipping_address,
            'shipping_city' => $this->shipping_city,
            'shipping_notes' => $this->shipping_notes,
            'items' => $this->items->map(fn ($item) => [
                // El id del producto no es un dato sensible: ya esta en la URL
                // publica de su ficha. Va aca para poder enlazar de vuelta al
                // producto y para el formulario de calificacion.
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'variant_label' => $item->variant_label,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
            ])->values(),
            // Que puede calificar HOY: vacio si el pedido todavia no se
            // entrego, o si ya califico todo. La tienda pinta el formulario
            // segun esto y no vuelve a preguntar (ver ProductReviewService).
            'reviewable_product_ids' => app(ProductReviewService::class)->pendingProductIds($this->resource),
            'placed_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
