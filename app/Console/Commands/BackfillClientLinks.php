<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de client_id (ver CUTOVER_TODO.md #4) para filas de sales/layaways/
 * receivables que ya tienen customer_phone pero se crearon antes de que
 * ClientQuickAssociate empezara a guardar el vinculo real. Matchea por
 * telefono normalizado (solo digitos) dentro del mismo negocio - nunca por
 * nombre, mismo criterio que Business::syncReceivable()/ClientesResumenInsight
 * del legacy: el nombre no es un identificador confiable.
 *
 * Un telefono que matchea 2+ clients del mismo negocio (ej. una familia que
 * comparte numero - caso real, no hipotetico, ver la nota en CUTOVER_TODO.md)
 * se reporta como ambiguo y se deja sin tocar; nunca elige uno al azar. Solo
 * llena filas con client_id NULL, jamas sobreescribe un vinculo ya guardado
 * (manual o de una corrida anterior de este comando).
 */
#[Signature('clients:backfill-links {--dry-run : Solo reporta que pasaria, sin escribir} {--business= : Limitar a un solo business_id}')]
#[Description('Vincula sales/layaways/receivables existentes a un Client por telefono normalizado')]
class BackfillClientLinks extends Command
{
    private const TABLES = ['sales', 'layaways', 'receivables'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyBusinessId = $this->option('business');

        $businesses = Business::query()
            ->when($onlyBusinessId, fn ($query) => $query->where('id', $onlyBusinessId))
            ->get();

        $totalLinked = 0;
        $totalAmbiguous = 0;

        foreach ($businesses as $business) {
            $clientsByPhone = $this->clientsByNormalizedPhone($business->id);

            if ($clientsByPhone->isEmpty()) {
                continue;
            }

            foreach (self::TABLES as $table) {
                [$linked, $ambiguous] = $this->backfillTable($table, $business->id, $clientsByPhone, $dryRun);
                $totalLinked += $linked;
                $totalAmbiguous += $ambiguous;

                if ($linked > 0 || $ambiguous > 0) {
                    $verbo = $dryRun ? 'se vincularian' : 'se vincularon';
                    $this->info("Negocio {$business->id} / {$table}: {$linked} {$verbo}, {$ambiguous} ambiguos (telefono compartido, sin tocar).");
                }
            }
        }

        $verboTotal = $dryRun ? 'se vincularian' : 'se vincularon';
        $this->info("Total: {$totalLinked} filas {$verboTotal}, {$totalAmbiguous} ambiguas dejadas sin tocar.");

        if ($dryRun) {
            $this->info('Corre sin --dry-run para aplicar.');
        }

        return self::SUCCESS;
    }

    /**
     * Telefonos con 2+ clients activos se marcan como ambiguos (valor null)
     * en vez de excluirse: asi backfillTable() los puede reportar como
     * "ambiguo, sin tocar" en lugar de simplemente no encontrar nada.
     *
     * @return Collection<string, ?int>
     */
    private function clientsByNormalizedPhone(int $businessId): Collection
    {
        return Client::where('business_id', $businessId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['id', 'phone'])
            ->reduce(function (Collection $carry, Client $client) {
                $normalized = preg_replace('/\D+/', '', (string) $client->phone);
                if ($normalized === '') {
                    return $carry;
                }

                $carry->put($normalized, $carry->has($normalized) ? null : $client->id);

                return $carry;
            }, collect());
    }

    /**
     * @param  Collection<string, ?int>  $clientsByPhone
     * @return array{0: int, 1: int} [vinculadas, ambiguas]
     */
    private function backfillTable(string $table, int $businessId, Collection $clientsByPhone, bool $dryRun): array
    {
        $linked = 0;
        $ambiguous = 0;

        DB::table($table)
            ->select('id', 'customer_phone')
            ->where('business_id', $businessId)
            ->whereNull('client_id')
            ->whereNotNull('customer_phone')
            ->where('customer_phone', '!=', '')
            ->chunkById(500, function ($rows) use ($table, $clientsByPhone, $dryRun, &$linked, &$ambiguous) {
                foreach ($rows as $row) {
                    $normalized = preg_replace('/\D+/', '', (string) $row->customer_phone);
                    if ($normalized === '' || ! $clientsByPhone->has($normalized)) {
                        continue;
                    }

                    $clientId = $clientsByPhone->get($normalized);

                    if ($clientId === null) {
                        $ambiguous++;

                        continue;
                    }

                    $linked++;

                    if (! $dryRun) {
                        DB::table($table)->where('id', $row->id)->update(['client_id' => $clientId]);
                    }
                }
            });

        return [$linked, $ambiguous];
    }
}
