<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReceiptPdfService;
use Illuminate\Http\Response;

/**
 * Publico, sin auth: quien descarga esto es el propio proveedor de
 * WhatsApp (Meta o Nexolu Communications, ver
 * MessagingChannel::sendDocument()), no el usuario del negocio - la firma
 * de la URL (middleware `signed`) es la unica autenticacion, mismo patron
 * que NotificationSnoozeController. Vence a las 24h (ver
 * App\Jobs\SendReceiptJob), no es un enlace permanente.
 */
class PublicReceiptController extends Controller
{
    public function show(string $type, int $id): Response
    {
        $pdf = app(ReceiptPdfService::class)->forEntity($type, $id);
        abort_if($pdf === null, 404);

        return response($pdf['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf['filename'].'"',
        ]);
    }
}
