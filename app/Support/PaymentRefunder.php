<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Patron "reembolso via fila negativa" usado al cancelar un apartado o una
 * orden de servicio: nunca se borran ni modifican los pagos originales (el
 * ingreso de un dia con cierre de caja ya hecho debe quedar intacto), se
 * crea una fila NEGATIVA fechada HOY por cada metodo de pago con el que se
 * cobro. Antes vivia reimplementado de forma independiente en Apartados y
 * (en el legacy) en ServiceOrder.
 */
class PaymentRefunder
{
    /**
     * @param  Relation  $payments  Relacion ya scopeada al dueño del reembolso (ej. $layaway->payments())
     * @param  callable(string $method, float $amount): array<string, mixed>  $rowFactory  Arma los atributos de la fila negativa para ese metodo/monto
     */
    public static function refundGroupedByMethod(Relation $payments, callable $rowFactory): void
    {
        $paymentModelClass = get_class($payments->getRelated());

        // reorder(): algunas relaciones (ej. ServiceOrder::payments) traen su
        // propio orderBy('created_at') por defecto, incompatible con GROUP BY
        // bajo ONLY_FULL_GROUP_BY si no se limpia primero.
        $paymentsByMethod = (clone $payments)
            ->reorder()
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->get();

        foreach ($paymentsByMethod as $row) {
            $total = (float) $row->total;
            if ($total > 0.009) {
                $paymentModelClass::create($rowFactory((string) $row->payment_method, $total));
            }
        }
    }
}
