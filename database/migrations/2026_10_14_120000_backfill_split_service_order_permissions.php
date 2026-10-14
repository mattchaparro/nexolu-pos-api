<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Editar, cancelar y eliminar ordenes de servicio dejaron de colgar de
 * service_orders.manage (ver App\Support\PermissionCatalog). Quien ya entraba
 * al modulo (con service_orders.manage o appointments.manage) recibe los tres
 * nuevos para que nada cambie el dia del despliegue; el dueño los recorta
 * despues desde Permisos. Los admin no lo necesitan: pasan por su rol.
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = ['service_orders.edit', 'service_orders.cancel', 'service_orders.delete'];

    public function up(): void
    {
        foreach (self::NEW_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('permissions', fn ($q) => $q->whereIn('name', ['service_orders.manage', 'appointments.manage']))
            ->each(fn (User $user) => $user->givePermissionTo(self::NEW_PERMISSIONS));

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        User::whereHas('permissions', fn ($q) => $q->whereIn('name', self::NEW_PERMISSIONS))
            ->each(fn (User $user) => $user->revokePermissionTo(self::NEW_PERMISSIONS));

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
