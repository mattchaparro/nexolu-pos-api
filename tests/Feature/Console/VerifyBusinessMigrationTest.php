<?php

namespace Tests\Feature\Console;

use App\Models\Branch;
use App\Models\Business;
use App\Models\BusinessTable;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El check C8 de `businesses:verify-migration`: un negocio migrado tiene que
 * quedar con su sede principal y sin una sola fila operativa suelta.
 *
 * Es el check que faltaba cuando el exportador todavia no creaba la sede.
 * Donde branch_id es NOT NULL la migracion al menos aborta ruidosa; donde
 * sigue siendo nullable el fallo es silencioso y peor - las filas migradas
 * quedan invisibles para su propio dueño, porque todo lo operativo esta
 * scopeado por sede.
 */
class VerifyBusinessMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_passes_when_the_business_has_its_main_branch_and_nothing_is_loose(): void
    {
        $business = Business::factory()->create();
        BusinessTable::factory()->for($business)->create();

        $this->artisan('branches:ensure-main', ['business' => $business->id])->assertSuccessful();

        $this->artisan('businesses:verify-migration', ['business' => $business->id])
            ->expectsOutputToContain('C8 sedes')
            ->assertSuccessful();
    }

    public function test_fails_when_the_business_has_no_branch_at_all(): void
    {
        $business = Business::factory()->create();

        $this->artisan('businesses:verify-migration', ['business' => $business->id])
            ->expectsOutputToContain('no tiene NINGUNA sede')
            ->assertFailed();
    }

    public function test_fails_when_an_operational_row_was_left_without_a_branch(): void
    {
        $business = Business::factory()->create();
        $table = BusinessTable::factory()->for($business)->create();

        $this->artisan('branches:ensure-main', ['business' => $business->id])->assertSuccessful();

        // Exactamente el estado en que quedaria un negocio migrado por el
        // exportador viejo: la sede existe pero la fila no la referencia.
        DB::table('business_tables')->where('id', $table->id)->update(['branch_id' => null]);

        $this->artisan('businesses:verify-migration', ['business' => $business->id])
            ->expectsOutputToContain('sin sede')
            ->assertFailed();
    }

    public function test_a_soft_deleted_branch_does_not_count_as_a_valid_one(): void
    {
        $business = Business::factory()->create();

        $this->artisan('branches:ensure-main', ['business' => $business->id])->assertSuccessful();

        Branch::withoutGlobalScope('business')->where('business_id', $business->id)->sole()->delete();

        // Un negocio cuya unica sede fue borrada esta tan roto como uno sin
        // ninguna: si el check apagara el scope de SoftDeletes (como hacia
        // withoutGlobalScopes()) esto pasaria en verde.
        $this->artisan('businesses:verify-migration', ['business' => $business->id])
            ->expectsOutputToContain('no tiene NINGUNA sede')
            ->assertFailed();
    }

    public function test_warns_but_does_not_fail_when_an_employee_has_no_branch_assigned(): void
    {
        $business = Business::factory()->create();

        $this->artisan('branches:ensure-main', ['business' => $business->id])->assertSuccessful();

        // Empleado creado despues del backfill: las filas operativas estan
        // bien, pero el no puede operar hasta que se le asigne sede. Molesta,
        // no corrompe - por eso es WARN y el comando sigue saliendo en exito.
        User::factory()->create(['business_id' => $business->id]);

        $this->artisan('businesses:verify-migration', ['business' => $business->id])
            ->expectsOutputToContain('sin sede asignada')
            ->assertSuccessful();
    }
}
