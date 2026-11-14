<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Sale;

/**
 * Formato compartido por las 3 plantillas de comprobante (Sale/ServiceOrder/
 * Layaway, ver resources/views/receipts/*.blade.php) - evita repetir el
 * mismo number_format/match en cada una.
 */
class ReceiptFormatter
{
    public static function money(float $value): string
    {
        return '$'.number_format($value, 0, ',', '.');
    }

    public static function quantity(float $value): string
    {
        if ((int) $value == $value) {
            return (string) (int) $value;
        }

        return number_format($value, 2, ',', '.');
    }

    /** Label configurado por el negocio para un id de medio de pago, o el id tal cual si no matchea ninguno. */
    public static function paymentMethodLabel(?Business $business, ?string $paymentMethodId): string
    {
        if (! $paymentMethodId) {
            return '-';
        }

        foreach ($business?->paymentMethods() ?? [] as $method) {
            if (($method['id'] ?? null) === $paymentMethodId) {
                return (string) $method['label'];
            }
        }

        return $paymentMethodId;
    }

    /**
     * Encabezado y pie del ticket con lo propio de la sede y, en lo que no
     * personalizo, lo del negocio (Branch::ticketConfig). Sin sede, lo del
     * negocio.
     *
     * @return array{paper_width: string, header_tagline: ?string, thanks_message: ?string, footer_text: ?string, address: ?string, phone: ?string}
     */
    public static function ticketConfig(?Business $business, ?Branch $branch): array
    {
        if ($branch !== null) {
            return $branch->ticketConfig();
        }

        return [
            'paper_width' => (string) ($business?->ticket_paper_width ?? '80'),
            'header_tagline' => $business?->ticket_header_tagline,
            'thanks_message' => $business?->ticket_thanks_message,
            'footer_text' => $business?->ticket_footer_text,
            'address' => $business?->address,
            'phone' => $business?->phone,
        ];
    }

    /**
     * Como se pago una venta, linea por linea: una sola con el medio, o el
     * desglose del pago dividido. Antes el comprobante imprimia el id
     * interno «mixed» en vez de decir con que se pago.
     *
     * @return list<array{label: string, amount: ?float}>
     */
    public static function paymentLines(?Business $business, Sale $sale): array
    {
        if ($sale->is_non_revenue) {
            return [['label' => 'Cortesía', 'amount' => null]];
        }

        $splits = $sale->relationLoaded('paymentSplits') ? $sale->paymentSplits : $sale->paymentSplits()->get();
        if ($splits->isNotEmpty()) {
            return $splits
                ->map(fn ($split) => [
                    'label' => self::paymentMethodLabel($business, $split->payment_method),
                    'amount' => (float) $split->amount,
                ])
                ->values()
                ->all();
        }

        return [['label' => self::paymentMethodLabel($business, $sale->payment_method), 'amount' => null]];
    }
}
