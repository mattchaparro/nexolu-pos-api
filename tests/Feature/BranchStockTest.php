<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La invariante central del inventario multisede:
 *
 *   el saldo real vive en branch_stocks (por sede)
 *   y la columna del catalogo es SIEMPRE la suma de las sedes.
 *
 * Si esas dos cosas se separan, el negocio ve un total que no existe o pierde
 * stock que si tiene, y no hay forma de darse cuenta mirando la pantalla. Por
 * eso casi todos los tests de aqui comprueban las dos caras a la vez.
 */
class BranchStockTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        BranchContext::forget();

        parent::tearDown();
    }

    public function test_a_movement_only_moves_the_stock_of_its_branch(): void
    {
        [$business, $main, $second, $user] = $this->businessWithTwoBranches();
        $product = Product::factory()->for($business)->create(['stock' => 10]);

        // El alta con stock inicial siembra la sede principal.
        $this->assertSame(10.0, BranchStock::quantity($main->id, 'product_id', $product->id));
        $this->assertSame(0.0, BranchStock::quantity($second->id, 'product_id', $product->id));

        BranchContext::set($second);
        app(StockService::class)->entry($user, $product, 4);

        $this->assertSame(10.0, BranchStock::quantity($main->id, 'product_id', $product->id));
        $this->assertSame(4.0, BranchStock::quantity($second->id, 'product_id', $product->id));
        $this->assertSame(14, (int) $product->fresh()->stock, 'La columna del catalogo es la suma de las sedes.');
    }

    public function test_selling_in_one_branch_does_not_touch_the_other(): void
    {
        [$business, $main, $second, $user] = $this->businessWithTwoBranches();
        $product = Product::factory()->for($business)->create(['stock' => 10]);

        BranchContext::set($second);
        app(StockService::class)->entry($user, $product, 6);
        app(StockService::class)->exit($user, $product, 2);

        $this->assertSame(10.0, BranchStock::quantity($main->id, 'product_id', $product->id));
        $this->assertSame(4.0, BranchStock::quantity($second->id, 'product_id', $product->id));
        $this->assertSame(14, (int) $product->fresh()->stock);
    }

    public function test_stock_at_answers_the_branch_with_context_and_the_total_without_it(): void
    {
        [$business, $main, $second, $user] = $this->businessWithTwoBranches();
        $product = Product::factory()->for($business)->create(['stock' => 10]);

        BranchContext::set($second);
        app(StockService::class)->entry($user, $product, 5);

        $product = $product->fresh();

        BranchContext::set($main);
        $this->assertSame(10.0, $product->stockAt());

        BranchContext::set($second);
        $this->assertSame(5.0, $product->stockAt());

        // Consolidado y "sin sede" (comandos, alertas por correo) responden
        // el total del negocio.
        BranchContext::setAllBranches();
        $this->assertSame(15.0, $product->stockAt());

        BranchContext::forget();
        $this->assertSame(15.0, $product->stockAt());
    }

    public function test_adjusting_to_an_absolute_value_adjusts_that_branch_only(): void
    {
        [$business, $main, $second, $user] = $this->businessWithTwoBranches();
        $product = Product::factory()->for($business)->create(['stock' => 10]);

        // "Dejar 3" en la segunda sede significa 3 ahi, no 3 en total.
        BranchContext::set($second);
        app(StockService::class)->adjust($user, $product->fresh(), 3);

        $this->assertSame(3.0, BranchStock::quantity($second->id, 'product_id', $product->id));
        $this->assertSame(10.0, BranchStock::quantity($main->id, 'product_id', $product->id));
        $this->assertSame(13, (int) $product->fresh()->stock);
    }

    public function test_ingredients_are_also_counted_per_branch(): void
    {
        [$business, $main, $second, $user] = $this->businessWithTwoBranches();
        $ingredient = Ingredient::factory()->for($business)->create(['stock' => 8]);

        BranchContext::set($second);
        app(StockService::class)->ingredientEntry($user, $ingredient, 2.5);

        $this->assertSame(8.0, BranchStock::quantity($main->id, 'ingredient_id', $ingredient->id));
        $this->assertSame(2.5, BranchStock::quantity($second->id, 'ingredient_id', $ingredient->id));
        $this->assertSame(10.5, (float) $ingredient->fresh()->stock);
    }

    public function test_the_aggregate_is_recalculated_and_not_incremented(): void
    {
        [$business, $main, $second, $user] = $this->businessWithTwoBranches();
        $product = Product::factory()->for($business)->create(['stock' => 10]);

        // Un saldo corregido a mano (o un backfill que entro tarde) tiene que
        // quedar reflejado en el agregado al siguiente movimiento, no
        // arrastrarse para siempre.
        BranchStock::add($business->id, $second->id, 'product_id', $product->id, 7);

        BranchContext::set($main);
        app(StockService::class)->entry($user, $product->fresh(), 1);

        $this->assertSame(18, (int) $product->fresh()->stock, '11 en la principal + 7 en la segunda.');
    }

    public function test_the_pos_catalog_shows_the_stock_of_the_active_branch(): void
    {
        [$business, $main, $second, $user] = $this->businessWithTwoBranches();
        $product = Product::factory()->for($business)->create(['stock' => 10, 'is_active' => true]);

        BranchContext::set($second);
        app(StockService::class)->entry($user, $product, 2);
        BranchContext::forget();

        $stockFor = function (Branch $branch) use ($user, $product): int {
            $response = $this->actingAs($user, 'sanctum')
                ->withHeader('X-Branch-Id', (string) $branch->id)
                ->getJson('/api/v1/products/sellable')
                ->assertOk();

            // El recurso va sin envoltorio 'data' (wrapping desactivado en
            // este proyecto), asi que la coleccion es la raiz de la respuesta.
            return collect($response->json())->firstWhere('id', $product->id)['stock'];
        };

        $this->assertSame(10, $stockFor($main));
        $this->assertSame(2, $stockFor($second));
    }

    public function test_a_movement_without_branch_context_lands_on_the_main_branch(): void
    {
        [$business, $main, , $user] = $this->businessWithTwoBranches();
        $product = Product::factory()->for($business)->create(['stock' => 0]);

        // Comandos, jobs y la cola de la tienda online no tienen sede activa;
        // el movimiento no se puede quedar sin aterrizar en ninguna.
        BranchContext::forget();
        $movement = app(StockService::class)->entry($user, $product, 3);

        $this->assertSame($main->id, (int) $movement->branch_id);
        $this->assertSame(3.0, BranchStock::quantity($main->id, 'product_id', $product->id));
    }

    public function test_movement_history_is_scoped_to_the_active_branch(): void
    {
        [$business, $main, $second, $user] = $this->businessWithTwoBranches();
        $product = Product::factory()->for($business)->create(['stock' => 0]);

        BranchContext::set($main);
        app(StockService::class)->entry($user, $product, 1);
        BranchContext::set($second);
        app(StockService::class)->entry($user, $product, 1);

        BranchContext::set($main);
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->count());

        BranchContext::setAllBranches();
        $this->assertSame(2, StockMovement::where('product_id', $product->id)->count());
    }

    /** @return array{0: Business, 1: Branch, 2: Branch, 3: User} */
    private function businessWithTwoBranches(): array
    {
        $business = Business::factory()->create(['feature_flags' => ['inventory' => true, 'multi_branch' => true]]);
        $main = Branch::factory()->for($business)->main()->create();
        $second = Branch::factory()->for($business)->create();

        $user = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $user->assignRole('admin');

        return [$business, $main, $second, $user];
    }
}
