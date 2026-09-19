<?php

namespace App\Console\Commands;

use App\Models\Business;
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
    /**
     * Toda tabla con un `payment_method` que use el vocabulario POR NEGOCIO,
     * y como llegar a su business_id: null = columna propia, array = join al
     * padre que la tiene.
     *
     * Faltaban tres, agregadas el 2026-09-18 tras un bug real en produccion.
     *
     * `sale_partial_payments` era el caso grave y no es casualidad cual
     * quedo fuera: la defensa al ESCRIBIR es el trait NormalizesPaymentMethod,
     * que resuelve el negocio por `$model->business_id`, asi que solo protege
     * a modelos con esa columna. Las dos tablas de pagos que no la tienen
     * (`sale_partial_payments` y `sale_payment_splits`) son justo las dos sin
     * defensa al escribir - y de esas dos, los splits al menos ya estaban en
     * este comando; los abonos no estaban en ninguno de los dos lados. Cerrar
     * una cuenta abierta revalida cada abono historico (ver
     * OpenTabService::close), asi que 8 de las 25 cuentas abiertas del
     * negocio 24 quedaron imposibles de cobrar.
     *
     * `layaway_payments`/`purchase_payments` si tienen el trait, o sea que
     * nada escrito por esta API se ensucia; entran aca por las filas que
     * llegan por fuera de Eloquent (el import de la migracion, o el monolito
     * legacy sobre la base compartida). No rompen un cobro - nadie revalida
     * sus filas viejas - pero desagrupan reportes.
     *
     * `saas_subscription_payments` NO va aca a proposito: su payment_method
     * es del cobro de la suscripcion a Nexolu (la pasarela), no del
     * vocabulario que configura cada negocio.
     *
     * @var array<string, array{table: string, foreign_key: string}|null>
     */
    private const TABLES = [
        'sales' => null,
        'sale_payment_splits' => ['table' => 'sales', 'foreign_key' => 'sale_id'],
        'sale_partial_payments' => ['table' => 'sales', 'foreign_key' => 'sale_id'],
        'receivables' => null,
        'service_payments' => null,
        'expenses' => null,
        'layaway_payments' => null,
        'purchase_payments' => null,
    ];

    public function handle(): int
    {
        $onlyBusinessId = $this->option('business') !== null ? (int) $this->option('business') : null;

        if (! app()->environment(['local', 'staging']) && $onlyBusinessId === null) {
            $this->error('Fuera de local/staging hay que pasar --business=ID - nunca una corrida global contra production (ver docblock de la clase).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $businesses = Business::query()
            ->when($onlyBusinessId, fn ($q) => $q->where('id', $onlyBusinessId))
            ->get()->keyBy('id');
        $totalChanged = 0;

        foreach (self::TABLES as $table => $parent) {
            $changed = 0;

            // Las tablas hijas no tienen business_id propio: se llega por su
            // padre (ver self::TABLES).
            $query = $parent === null
                ? DB::table($table)
                    ->select('id as row_id', 'payment_method', 'business_id')
                    ->orderBy('id')
                    ->where('payment_method', '!=', '')
                    ->whereNotNull('payment_method')
                    ->when($onlyBusinessId, fn ($q) => $q->where('business_id', $onlyBusinessId))
                : DB::table($table)
                    ->join($parent['table'], $parent['table'].'.id', '=', $table.'.'.$parent['foreign_key'])
                    ->select($table.'.id as row_id', $table.'.payment_method', $parent['table'].'.business_id')
                    ->orderBy($table.'.id')
                    ->where($table.'.payment_method', '!=', '')
                    ->whereNotNull($table.'.payment_method')
                    ->when($onlyBusinessId, fn ($q) => $q->where($parent['table'].'.business_id', $onlyBusinessId));

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
