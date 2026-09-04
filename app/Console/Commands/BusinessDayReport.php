<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Sale;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre de dia de UN negocio: los cuadres que un humano quisiera revisar
 * cada noche, automatizados. Nacio del post-mortem manual del primer dia
 * real de Las Banquitas migrada (2026-09-03), donde estas mismas
 * verificaciones se corrieron a mano por tinker.
 *
 * SOLO LECTURA. Complementa a businesses:verify-migration (que valida el
 * estado COMPLETO del negocio tras migrar): esto valida la OPERACION de un
 * dia puntual - que cada venta cuadre con sus items, que cada unidad movida
 * de stock tenga su venta, que lo vendido coincida con lo reportado por
 * medio de pago, y que la caja del dia exista.
 *
 * Sale con codigo !=0 si alguna verificacion dura falla - listo para
 * engancharse a un cron nocturno o correrse a mano tras el cierre.
 */
#[Signature('businesses:day-report {business : business_id a revisar} {--date= : Dia a revisar (YYYY-MM-DD, default hoy)} {--tolerance=0.02 : Tolerancia en pesos}')]
#[Description('Cuadres de cierre de dia de un negocio (solo lectura): ventas vs items, stock vs movimientos, vendido vs reportado, caja')]
class BusinessDayReport extends Command
{
    private float $tolerance = 0.02;

    public function handle(): int
    {
        $this->tolerance = (float) $this->option('tolerance');
        $date = $this->option('date') ?: now()->toDateString();

        $business = Business::find($this->argument('business'));
        if (! $business) {
            $this->error("Negocio {$this->argument('business')} no existe.");

            return self::FAILURE;
        }

        $this->line("<fg=cyan>=== Cierre de dia {$date} — {$business->name} (#{$business->id}) ===</>");

        $this->printActivity($business, $date);

        $results = [
            $this->checkSaleTotals($business, $date),
            $this->checkMixedSplits($business, $date),
            $this->checkSoldEqualsReported($business, $date),
            $this->checkStockMovements($business, $date),
            $this->checkStockInvariants($business),
            $this->checkCash($business, $date),
        ];

        $failed = false;
        foreach ($results as $r) {
            $tag = match ($r['level']) {
                'ok' => '<fg=green>  OK  </>',
                'warn' => '<fg=yellow> WARN </>',
                'fail' => '<fg=red> FAIL </>',
            };
            $this->line("{$tag} {$r['code']} - {$r['message']}");
            foreach ($r['details'] ?? [] as $d) {
                $this->line("         · {$d}");
            }
            if ($r['level'] === 'fail') {
                $failed = true;
            }
        }

        $this->newLine();
        if ($failed) {
            $this->error('DIA CON DESCUADRES - revisar el detalle arriba.');

            return self::FAILURE;
        }
        $this->info('DIA CUADRADO.');

        return self::SUCCESS;
    }

    private function printActivity(Business $business, string $date): void
    {
        $b = $business->id;
        $creadas = Sale::where('business_id', $b)->whereDate('created_at', $date)->count();
        $ingreso = Sale::where('business_id', $b)->whereDate('closed_at', $date)
            ->where('status', 'closed')->where('is_non_revenue', false)->where('is_credit', false)->sum('total');
        $cortesias = Sale::where('business_id', $b)->whereDate('closed_at', $date)->where('is_non_revenue', true)->sum('total');
        $movs = DB::table('stock_movements')->where('business_id', $b)->whereDate('created_at', $date)->count();
        $canceladas = DB::table('log_actions')->where('business_id', $b)->whereDate('created_at', $date)->where('action', 'tab.cancelled')->count();

        $this->line(sprintf(
            '  ventas creadas: %d | ingreso cerrado: $%s | cortesias: $%s | mov. stock: %d | cuentas canceladas: %d',
            $creadas, number_format((float) $ingreso, 0), number_format((float) $cortesias, 0), $movs, $canceladas,
        ));
    }

    /**
     * Cada venta cerrada del dia cuadra contra sus items:
     * items(subtotal-descuento) - descuento_carrito + domicilio + cargos == total.
     *
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkSaleTotals(Business $business, string $date): array
    {
        $details = [];
        $sales = Sale::where('business_id', $business->id)->whereDate('closed_at', $date)->where('status', 'closed')
            ->get(['id', 'total', 'cart_discount_amount', 'delivery_fee', 'service_charge_amount', 'ipoconsumo_amount']);

        foreach ($sales as $s) {
            $items = (float) DB::table('sale_items')->where('sale_id', $s->id)->selectRaw('COALESCE(SUM(subtotal - discount_amount),0) t')->value('t');
            $esperado = round($items - (float) $s->cart_discount_amount + (float) $s->delivery_fee + (float) $s->service_charge_amount + (float) $s->ipoconsumo_amount, 2);
            if (abs($esperado - (float) $s->total) > $this->tolerance) {
                $details[] = "venta #{$s->id}: total={$s->total} esperado={$esperado}";
            }
        }

        if ($details !== []) {
            return ['code' => 'D1 items=total', 'level' => 'fail', 'message' => count($details).' venta(s) no cuadran items vs total.', 'details' => array_slice($details, 0, 10)];
        }

        return ['code' => 'D1 items=total', 'level' => 'ok', 'message' => $sales->count().' venta(s) cerradas cuadran items vs total.'];
    }

    /**
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkMixedSplits(Business $business, string $date): array
    {
        $details = [];
        foreach (Sale::where('business_id', $business->id)->whereDate('closed_at', $date)->where('payment_method', 'mixed')->get(['id', 'total']) as $s) {
            $sum = (float) DB::table('sale_payment_splits')->where('sale_id', $s->id)->sum('amount');
            if (abs($sum - (float) $s->total) > $this->tolerance) {
                $details[] = "venta #{$s->id}: splits={$sum} != total={$s->total}";
            }
        }

        if ($details !== []) {
            return ['code' => 'D2 mixed', 'level' => 'fail', 'message' => 'Pagos divididos descuadrados.', 'details' => $details];
        }

        return ['code' => 'D2 mixed', 'level' => 'ok', 'message' => 'Pagos divididos del dia cuadran con sus splits.'];
    }

    /**
     * Lo vendido del dia == lo reportado por medio de pago, con el MISMO
     * codigo del resumen del dia (allocatedRevenueByPaymentMethod).
     *
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkSoldEqualsReported(Business $business, string $date): array
    {
        $ventas = Sale::where('business_id', $business->id)->whereDate('closed_at', $date)
            ->where('status', 'closed')->where('is_non_revenue', false)->where('is_credit', false)
            ->with('paymentSplits')->get();

        $porMetodo = [];
        foreach ($ventas as $v) {
            foreach ($v->allocatedRevenueByPaymentMethod() as $m => $monto) {
                $mm = $business->normalizePaymentMethodId($m) ?? $m;
                $porMetodo[$mm] = ($porMetodo[$mm] ?? 0) + (float) $monto;
            }
        }

        $vendido = round((float) $ventas->sum('total'), 2);
        $reportado = round(array_sum($porMetodo), 2);
        $desglose = collect($porMetodo)->map(fn ($t, $m) => $m.'=$'.number_format($t, 0))->implode(' ');

        if (abs($vendido - $reportado) > $this->tolerance) {
            return ['code' => 'D3 vendido=reportado', 'level' => 'fail', 'message' => "vendido=\${$vendido} reportado=\${$reportado} ({$desglose})"];
        }

        return ['code' => 'D3 vendido=reportado', 'level' => 'ok', 'message' => '$'.number_format($vendido, 0)." cuadra ({$desglose})"];
    }

    /**
     * Por cada venta CREADA en el dia: el neto de movimientos de stock que la
     * referencian == -(items actuales con track_stock y sin receta). Detecta
     * doble descuento, descuento sin venta y reversos sin restaurar. Solo
     * ventas del dia: las migradas traen referencias con ids del legacy y no
     * son comparables por esta via.
     *
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkStockMovements(Business $business, string $date): array
    {
        $b = $business->id;
        $minId = Sale::where('business_id', $b)->whereDate('created_at', $date)->min('id');
        if ($minId === null) {
            return ['code' => 'D4 stock=ventas', 'level' => 'ok', 'message' => 'Sin ventas creadas en el dia.'];
        }

        $refs = DB::table('stock_movements')->where('business_id', $b)->whereDate('created_at', $date)
            ->whereNotNull('product_id')->whereNotNull('reference')->pluck('reference');

        $ids = [];
        foreach ($refs as $r) {
            if (preg_match('/#(\d+)/', (string) $r, $m) && (int) $m[1] >= $minId) {
                $ids[(int) $m[1]] = true;
            }
        }

        $details = [];
        foreach (array_keys($ids) as $sid) {
            $neto = (float) DB::table('stock_movements')->where('business_id', $b)->whereNotNull('product_id')
                ->where(function ($q) use ($sid) {
                    $q->where('reference', "Venta #{$sid}")->orWhere('reference', "Ajuste venta #{$sid}");
                })->sum('quantity');

            $sale = Sale::withoutGlobalScopes()->where('id', $sid)->where('business_id', $b)->first(['id']);
            $items = $sale
                ? (float) DB::table('sale_items')->join('products', 'products.id', '=', 'sale_items.product_id')
                    ->where('sale_items.sale_id', $sid)
                    ->where('products.track_stock', true)
                    ->whereRaw('NOT EXISTS (SELECT 1 FROM ingredient_product ip WHERE ip.product_id = products.id)')
                    ->sum('sale_items.quantity')
                : 0.0;

            $diff = round($neto + $items, 2);
            if (abs($diff) > 0.001) {
                $details[] = 'venta #'.$sid.($sale ? '' : ' (borrada)').": mov_neto={$neto} items={$items} diff={$diff}";
            }
        }

        if ($details !== []) {
            return ['code' => 'D4 stock=ventas', 'level' => 'fail', 'message' => count($details).' venta(s) con stock descuadrado de sus items.', 'details' => array_slice($details, 0, 10)];
        }

        return ['code' => 'D4 stock=ventas', 'level' => 'ok', 'message' => count($ids).' venta(s) del dia con movimientos que cuadran (canceladas netean 0).'];
    }

    /**
     * Invariantes de inventario que deben valer SIEMPRE, no solo hoy:
     * agregado == suma de sedes, y sin stock negativo.
     *
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkStockInvariants(Business $business): array
    {
        $b = $business->id;
        $details = [];

        if (Schema::hasTable('branch_stocks')) {
            $desalineados = DB::select(
                'SELECT p.id, p.name FROM products p
                 LEFT JOIN (SELECT product_id, SUM(stock) q FROM branch_stocks WHERE business_id = ? AND product_id IS NOT NULL GROUP BY product_id) bs
                   ON bs.product_id = p.id
                 WHERE p.business_id = ? AND p.deleted_at IS NULL AND p.track_stock = 1 AND ABS(p.stock - COALESCE(bs.q, 0)) > 0.001',
                [$b, $b]
            );
            foreach (array_slice($desalineados, 0, 5) as $row) {
                $details[] = "agregado != sedes: #{$row->id} {$row->name}";
            }
        }

        $negativos = DB::table('products')->where('business_id', $b)->whereNull('deleted_at')
            ->where('track_stock', true)->where('stock', '<', 0)->get(['id', 'name', 'stock']);
        foreach ($negativos as $p) {
            $details[] = "stock negativo: #{$p->id} {$p->name} = {$p->stock}";
        }

        if ($details !== []) {
            return ['code' => 'D5 invariantes', 'level' => 'fail', 'message' => 'Inventario con invariantes rotas.', 'details' => $details];
        }

        return ['code' => 'D5 invariantes', 'level' => 'ok', 'message' => 'Agregado==sedes y sin stock negativo.'];
    }

    /**
     * Caja del dia: turnos abiertos/cerrados y si existe el cierre diario.
     * WARN (no FAIL) cuando falta el cierre: muchos negocios cierran el dia
     * a la mañana siguiente.
     *
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkCash(Business $business, string $date): array
    {
        $b = $business->id;
        $turnos = DB::table('cash_shifts')->where('business_id', $b)->whereDate('opened_at', $date)->get(['id', 'opened_at', 'closed_at']);
        $abiertos = $turnos->whereNull('closed_at')->count();
        $cierres = DB::table('cash_closings')->where('business_id', $b)->where('date', $date)->count();

        $msg = $turnos->count().' turno(s)'.($abiertos ? " ({$abiertos} sin cerrar)" : '').', '.$cierres.' cierre(s) de caja del dia';

        if ($turnos->count() > 0 && $cierres === 0) {
            return ['code' => 'D6 caja', 'level' => 'warn', 'message' => $msg.' - cierre diario pendiente.'];
        }
        if ($abiertos > 0) {
            return ['code' => 'D6 caja', 'level' => 'warn', 'message' => $msg.'.'];
        }

        return ['code' => 'D6 caja', 'level' => 'ok', 'message' => $msg.'.'];
    }
}
