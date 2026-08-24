<?php

namespace App\Console\Commands;

use App\Mail\LowStockAlertMail;
use App\Models\AiChannelIdentity;
use App\Models\Business;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\LowStockAlertReport;
use App\Support\WhatsAppRecipients;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Envia una alerta de inventario bajo por correo y/o WhatsApp, segun lo que
 * cada negocio tenga activado. Los dos canales son independientes entre si:
 * un negocio puede tener el correo apagado y la preferencia de WhatsApp
 * prendida, o al reves.
 *
 * Hora configurable por negocio (Business::notificationHour('inventario_bajo'),
 * default 08:00) - mismo cambio y mismo motivo que
 * SendDailyWhatsAppSummary: el schedule (routes/console.php) paso de una
 * corrida fija a cada 5 minutos, y last_low_stock_alert_sent_on evita
 * reevaluar el mismo negocio mas de una vez por dia. La hora gatea el chequeo
 * completo (los dos canales), no uno a la vez - es "cuando queres que te
 * avise de esto", no una preferencia por canal.
 */
#[Signature('inventory:send-low-stock-alerts {--business_id= : Enviar solo para un negocio}')]
#[Description('Envia una alerta de inventario bajo (correo y/o WhatsApp) a los negocios configurados')]
class InventorySendLowStockAlerts extends Command
{
    public function handle(MessagingChannel $whatsapp): int
    {
        $businessId = $this->option('business_id');

        // Candidato si tiene el correo activado O tiene WhatsApp vinculado: el
        // filtro inicial no puede exigir solo uno de los dos, o se perderian
        // negocios que solo quieren el otro canal.
        $query = Business::query()
            ->where(function (Builder $q) {
                $q->where('low_stock_email_enabled', true)
                    ->orWhereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('ai_channel_identities')
                            ->whereColumn('ai_channel_identities.business_id', 'businesses.id')
                            ->where('channel', AiChannelIdentity::CHANNEL_WHATSAPP)
                            ->whereNotNull('verified_at');
                    });
            });

        if ($businessId) {
            $query->where('id', $businessId);
        }

        $whatsappTemplate = config('services.whatsapp.templates.inventario_bajo');

        $now = Carbon::now();
        $emailsSent = 0;
        $whatsappSent = 0;

        foreach ($query->get() as $business) {
            if (! $business->hasFeature('low_stock_alert')) {
                continue;
            }

            // Silenciar aplica a los dos canales por igual: significa "no me
            // avises de esto ahora", no "no por correo pero si por WhatsApp".
            if ($business->low_stock_snoozed_until?->isFuture()) {
                continue;
            }

            // Igual que el resumen diario: se evalua una sola vez por dia,
            // en el primer tick que alcanza o pasa la hora elegida.
            if ($business->last_low_stock_alert_sent_on?->isSameDay($now)) {
                continue;
            }

            if ($now->format('H:i') < $business->notificationHour('inventario_bajo')) {
                continue;
            }

            $business->update(['last_low_stock_alert_sent_on' => $now->toDateString()]);

            if ($business->low_stock_email_enabled) {
                $emailsSent += $this->sendEmail($business);
            }

            if (! empty($whatsappTemplate['name'])
                && ($business->notification_preferences['inventario_bajo'] ?? false)) {
                $whatsappSent += $this->sendWhatsApp($whatsapp, $business, $whatsappTemplate);
            }
        }

        $this->info("Correos enviados: {$emailsSent}. WhatsApp enviados: {$whatsappSent}.");

        return self::SUCCESS;
    }

    private function sendEmail(Business $business): int
    {
        $recipient = $business->low_stock_email ?: $business->users()
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'admin'))
            ->value('email');

        if (! $recipient) {
            return 0;
        }

        $report = LowStockAlertReport::forBusiness($business);
        if ($report['count'] === 0) {
            return 0;
        }

        try {
            Mail::to($recipient)->send(new LowStockAlertMail($business, $report['items']));

            return 1;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param  array{name?: string, lang?: string}  $template */
    private function sendWhatsApp(MessagingChannel $client, Business $business, array $template): int
    {
        $recipients = WhatsAppRecipients::linkedAdmins($business);
        if ($recipients->isEmpty()) {
            return 0;
        }

        $report = LowStockAlertReport::forBusiness($business);
        if ($report['count'] === 0) {
            return 0;
        }

        $components = $this->whatsappComponents($business, $report);
        $sent = 0;

        foreach ($recipients as $identity) {
            if ($client->sendTemplate($identity->external_id, $template['name'], $template['lang'] ?? 'es_CO', $components, $business->id, 'inventario_bajo')) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Arma los 3 parametros del body de la plantilla low_stock_alert:
     * negocio, cantidad total, y el bloque de items mas urgentes.
     *
     * Los items van separados por tabulacion vertical (chr(11)), NO por
     * salto de linea normal: Meta rechaza \n/\r/\t en el valor de un
     * parametro de plantilla (error 132018). Tampoco llevan guion ni bullet
     * al inicio -- un caracter asi justo despues del salto sale indentado en
     * el cliente de WhatsApp. El cierre ("Y N mas...") lleva doble
     * tabulacion antes para dejar una linea en blanco de separacion.
     *
     * @param  array{count: int, items: Collection<int, array<string, mixed>>}  $report
     * @return list<array<string, mixed>>
     */
    private function whatsappComponents(Business $business, array $report): array
    {
        $topN = 5;
        $items = $report['items']->take($topN);

        $lines = $items
            ->map(fn (array $item) => $item['name'].': '.$this->coverageLabel($item['coverage_days']))
            ->all();

        $remaining = $report['count'] - $items->count();
        $block = implode("\x0B", $lines);

        if ($remaining > 0) {
            $block .= "\x0B\x0B".'Y '.$remaining.' mas por debajo del umbral.';
        }

        $values = [
            $business->name,
            (string) $report['count'],
            $block,
        ];

        return [[
            'type' => 'body',
            'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => $v], $values),
        ]];
    }

    private function coverageLabel(?float $coverageDays): string
    {
        if ($coverageDays === null) {
            return 'sin movimiento reciente';
        }

        if ($coverageDays <= 0) {
            return 'agotado';
        }

        $days = (int) round($coverageDays);

        return 'se acaba en ~'.$days.' dia'.($days === 1 ? '' : 's');
    }
}
