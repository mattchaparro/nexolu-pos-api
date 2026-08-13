<?php

namespace App\Services;

use App\Models\AiMessagePackPurchase;
use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * Balance de paquetes de mensajes de IA comprados (no expiran, a diferencia
 * del cupo mensual incluido). AiMessagePackPurchase es solo el rastro de
 * auditoria de cada acreditacion; la fuente de verdad del balance disponible
 * es Business::ai_message_pack_balance.
 */
class AiMessagePackService
{
    /** Acredita un paquete ya pagado y deja registro de auditoria. */
    public function credit(Business $business, int $messages, int $priceCop, ?int $createdByUserId = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($business, $messages, $priceCop, $createdByUserId, $notes) {
            $business->increment('ai_message_pack_balance', $messages);

            AiMessagePackPurchase::create([
                'business_id' => $business->id,
                'messages' => $messages,
                'price_cop' => $priceCop,
                'created_by_user_id' => $createdByUserId,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Consume un mensaje del balance de paquetes, de forma atomica (nunca
     * deja el balance en negativo bajo concurrencia). Devuelve false si no
     * habia balance disponible - el llamante decide que hacer con eso.
     */
    public function consumeOne(Business $business): bool
    {
        return Business::where('id', $business->id)
            ->where('ai_message_pack_balance', '>', 0)
            ->decrement('ai_message_pack_balance') > 0;
    }
}
