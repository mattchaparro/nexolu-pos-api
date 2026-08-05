<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Roles reales de produccion (confirmados en el dump de pos_saas): superadmin,
 * admin, employee, client - todos bajo el guard 'web' (User::getDefaultGuardName).
 * schema.sql solo trae la estructura de `roles`, no estas filas.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['superadmin', 'admin', 'employee', 'client'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
