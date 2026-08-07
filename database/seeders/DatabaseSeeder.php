<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * No uses WithoutModelEvents aqui: esta app depende de hooks de modelo
     * (creating/saving) para cosas obligatorias - Business::slug,
     * Product::sku, BelongsToBusiness::business_id, normalizacion de
     * payment_method - desactivarlos en un seeder rompe el insert (slug
     * quedaba NULL en un NOT NULL) en vez de solo saltarse una optimizacion.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(StockMovementReasonSeeder::class);

        // User::factory(10)->create();

        $business = Business::factory()->create([
            'name' => 'Negocio de Prueba',
        ]);

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'business_id' => $business->id,
            'is_business_owner' => true,
        ]);
        $user->assignRole('admin');

        // Sin este usuario, /superadmin/* siempre da 403 en local/Postman: no
        // hay forma de autenticarse como superadmin sin un tinker manual.
        $superadmin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'business_id' => null,
        ]);
        $superadmin->assignRole('superadmin');
    }
}
