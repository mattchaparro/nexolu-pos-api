<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Sale;
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
            'is_delivery' => (bool) $this->is_delivery,
            'kitchen_status' => KitchenBoardService::normalizeStatus($this->kitchen_status),
            'created_at' => $this->created_at?->format('H:i'),
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->product?->name ?? 'Producto eliminado',
                'is_deleted' => ! $item->product,
                'quantity' => $item->quantity,
                'kitchen_status' => KitchenBoardService::normalizeStatus($item->kitchen_status),
            ]),
        ];
    }
}
