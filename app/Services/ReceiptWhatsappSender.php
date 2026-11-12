<?php

namespace App\Services;

use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\ChannelPhone;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Manda un comprobante (venta, orden de servicio, apartado) por WhatsApp.
 *
 * Va con PLANTILLA (config services.whatsapp.templates.comprobante): el
 * cliente casi nunca le escribio al negocio en las ultimas 24 horas, y fuera
 * de esa ventana Meta solo entrega plantillas. La plantilla lleva el PDF
 * como documento en el encabezado y el nombre del negocio en el cuerpo.
 *
 * Antes se mandaba como documento libre y Connect lo rechazaba siempre («el
 * mensaje no trae texto ni plantilla»): en produccion fallaron los 48 envios
 * de los ultimos 60 dias, todos con la pantalla diciendo que se estaba
 * enviando. Ahora se envia en el momento y el cajero ve si llego o no.
 */
class ReceiptWhatsappSender
{
    public function __construct(
        private ReceiptPdfService $pdfService,
        private MessagingChannel $channel,
    ) {}

    /**
     * @param  'sale'|'service-order'|'layaway'  $entityType
     *
     * @throws ValidationException si no se puede enviar (el mensaje es para el cajero)
     */
    public function send(string $entityType, int $entityId, string $rawPhone, string $documentTitle): void
    {
        $phone = ChannelPhone::normalize($rawPhone);
        if ($phone === null) {
            throw ValidationException::withMessages([
                'to' => 'Ese número no parece un celular. Escríbelo con sus 10 dígitos, por ejemplo 3001234567.',
            ]);
        }

        $template = (string) config('services.whatsapp.templates.comprobante.name');
        if ($template === '') {
            throw ValidationException::withMessages([
                'channel' => 'El envío de comprobantes por WhatsApp todavía no está activo. Envíalo por correo o compártelo como PDF.',
            ]);
        }

        $pdf = $this->pdfService->forEntity($entityType, $entityId);
        if (! $pdf) {
            throw ValidationException::withMessages(['to' => 'No encontramos el comprobante.']);
        }

        // Firmado y con vencimiento: Meta descarga el PDF desde este enlace.
        $documentUrl = URL::temporarySignedRoute('receipts.public.show', now()->addDays(7), [
            'type' => $entityType,
            'id' => $entityId,
        ]);

        $business = $pdf['business'];
        $sent = $this->channel->sendTemplate(
            $phone,
            $template,
            (string) config('services.whatsapp.templates.comprobante.lang', 'es_CO'),
            [
                [
                    'type' => 'header',
                    'parameters' => [[
                        'type' => 'document',
                        'document' => ['link' => $documentUrl, 'filename' => $pdf['filename']],
                    ]],
                ],
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => (string) $business->name],
                        ['type' => 'text', 'text' => mb_strtolower($documentTitle)],
                    ],
                ],
            ],
            (int) $business->id,
            'comprobante',
        );

        if (! $sent) {
            throw ValidationException::withMessages([
                'to' => 'WhatsApp no aceptó el envío. Revisa que el número tenga WhatsApp o envíalo por correo.',
            ]);
        }
    }
}
