<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Alerta de que uno o mas productos o ingredientes del negocio estan por
 * debajo de su umbral de inventario. Enviado por el comando
 * inventory:send-low-stock-alerts. Los headers X-Nexolu-Business-Id /
 * X-Nexolu-Email-Type hacen que LogSentEmail clasifique este envio
 * automaticamente en el historial de correos del panel de SuperAdmin.
 */
class LowStockAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, array{kind: string, id: int, name: string, stock: float, threshold: int, unit: ?string}>  $items
     */
    public function __construct(
        public Business $business,
        public Collection $items,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Alerta de inventario bajo - '.$this->business->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.low-stock-alert',
            with: [
                'business_name' => $this->business->name,
                'items' => $this->items,
                'app_url' => config('app.url'),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => 'low_stock_alert',
            ],
        );
    }
}
