<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos de la venta financiada (ver App\Support\PermissionCatalog).
 * Hasta hoy cualquier empleado podia vender financiado, asi que todos reciben
 * financing.sell para que nada cambie el dia del despliegue. Ver financiados y
 * registrar giros eran solo del dueño: esos arrancan apagados.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['financing.sell', 'financing.view', 'financing.payouts'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->where('name', 'employee'))
            ->each(fn (User $user) => $user->givePermissionTo('financing.sell'));

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        User::whereHas('permissions', fn ($q) => $q->where('name', 'financing.sell'))
            ->each(fn (User $user) => $user->revokePermissionTo('financing.sell'));

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
