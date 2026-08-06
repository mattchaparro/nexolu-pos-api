<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Ai\Insights\SmartSummaryInsight;
use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Support\WhatsAppRecipients;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Resumen diario del negocio por WhatsApp: la primera notificacion proactiva
 * (no una respuesta dentro del chat) que el negocio recibe sin pedirla en el
 * momento.
 *
 * Reutiliza el mismo calculo deterministico que alimenta la tarjeta "Resumen
 * inteligente" del dashboard (SmartSummaryInsight::gatherData): no hay un
 * segundo lugar que decida "como le fue al negocio hoy", y esta notificacion
 * NO le cuesta tokens de IA -- son los mismos numeros ya calculados en PHP,
 * solo van por otro canal en vez de redactarse con el modelo (a diferencia
 * del dashboard, que si pasa por AiInsightService para la version en prosa).
 *
 * Va por PLANTILLA: es un mensaje que el negocio inicia, no una respuesta
 * dentro de la ventana de 24h. Sin la plantilla aprobada en Meta, el comando
 * no envia nada (mismo patron tolerante que reminders:send-whatsapp-notifications).
 */
#[Signature('notifications:send-daily-whatsapp-summary {--business_id= : Solo para un negocio}')]
#[Description('Envia el resumen diario del negocio por WhatsApp a los admins que lo activaron')]
class SendDailyWhatsAppSummary extends Command
{
    public function handle(SmartSummaryInsight $insight, WhatsAppCloudClient $client): int
    {
        $template = config('services.whatsapp.templates.resumen_diario');

        if (empty($template['name'])) {
            $this->warn('Plantilla resumen_diario no configurada todavia. Nada que enviar.');

            return self::SUCCESS;
        }

        $query = Business::query()->where('active', true);

        if ($businessId = $this->option('business_id')) {
            $query->where('id', $businessId);
        }

        $sent = 0;

        foreach ($query->cursor() as $business) {
            // Preferencia apagada por defecto: nadie recibe WhatsApp sin
            // activarlo explicitamente desde Settings.
            if (! ($business->notification_preferences['resumen_diario'] ?? false)) {
                continue;
            }

            $recipients = WhatsAppRecipients::linkedAdmins($business);
            if ($recipients->isEmpty()) {
                continue;
            }

            $data = $insight->gatherData($business->id);

            // Mismo criterio que la tarjeta del dashboard: si no hay ni
            // ventas de referencia ni nada prioritario, no hay lectura util
            // que dar.
            if (! $insight->isWorthShowing($data)) {
                continue;
            }

            $components = $this->components($data);

            foreach ($recipients as $identity) {
                if ($client->sendTemplate($identity->external_id, $template['name'], $template['lang'] ?? 'es', $components)) {
                    $sent++;
                }
            }
        }

        $this->info("Resumenes diarios enviados: {$sent}");

        return self::SUCCESS;
    }

    /**
     * Arma los 4 parametros del body de la plantilla, en el mismo orden que
     * espera la plantilla aprobada: salud, ventas, gastos, prioridad.
     *
     * @param  array<string, mixed>  $data  lo que devuelve SmartSummaryInsight::gatherData()
     * @return list<array<string, mixed>>
     */
    private function components(array $data): array
    {
        $emoji = match ($data['health_level']) {
            'green' => '🟢',
            'yellow' => '🟡',
            default => '🔴',
        };

        $vsYesterday = $data['sales_today_vs_yesterday_pct'] !== null
            ? sprintf('%+.0f%% vs ayer', $data['sales_today_vs_yesterday_pct'])
            : 'sin dato de ayer';

        $expenses = 'Gastos: $'.number_format($data['expenses_today'], 0, ',', '.')
            .($data['unusual_expense'] ? ' (mas alto de lo normal)' : '');

        $priority = $data['priority']['text'] ?? 'sin urgencias hoy, todo en orden';

        $values = [
            $emoji.' '.ucfirst((string) $data['health_factor']),
            'Ventas: $'.number_format($data['sales_today_total'], 0, ',', '.').' ('.$vsYesterday.')',
            $expenses,
            ucfirst($priority),
        ];

        return [[
            'type' => 'body',
            'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => $v], $values),
        ]];
    }
}
