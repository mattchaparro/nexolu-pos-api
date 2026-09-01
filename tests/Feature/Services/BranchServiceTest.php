<?php

namespace Tests\Feature\Services;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\BusinessTable;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use App\Services\BranchService;
use App\Services\BusinessRegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La invariante de la que cuelga todo el modulo multisede: TODO negocio
 * tiene una sede principal, tambien el monosede que nunca abrira una segunda.
 * Si esto se rompe, cada consulta operativa necesitaria un camino alterno
 * para "negocio sin sedes".
 */
class BranchServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registering_a_business_creates_its_main_branch(): void
    {
        $result = app(BusinessRegistrationService::class)->register([
            'business_name' => 'Panaderia La Espiga',
            'owner_name' => 'Ana Gomez',
            'email' => 'ana'.uniqid().'@example.com',
            'password' => 'secret-password',
            'phone' => '3001234567',
            'address' => 'Calle 10 #5-20',
        ]);

        $branch = $result['business']->branches()->withoutGlobalScope('business')->first();

        $this->assertNotNull($branch, 'El negocio nuevo debe nacer con su sede principal.');
        $this->assertTrue($branch->is_main);
        $this->assertTrue($branch->is_active);
        // La direccion y el telefono se copian al local: desde que existe la
        // sede, son los que salen en el tiquete.
        $this->assertSame('Calle 10 #5-20', $branch->address);
        $this->assertSame('3001234567', $branch->phone);

        // El dueño queda asignado y con esa sede por defecto, o entraria sin
        // ninguna sede resuelta y no podria vender.
        $this->assertSame($branch->id, (int) $result['user']->default_branch_id);
        $this->assertTrue($branch->users()->whereKey($result['user']->id)->exists());
    }

    public function test_ensure_main_branch_is_idempotent(): void
    {
        $business = Business::factory()->create();
        $service = app(BranchService::class);

        $first = $service->ensureMainBranch($business);
        $second = $service->ensureMainBranch($business);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Branch::withoutGlobalScope('business')->where('business_id', $business->id)->count());
    }

    public function test_ensure_main_branch_promotes_the_oldest_when_none_is_marked(): void
    {
        $business = Business::factory()->create();
        $oldest = Branch::factory()->for($business)->create(['is_main' => false]);
        Branch::factory()->for($business)->create(['is_main' => false]);

        $main = app(BranchService::class)->ensureMainBranch($business);

        $this->assertSame($oldest->id, $main->id, 'No debe crear una sede duplicada si ya hay sedes.');
        $this->assertSame(2, Branch::withoutGlobalScope('business')->where('business_id', $business->id)->count());
    }

    public function test_only_one_branch_stays_main_per_business(): void
    {
        $business = Business::factory()->create();
        $first = Branch::factory()->for($business)->main()->create();
        $second = Branch::factory()->for($business)->main()->create();

        $this->assertFalse($first->fresh()->is_main, 'La sede principal anterior debe degradarse sola.');
        $this->assertTrue($second->fresh()->is_main);
    }

    public function test_backfill_assigns_branch_to_existing_operational_rows(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id, 'default_branch_id' => null]);
        $table = BusinessTable::factory()->for($business)->create();

        DB::table('business_tables')->where('id', $table->id)->update(['branch_id' => null]);

        $result = app(BranchService::class)->backfill($business);

        $this->assertSame(
            $result['branch']->id,
            (int) DB::table('business_tables')->where('id', $table->id)->value('branch_id')
        );
        $this->assertSame($result['branch']->id, (int) $user->fresh()->default_branch_id);
        $this->assertSame(['business_tables' => 1], $result['rows']);
    }

    public function test_backfill_never_reassigns_a_row_that_already_has_a_branch(): void
    {
        $business = Business::factory()->create();
        $other = Branch::factory()->for($business)->create();
        $table = BusinessTable::factory()->for($business)->create();

        DB::table('business_tables')->where('id', $table->id)->update(['branch_id' => $other->id]);

        app(BranchService::class)->backfill($business);

        $this->assertSame(
            $other->id,
            (int) DB::table('business_tables')->where('id', $table->id)->value('branch_id'),
            'El backfill solo toca filas en NULL.'
        );
    }

    public function test_backfill_seeds_existing_catalog_stock_into_the_main_branch(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->for($business)->create(['stock' => 12]);
        $ingredient = Ingredient::factory()->for($business)->create(['stock' => 4.5]);

        // Un negocio que llega por migracion trae su catalogo con stock pero
        // sin una sola fila en branch_stocks: BusinessDataExporter copia las
        // tablas del monolito, que no sabe de sedes.
        DB::table('branch_stocks')->where('business_id', $business->id)->delete();

        $result = app(BranchService::class)->backfill($business);

        $this->assertSame(
            12.0,
            BranchStock::quantity($result['branch']->id, 'product_id', $product->id)
        );
        $this->assertSame(
            4.5,
            BranchStock::quantity($result['branch']->id, 'ingredient_id', $ingredient->id)
        );
        $this->assertSame(1, $result['stock']['products']);
        $this->assertSame(1, $result['stock']['ingredients']);
    }

    public function test_backfill_never_reseeds_stock_that_is_already_split_between_branches(): void
    {
        $business = Business::factory()->create();
        $main = Branch::factory()->for($business)->main()->create();
        $second = Branch::factory()->for($business)->create();
        $product = Product::factory()->for($business)->create(['stock' => 10]);

        BranchStock::add($business->id, $second->id, 'product_id', $product->id, 3);

        $result = app(BranchService::class)->backfill($business);

        $this->assertArrayNotHasKey('products', $result['stock'], 'Ya tenia saldo por sede: sembrar de nuevo lo duplicaria.');
        $this->assertSame(10.0, BranchStock::quantity($main->id, 'product_id', $product->id));
        $this->assertSame(3.0, BranchStock::quantity($second->id, 'product_id', $product->id));
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $business = Business::factory()->create();
        $table = BusinessTable::factory()->for($business)->create();
        DB::table('business_tables')->where('id', $table->id)->update(['branch_id' => null]);

        // Crear una fila operativa ya le garantiza la sede al negocio (ver
        // BelongsToBranch), asi que lo que el dry-run no puede hacer es
        // escribir nada MAS de lo que ya habia.
        $branchesBefore = Branch::withoutGlobalScope('business')->where('business_id', $business->id)->count();

        $result = app(BranchService::class)->backfill($business, dryRun: true);

        $this->assertSame(['business_tables' => 1], $result['rows']);
        $this->assertSame($branchesBefore, Branch::withoutGlobalScope('business')->where('business_id', $business->id)->count());
        $this->assertNull(DB::table('business_tables')->where('id', $table->id)->value('branch_id'));
    }

    public function test_dry_run_does_not_create_the_branch_of_a_business_that_has_none(): void
    {
        $business = Business::factory()->create();

        app(BranchService::class)->backfill($business, dryRun: true);

        $this->assertSame(0, Branch::withoutGlobalScope('business')->where('business_id', $business->id)->count());
    }
}
