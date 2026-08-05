<?php

namespace App\Console\Commands;

use App\Support\PermissionCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sincroniza los permisos del catalogo con la base. Existe para correr en
 * cada despliegue - sin esto, un permiso nuevo agregado al catalogo nunca
 * llega a las bases existentes (los deploys no ejecutan seeders).
 *
 * Es idempotente: crea lo que falta, otorga al rol admin lo que le falte, no
 * borra nada. Correrlo mil veces deja el mismo resultado que correrlo una.
 */
#[Signature('permissions:sync')]
#[Description('Crea los permisos del catalogo que falten y se los asigna al rol admin')]
class SyncPermissions extends Command
{
    public function handle(): int
    {
        $resultado = PermissionCatalog::sync();

        if ($resultado['creados'] === [] && $resultado['otorgados'] === []) {
            $this->info('Permisos ya sincronizados. Nada que hacer.');

            return self::SUCCESS;
        }

        if ($resultado['creados'] !== []) {
            $this->info('Permisos creados: '.implode(', ', $resultado['creados']));
        }

        if ($resultado['otorgados'] !== []) {
            $this->info('Otorgados al rol admin: '.implode(', ', $resultado['otorgados']));
        }

        return self::SUCCESS;
    }
}
