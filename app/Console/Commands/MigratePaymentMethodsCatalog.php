<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\PosPaymentMethod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Migra al catalogo normalizado (business_pos_payment_methods) los negocios
 * que todavia leen su lista de medios de pago del JSON libre
 * (businesses.payment_methods) - ver PosPaymentMethodController y la nota en
 * Business::paymentMethods(). Un negocio nunca se toca dos veces: si ya
 * tiene aunque sea una fila en el pivote, se salta por completo (no
 * sobreescribe una seleccion manual que un admin ya haya hecho desde
 * Ajustes).
 *
 * A diferencia de legacy:normalize-payment-methods, este comando SI puede
 * correr contra cualquier ambiente (no solo local): business_pos_payment_methods
 * y pos_payment_methods son tablas 100% nuevas que el monolito legacy nunca
 * lee ni escribe, asi que no hay riesgo de que el legacy vea un dato
 * distinto al que escribio. El riesgo real no es de esquema compartido,
 * es de negocio: en cuanto un negocio tiene filas de pivote,
 * Business::paymentMethods() deja de leer el JSON y las ventas nuevas
 * empiezan a usar el id del catalogo (que puede no ser el mismo string que
 * usaba el JSON, ej. 'efectivo' -> 'cash') - por eso soporta --dry-run y
 * por eso el runbook de cutover (docs/PRODUCTION_CUTOVER.md) sigue
 * exigiendo revisar el reporte de "sin match" antes de correrlo real.
 */
#[Signature('payment-methods:migrate-catalog {--dry-run : Solo reporta que pasaria, sin escribir} {--business= : Limitar a un solo business_id}')]
#[Description('Migra negocios del JSON libre de medios de pago al catalogo normalizado (business_pos_payment_methods)')]
class MigratePaymentMethodsCatalog extends Command
{
    /**
     * Alias legacy -> key del catalogo. Distinto del alias map de
     * Business::normalizePaymentMethodId() (que resuelve contra la config
     * YA CONFIGURADA de un negocio) - este resuelve contra las keys FIJAS
     * del catalogo global, que son en ingles (ver PosPaymentMethodSeeder).
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'cash' => 'cash',
        'efectivo' => 'cash',
        'transfer' => 'transfer',
        'transferencia' => 'transfer',
        'transferencias' => 'transfer',
        'credit' => 'credit',
        'fiado' => 'credit',
        'credito' => 'credit',
        'crédito' => 'credit',
        'nequi' => 'nequi',
        'bold' => 'bold',
        'daviplata' => 'daviplata',
        'datafono' => 'datafono',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyBusinessId = $this->option('business');

        $catalogByKey = PosPaymentMethod::query()->get()->keyBy('key');

        $businesses = Business::query()
            ->whereDoesntHave('posPaymentMethods')
            ->when($onlyBusinessId, fn ($query) => $query->where('id', $onlyBusinessId))
            ->get();

        if ($businesses->isEmpty()) {
            $this->info('No hay negocios pendientes de migrar (todos ya tienen catalogo, o no existe ese --business).');

            return self::SUCCESS;
        }

        $migrated = 0;
        $skippedNoMatch = [];

        foreach ($businesses as $business) {
            $legacyMethods = Business::normalizePaymentMethodsInput((array) ($business->payment_methods ?? []));

            $sync = [];
            $unmatched = [];

            foreach ($legacyMethods as $method) {
                $legacyId = strtolower((string) ($method['id'] ?? ''));
                $catalogKey = self::ALIASES[$legacyId] ?? null;
                $catalogEntry = $catalogKey !== null ? $catalogByKey->get($catalogKey) : null;

                if ($catalogEntry === null) {
                    $unmatched[] = $method['label'] ?? $legacyId;

                    continue;
                }

                $sync[$catalogEntry->id] = ['is_enabled' => true];
            }

            if ($sync === []) {
                $skippedNoMatch[] = ['business_id' => $business->id, 'sin_match' => $unmatched];

                continue;
            }

            if (! $dryRun) {
                $business->posPaymentMethods()->syncWithoutDetaching($sync);
            }

            $migrated++;
            $verbo = $dryRun ? 'migraria' : 'migro';
            $this->info("Negocio {$business->id}: {$verbo} ".count($sync).' medio(s)'.
                ($unmatched !== [] ? ' - sin match: '.implode(', ', $unmatched) : '').'.');
        }

        if ($skippedNoMatch !== []) {
            $this->warn('Negocios SIN NINGUN medio con match (necesitan revision manual, no se tocaron):');
            foreach ($skippedNoMatch as $row) {
                $this->warn("  business_id={$row['business_id']}: ".implode(', ', $row['sin_match']));
            }
        }

        $verboTotal = $dryRun ? 'se migrarian' : 'se migraron';
        $this->info("Total: {$migrated} negocio(s) {$verboTotal}, ".count($skippedNoMatch).' sin ningun match.');

        if ($dryRun) {
            $this->info('Corre sin --dry-run para aplicar.');
        }

        return self::SUCCESS;
    }
}
