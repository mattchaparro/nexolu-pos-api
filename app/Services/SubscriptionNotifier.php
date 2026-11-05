<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessPaymentSource;
use App\Models\User;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\ChannelPhone;
use App\Support\WhatsAppRecipients;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos de la suscripcion por WhatsApp al dueño: por vencer, vencida, cobro
 * automatico hecho o rechazado. El correo no alcanzaba: casi nadie lo lee
 * (ver Las Banquitas, nov-2026, que vencio con el aviso en el correo).
 *
 * Va con la plantilla generica de recordatorio (un solo texto): es la que
 * ya esta aprobada en Meta, asi que sirve fuera de la ventana de 24 h.
 */
class SubscriptionNotifier
{
    public function __construct(private MessagingChannel $channel) {}

    /**
     * 3 dias antes. Si tiene renovacion automatica se le dice cuando y a que
     * medio se le va a cobrar: es lo que hace legitimo el cobro sin que el
     * dueño lo apruebe cada vez.
     */
    public function upcoming(Business $business, ?BusinessPaymentSource $source, int $amountCop): int
    {
        $when = $this->date($business->paid_until);

        if ($source && $business->subscription_auto_renew) {
            return $this->whatsApp($business, "Tu suscripción de Nexolú POS se renueva el {$when}. Ese día cobraremos {$this->money($amountCop)} a tu {$source->label}. Si quieres cambiar el medio de pago o apagar la renovación automática, entra a {$this->url()}");
        }

        return $this->whatsApp($business, "Tu suscripción de Nexolú POS vence el {$when} ({$this->money($amountCop)}). Paga a tiempo para no perder el acceso: {$this->url()}");
    }

    public function dueToday(Business $business): int
    {
        return $this->whatsApp($business, "Tu suscripción de Nexolú POS vence hoy. Tienes hasta el {$this->date($business->graceEndsAt())} para pagar; después el sistema se bloquea hasta que pagues. Paga aquí: {$this->url()}");
    }

    public function lastGraceDay(Business $business): int
    {
        return $this->whatsApp($business, "Hoy es el último día para pagar tu suscripción de Nexolú POS. Mañana el sistema se bloquea y no podrás vender hasta que pagues. Paga aquí: {$this->url()}");
    }

    public function lockedOut(Business $business): int
    {
        return $this->whatsApp($business, "Tu acceso a Nexolú POS quedó suspendido por falta de pago. Apenas pagues se activa de nuevo, con todos tus datos: {$this->url()}");
    }

    public function renewed(Business $business, int $amountCop): int
    {
        return $this->whatsApp($business, "Listo: renovamos tu suscripción de Nexolú POS por {$this->money($amountCop)}. Tu plan queda activo hasta el {$this->date($business->paid_until)}.");
    }

    /** El cobro automatico no paso: cuando se reintenta, o hasta cuando puede pagar a mano. */
    public function chargeFailed(Business $business, int $amountCop): int
    {
        $next = $this->nextAutoRenewAttempt($business);
        $retry = $next ? " Lo intentaremos de nuevo el {$this->date($next)}." : '';
        $deadline = $business->isLockedOut()
            ? ' El sistema está bloqueado hasta que se pague.'
            : " Si no se paga antes del {$this->date($business->graceEndsAt())}, el sistema se bloquea.";

        return $this->whatsApp($business, "No pudimos cobrar la suscripción de Nexolú POS ({$this->money($amountCop)}) a tu medio de pago guardado.{$retry}{$deadline} Puedes pagar con otro medio aquí: {$this->url()}");
    }

    /** Proximo dia en que subscriptions:renew volvera a intentar el cobro, o null si ya no. */
    public function nextAutoRenewAttempt(Business $business): ?CarbonInterface
    {
        if (! $business->paid_until) {
            return null;
        }

        $dueDay = $business->paid_until->copy()->startOfDay();
        $daysLate = (int) $dueDay->diffInDays(now()->startOfDay());

        foreach (config('subscriptions.auto_renew_attempt_days', []) as $day) {
            if ($day > $daysLate) {
                return $dueDay->copy()->addDays($day);
            }
        }

        return null;
    }

    /** @return int a cuantos numeros se envio */
    public function whatsApp(Business $business, string $text): int
    {
        $template = config('services.whatsapp.templates.recordatorio');
        if (empty($template['name'])) {
            return 0;
        }

        $components = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($text, 0, 300)]]]];
        $sent = 0;

        foreach ($this->recipients($business) as $phone) {
            try {
                if ($this->channel->sendTemplate($phone, $template['name'], $template['lang'] ?? 'es', $components, $business->id, 'suscripcion')) {
                    $sent++;
                }
            } catch (Throwable $e) {
                Log::warning('subscription.whatsapp_failed', ['business_id' => $business->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * Los admins con el WhatsApp vinculado. Si ninguno lo vinculo, el
     * celular del dueño o el telefono del negocio: el aviso de cobro no es
     * una preferencia que se activa, el negocio tiene que enterarse.
     *
     * @return Collection<int, string>
     */
    private function recipients(Business $business): Collection
    {
        $linked = WhatsAppRecipients::linkedAdmins($business)->pluck('external_id')->filter();
        if ($linked->isNotEmpty()) {
            return $linked->unique()->values();
        }

        $owner = User::where('business_id', $business->id)->where('is_business_owner', true)->first();
        $phone = ChannelPhone::normalize((string) ($owner?->cellphone ?: $business->phone));

        return collect($phone ? [$phone] : []);
    }

    private function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/suscripcion';
    }

    private function money(int $amountCop): string
    {
        return '$'.number_format($amountCop, 0, ',', '.');
    }

    private function date(?CarbonInterface $date): string
    {
        return $date ? $date->copy()->locale('es')->translatedFormat('j \d\e F') : '';
    }
}
