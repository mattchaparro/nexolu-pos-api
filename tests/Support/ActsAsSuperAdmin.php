<?php

namespace Tests\Support;

use App\Models\User;

/**
 * Compartido por todos los tests del panel SuperAdmin: crea el actor con el
 * rol real de produccion ('superadmin', guard 'web' - ver RoleSeeder).
 */
trait ActsAsSuperAdmin
{
    protected function superadmin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole('superadmin');

        return $user;
    }
}
