<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Envio de un comprobante (venta/orden de servicio/apartado) por correo -
 * ver App\Jobs\SendReceiptJob, unico punto que la despacha. El PDF se
 * regenera al momento de enviar (App\Services\ReceiptPdfService), nunca se
 * guarda en disco ni se serializa junto con el job.
 */
class ReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public string $documentTitle,
        public string $pdfFilename,
        public string $pdfContent,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->documentTitle.' - '.$this->business->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.receipt',
            with: [
                'business_name' => $this->business->name,
                'document_title' => $this->documentTitle,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, $this->pdfFilename)
                ->withMime('application/pdf'),
        ];
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => 'receipt',
            ],
        );
    }
}
