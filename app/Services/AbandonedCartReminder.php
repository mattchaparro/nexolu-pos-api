<?php

namespace App\Services;

use App\Mail\AbandonedCartMail;
use App\Models\Business;
use App\Models\StoreCart;
use App\Support\ChannelPhone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Le escribe una vez a quien dejo el carrito lleno y se fue.
 *
 * Reglas que no son detalles:
 *
 * - **Una sola vez.** `reminded_at` no se limpia nunca. Insistirle a alguien
 *   que no compro es la via rapida a que marque el correo como spam, y el
 *   dominio que se quema es el del comercio.
 * - **Solo si dejo como contactarlo.** El correo/telefono es opcional en la
 *   tienda a proposito (pedirlo antes de comprar cuesta conversion), asi que
 *   la mayoria de carritos no se pueden recuperar. Se guardan igual porque
 *   contar cuantos se abandonan ya es informacion que nadie tenia.
 * - **Nunca a quien ya compro.** `order_id` lo saca de la cola.
 */
class AbandonedCartReminder
{
    /**
     * Cuanto tiene que estar quieto un carrito para considerarlo abandonado.
     *
     * Una hora: menos es interrumpir a alguien que sigue comprando en otra
     * pestaña, y mucho mas es escribirle cuando ya se le paso la intencion.
     */
    public const IDLE_HOURS = 1;

    /**
     * Y hasta cuando vale la pena. Un carrito de hace tres dias no se
     * recupera con un correo; se recupera con una campaña, que es otra cosa.
     */
    public const MAX_AGE_HOURS = 48;

    public function __construct(private CommsNotificationService $comms) {}

    /**
     * Manda los recordatorios pendientes de un negocio.
     *
     * @return int cuantos se mandaron
     */
    public function run(Business $business): int
    {
        if (! $business->hasFeature('online_store')) {
            return 0;
        }

        $carts = StoreCart::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->whereNull('order_id')
            ->whereNull('reminded_at')
            ->whereBetween('last_activity_at', [
                now()->subHours(self::MAX_AGE_HOURS),
                now()->subHours(self::IDLE_HOURS),
            ])
            ->get()
            ->filter(fn (StoreCart $cart) => $cart->isReachable());

        $enviados = 0;
        foreach ($carts as $cart) {
            // Se marca ANTES de enviar. Si el envio falla, ese comprador se
            // queda sin recordatorio -- que es mucho mejor que la
            // alternativa: un fallo a mitad de camino y el job reintentando
            // hasta que la persona recibe el mismo correo cinco veces.
            $cart->forceFill(['reminded_at' => now()])->save();

            if ($this->send($business, $cart)) {
                $enviados++;
            }
        }

        return $enviados;
    }

    private function send(Business $business, StoreCart $cart): bool
    {
        try {
            $channels = [];
            $to = [];

            $phone = ChannelPhone::normalize((string) $cart->contact_phone);
            if ($phone !== null) {
                $channels[] = 'whatsapp';
                $to['whatsapp'] = $phone;
            }

            $email = filter_var((string) $cart->contact_email, FILTER_VALIDATE_EMAIL)
                ? (string) $cart->contact_email
                : null;
            if ($email !== null) {
                $channels[] = 'email';
                $to['email'] = $email;
            }

            if ($channels === []) {
                return false;
            }

            $mail = new AbandonedCartMail($cart, $business, $this->recoveryUrl($business, $cart));

            $this->comms->send(
                channels: $channels,
                to: $to,
                subject: $email !== null ? $mail->envelope()->subject : null,
                html: $email !== null ? $mail->render() : null,
                // Sin plantilla de WhatsApp aprobada todavia: Meta no deja
                // iniciar una conversacion con texto libre, asi que hoy esto
                // sale por correo. Ver docs/WHATSAPP_TEMPLATES_PENDING.md.
                whatsappTemplate: null,
                businessId: $business->id,
                reference: 'carrito_abandonado',
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('abandoned_cart.reminder_failed', [
                'cart_id' => $cart->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * El enlace que devuelve el carrito.
     *
     * Va FIRMADO y con vencimiento: el token del carrito por si solo no
     * puede ser una llave permanente dentro de un correo que se reenvia, se
     * archiva y termina en cualquier parte.
     *
     * El enlace apunta a la TIENDA y le pasa la firma tal cual: la tienda se
     * la devuelve a la API para recuperar el carrito. La firma cubre la URL
     * de la API, asi que reenviarla intacta es lo que la mantiene valida --
     * y es lo que permite que el comprador aterrice en la tienda y no en un
     * JSON.
     */
    private function recoveryUrl(Business $business, StoreCart $cart): string
    {
        $firmada = URL::temporarySignedRoute(
            // El nombre real lleva el prefijo del grupo de rutas.
            'api.v1.storefront.cart.recover',
            now()->addHours(self::MAX_AGE_HOURS),
            ['business' => $business->slug, 'token' => $cart->token],
        );

        $query = parse_url($firmada, PHP_URL_QUERY) ?: '';
        $base = rtrim((string) config('app.storefront_url'), '/');

        return "{$base}/{$business->slug}/carrito?{$query}";
    }
}
