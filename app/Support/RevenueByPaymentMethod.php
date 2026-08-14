<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Collection;

/**
 * Fuente unica del desglose de ingresos por medio de pago, a partir de las
 * "3+1 fuentes de ingreso" del negocio (ventas cerradas que generan ingreso,
 * fiados cobrados, abonos a servicios, abonos a apartados). Antes de esta
 * clase la misma logica de agrupacion vivia duplicada, byte a byte, en
 * CashClosingService::buildPaymentBreakdown() y
 * SalesReportService::buildPaymentBreakdown() - y DashboardService::todaySummary()
 * tenia una tercera version parcial (sin apartados, con 'cash'/'transfer'
 * hardcodeados en vez de resolver el id configurado del negocio, el mismo
 * BUG CRITICO 2 que el legacy dejo sin corregir en su DashboardController).
 * El fiado (credit) nunca aparece aqui: no es ingreso hasta que se cobra,
 * momento en que ya entra como un Receivable pagado con su propio metodo real.
 */
class RevenueByPaymentMethod
{
    /**
     * Desglose combinado (todas las fuentes juntas) por medio de pago.
     *
     * @return Collection<string, array{id: string, label: string, total: float}>
     */
    public static function combined(
        ?Business $business,
        Collection $revenueSales,
        Collection $paidReceivables,
        Collection $servicePayments,
        Collection $layawayPayments,
    ): Collection {
        $totals = self::mergeTotals(
            self::salesTotalsByMethod($revenueSales),
            self::flatTotalsByMethod($paidReceivables),
            self::flatTotalsByMethod($servicePayments),
            self::flatTotalsByMethod($layawayPayments),
        );

        return self::labelled($business, $totals);
    }

    /**
     * Desglose por canal: cada fuente de ingreso con su propio total, conteo
     * y desglose por medio de pago. No existia ningun reporte asi en el
     * legacy (confirmado por auditoria) - es la base del modulo "Resumen del dia".
     *
     * @return list<array{key: string, label: string, total: float, count: int, by_payment_method: list<array{id: string, label: string, total: float}>}>
     */
    public static function perChannel(
        ?Business $business,
        Collection $revenueSales,
        Collection $paidReceivables,
        Collection $servicePayments,
        Collection $layawayPayments,
    ): array {
        return [
            [
                'key' => 'sales',
                'label' => 'Ventas',
                'total' => round((float) $revenueSales->sum('total'), 2),
                'count' => $revenueSales->count(),
                'by_payment_method' => self::labelled($business, self::salesTotalsByMethod($revenueSales))->values()->all(),
            ],
            [
                'key' => 'services',
                'label' => 'Servicios',
                'total' => round((float) $servicePayments->sum('amount'), 2),
                'count' => $servicePayments->count(),
                'by_payment_method' => self::labelled($business, self::flatTotalsByMethod($servicePayments))->values()->all(),
            ],
            [
                'key' => 'layaways',
                'label' => 'Apartados',
                'total' => round((float) $layawayPayments->sum('amount'), 2),
                'count' => $layawayPayments->count(),
                'by_payment_method' => self::labelled($business, self::flatTotalsByMethod($layawayPayments))->values()->all(),
            ],
            [
                'key' => 'receivables',
                'label' => 'Fiados cobrados',
                'total' => round((float) $paidReceivables->sum('amount'), 2),
                'count' => $paidReceivables->count(),
                'by_payment_method' => self::labelled($business, self::flatTotalsByMethod($paidReceivables))->values()->all(),
            ],
        ];
    }

    /** @return array<string, float> */
    private static function salesTotalsByMethod(Collection $revenueSales): array
    {
        $totals = [];
        foreach ($revenueSales as $sale) {
            foreach ($sale->allocatedRevenueByPaymentMethod() as $methodId => $amount) {
                $totals[$methodId] = ($totals[$methodId] ?? 0) + (float) $amount;
            }
        }

        return $totals;
    }

    /** Agrupa cualquier coleccion con columnas `payment_method`/`amount` (Receivable, ServicePayment, LayawayPayment). */
    private static function flatTotalsByMethod(Collection $entries): array
    {
        return $entries->groupBy('payment_method')
            ->map(fn (Collection $group) => (float) $group->sum('amount'))
            ->filter(fn ($total, $id) => $id !== null && $id !== '')
            ->all();
    }

    /** @param  array<string, float>  ...$totalSets */
    private static function mergeTotals(array ...$totalSets): array
    {
        $merged = [];
        foreach ($totalSets as $totals) {
            foreach ($totals as $id => $amount) {
                $merged[$id] = ($merged[$id] ?? 0) + $amount;
            }
        }

        return $merged;
    }

    /**
     * Adjunta el label configurado y garantiza que todo medio de pago del
     * negocio aparezca en el resultado (en $0 si no se uso ese dia) - una
     * tabla/grafico de medios de pago no deberia "saltar" columnas entre un
     * dia y otro. Tambien incluye cualquier id usado que ya no este
     * configurado (negocio que quito un medio de pago con historial previo).
     *
     * @param  array<string, float>  $totals
     * @return Collection<string, array{id: string, label: string, total: float}>
     */
    private static function labelled(?Business $business, array $totals): Collection
    {
        $configured = collect($business?->paymentMethods() ?? [])
            ->filter(fn ($m) => ($m['id'] ?? null) !== 'credit')
            ->values();

        $result = collect();
        foreach ($configured as $method) {
            $id = (string) ($method['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $result[$id] = [
                'id' => $id,
                'label' => (string) ($method['label'] ?? ucfirst(str_replace('_', ' ', $id))),
                'total' => round((float) ($totals[$id] ?? 0), 2),
            ];
        }

        foreach ($totals as $id => $total) {
            if ($id === null || $id === '' || $result->has($id) || $business?->isCreditPaymentMethod($id)) {
                continue;
            }
            $result[$id] = [
                'id' => (string) $id,
                'label' => ucfirst(str_replace('_', ' ', (string) $id)),
                'total' => round((float) $total, 2),
            ];
        }

        return $result;
    }
}
