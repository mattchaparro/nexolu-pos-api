<?php

namespace App\Support\Printing;

use App\Models\Business;
use App\Models\Printer;
use App\Models\Sale;
use App\Models\SaleItemOption;
use App\Support\ReceiptFormatter;

/**
 * Los tickets termicos: factura, comanda de cocina (nueva, adicion o
 * anulacion), ticket de domicilio y pagina de prueba.
 *
 * La factura dice lo mismo que el comprobante HTML de hoy
 * (resources/views/receipts/partials/body/sale.blade.php) con el mismo
 * formato de dinero y medios de pago (ReceiptFormatter). La comanda es para
 * leerse de lejos: cantidades y platos en letra alta, sin precios.
 */
class TicketRenderer
{
    public const KITCHEN_NEW = 'new';

    public const KITCHEN_ADDITION = 'addition';

    public const KITCHEN_VOID = 'void';

    private const TIMEZONE = 'America/Bogota';

    /**
     * Factura de una venta cerrada (o la cuenta por cobrar de una abierta).
     *
     * @param  string|null  $copyLabel  «COPIA CLIENTE» / «COPIA LOCAL» cuando se imprimen dos
     */
    public function receipt(Sale $sale, Printer $printer, ?string $copyLabel = null, bool $openCashDrawer = false): EscPosBuilder
    {
        $sale->loadMissing('items.product', 'items.options', 'business', 'branch', 'paymentSplits');
        $business = $sale->business;
        $ticket = ReceiptFormatter::ticketConfig($business, $sale->branch);
        $builder = new EscPosBuilder($printer->charsPerLine());

        if ($openCashDrawer) {
            $builder->openCashDrawer();
        }

        $this->header($builder, $business, $ticket);

        $builder->alignCenter()->bold()
            ->line($sale->status === 'open' ? 'CUENTA POR COBRAR' : 'RECIBO DE VENTA')
            ->bold(false);
        if ($copyLabel !== null) {
            $builder->line($copyLabel);
        }
        $builder->alignLeft()
            ->columns('Factura', $this->invoiceNumber($sale))
            ->columns('Fecha', $this->localTime($sale->closed_at ?? $sale->created_at, 'd/m/Y H:i'));

        if ($sale->customer_name || $sale->customer_phone) {
            $builder->separator()->line('Cliente: '.($sale->customer_name ?: 'Consumidor final'));
            if ($sale->customer_phone) {
                $builder->line('Teléfono: '.$sale->customer_phone);
            }
        }

        $builder->separator();
        foreach ($sale->items as $item) {
            $builder->bold()->line($item->product?->name ?: 'Producto eliminado')->bold(false);
            foreach (SaleItemOption::tally($item->options) as $option) {
                $extra = $option['extra_price'] > 0 ? ' ('.ReceiptFormatter::money($option['extra_price']).')' : '';
                $builder->line('  + '.$option['name'].($option['quantity'] > 1 ? ' ×'.$option['quantity'] : '').$extra);
            }
            $builder->columns(
                '  '.ReceiptFormatter::quantity((float) $item->quantity).' x '.ReceiptFormatter::money((float) $item->unit_price),
                ReceiptFormatter::money((float) $item->subtotal),
            );
            if ((float) $item->discount_amount > 0) {
                $builder->columns('  Descuento', '-'.ReceiptFormatter::money((float) $item->discount_amount));
            }
        }

        $builder->separator();
        if ((float) $sale->cart_discount_amount > 0) {
            $builder->columns('Descuento', '-'.ReceiptFormatter::money((float) $sale->cart_discount_amount));
        }
        if ((float) $sale->service_charge_amount > 0) {
            $builder->columns('Servicio', ReceiptFormatter::money((float) $sale->service_charge_amount));
        }
        if ((float) $sale->ipoconsumo_amount > 0) {
            $builder->columns('Impoconsumo', ReceiptFormatter::money((float) $sale->ipoconsumo_amount));
        }
        if ($sale->is_delivery && (float) $sale->delivery_fee > 0) {
            $builder->columns('Domicilio', ReceiptFormatter::money((float) $sale->delivery_fee));
        }
        if ($sale->status !== 'open') {
            foreach (ReceiptFormatter::paymentLines($business, $sale) as $index => $payment) {
                $prefix = $index === 0 ? 'Pago: ' : '      ';
                $payment['amount'] === null
                    ? $builder->columns(rtrim($prefix), $payment['label'])
                    : $builder->columns($prefix.$payment['label'], ReceiptFormatter::money($payment['amount']));
            }
        }
        $builder->bold()->tall()
            ->columns('TOTAL', ReceiptFormatter::money((float) $sale->total))
            ->tall(false)->bold(false);

        $builder->separator()->alignCenter();
        if (filled($ticket['footer_text'])) {
            $builder->line((string) $ticket['footer_text']);
        }
        $builder->line($ticket['thanks_message'] ?: 'Gracias por su compra.')
            ->line('Generado por Nexolú - pos.nexolu.co')
            ->feed(3)
            ->cut();

        return $builder;
    }

    /**
     * Comanda de cocina. $lines son los platos de esta impresion: todos en
     * una comanda nueva, solo lo agregado en una adicion, lo quitado en una
     * anulacion.
     *
     * @param  list<array{label: string, options_label: ?string, components?: list<array{name: string, quantity: float}>, quantity: int}>  $lines
     */
    public function kitchen(Sale $sale, Printer $printer, array $lines, string $kind = self::KITCHEN_NEW, bool $isReprint = false): EscPosBuilder
    {
        $sale->loadMissing('table', 'user', 'onlineOrder');
        $builder = new EscPosBuilder($printer->charsPerLine());

        $title = match ($kind) {
            self::KITCHEN_ADDITION => 'ADICIÓN',
            self::KITCHEN_VOID => '*** ANULADO ***',
            default => 'COMANDA',
        };
        $builder->alignCenter()->bold()->large()->line($title)->line($this->destination($sale))->large(false);
        if ($isReprint) {
            $builder->line('REIMPRESIÓN - NO PREPARAR DOBLE');
        }

        $builder->bold(false)->alignLeft()->separator();
        $order = $sale->onlineOrder;
        $builder->columns($order ? 'Pedido online #'.$order->number : 'Cuenta #'.$sale->id, $this->localTime(now(), 'H:i'));
        if ($sale->user?->name && $order === null) {
            $builder->line('Atiende: '.$sale->user->name);
        }
        if ($sale->customer_name && $sale->table === null) {
            $builder->line('Cliente: '.$sale->customer_name);
        }
        $builder->separator();

        foreach ($lines as $line) {
            $builder->bold()->tall()->line($line['quantity'].' x '.$line['label'])->tall(false)->bold(false);
            if (filled($line['options_label'])) {
                foreach (explode(', ', (string) $line['options_label']) as $option) {
                    $builder->line('    + '.$option);
                }
            }
            // Piezas del combo ya multiplicadas: 2 combos pareja = 4 hamburguesas.
            foreach ($line['components'] ?? [] as $piece) {
                $builder->line('    · '.ReceiptFormatter::quantity($piece['quantity'] * $line['quantity']).' '.$piece['name']);
            }
        }

        if (filled($sale->notes) && $kind !== self::KITCHEN_VOID) {
            $builder->separator()->bold()->line('NOTA: '.$sale->notes)->bold(false);
        }

        return $builder->feed(3)->cut();
    }

    /**
     * Ticket que se lleva el domiciliario: a quien, a donde y cuanto cobrar.
     */
    public function delivery(Sale $sale, Printer $printer): EscPosBuilder
    {
        $sale->loadMissing('items.product', 'items.options', 'business', 'courier', 'onlineOrder', 'paymentSplits');
        $order = $sale->onlineOrder;
        $builder = new EscPosBuilder($printer->charsPerLine());

        $builder->alignCenter()->bold()->large()->line('DOMICILIO')->large(false)
            ->line($order ? 'Pedido online #'.$order->number : 'Factura '.$this->invoiceNumber($sale))
            ->bold(false)->alignLeft()->separator();

        $builder->bold()->line($sale->customer_name ?: 'Cliente')->bold(false);
        if ($sale->customer_phone) {
            $builder->tall()->line('Tel: '.$sale->customer_phone)->tall(false);
        }
        $builder->bold()->tall()->line($sale->delivery_address ?: 'Sin dirección')->tall(false)->bold(false);
        if (filled($sale->notes)) {
            $builder->line('Nota: '.$sale->notes);
        }

        $builder->separator();
        foreach ($sale->items as $item) {
            $options = SaleItemOption::label($item->options);
            $builder->line(ReceiptFormatter::quantity((float) $item->quantity).' x '.($item->product?->name ?: 'Producto').($options !== '' ? ' ('.$options.')' : ''));
        }

        $builder->separator();
        $paidOnline = $order?->paid_at !== null;
        if ($paidOnline) {
            $builder->bold()->tall()->line('PAGADO EN LÍNEA - NO COBRAR')->tall(false)->bold(false);
        } else {
            $builder->bold()->tall()->columns('COBRAR', ReceiptFormatter::money((float) $sale->total))->tall(false)->bold(false);
            if ($sale->status !== 'open') {
                foreach (ReceiptFormatter::paymentLines($sale->business, $sale) as $payment) {
                    $builder->line('Pago: '.$payment['label'].($payment['amount'] !== null ? ' '.ReceiptFormatter::money($payment['amount']) : ''));
                }
            }
        }

        if ($sale->courier?->name) {
            $builder->line('Domiciliario: '.$sale->courier->name);
        }
        $builder->line('Salió: '.$this->localTime(now(), 'H:i'));

        return $builder->feed(3)->cut();
    }

    /** Pagina de prueba: confirma conexion, ancho, tildes, corte y (si se pide) el cajon. */
    public function test(Printer $printer, bool $openCashDrawer = false): EscPosBuilder
    {
        $builder = new EscPosBuilder($printer->charsPerLine());
        if ($openCashDrawer) {
            $builder->openCashDrawer();
        }

        return $builder->alignCenter()->bold()->large()->line('NEXOLÚ')->large(false)
            ->line('Página de prueba')->bold(false)
            ->line($printer->name)
            ->alignLeft()->separator()
            ->line('Tildes: áéíóú ÁÉÍÓÚ ñÑ ü ¿? ¡!')
            ->columns('Ancho de papel', $printer->paper_width.' mm')
            ->columns('Hora', $this->localTime(now(), 'd/m/Y H:i'))
            ->separator('=')
            ->alignCenter()->line('Si lees esto bien, la impresora quedó lista.')
            ->feed(3)
            ->cut();
    }

    private function header(EscPosBuilder $builder, ?Business $business, array $ticket): void
    {
        $builder->alignCenter()->bold()->large()->line($business?->name ?: 'Negocio')->large(false)->bold(false);
        if ($business?->nit) {
            $builder->line('NIT: '.$business->nit);
        }
        if (filled($ticket['phone'])) {
            $builder->line('Tel: '.$ticket['phone']);
        }
        if (filled($ticket['address'])) {
            $builder->line((string) $ticket['address']);
        }
        if (filled($ticket['header_tagline'])) {
            $builder->line((string) $ticket['header_tagline']);
        }
        $builder->alignLeft()->separator();
    }

    /** «MESA 4», «DOMICILIO», «PARA RECOGER» o «MOSTRADOR». */
    private function destination(Sale $sale): string
    {
        return match (true) {
            $sale->table !== null => mb_strtoupper($sale->table->name),
            (bool) $sale->is_delivery, $sale->onlineOrder !== null && ! $sale->onlineOrder->is_pickup => 'DOMICILIO',
            $sale->onlineOrder !== null => 'PARA RECOGER',
            default => 'MOSTRADOR',
        };
    }

    private function invoiceNumber(Sale $sale): string
    {
        return $sale->invoice_number ?: (($sale->business?->invoice_prefix ?: 'FAC').'-'.$sale->id);
    }

    private function localTime(mixed $time, string $format): string
    {
        return ($time ?? now())->copy()->timezone(self::TIMEZONE)->format($format);
    }
}
