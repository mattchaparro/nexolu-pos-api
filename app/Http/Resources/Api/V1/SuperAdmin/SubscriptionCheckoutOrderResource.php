<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionCheckoutOrderResource extends JsonResource
{
    /**
     * Una orden pendiente sin novedad despues de este tiempo es, en la
     * practica, un checkout que el usuario abrio y no termino: el webhook de
     * la pasarela llega en segundos, no en horas.
     */
    private const STALE_PENDING_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        return [
            'id' => $this->id,
            'business' => $this->whenLoaded('business', fn () => $this->business
                ? ['id' => $this->business->id, 'name' => $this->business->name]
                : null),
            'business_id' => $this->business_id,
            'order_key' => $this->order_key,
            'amount_cop' => $this->amount_cop,
            'ai_addon_included' => (bool) $this->ai_addon_included,
            'ai_addon_amount_cop' => $this->ai_addon_amount_cop,
            'subscription_days' => $this->subscription_days,
            'status' => $this->status,
            'provider' => $this->provider,
            'provider_order_id' => $this->provider_order_id,

            // Lo que respondio la pasarela. `status` es el suyo, que no
            // siempre coincide con el nuestro: nosotros solo tenemos
            // confirmed/failed/pending y el proveedor distingue DECLINED de
            // ERROR, que es la diferencia entre "la tarjeta no tiene fondos" y
            // "algo se rompio de nuestro lado".
            'provider_status' => $payload['status'] ?? null,
            'provider_event' => $payload['event'] ?? null,
            // Comision y neto: sin esto, "cuanto entro" es el monto bruto, que
            // no es lo que llega a la cuenta.
            'fee_cop' => $payload['fee_cop'] ?? null,
            'net_amount_cop' => $payload['net_amount_cop'] ?? null,
            'payload' => $payload ?: null,

            // Una orden pendiente vieja no es un cobro en curso: es un
            // checkout abandonado. Sin distinguirlos, la lista de pendientes
            // crece para siempre y deja de mirarse.
            'pending_stale' => $this->status === 'pending'
                && $this->created_at?->lt(now()->subMinutes(self::STALE_PENDING_MINUTES)),

            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
