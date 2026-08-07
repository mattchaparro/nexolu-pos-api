<?php

namespace App\Services\WhatsApp;

use App\Models\AiChannelIdentity;
use App\Support\WhatsAppSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registra el consumo de WhatsApp Cloud API en whatsapp_usage_daily, para
 * poder reportar despues cuanto gasta cada negocio. Nunca debe tumbar el
 * envio real del mensaje: cualquier fallo se traga y se loguea.
 */
class WhatsAppUsageRecorder
{
    public function record(string $category, ?string $phone = null, ?int $businessId = null, ?Carbon $when = null): void
    {
        try {
            $when ??= Carbon::now();
            $businessId ??= $this->resolveBusiness($phone);
            $cost = WhatsAppSettings::rateMicros($category);

            // upsert + incremento atomico: dos envios simultaneos suman 2, no se pisan.
            DB::table('whatsapp_usage_daily')->upsert(
                [[
                    'business_id' => $businessId,
                    'date' => $when->toDateString(),
                    'category' => $category,
                    'messages_count' => 1,
                    'cost_micros' => $cost,
                    'created_at' => $when,
                    'updated_at' => $when,
                ]],
                ['business_id', 'date', 'category'],
                [
                    'messages_count' => DB::raw('messages_count + 1'),
                    'cost_micros' => DB::raw("cost_micros + {$cost}"),
                    'updated_at' => DB::raw("'{$when->toDateTimeString()}'"),
                ],
            );
        } catch (\Throwable $e) {
            try {
                Log::warning('whatsapp.usage: no se pudo registrar el consumo', [
                    'category' => $category,
                    'business_id' => $businessId,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }
    }

    // Sin BelongsToBusiness a proposito: corre en jobs/comandos sin sesion HTTP.
    private function resolveBusiness(?string $phone): ?int
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        return AiChannelIdentity::query()
            ->withoutGlobalScopes()
            ->where('channel', AiChannelIdentity::CHANNEL_WHATSAPP)
            ->where('external_id', $phone)
            ->orderByRaw('verified_at IS NULL')
            ->orderByDesc('verified_at')
            ->value('business_id');
    }
}
