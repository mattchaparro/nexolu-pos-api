<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\SendReceiptJob;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Compartido por SaleController/ServiceOrderController/LayawayController:
 * la accion de "imprimir" (descarga directa del PDF) y "enviar" (WhatsApp o
 * correo, ver App\Jobs\SendReceiptJob) es identica para los 3 tipos de
 * comprobante - lo unico que cambia es como cada controlador arma el PDF
 * (App\Services\ReceiptPdfService) y el nombre de la entidad para el
 * asunto del correo.
 */
trait SendsReceipts
{
    /** @param  array{filename: string, content: string, business: Business}  $pdf */
    private function receiptDownloadResponse(array $pdf): Response
    {
        return response($pdf['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf['filename'].'"',
        ]);
    }

    /** @param  array{html: string, business: Business}  $print */
    private function receiptPrintResponse(array $print): Response
    {
        return response($print['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param  'sale'|'service-order'|'layaway'  $entityType
     */
    private function receiptSendResponse(Request $request, string $entityType, int $entityId, string $documentTitle): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'in:whatsapp,email'],
            'to' => ['required', 'string', 'max:255'],
        ]);

        SendReceiptJob::dispatch($entityType, $entityId, $data['channel'], $data['to'], $documentTitle);

        return response()->json(['queued' => true]);
    }
}
