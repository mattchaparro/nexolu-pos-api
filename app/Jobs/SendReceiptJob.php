<?php

namespace App\Jobs;

use App\Mail\ReceiptMail;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\ReceiptPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Envia el comprobante de una venta/orden de servicio/apartado por correo o
 * WhatsApp - despachado desde ReceiptController::send() (uno por cada tipo,
 * ver App\Http\Controllers\Concerns\SendsReceipts). En cola en vez de
 * sincrono para no bloquear el request con el render del PDF + la llamada
 * de red al proveedor de correo/WhatsApp; el PDF se regenera aca (no se
 * serializa junto con el job) igual que legacy (ver SendSaleReceiptEmail).
 */
class SendReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  'sale'|'service-order'|'layaway'  $entityType
     * @param  'email'|'whatsapp'  $channel
     */
    public function __construct(
        public string $entityType,
        public int $entityId,
        public string $channel,
        public string $to,
        public string $documentTitle,
    ) {}

    public function handle(ReceiptPdfService $pdfService, MessagingChannel $messagingChannel): void
    {
        $pdf = $pdfService->forEntity($this->entityType, $this->entityId);
        if (! $pdf) {
            Log::warning('SendReceiptJob: entidad no encontrada', ['type' => $this->entityType, 'id' => $this->entityId]);

            return;
        }

        if ($this->channel === 'email') {
            Mail::to($this->to)->send(new ReceiptMail($pdf['business'], $this->documentTitle, $pdf['filename'], $pdf['content']));

            return;
        }

        $documentUrl = URL::temporarySignedRoute('receipts.public.show', now()->addHours(24), [
            'type' => $this->entityType,
            'id' => $this->entityId,
        ]);

        $messagingChannel->sendDocument(
            $this->to,
            $documentUrl,
            $pdf['filename'],
            'Aquí tienes tu comprobante.',
            $pdf['business']->id,
            'comprobante',
        );
    }
}
