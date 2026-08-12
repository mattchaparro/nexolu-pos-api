<?php

namespace App\Support;

use App\Models\Business;

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
}
