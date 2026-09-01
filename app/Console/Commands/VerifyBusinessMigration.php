<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\CashClosing;
use App\Models\LayawayPayment;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePaymentSplit;
use App\Models\ServicePayment;
use App\Support\RevenueByPaymentMethod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Verificacion profunda POST-migracion de un negocio, del lado nuevo
 * (nexolu-pos-api). Complementa la verificacion interna de
 * BusinessDataExporter::verifyExport() (conteos legacy<->destino +
 * SUM(sales.total)), que corre DURANTE el export y por eso no puede ver el
 * estado ya normalizado ni ejercitar el codigo real de reportes.
 *
 * Este comando corre DESPUES de run-migration-patches (cuando payment_method
 * ya quedo normalizado en destino) y valida, con el MISMO codigo que alimenta
 * la UI (RevenueByPaymentMethod, Sale::allocatedRevenueByPaymentMethod), que
 * "lo vendido coincide con lo reportado" por medio de pago, ademas de la
 * integridad estructural del cierre migrado.
 *
 * Es de SOLO LECTURA: no escribe nada, solo reporta. Pensado para correrse
 * por negocio recien migrado, o con --all para barrer todos los ya migrados
 * (loop automatizado). Sale con codigo !=0 si alguna verificacion DURA falla.
 *
 * Verificaciones:
 *   C1 vocabulario_cerrado   - todo payment_method resuelve a un medio del negocio (o mixed/null)
 *   C2 integridad_mixed      - mixed <=> tiene >=2 splits, SUM(splits)==total, ningun split es fiado
 *   C3 sin_fuga_de_ingreso   - ninguna venta de ingreso queda sin metodo ni splits
 *   C4 vendido_igual_reportado - SUM(ingreso) == desglose por medio de pago (codigo real), por canal
 *   C5 cierres_de_caja        - el payment_breakdown guardado usa vocabulario normalizado (WARN)
 *   C6 sin_huerfanos          - FKs del negocio resuelven en destino
 *   C7 sin_contaminacion      - ninguna fila del negocio referencia datos de otro negocio
 */
#[Signature('businesses:verify-migration {business? : business_id en destino a verificar} {--all : Verifica todos los negocios} {--tolerance=0.02 : Tolerancia en pesos para las comparaciones de dinero}')]
#[Description('Verificacion profunda post-migracion (solo lectura): lo vendido == lo reportado por medio de pago + integridad estructural')]
class VerifyBusinessMigration extends Command
{
    private float $tolerance = 0.02;

    /** @var list<string> Sentinelas de payment_method que no son un medio del catalogo. */
    private const NON_CATALOG_METHODS = ['mixed'];

    public function handle(): int
    {
        $this->tolerance = (float) $this->option('tolerance');

        $businesses = $this->resolveBusinesses();
        if ($businesses === null) {
            return self::FAILURE;
        }
        if ($businesses->isEmpty()) {
            $this->warn('No hay negocios para verificar.');

            return self::SUCCESS;
        }

        $anyFailed = false;
        foreach ($businesses as $business) {
            $anyFailed = $this->verifyBusiness($business) || $anyFailed;
        }

        $this->newLine();
        if ($anyFailed) {
            $this->error('VERIFICACION CON FALLAS: al menos un negocio no cuadra (ver detalle arriba).');

            return self::FAILURE;
        }

        $this->info('VERIFICACION OK: todos los negocios verificados cuadran.');

        return self::SUCCESS;
    }

    /** @return Collection<int, Business>|null */
    private function resolveBusinesses(): ?Collection
    {
        if ($this->option('all')) {
            return Business::query()->orderBy('id')->get();
        }

        $id = $this->argument('business');
        if ($id === null) {
            $this->error('Pasa un business_id, o --all para verificar todos.');

            return null;
        }

        $business = Business::find($id);
        if (! $business) {
            $this->error("Negocio {$id} no existe en destino.");

            return null;
        }

        return collect([$business]);
    }

    /** @return bool true si el negocio tuvo alguna falla dura. */
    private function verifyBusiness(Business $business): bool
    {
        $this->newLine();
        $this->line("<fg=cyan>=== Negocio {$business->id}: {$business->name} ({$business->slug}) ===</>");

        $results = [
            $this->checkVocabulary($business),
            $this->checkMixedIntegrity($business),
            $this->checkNoRevenueLeak($business),
            $this->checkSoldEqualsReported($business),
            $this->checkCashClosings($business),
            $this->checkNoOrphans($business),
            $this->checkNoCrossTenant($business),
        ];

        $hardFailed = false;
        foreach ($results as $r) {
            $tag = match ($r['level']) {
                'ok' => '<fg=green>  OK  </>',
                'warn' => '<fg=yellow> WARN </>',
                'fail' => '<fg=red> FAIL </>',
            };
            $this->line("{$tag} {$r['code']} - {$r['message']}");
            foreach ($r['details'] ?? [] as $detail) {
                $this->line("         · {$detail}");
            }
            if ($r['level'] === 'fail') {
                $hardFailed = true;
            }
        }

        return $hardFailed;
    }

    /**
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkVocabulary(Business $business): array
    {
        $enabled = collect($business->allowedPaymentMethodIds())->all();
        // Catalogo GLOBAL (todas las keys que el sistema reconoce), para
        // distinguir un valor roto (no resuelve a nada) de uno canonico valido
        // que el negocio simplemente no tiene habilitado hoy.
        $globalKeys = DB::table('pos_payment_methods')->pluck('key')->map(fn ($k) => (string) $k)->all();

        $sources = [
            'sales' => Sale::where('business_id', $business->id)->whereNotNull('payment_method')->distinct()->pluck('payment_method'),
            'sale_payment_splits' => SalePaymentSplit::whereIn('sale_id', Sale::where('business_id', $business->id)->select('id'))->whereNotNull('payment_method')->distinct()->pluck('payment_method'),
            'receivables' => Receivable::where('business_id', $business->id)->whereNotNull('payment_method')->distinct()->pluck('payment_method'),
            'service_payments' => ServicePayment::where('business_id', $business->id)->whereNotNull('payment_method')->distinct()->pluck('payment_method'),
            'layaway_payments' => LayawayPayment::where('business_id', $business->id)->whereNotNull('payment_method')->distinct()->pluck('payment_method'),
        ];

        $broken = [];       // no resuelve a ninguna key del catalogo global -> FAIL
        $notEnabled = [];   // key canonica valida, pero no habilitada en el negocio -> WARN
        foreach ($sources as $table => $values) {
            foreach ($values as $value) {
                if (in_array((string) $value, self::NON_CATALOG_METHODS, true)) {
                    continue;
                }
                $normalized = $business->normalizePaymentMethodId($value) ?? $value;
                if (in_array($normalized, $enabled, true)) {
                    continue;
                }
                if (in_array($normalized, $globalKeys, true)) {
                    $notEnabled[] = "{$table}: \"{$value}\" -> \"{$normalized}\" (canonico, pero el negocio no lo tiene habilitado)";
                } else {
                    $broken[] = "{$table}: \"{$value}\" (normaliza a \"{$normalized}\", no existe en el catalogo global)";
                }
            }
        }

        if ($broken !== []) {
            return ['code' => 'C1 vocabulario', 'level' => 'fail', 'message' => 'Hay medios de pago que no resuelven a ninguna key del catalogo.', 'details' => array_merge($broken, $notEnabled)];
        }
        if ($notEnabled !== []) {
            return ['code' => 'C1 vocabulario', 'level' => 'warn', 'message' => 'Hay ventas con un medio de pago canonico que el negocio no tiene habilitado (la plata igual se reporta, pero revisar el catalogo).', 'details' => $notEnabled];
        }

        return ['code' => 'C1 vocabulario', 'level' => 'ok', 'message' => 'Todo payment_method resuelve al catalogo configurado.'];
    }

    /**
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkMixedIntegrity(Business $business): array
    {
        $details = [];

        // mixed sin >=2 splits, o cuya suma de splits != total.
        $mixedSales = Sale::where('business_id', $business->id)
            ->where('payment_method', 'mixed')
            ->with('paymentSplits')
            ->get(['id', 'total', 'payment_method']);

        $badSum = 0;
        $tooFew = 0;
        $creditSplit = 0;
        foreach ($mixedSales as $sale) {
            $splits = $sale->paymentSplits;
            if ($splits->count() < 2) {
                $tooFew++;
            }
            $sum = round((float) $splits->sum('amount'), 2);
            if (abs($sum - round((float) $sale->total, 2)) > $this->tolerance) {
                $badSum++;
                if ($badSum <= 3) {
                    $details[] = "venta #{$sale->id}: SUM(splits)={$sum} != total={$sale->total}";
                }
            }
            foreach ($splits as $split) {
                if ($business->isCreditPaymentMethod($business->normalizePaymentMethodId($split->payment_method))) {
                    $creditSplit++;
                }
            }
        }

        // splits colgados de una venta que NO es mixed.
        $orphanSplits = SalePaymentSplit::whereIn('sale_id', Sale::where('business_id', $business->id)->where(function ($q) {
            $q->whereNull('payment_method')->orWhere('payment_method', '!=', 'mixed');
        })->select('id'))->count();

        if ($badSum || $tooFew || $creditSplit || $orphanSplits) {
            if ($tooFew) {
                $details[] = "{$tooFew} venta(s) 'mixed' con menos de 2 splits";
            }
            if ($creditSplit) {
                $details[] = "{$creditSplit} split(s) con metodo fiado (no permitido en pago dividido)";
            }
            if ($orphanSplits) {
                $details[] = "{$orphanSplits} split(s) colgados de una venta que no es 'mixed'";
            }

            return ['code' => 'C2 mixed', 'level' => 'fail', 'message' => 'Inconsistencias en el pago dividido (mixed/splits).', 'details' => $details];
        }

        return ['code' => 'C2 mixed', 'level' => 'ok', 'message' => "Pago dividido coherente ({$mixedSales->count()} ventas mixed)."];
    }

    /**
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkNoRevenueLeak(Business $business): array
    {
        // Venta de ingreso (cerrada, no cortesia, no fiado) sin metodo y sin splits:
        // suma al total vendido pero a ningun medio de pago -> el reporte no cuadra.
        $leaks = Sale::where('business_id', $business->id)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereNull('payment_method')
            ->whereDoesntHave('paymentSplits')
            ->count();

        if ($leaks > 0) {
            return ['code' => 'C3 fuga_ingreso', 'level' => 'fail', 'message' => "{$leaks} venta(s) de ingreso sin metodo de pago ni splits (dinero sin atribuir)."];
        }

        return ['code' => 'C3 fuga_ingreso', 'level' => 'ok', 'message' => 'Ninguna venta de ingreso queda sin medio de pago.'];
    }

    /**
     * El corazon: lo vendido debe igualar lo reportado por medio de pago, por
     * cada canal de ingreso, usando el MISMO codigo que la UI.
     *
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkSoldEqualsReported(Business $business): array
    {
        $revenueSales = Sale::where('business_id', $business->id)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->with('paymentSplits')
            ->get(['id', 'total', 'payment_method', 'is_credit']);

        $paidReceivables = Receivable::where('business_id', $business->id)->where('status', 'paid')->get(['payment_method', 'amount']);
        $servicePayments = ServicePayment::where('business_id', $business->id)->get(['payment_method', 'amount']);
        $layawayPayments = LayawayPayment::where('business_id', $business->id)->get(['payment_method', 'amount']);

        $channels = RevenueByPaymentMethod::perChannel($business, $revenueSales, $paidReceivables, $servicePayments, $layawayPayments);

        $details = [];
        $failed = false;
        foreach ($channels as $channel) {
            $sold = round((float) $channel['total'], 2);
            $reported = round((float) collect($channel['by_payment_method'])->sum('total'), 2);
            $diff = round($sold - $reported, 2);
            if (abs($diff) > $this->tolerance) {
                $failed = true;
                $details[] = "canal '{$channel['key']}': vendido={$sold} reportado={$reported} diferencia={$diff}";
            }
        }

        if ($failed) {
            return ['code' => 'C4 vendido=reportado', 'level' => 'fail', 'message' => 'Lo vendido NO coincide con lo reportado por medio de pago.', 'details' => $details];
        }

        $soldTotal = round((float) collect($channels)->sum('total'), 2);

        return ['code' => 'C4 vendido=reportado', 'level' => 'ok', 'message' => "Lo vendido coincide con lo reportado por medio de pago (base ingreso: \${$soldTotal})."];
    }

    /**
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkCashClosings(Business $business): array
    {
        $configured = collect($business->allowedPaymentMethodIds())->all();
        $closings = CashClosing::where('business_id', $business->id)->get(['id', 'payment_breakdown']);

        $withLegacyVocab = [];
        foreach ($closings as $closing) {
            foreach ((array) $closing->payment_breakdown as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '' || in_array($id, self::NON_CATALOG_METHODS, true)) {
                    continue;
                }
                if (! in_array($id, $configured, true) && $business->normalizePaymentMethodId($id) !== $id) {
                    $withLegacyVocab[$closing->id] = true;
                }
            }
        }

        if ($withLegacyVocab !== []) {
            $ids = implode(', ', array_slice(array_keys($withLegacyVocab), 0, 10));

            return ['code' => 'C5 cierres_caja', 'level' => 'warn', 'message' => count($withLegacyVocab).' cierre(s) guardan el desglose con vocabulario legacy sin normalizar (cosmetico al mostrar historicos).', 'details' => ["ids: {$ids}"]];
        }

        return ['code' => 'C5 cierres_caja', 'level' => 'ok', 'message' => "{$closings->count()} cierre(s) con desglose en vocabulario normalizado."];
    }

    /**
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkNoOrphans(Business $business): array
    {
        $b = $business->id;
        $orphans = [];

        // Nota: todas las subconsultas de "existencia" usan withoutGlobalScopes()
        // para incluir filas soft-deleted. Un sale_item puede referenciar un
        // producto ya borrado logicamente del MISMO negocio - eso es valido, no
        // un huerfano. Sin withoutGlobalScopes, el scope de SoftDeletes las
        // esconderia y darian falso positivo.
        $saleIdsAll = Sale::withoutGlobalScopes()->where('business_id', $b)->select('id');

        // Splits colgados de una venta inexistente. No tienen business_id
        // propio, asi que la unica forma de huerfano posible es un sale_id que
        // no existe en ninguna venta.
        $c = SalePaymentSplit::whereNotExists(function ($q) {
            $q->selectRaw('1')->from('sales')->whereColumn('sales.id', 'sale_payment_splits.sale_id');
        })->count();
        if ($c > 0) {
            $orphans[] = "sale_payment_splits huerfanos de sales (global): {$c}";
        }

        $c = Sale::withoutGlobalScopes()->where('business_id', $b)->whereNotNull('client_id')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('clients')->whereColumn('clients.id', 'sales.client_id');
            })->count();
        if ($c > 0) {
            $orphans[] = "sales.client_id que no existe en clients: {$c}";
        }

        $c = Receivable::withoutGlobalScopes()->where('business_id', $b)->whereNotNull('sale_id')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('sales')->whereColumn('sales.id', 'receivables.sale_id');
            })->count();
        if ($c > 0) {
            $orphans[] = "receivables.sale_id colgante: {$c}";
        }

        $c = Product::withoutGlobalScopes()->where('business_id', $b)->whereNotNull('category_id')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('product_categories')->whereColumn('product_categories.id', 'products.category_id');
            })->count();
        if ($c > 0) {
            $orphans[] = "products.category_id que no existe en product_categories: {$c}";
        }

        if ($orphans !== []) {
            return ['code' => 'C6 huerfanos', 'level' => 'fail', 'message' => 'Hay FKs del negocio que no resuelven en destino.', 'details' => $orphans];
        }

        return ['code' => 'C6 huerfanos', 'level' => 'ok', 'message' => 'Sin FKs huerfanas en el cierre del negocio.'];
    }

    /**
     * @return array{code: string, level: 'ok'|'warn'|'fail', message: string, details?: list<string>}
     */
    private function checkNoCrossTenant(Business $business): array
    {
        $b = $business->id;
        $issues = [];

        // Contaminacion = la fila referenciada EXISTE y pertenece a OTRO
        // negocio (whereExists contra la tabla fisica con business_id != b).
        // Un product_id que no existe en absoluto es un huerfano (C6), no
        // contaminacion - por eso whereExists y no whereNotIn.

        // sale_items del negocio cuyo product pertenece a otro negocio.
        $c = SaleItem::whereExists(function ($q) use ($b) {
            $q->selectRaw('1')->from('sales')->whereColumn('sales.id', 'sale_items.sale_id')->where('sales.business_id', $b);
        })->whereNotNull('product_id')
            ->whereExists(function ($q) use ($b) {
                $q->selectRaw('1')->from('products')
                    ->whereColumn('products.id', 'sale_items.product_id')
                    ->where('products.business_id', '!=', $b);
            })->count();
        if ($c > 0) {
            $issues[] = "sale_items apuntando a productos de otro negocio: {$c}";
        }

        // products del negocio cuya categoria pertenece a otro negocio.
        $c = Product::withoutGlobalScopes()->where('business_id', $b)->whereNotNull('category_id')
            ->whereExists(function ($q) use ($b) {
                $q->selectRaw('1')->from('product_categories')
                    ->whereColumn('product_categories.id', 'products.category_id')
                    ->where('product_categories.business_id', '!=', $b);
            })->count();
        if ($c > 0) {
            $issues[] = "products con categoria de otro negocio: {$c}";
        }

        if ($issues !== []) {
            return ['code' => 'C7 contaminacion', 'level' => 'fail', 'message' => 'Hay filas del negocio que referencian datos de otro negocio.', 'details' => $issues];
        }

        return ['code' => 'C7 contaminacion', 'level' => 'ok', 'message' => 'Sin contaminacion cruzada entre negocios.'];
    }
}
