<?php

namespace Tests\Feature\Console;

use App\Models\Branch;
use App\Models\Business;
use App\Models\BusinessTable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `branches:ensure-main` es la puerta de entrada de los negocios que existian
 * antes del modulo multisede y de los que llegaron por migracion desde el
 * monolito con la version vieja del exportador, que copiaba sus filas sin
 * inventarles una sede: entraban con branch_id en NULL.
 *
 * Desde 2026-09-01 BusinessDataExporter crea la sede y estampa branch_id
 * durante el export (no le queda opcion: stock_movements y cash_closings lo
 * exigen NOT NULL), asi que para una migracion nueva este comando ya no
 * encuentra filas sueltas - pero sigue siendo el que asigna empleados a la
 * sede y siembra branch_stocks, y el que repara a los negocios viejos.
 *
 * Que sea repetible sin daño no es un detalle: es lo que permite volver a
 * correrlo cada vez que una tabla nueva entra a
 * BranchService::OPERATIONAL_TABLES.
 */
class EnsureMainBranchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_creates_the_main_branch_and_backfills_pending_rows(): void
    {
        $business = Business::factory()->create();
        $table = BusinessTable::factory()->for($business)->create();

        $this->artisan('branches:ensure-main', ['business' => $business->id])
            ->assertSuccessful();

        $branch = Branch::withoutGlobalScope('business')->where('business_id', $business->id)->sole();

        $this->assertTrue($branch->is_main);
        $this->assertSame($branch->id, (int) DB::table('business_tables')->where('id', $table->id)->value('branch_id'));
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $business = Business::factory()->create();

        $this->artisan('branches:ensure-main', ['business' => $business->id])->assertSuccessful();
        $branch = Branch::withoutGlobalScope('business')->where('business_id', $business->id)->sole();

        $this->artisan('branches:ensure-main', ['business' => $business->id])->assertSuccessful();

        $this->assertSame(1, Branch::withoutGlobalScope('business')->where('business_id', $business->id)->count());
        $this->assertSame($branch->id, Branch::withoutGlobalScope('business')->where('business_id', $business->id)->sole()->id);
    }

    public function test_it_accepts_a_slug(): void
    {
        $business = Business::factory()->create();

        $this->artisan('branches:ensure-main', ['business' => $business->slug])->assertSuccessful();

        $this->assertTrue(Branch::withoutGlobalScope('business')->where('business_id', $business->id)->exists());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $business = Business::factory()->create();

        $this->artisan('branches:ensure-main', ['business' => $business->id, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertFalse(Branch::withoutGlobalScope('business')->where('business_id', $business->id)->exists());
    }

    public function test_it_refuses_to_run_without_a_target(): void
    {
        $this->artisan('branches:ensure-main')->assertFailed();
    }

    /**
     * Un negocio archivado se puede restaurar. Si sus filas se quedaron sin
     * sede, vuelve a la vida invisible para toda consulta scopeada por sede,
     * y su inventario descuadrado contra la suma de sedes.
     */
    public function test_all_also_covers_soft_deleted_businesses(): void
    {
        $business = Business::factory()->create();
        $table = BusinessTable::factory()->for($business)->create();
        DB::table('business_tables')->where('id', $table->id)->update(['branch_id' => null]);
        $business->delete();

        $this->artisan('branches:ensure-main', ['--all' => true])->assertSuccessful();

        $this->assertTrue(
            Branch::withoutGlobalScope('business')->where('business_id', $business->id)->where('is_main', true)->exists()
        );
        $this->assertNotNull(DB::table('business_tables')->where('id', $table->id)->value('branch_id'));
    }

    public function test_all_covers_every_business(): void
    {
        $first = Business::factory()->create();
        $second = Business::factory()->create();

        $this->artisan('branches:ensure-main', ['--all' => true])->assertSuccessful();

        foreach ([$first, $second] as $business) {
            $this->assertTrue(
                Branch::withoutGlobalScope('business')->where('business_id', $business->id)->where('is_main', true)->exists()
            );
        }
    }
}
