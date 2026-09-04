<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalePartialPaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'payer_label' => $this->payer_label,
            'user_id' => $this->user_id,
            // Cuando se registro el abono - el POS lo muestra en el detalle
            // de la cuenta ("no es claro cuando ni cuanto abono").
            'created_at' => $this->created_at,
        ];
    }
}
