<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cambiar productos y cancelar apartados dejaron de colgar de
 * layaways.manage (ver App\Support\PermissionCatalog). Quien ya lo tenia
 * recibe los dos nuevos para que nada cambie el dia del despliegue; el dueño
 * los recorta despues desde Permisos. Los admin pasan por su rol.
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = ['layaways.edit_items', 'layaways.cancel'];

    public function up(): void
    {
        foreach (self::NEW_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('permissions', fn ($q) => $q->where('name', 'layaways.manage'))
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
