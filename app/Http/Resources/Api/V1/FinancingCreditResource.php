<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FinancingCredit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancingCreditResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isOverdue = $this->status === FinancingCredit::STATUS_PENDING
            && $this->expected_payout_date !== null
            && $this->expected_payout_date->lt(now()->startOfDay());

        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'invoice_number' => $this->whenLoaded('sale', fn () => $this->sale?->invoice_number),
            'sale_total' => $this->whenLoaded('sale', fn () => $this->sale ? (float) $this->sale->total : null),
            'provider' => new FinancingProviderResource($this->whenLoaded('provider')),
            'amount' => (float) $this->amount,
            'approval_number' => $this->approval_number,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_identification' => $this->customer_identification,
            'status' => $this->status,
            'is_overdue' => $isOverdue,
            'expected_payout_date' => $this->expected_payout_date?->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payout_method' => $this->payout_method,
            'payout_reference' => $this->payout_reference,
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->fullName()),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
