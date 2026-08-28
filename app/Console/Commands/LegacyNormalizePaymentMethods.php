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
 * variante id-minuscula.
 *
 * Guard (2026-08-28, reescrito): el cutover real terminó siendo negocio
 * por negocio, no "big bang" (ver CUTOVER_PER_BUSINESS.md), así que ya no
 * tiene sentido bloquear el comando entero fuera de local/staging - lo que
 * hay que evitar es una corrida SIN scope (todos los negocios de una)
 * contra la base que sirve trafico real. En local/staging sigue corriendo
 * libre (con o sin --business, para pruebas). En cualquier otro ambiente
 * (production incluido) EXIGE --business=ID - se niega a correr global.
 * Pensado para dispararse recien despues de que `businesses:migrate` deja
 * a ESE negocio en estado completed (ver
 * Api\Admin\BusinessMigrationPatchController, que es quien lo llama en
 * production hoy).
 */
#[Signature('legacy:normalize-payment-methods {--dry-run : Solo reporta cuantas filas cambiarian, sin escribir} {--business= : Limitar a un solo business_id}')]
#[Description('Normaliza payment_method a id-minuscula - fuera de local/staging exige --business=ID')]
class LegacyNormalizePaymentMethods extends Command
{
    public function handle(): int
    {
        $onlyBusinessId = $this->option('business') !== null ? (int) $this->option('business') : null;

        if (! app()->environment(['local', 'staging']) && $onlyBusinessId === null) {
            $this->error('Fuera de local/staging hay que pasar --business=ID - nunca una corrida global contra production (ver docblock de la clase).');

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

        $businesses = Business::query()
            ->when($onlyBusinessId, fn ($q) => $q->where('id', $onlyBusinessId))
            ->get()->keyBy('id');
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
                    ->when($onlyBusinessId, fn ($q) => $q->where('sales.business_id', $onlyBusinessId))
                : DB::table($table)
                    ->select('id as row_id', 'payment_method', 'business_id')
                    ->orderBy('id')
                    ->where('payment_method', '!=', '')
                    ->whereNotNull('payment_method')
                    ->when($onlyBusinessId, fn ($q) => $q->where('business_id', $onlyBusinessId));

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
