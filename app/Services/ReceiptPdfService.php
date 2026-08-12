<?php

namespace App\Services;

use App\Models\Layaway;
use App\Models\Sale;
use App\Models\ServiceOrder;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Genera el PDF del comprobante para los 3 tipos de venta que hoy no tienen
 * ninguna forma de entregarle un recibo al cliente (ver auditoria de
 * paridad con legacy): venta directa/cuenta cerrada (Sale), orden de
 * servicio (ServiceOrder) y apartado (Layaway). Un template Blade por tipo
 * (resources/views/receipts/*.blade.php) porque cada uno tiene una forma de
 * datos bien distinta (items sueltos vs. abonos parciales) - ver
 * ReceiptController para donde se usa esto (descarga directa y el fetch
 * publico que usa el envio por WhatsApp).
 */
class ReceiptPdfService
{
    /** Tipos validos para las rutas genericas (fetch publico firmado, job de envio). */
    public const TYPES = ['sale', 'service-order', 'layaway'];

    /**
     * Punto unico usado tanto por el fetch publico firmado (WhatsApp
     * necesita una URL que pueda descargar sin login) como por
     * App\Jobs\SendReceiptJob - evita repetir el match() en los dos
     * lugares. withoutGlobalScope('business'): estos dos llamantes no
     * corren con un usuario autenticado (o corren con el equivocado, en el
     * caso del job en cola), asi que el scope de tenant de
     * BelongsToBusiness no aplicaria de todos modos.
     *
     * @return array{filename: string, content: string, business: Business}|null null si el id no existe
     */
    public function forEntity(string $type, int $id): ?array
    {
        return match ($type) {
            'sale' => ($sale = Sale::withoutGlobalScope('business')->find($id)) ? $this->forSale($sale) : null,
            'service-order' => ($order = ServiceOrder::withoutGlobalScope('business')->find($id)) ? $this->forServiceOrder($order) : null,
            'layaway' => ($layaway = Layaway::withoutGlobalScope('business')->find($id)) ? $this->forLayaway($layaway) : null,
            default => null,
        };
    }

    /** @return array{filename: string, content: string, business: Business} */
    public function forSale(Sale $sale): array
    {
        $sale->loadMissing('items.product', 'business');
        $business = $sale->business;

        $invoiceNumber = $sale->invoice_number ?: (($business?->invoice_prefix ?: 'FAC').'-'.$sale->id);
        $issuedAt = ($sale->closed_at ?? $sale->created_at)?->timezone('America/Bogota')->format('d/m/Y H:i') ?: now()->format('d/m/Y H:i');

        $content = Pdf::loadView('receipts.sale', [
            'business' => $business,
            'sale' => $sale,
            'invoiceNumber' => $invoiceNumber,
            'issuedAt' => $issuedAt,
            'paperWidthMm' => $this->paperWidthMm($business?->ticket_paper_width),
        ])->output();

        return [
            'filename' => 'recibo-'.$this->safeFilenamePart($invoiceNumber).'.pdf',
            'content' => $content,
            'business' => $business,
        ];
    }

    /** @return array{filename: string, content: string, business: Business} */
    public function forServiceOrder(ServiceOrder $serviceOrder): array
    {
        $serviceOrder->loadMissing('items', 'payments', 'client', 'business');
        $business = $serviceOrder->business;
        $invoiceNumber = '#'.$serviceOrder->id;

        $content = Pdf::loadView('receipts.service-order', [
            'business' => $business,
            'serviceOrder' => $serviceOrder,
            'invoiceNumber' => $invoiceNumber,
            'issuedAt' => $serviceOrder->created_at->timezone('America/Bogota')->format('d/m/Y H:i'),
            'paperWidthMm' => $this->paperWidthMm($business?->ticket_paper_width),
        ])->output();

        return [
            'filename' => 'orden-servicio-'.$serviceOrder->id.'.pdf',
            'content' => $content,
            'business' => $business,
        ];
    }

    /** @return array{filename: string, content: string, business: Business} */
    public function forLayaway(Layaway $layaway): array
    {
        $layaway->loadMissing('items.product', 'payments', 'business');
        $business = $layaway->business;
        $invoiceNumber = '#'.$layaway->id;

        $content = Pdf::loadView('receipts.layaway', [
            'business' => $business,
            'layaway' => $layaway,
            'invoiceNumber' => $invoiceNumber,
            'issuedAt' => $layaway->created_at->timezone('America/Bogota')->format('d/m/Y H:i'),
            'paperWidthMm' => $this->paperWidthMm($business?->ticket_paper_width),
        ])->output();

        return [
            'filename' => 'apartado-'.$layaway->id.'.pdf',
            'content' => $content,
            'business' => $business,
        ];
    }

    private function paperWidthMm(?string $ticketPaperWidth): int
    {
        return $ticketPaperWidth === '58' ? 58 : 80;
    }

    private function safeFilenamePart(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $value) ?: 'recibo';
    }
}
