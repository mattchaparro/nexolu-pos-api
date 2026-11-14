<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un ticket de la cola, para el POS (sin los bytes).
 *
 * @mixin PrintJob
 */
class PrintJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'sale_id' => $this->sale_id,
            'printer_id' => $this->printer_id,
            'printer_name' => $this->printer?->name,
            'attempts' => $this->attempts,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
            'printed_at' => $this->printed_at?->toIso8601String(),
        ];
    }
}
