<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Sale;
use App\Models\SaleItemOption;
use App\Services\KitchenBoardService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Sale */
class KitchenTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'table_name' => $this->table?->name,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            // La nota de la cuenta ("sin cebolla", "alergia a la nuez", dirección
            // del domicilio): quien cocina tiene que verla en la comanda.
            'notes' => $this->notes,
            'is_delivery' => (bool) $this->is_delivery,
            'delivery_address' => $this->delivery_address,
            // Pedido online: ya esta cobrado (o el negocio verifico el pago) y
            // sale de la comandera al despacharse, no al cobrarse.
            'online_order' => $this->onlineOrder ? [
                'number' => $this->onlineOrder->number,
                'status' => $this->onlineOrder->status,
                'is_pickup' => (bool) $this->onlineOrder->is_pickup,
                'is_paid_online' => $this->onlineOrder->paid_at !== null,
            ] : null,
            'kitchen_status' => KitchenBoardService::normalizeStatus($this->kitchen_status),
            'created_at' => $this->created_at?->format('H:i'),
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->product?->name ?? 'Producto eliminado',
                'is_deleted' => ! $item->product,
                'quantity' => $item->quantity,
                'options' => SaleItemOption::tally($item->options)
                    ->map(fn (array $option) => ['group' => $option['group'], 'name' => $option['name'], 'quantity' => $option['quantity']])
                    ->values(),
                'kitchen_status' => KitchenBoardService::normalizeStatus($item->kitchen_status),
            ]),
        ];
    }
}
