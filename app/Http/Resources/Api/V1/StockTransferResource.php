<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockTransfer */
class StockTransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'transferred_at' => $this->transferred_at?->toIso8601String(),
            'from_branch' => $this->whenLoaded('fromBranch', fn () => [
                'id' => $this->fromBranch->id,
                'name' => $this->fromBranch->name,
            ]),
            'to_branch' => $this->whenLoaded('toBranch', fn () => [
                'id' => $this->toBranch->id,
                'name' => $this->toBranch->name,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (StockTransferItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'ingredient_id' => $item->ingredient_id,
                'name' => $this->itemName($item),
                'quantity' => (float) $item->quantity,
                'unit_cost_cop' => $item->unit_cost_cop !== null ? (float) $item->unit_cost_cop : null,
            ])),
        ];
    }

    private function itemName(StockTransferItem $item): ?string
    {
        return $item->relationLoaded('productVariant') && $item->productVariant
            ? $item->productVariant->name
            : ($item->relationLoaded('product') && $item->product
                ? $item->product->name
                : ($item->relationLoaded('ingredient') && $item->ingredient ? $item->ingredient->name : null));
    }
}
