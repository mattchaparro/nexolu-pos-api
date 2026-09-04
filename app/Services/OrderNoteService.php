<?php

namespace App\Services;

use App\Mail\OnlineOrderNoteMail;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;
use App\Support\ChannelPhone;
use Illuminate\Support\Facades\Log;

/**
 * Notas de un pedido: las del equipo y los mensajes al comprador.
 *
 * Un mensaje al comprador se manda EN EL MOMENTO, no por cola, a diferencia
 * de los avisos automaticos: quien lo escribio esta mirando la pantalla y
 * necesita saber si salio. Se guarda el resultado real de cada canal, porque
 * "le escribi" y "crei que le escribi" tienen que verse distinto.
 */
class OrderNoteService
{
    public function __construct(private CommsNotificationService $comms) {}

    /**
     * Canales por los que HOY se le puede escribir a este comprador.
     *
     * Comprar como invitado significa que puede haber dejado solo el
     * telefono: ofrecerle "correo" al comerciante y fallar despues es peor
     * que no ofrecerlo.
     *
     * @return list<string>
     */
    public function availableChannels(Order $order): array
    {
        $canales = [];

        if (ChannelPhone::normalize((string) $order->customer_phone) !== null) {
            $canales[] = 'whatsapp';
        }

        if (filter_var((string) $order->customer_email, FILTER_VALIDATE_EMAIL)) {
            $canales[] = 'email';
        }

        return $canales;
    }

    /**
     * Anota, y si es para el comprador, se lo manda.
     *
     * @param  list<string>  $channels  vacio para una nota interna
     */
    public function add(
        ?User $actor,
        Order $order,
        string $body,
        string $visibility = OrderNote::VISIBILITY_INTERNAL,
        array $channels = [],
    ): OrderNote {
        $paraElComprador = $visibility === OrderNote::VISIBILITY_CUSTOMER;
        $channels = $paraElComprador ? array_values(array_intersect($channels, $this->availableChannels($order))) : [];

        $note = OrderNote::withoutGlobalScopes()->create([
            'order_id' => $order->id,
            'business_id' => $order->business_id,
            'user_id' => $actor?->id,
            'visibility' => $visibility,
            'body' => $body,
            'channels' => $channels ?: null,
        ]);

        if ($channels !== []) {
            $note->forceFill(['delivery' => $this->deliver($order, $body, $channels)])->save();
        }

        return $note;
    }

    /**
     * @param  list<string>  $channels
     * @return array<string, array{status: string, error: string|null}>
     */
    private function deliver(Order $order, string $body, array $channels): array
    {
        try {
            $to = [];

            if (in_array('whatsapp', $channels, true)) {
                $to['whatsapp'] = (string) ChannelPhone::normalize((string) $order->customer_phone);
            }

            $email = in_array('email', $channels, true);
            $mail = null;
            if ($email) {
                $to['email'] = (string) $order->customer_email;
                $mail = new OnlineOrderNoteMail($order->loadMissing('business'), $body, $this->storeName($order));
            }

            $resultado = $this->comms->dispatch(
                channels: $channels,
                to: $to,
                subject: $mail?->envelope()->subject,
                html: $mail?->render(),
                // WhatsApp lo manda como texto libre. Meta solo lo entrega
                // dentro de la ventana de 24h desde el ultimo mensaje del
                // comprador; fuera de ella el Core devuelve el fallo y queda
                // registrado, en vez de darse por enviado.
                text: $body,
                businessId: $order->business_id,
                reference: 'pedido_nota:'.$order->id,
            );

            if ($resultado !== []) {
                return $resultado;
            }

            return $this->allFailed($channels, 'No pudimos contactar el servicio de mensajería.');
        } catch (\Throwable $e) {
            Log::warning('online_order.note_delivery_failed', [
                'order_id' => $order->id,
                'channels' => $channels,
                'message' => $e->getMessage(),
            ]);

            return $this->allFailed($channels, $e->getMessage());
        }
    }

    /**
     * @param  list<string>  $channels
     * @return array<string, array{status: string, error: string|null}>
     */
    private function allFailed(array $channels, string $motivo): array
    {
        return array_fill_keys($channels, ['status' => 'failed', 'error' => $motivo]);
    }

    private function storeName(Order $order): string
    {
        $business = $order->business;

        return $business?->storeSettings()->withoutGlobalScopes()->value('store_name')
            ?: (string) ($business?->name ?? 'la tienda');
    }
}
