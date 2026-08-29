<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockMovementReason;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_creating_a_movement_updates_product_stock(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'type' => 'entry',
            'quantity' => 5,
            'notes' => 'Reposicion de proveedor',
        ]);

        $response->assertCreated()
            ->assertJsonPath('type', 'entry')
            ->assertJsonPath('quantity', 5)
            ->assertJsonPath('reason.code', 'manual_in')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', $user->name);

        $this->assertSame(15, $product->fresh()->stock);
    }

    public function test_exit_movement_decreases_stock(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'type' => 'exit',
            'quantity' => 4,
            'notes' => 'Producto vencido',
        ])->assertCreated()->assertJsonPath('quantity', -4);

        $this->assertSame(6, $product->fresh()->stock);
    }

    public function test_adjustment_movement_sets_stock_to_target_value(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'type' => 'adjustment',
            'quantity' => 3,
        ])->assertCreated()->assertJsonPath('quantity', -7);

        $this->assertSame(3, $product->fresh()->stock);
    }

    /**
     * Cubre StockService::assertStockIsManuallyManageable() (tarea de
     * Reportes: bloquear ajuste manual de stock en productos con
     * variantes) - un producto con variantes no expone products.stock para
     * editar directo, cada variante tiene el suyo (aun no hay endpoint de
     * movimiento por variante, ver Fase 2+).
     */
    public function test_a_product_with_variants_cannot_have_its_stock_adjusted_directly(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['variants' => true]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 0]);
        $product->variants()->create(['business_id' => $business->id, 'sku' => 'CAM-S', 'price' => 45000, 'stock' => 5]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'type' => 'entry',
            'quantity' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('product');
    }

    public function test_user_cannot_create_a_movement_for_another_business_product(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'type' => 'entry',
            'quantity' => 5,
        ])->assertUnprocessable();
    }

    public function test_index_lists_only_the_business_movements_and_can_filter_by_product(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $productA = Product::factory()->create(['business_id' => $business->id]);
        $productB = Product::factory()->create(['business_id' => $business->id]);

        StockMovement::factory()->create(['product_id' => $productA->id, 'business_id' => $business->id, 'user_id' => $user->id]);
        StockMovement::factory()->create(['product_id' => $productB->id, 'business_id' => $business->id, 'user_id' => $user->id]);
        StockMovement::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/stock-movements')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/stock-movements?product_id='.$productA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_single_sale_product_auto_deactivates_when_stock_reaches_zero_and_reactivates_on_entry(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'stock' => 1,
            'track_stock' => true,
            'is_single_sale' => true,
            'is_active' => true,
        ]);

        app(StockService::class)->exit($user, $product, 1, 'Ultima unidad vendida');

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertFalse((bool) $product->fresh()->is_active);

        app(StockService::class)->entry($user, $product, 2, 'Reposicion');

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertTrue((bool) $product->fresh()->is_active);
    }

    /**
     * El legacy usaba 'layaway'/'layaway_cancel' como StockMovement::type en
     * LayawayService, valores que NUNCA estuvieron en el enum de la columna
     * (solo entry/exit/adjustment/sale) - un INSERT real revienta con
     * "Data truncated for column 'type'" bajo STRICT_TRANS_TABLES. Este test
     * documenta que nuestro StockService nunca usa valores fuera del enum,
     * para no reproducir ese bug cuando se construya Apartados.
     */
    public function test_stock_movement_type_is_always_a_valid_enum_value(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $movement = app(StockService::class)->entry($user, $product, 1);

        $this->assertContains($movement->type, StockMovement::TYPES);
    }

    /**
     * products.stock es una columna calculada para un producto con receta
     * (ver ProductAvailability) - permitir un movimiento manual sobre el
     * dejaria la columna desincronizada del stock real de los ingredientes.
     */
    public function test_manual_stock_movements_are_rejected_for_recipe_managed_products(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 5]);
        $product->ingredients()->attach($ingredient->id, ['quantity' => 1]);

        foreach (['entry', 'exit', 'adjustment'] as $type) {
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
                'product_id' => $product->id,
                'type' => $type,
                'quantity' => 2,
            ])->assertStatus(422)->assertJsonValidationErrors('product');
        }

        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_system_reasons_are_seeded_and_globally_scoped(): void
    {
        $this->assertNotNull(StockMovementReason::systemIdForCode(StockMovementReason::CODE_SALE));
        $this->assertNotNull(StockMovementReason::systemIdForCode(StockMovementReason::CODE_SALE_REVERSAL));
        $this->assertNotNull(StockMovementReason::systemIdForCode(StockMovementReason::CODE_MANUAL_IN));
        $this->assertNotNull(StockMovementReason::systemIdForCode(StockMovementReason::CODE_MANUAL_OUT));
        $this->assertNotNull(StockMovementReason::systemIdForCode(StockMovementReason::CODE_ADJUSTMENT));
    }
}
