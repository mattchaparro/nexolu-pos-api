<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * database/migrations/2026_08_24_160000_migrate_legacy_dashboard_shortcuts_format.php -
 * corre la migracion directo (sin pasar por el runner de migraciones, que
 * en este repo asume el baseline ya aplicado - ver CLAUDE.md) contra
 * usuarios de prueba para probar la traduccion de formato sin afectar el
 * historial real de migraciones de la suite.
 */
class DashboardShortcutsMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private function runMigration(): void
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_08_24_160000_migrate_legacy_dashboard_shortcuts_format.php');
        $migration->up();
    }

    public function test_translates_legacy_routes_and_collapses_colors_to_the_brand_palette(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'dashboard_shortcuts' => [
                ['color' => 'green', 'route' => 'admin.sales.create'],
                ['color' => 'white', 'route' => 'admin.products.index'],
                ['color' => 'blue', 'route' => 'admin.reports.daily'],
                ['color' => 'amber', 'route' => 'admin.sales.receivables.index'],
            ],
        ]);

        $this->runMigration();

        $this->assertEquals([
            ['route_name' => 'sales.create', 'color' => 'primary'],
            ['route_name' => 'catalog.index', 'color' => 'outline'],
            ['route_name' => 'daily-summary.index', 'color' => 'outline'],
            ['route_name' => 'receivables.index', 'color' => 'outline'],
        ], $user->refresh()->dashboard_shortcuts);
    }

    public function test_drops_legacy_routes_without_an_equivalent_module_in_the_new_frontend(): void
    {
        $business = Business::factory()->create();
        // ai-chat.index (Asistente IA) todavia no existe como modulo en
        // nexolu-pos-front - se descarta en vez de romper el resto del set.
        $user = User::factory()->create([
            'business_id' => $business->id,
            'dashboard_shortcuts' => [
                ['color' => 'green', 'route' => 'admin.sales.create'],
                ['color' => 'purple', 'route' => 'ai-chat.index'],
            ],
        ]);

        $this->runMigration();

        $this->assertEquals(
            [['route_name' => 'sales.create', 'color' => 'primary']],
            $user->refresh()->dashboard_shortcuts,
        );
    }

    public function test_leaves_shortcuts_already_in_the_new_format_untouched(): void
    {
        $business = Business::factory()->create();
        $original = [['route_name' => 'sales.create', 'color' => 'primary']];
        $user = User::factory()->create([
            'business_id' => $business->id,
            'dashboard_shortcuts' => $original,
        ]);

        $this->runMigration();

        $this->assertEquals($original, $user->refresh()->dashboard_shortcuts);
    }

    public function test_leaves_users_without_saved_shortcuts_untouched(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id, 'dashboard_shortcuts' => null]);

        $this->runMigration();

        $this->assertNull($user->refresh()->dashboard_shortcuts);
    }

    public function test_running_it_twice_does_not_re_transform_already_migrated_rows(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'dashboard_shortcuts' => [['color' => 'green', 'route' => 'admin.sales.create']],
        ]);

        $this->runMigration();
        $this->runMigration();

        $this->assertEquals(
            [['route_name' => 'sales.create', 'color' => 'primary']],
            $user->refresh()->dashboard_shortcuts,
        );
    }
}
