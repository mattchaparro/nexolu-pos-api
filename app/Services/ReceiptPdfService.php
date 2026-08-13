<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Layaway;
use App\Models\Sale;
use App\Models\ServiceOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;

/**
 * Genera el comprobante para los 3 tipos de venta que hoy no tienen ninguna
 * forma de entregarle un recibo al cliente (ver auditoria de paridad con
 * legacy): venta directa/cuenta cerrada (Sale), orden de servicio
 * (ServiceOrder) y apartado (Layaway). Un template Blade por tipo
 * (resources/views/receipts/*.blade.php) porque cada uno tiene una forma de
 * datos bien distinta (items sueltos vs. abonos parciales) - ver
 * ReceiptController para donde se usa esto (descarga directa y el fetch
 * publico que usa el envio por WhatsApp).
 *
 * Dos formatos distintos, igual que el legacy (InvoiceController con vista
 * "thermal" vs CashReceiptController con CashReceiptPDF): el PDF
 * (Pdf::loadView, formato descargable/adjuntable para enviar por
 * WhatsApp/correo) y el HTML de impresion (view()->render(), pensado para
 * que el navegador dispare window.print() directo contra una impresora
 * termica - sin el visor de PDF de por medio, que suele agregar margenes/
 * escalado que rompen el ancho del ticket). Ambos comparten el mismo
 * partial de contenido (receipts.partials.body.*) y solo cambia el layout
 * que lo envuelve.
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
     * @return array{filename: string, content: string, business: ?Business}|null null si el id no existe
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

    /**
     * Version HTML de forEntity(), pensada para abrirse en el navegador e
     * imprimirse directo (ver receipts.print.*). A diferencia de
     * forEntity(), este SI corre siempre con un usuario autenticado (la
     * ruta no es publica), pero igual se usa withoutGlobalScope('business')
     * aca para reusar el mismo patron - el controlador ya valida la
     * pertenencia del modelo con route-model-binding + el scope global.
     *
     * @return array{html: string, business: ?Business}|null
     */
    public function printHtmlForEntity(string $type, int $id, bool $autoPrint = true): ?array
    {
        return match ($type) {
            'sale' => ($sale = Sale::withoutGlobalScope('business')->find($id)) ? $this->printSale($sale, $autoPrint) : null,
            'service-order' => ($order = ServiceOrder::withoutGlobalScope('business')->find($id)) ? $this->printServiceOrder($order, $autoPrint) : null,
            'layaway' => ($layaway = Layaway::withoutGlobalScope('business')->find($id)) ? $this->printLayaway($layaway, $autoPrint) : null,
            default => null,
        };
    }

    /** @return array{filename: string, content: string, business: ?Business} */
    public function forSale(Sale $sale): array
    {
        $data = $this->saleData($sale);

        return [
            'filename' => 'recibo-'.$this->safeFilenamePart($data['invoiceNumber']).'.pdf',
            'content' => Pdf::loadView('receipts.sale', $data)->output(),
            'business' => $data['business'],
        ];
    }

    /** @return array{html: string, business: ?Business} */
    public function printSale(Sale $sale, bool $autoPrint = true): array
    {
        $data = $this->saleData($sale) + ['autoPrint' => $autoPrint];
        $data['logoSrc'] = $this->logoSrc($data['business']);

        return [
            'html' => View::make('receipts.print.sale', $data)->render(),
            'business' => $data['business'],
        ];
    }

    /** @return array{filename: string, content: string, business: ?Business} */
    public function forServiceOrder(ServiceOrder $serviceOrder): array
    {
        $data = $this->serviceOrderData($serviceOrder);

        return [
            'filename' => 'orden-servicio-'.$serviceOrder->id.'.pdf',
            'content' => Pdf::loadView('receipts.service-order', $data)->output(),
            'business' => $data['business'],
        ];
    }

    /** @return array{html: string, business: ?Business} */
    public function printServiceOrder(ServiceOrder $serviceOrder, bool $autoPrint = true): array
    {
        $data = $this->serviceOrderData($serviceOrder) + ['autoPrint' => $autoPrint];
        $data['logoSrc'] = $this->logoSrc($data['business']);

        return [
            'html' => View::make('receipts.print.service-order', $data)->render(),
            'business' => $data['business'],
        ];
    }

    /** @return array{filename: string, content: string, business: ?Business} */
    public function forLayaway(Layaway $layaway): array
    {
        $data = $this->layawayData($layaway);

        return [
            'filename' => 'apartado-'.$layaway->id.'.pdf',
            'content' => Pdf::loadView('receipts.layaway', $data)->output(),
            'business' => $data['business'],
        ];
    }

    /** @return array{html: string, business: ?Business} */
    public function printLayaway(Layaway $layaway, bool $autoPrint = true): array
    {
        $data = $this->layawayData($layaway) + ['autoPrint' => $autoPrint];
        $data['logoSrc'] = $this->logoSrc($data['business']);

        return [
            'html' => View::make('receipts.print.layaway', $data)->render(),
            'business' => $data['business'],
        ];
    }

    /** @return array{business: ?Business, sale: Sale, invoiceNumber: string, issuedAt: string, paperWidthMm: int} */
    private function saleData(Sale $sale): array
    {
        $sale->loadMissing('items.product', 'business');
        $business = $sale->business;

        return [
            'business' => $business,
            'sale' => $sale,
            'invoiceNumber' => $sale->invoice_number ?: (($business?->invoice_prefix ?: 'FAC').'-'.$sale->id),
            'issuedAt' => ($sale->closed_at ?? $sale->created_at)?->timezone('America/Bogota')->format('d/m/Y H:i') ?: now()->format('d/m/Y H:i'),
            'paperWidthMm' => $this->paperWidthMm($business?->ticket_paper_width),
        ];
    }

    /** @return array{business: ?Business, serviceOrder: ServiceOrder, invoiceNumber: string, issuedAt: string, paperWidthMm: int} */
    private function serviceOrderData(ServiceOrder $serviceOrder): array
    {
        $serviceOrder->loadMissing('items', 'payments', 'client', 'business');
        $business = $serviceOrder->business;

        return [
            'business' => $business,
            'serviceOrder' => $serviceOrder,
            'invoiceNumber' => '#'.$serviceOrder->id,
            'issuedAt' => $serviceOrder->created_at->timezone('America/Bogota')->format('d/m/Y H:i'),
            'paperWidthMm' => $this->paperWidthMm($business?->ticket_paper_width),
        ];
    }

    /** @return array{business: ?Business, layaway: Layaway, invoiceNumber: string, issuedAt: string, paperWidthMm: int} */
    private function layawayData(Layaway $layaway): array
    {
        $layaway->loadMissing('items.product', 'payments', 'business');
        $business = $layaway->business;

        return [
            'business' => $business,
            'layaway' => $layaway,
            'invoiceNumber' => '#'.$layaway->id,
            'issuedAt' => $layaway->created_at->timezone('America/Bogota')->format('d/m/Y H:i'),
            'paperWidthMm' => $this->paperWidthMm($business?->ticket_paper_width),
        ];
    }

    private function logoSrc(?Business $business): ?string
    {
        return $business?->logo_path ? asset($business->logo_path) : null;
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
