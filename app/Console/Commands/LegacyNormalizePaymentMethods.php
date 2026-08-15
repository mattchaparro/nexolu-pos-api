<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SalePaymentSplit;
use App\Models\ServicePayment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Unifica el vocabulario de payment_method (ver CUTOVER_TODO.md #1) a la
 * variante id-minuscula, SOLO sobre una copia local/dev de datos - nunca
 * contra la base compartida de staging o produccion, que el monolito legacy
 * sigue escribiendo (por eso el fix esta marcado "pendiente" alla).
 *
 * A diferencia de lo que recomienda CUTOVER_TODO.md, esta normalizacion
 * queda deliberadamente desalineada de la validacion actual de
 * StoreExpenseRequest (que exige el enum capitalizado, igual que legacy):
 * es una decision consciente para tener datos locales consistentes con los
 * que ya usan sales/receivables, a costa de que un `POST /v1/expenses`
 * nuevo contra esta misma copia local siga escribiendo capitalizado. No
 * "arregla" el bug documentado, solo lo aisla a la copia local importada.
 */
#[Signature('legacy:normalize-payment-methods {--dry-run : Solo reporta cuantas filas cambiarian, sin escribir}')]
#[Description('Normaliza payment_method a id-minuscula en datos importados de legacy (solo local/dev)')]
class LegacyNormalizePaymentMethods extends Command
{
    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Este comando solo corre con APP_ENV=local. Nunca contra staging/produccion (ver docblock de la clase).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $tables = [
            'sales' => Sale::class,
            'sale_payment_splits' => SalePaymentSplit::class,
            'receivables' => Receivable::class,
            'service_payments' => ServicePayment::class,
            'expenses' => Expense::class,
        ];

        $businesses = Business::query()->get()->keyBy('id');
        $totalChanged = 0;

        foreach ($tables as $table => $modelClass) {
            if (! class_exists($modelClass)) {
                $this->warn("Modelo {$modelClass} no existe, se salta {$table}.");

                continue;
            }

            $changed = 0;

            // sale_payment_splits no tiene business_id propio: cuelga de sales.
            $query = $table === 'sale_payment_splits'
                ? DB::table('sale_payment_splits')
                    ->join('sales', 'sales.id', '=', 'sale_payment_splits.sale_id')
                    ->select('sale_payment_splits.id as row_id', 'sale_payment_splits.payment_method', 'sales.business_id')
                    ->orderBy('sale_payment_splits.id')
                    ->where('sale_payment_splits.payment_method', '!=', '')
                    ->whereNotNull('sale_payment_splits.payment_method')
                : DB::table($table)
                    ->select('id as row_id', 'payment_method', 'business_id')
                    ->orderBy('id')
                    ->where('payment_method', '!=', '')
                    ->whereNotNull('payment_method');

            $query->chunk(500, function ($rows) use ($table, $businesses, $dryRun, &$changed) {
                foreach ($rows as $row) {
                    $business = $businesses->get($row->business_id);

                    if ($business === null) {
                        continue;
                    }

                    $normalized = $business->normalizePaymentMethodId(strtolower($row->payment_method));

                    if ($normalized === null || $normalized === $row->payment_method) {
                        continue;
                    }

                    $changed++;

                    if (! $dryRun) {
                        DB::table($table)->where('id', $row->row_id)->update(['payment_method' => $normalized]);
                    }
                }
            });

            $verbo = $dryRun ? 'cambiarian' : 'cambiaron';
            $this->info("{$table}: {$changed} filas {$verbo}.");
            $totalChanged += $changed;
        }

        if ($dryRun) {
            $this->info("Total: {$totalChanged} filas cambiarian. Corre sin --dry-run para aplicar.");
        } else {
            $this->info("Total: {$totalChanged} filas normalizadas.");
        }

        return self::SUCCESS;
    }
}
