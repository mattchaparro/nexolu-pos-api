<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\BranchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Deja a uno o todos los negocios con su sede principal creada, sus empleados
 * asignados a ella y ninguna fila operativa sin sede.
 *
 * Los negocios creados desde que existe este modulo ya nacen con su sede
 * (BusinessRegistrationService); este comando es para los anteriores y para
 * los que llegan por migracion desde el monolito - BusinessDataExporter copia
 * las filas del negocio pero no puede inventarle una sede, asi que entran con
 * branch_id en NULL.
 *
 * Idempotente y seguro de repetir: solo toca filas que estan en NULL, nunca
 * reasigna una sede ya puesta. Correrlo de nuevo despues de agregar una tabla
 * a BranchService::OPERATIONAL_TABLES es el camino esperado.
 */
#[Signature('branches:ensure-main {business? : id o slug del negocio} {--all : Todos los negocios} {--dry-run : Solo reporta que pasaria, sin escribir}')]
#[Description('Crea la sede principal de un negocio y backfillea el branch_id de sus filas operativas')]
class EnsureMainBranch extends Command
{
    public function handle(BranchService $branches): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $target = $this->argument('business');

        if (! $target && ! $this->option('all')) {
            $this->error('Indica un negocio (id o slug) o usa --all.');

            return self::FAILURE;
        }

        // withTrashed: un negocio archivado se puede restaurar, y si sus
        // filas se quedaron sin sede volveria a la vida invisible para
        // cualquier consulta scopeada por sede. Ademas descuadraria el
        // agregado de inventario contra la suma de sedes, que es justo lo que
        // este comando existe para garantizar.
        $businesses = Business::withTrashed()
            ->when($target, fn ($query) => $query->where(function ($inner) use ($target) {
                $inner->where('slug', $target);

                // Solo comparo contra id si el argumento es numerico: MySQL
                // castea un slug a 0 y "el negocio 0" haria match con
                // cualquier cosa rara antes que fallar.
                if (ctype_digit((string) $target)) {
                    $inner->orWhere('id', (int) $target);
                }
            }))
            ->orderBy('id')
            ->get();

        if ($businesses->isEmpty()) {
            $this->error('No se encontro ningun negocio con ese id o slug.');

            return self::FAILURE;
        }

        $totalRows = 0;
        $totalStock = 0;
        $created = 0;

        foreach ($businesses as $business) {
            $hadBranch = $business->branches()->withoutGlobalScope('business')->exists();
            $result = $branches->backfill($business, $dryRun);

            $rows = array_sum($result['rows']);
            $totalRows += $rows;

            if (! $hadBranch) {
                $created++;
            }

            $stock = array_sum($result['stock']);
            $totalStock += $stock;

            if (! $hadBranch || $rows > 0 || $stock > 0 || $result['users'] > 0) {
                $detalle = collect($result['rows'])
                    ->map(fn (int $count, string $table) => "{$table}={$count}")
                    ->implode(', ');

                $this->line(sprintf(
                    'Negocio %d (%s): sede %s, %d empleados asignados, %d filas%s, %d saldos de inventario',
                    $business->id,
                    $business->slug,
                    $hadBranch ? 'ya existia' : ($dryRun ? 'se crearia' : 'creada'),
                    $result['users'],
                    $rows,
                    $detalle ? " ({$detalle})" : '',
                    $stock,
                ));
            }
        }

        $this->info(sprintf(
            'Total: %d negocios revisados, %d sedes %s, %d filas %s, %d saldos de inventario %s.',
            $businesses->count(),
            $created,
            $dryRun ? 'se crearian' : 'creadas',
            $totalRows,
            $dryRun ? 'se actualizarian' : 'actualizadas',
            $totalStock,
            $dryRun ? 'se sembrarian' : 'sembrados',
        ));

        if ($dryRun) {
            $this->info('Corre sin --dry-run para aplicar.');
        }

        return self::SUCCESS;
    }
}
