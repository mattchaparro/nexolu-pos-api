<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adopta `php artisan migrate` en un ambiente que ya tiene el esquema
 * cargado desde database/legacy-schema/schema.sql (local, testing, SG,
 * produccion) - marca database/migrations/0000_00_00_000000_baseline_legacy_schema.php
 * como ya corrida (fila en `migrations`, sin ejecutar su up()) para que
 * `migrate` no intente recrear tablas que ya existen. De aca en
 * adelante, toda migracion nueva que se agregue si corre de verdad.
 *
 * Idempotente: si el baseline ya esta marcado, no hace nada. Rechaza
 * correr contra una base sin el esquema legacy cargado (no es para
 * bootstrapear un ambiente desde cero, ver database/legacy-schema/).
 */
#[Signature('migrate:baseline {--dry-run : Solo informa si el baseline falta o ya esta, no inserta nada}')]
#[Description('Marca el baseline de schema.sql como ya migrado, para adoptar "php artisan migrate" de aca en adelante')]
class SeedMigrationsBaseline extends Command
{
    private const BASELINE_MIGRATION = '0000_00_00_000000_baseline_legacy_schema';

    public function handle(): int
    {
        if (! Schema::hasTable('businesses')) {
            $this->error('No se encontro la tabla `businesses` - este comando es para adoptar migraciones en un ambiente que ya tiene el esquema legacy cargado (database/legacy-schema/schema.sql), no para levantar uno nuevo desde cero.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('migrations')) {
            $this->call('migrate:install');
        }

        $alreadySeeded = DB::table('migrations')->where('migration', self::BASELINE_MIGRATION)->exists();

        if ($alreadySeeded) {
            $this->info('El baseline ya estaba marcado como migrado - nada que hacer.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line('[dry-run] Falta marcar el baseline como migrado.');

            return self::SUCCESS;
        }

        DB::table('migrations')->insert([
            'migration' => self::BASELINE_MIGRATION,
            'batch' => 0,
        ]);

        $this->info('Baseline marcado como migrado. "php artisan migrate" ahora solo corre migraciones nuevas.');

        return self::SUCCESS;
    }
}
