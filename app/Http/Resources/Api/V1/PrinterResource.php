<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Printer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Printer
 */
class PrinterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'connection' => $this->connection,
            'address' => $this->address,
            'paper_width' => $this->paper_width,
            'is_active' => $this->is_active,
            'branch_id' => $this->branch_id,
            'print_agent_id' => $this->print_agent_id,
        ];
    }
}
