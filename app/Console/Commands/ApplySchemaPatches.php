<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Aplica los patches SQL pendientes de database/legacy-schema/patches/ - ver
 * el README de esa carpeta. schema.sql solo se carga completo una vez por
 * ambiente; cuando una tabla 100% nueva se agrega ahi despues de esa carga
 * inicial (nunca una tabla que el legacy ya usa - esa regla no cambia), este
 * comando es lo que pone al dia cualquier ambiente que ya estaba corriendo
 * (local, testing, el droplet de produccion) sin tocar sus datos.
 *
 * Idempotente: lleva su propio registro (tabla schema_patches, se crea sola
 * la primera vez) de que archivos ya aplico, y salta los que ya corrio.
 * Seguro de correr en cada deploy, siempre, no solo cuando sabes que hay
 * algo pendiente.
 */
#[Signature('schema:apply-patches {--dry-run : Solo lista los patches pendientes, no los ejecuta}')]
#[Description('Aplica los patches SQL pendientes de database/legacy-schema/patches/ (tablas nuevas, nunca legacy-shared)')]
class ApplySchemaPatches extends Command
{
    public function handle(): int
    {
        $this->ensureTrackingTableExists();

        $applied = DB::table('schema_patches')->pluck('filename')->all();

        $files = collect(glob($this->patchesPath().'/*.sql'))
            ->map(fn (string $path) => basename($path))
            ->sort()
            ->values();

        $pending = $files->diff($applied)->values();

        if ($pending->isEmpty()) {
            $this->info('No hay patches pendientes.');

            return self::SUCCESS;
        }

        foreach ($pending as $filename) {
            if ($this->option('dry-run')) {
                $this->line("[dry-run] Pendiente: {$filename}");

                continue;
            }

            $this->applyPatch($filename);
            $this->info("Aplicado: {$filename}");
        }

        return self::SUCCESS;
    }

    private function ensureTrackingTableExists(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `schema_patches` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `filename` varchar(255) NOT NULL,
              `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `schema_patches_filename_unique` (`filename`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function patchesPath(): string
    {
        return config('database.schema_patches_path');
    }

    private function applyPatch(string $filename): void
    {
        $sql = file_get_contents($this->patchesPath()."/{$filename}");

        // Se descartan las lineas de comentario ANTES de partir por ';' -
        // un comentario puede traer un ';' en el texto (ej. una fecha entre
        // parentesis seguida de punto y coma), lo que rompe un split ingenuo.
        $withoutComments = collect(explode("\n", $sql))
            ->reject(fn (string $line) => str_starts_with(trim($line), '--') || trim($line) === '')
            ->implode("\n");

        $statements = collect(explode(';', $withoutComments))
            ->map(fn (string $statement) => trim($statement))
            ->filter();

        foreach ($statements as $statement) {
            DB::statement($statement);
        }

        DB::table('schema_patches')->insert(['filename' => $filename]);
    }
}
