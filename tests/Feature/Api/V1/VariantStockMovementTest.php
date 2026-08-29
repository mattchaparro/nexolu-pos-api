<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Movimientos manuales de stock sobre UNA variante.
 *
 * Hasta ahora el stock de una variante solo cambiaba reescribiendolo desde
 * el formulario del producto (ProductService::syncVariants lo persiste
 * directo): era el unico stock del sistema que se movia sin dejar rastro.
 * Estas pruebas fijan que entrada/salida/ajuste de variante pasan por
 * StockMovement como todo lo demas.
 */
class VariantStockMovementTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: User, 1: Product, 2: ProductVariant} */
    private function productWithVariant(int $stock = 10): array
    {
        $business = Business::factory()->create(['feature_flags' => ['variants' => true, 'inventory' => true]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'category_id' => $category->id, 'stock' => 0]);

        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id, 'name' => 'Talla']);
        $value = ProductAttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'business_id' => $business->id,
            'value' => 'S',
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'business_id' => $business->id,
            'stock' => $stock,
        ]);
        $variant->attributeValues()->sync([$value->id => ['product_attribute_id' => $attribute->id]]);

        return [$user, $product, $variant];
    }

    public function test_an_entry_raises_the_variant_stock_and_leaves_a_movement(): void
    {
        [$user, $product, $variant] = $this->productWithVariant(10);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 5,
            'notes' => 'Llegó mercancía',
        ])->assertCreated()
            ->assertJsonPath('product_variant_id', $variant->id)
            ->assertJsonPath('quantity', 5);

        $this->assertSame(15, $variant->fresh()->stock);
        // El producto padre no se toca: su stock es una columna fantasma.
        $this->assertSame(0, $product->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => StockMovement::TYPE_ENTRY,
            'user_id' => $user->id,
            'notes' => 'Llegó mercancía',
        ]);
    }

    public function test_an_exit_lowers_the_variant_stock(): void
    {
        [$user, $product, $variant] = $this->productWithVariant(10);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => StockMovement::TYPE_EXIT,
            'quantity' => 3,
        ])->assertCreated();

        $this->assertSame(7, $variant->fresh()->stock);
    }

    public function test_an_adjustment_sets_the_absolute_stock_and_records_the_difference(): void
    {
        [$user, $product, $variant] = $this->productWithVariant(10);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => StockMovement::TYPE_ADJUSTMENT,
            'quantity' => 4,
        ])->assertCreated()
            ->assertJsonPath('quantity', -6);

        $this->assertSame(4, $variant->fresh()->stock);
    }

    public function test_a_variant_of_another_product_is_rejected(): void
    {
        [$user, $product] = $this->productWithVariant();
        $otherProduct = Product::factory()->create([
            'business_id' => $user->business_id,
            'category_id' => $product->category_id,
        ]);
        $foreignVariant = ProductVariant::factory()->create([
            'product_id' => $otherProduct->id,
            'business_id' => $user->business_id,
            'stock' => 5,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'product_variant_id' => $foreignVariant->id,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('product_variant_id');

        $this->assertSame(5, $foreignVariant->fresh()->stock);
    }

    public function test_a_variant_of_another_business_is_rejected(): void
    {
        [$user, $product] = $this->productWithVariant();
        $foreignVariant = ProductVariant::factory()->create(['stock' => 5]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'product_variant_id' => $foreignVariant->id,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('product_variant_id');
    }

    public function test_the_history_can_be_filtered_to_a_single_variant(): void
    {
        [$user, $product, $variant] = $this->productWithVariant(10);
        $other = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'business_id' => $user->business_id,
            'stock' => 2,
        ]);

        foreach ([[$variant, 5], [$other, 7]] as [$target, $qty]) {
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
                'product_id' => $product->id,
                'product_variant_id' => $target->id,
                'type' => StockMovement::TYPE_ENTRY,
                'quantity' => $qty,
            ])->assertCreated();
        }

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/stock-movements?product_variant_id={$variant->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_variant_id', $variant->id);
    }

    public function test_the_product_level_endpoint_still_refuses_a_product_with_variants(): void
    {
        [$user, $product] = $this->productWithVariant();

        // Sin product_variant_id, el guard de siempre sigue mandando: el
        // stock del padre es fantasma y ajustarlo no cambiaria nada real.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 5,
        ])->assertUnprocessable();
    }

    public function test_editing_the_product_records_a_movement_for_the_variant_stock_change(): void
    {
        // Antes esto escribia el stock a pelo: era la unica via del sistema
        // que cambiaba inventario sin dejar rastro.
        [$user, $product, $variant] = $this->productWithVariant(10);
        $valueId = $variant->attributeValues->first()->id;

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$product->id}", [
            'name' => $product->name,
            'price' => $product->price,
            'category_id' => $product->category_id,
            'variants' => [[
                'id' => $variant->id,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'stock' => 25,
                'attribute_value_ids' => [$valueId],
            ]],
        ])->assertOk();

        $this->assertSame(25, $variant->fresh()->stock);

        $movement = StockMovement::where('product_variant_id', $variant->id)->latest('id')->firstOrFail();
        $this->assertSame(StockMovement::TYPE_ADJUSTMENT, $movement->type);
        $this->assertEqualsWithDelta(15, (float) $movement->quantity, 0.0001);
        $this->assertSame($user->id, $movement->user_id);
    }

    public function test_editing_without_touching_the_stock_records_nothing(): void
    {
        [$user, $product, $variant] = $this->productWithVariant(10);
        $valueId = $variant->attributeValues->first()->id;

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$product->id}", [
            'name' => 'Nombre nuevo',
            'price' => $product->price,
            'category_id' => $product->category_id,
            'variants' => [[
                'id' => $variant->id,
                'sku' => $variant->sku,
                'price' => 99000,
                'stock' => 10,
                'attribute_value_ids' => [$valueId],
            ]],
        ])->assertOk();

        $this->assertSame(10, $variant->fresh()->stock);
        $this->assertSame('99000.00', $variant->fresh()->price);
        $this->assertSame(0, StockMovement::where('product_variant_id', $variant->id)->count());
    }

    public function test_a_variant_can_be_paused_and_reactivated(): void
    {
        [$user, $product, $variant] = $this->productWithVariant();
        $this->assertTrue($variant->is_active);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}/variants/{$variant->id}/toggle")
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertFalse($variant->fresh()->is_active);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}/variants/{$variant->id}/toggle")
            ->assertOk()
            ->assertJsonPath('is_active', true);
    }

    public function test_cannot_pause_a_variant_through_another_product(): void
    {
        [$user, $product] = $this->productWithVariant();
        $otherProduct = Product::factory()->create([
            'business_id' => $user->business_id,
            'category_id' => $product->category_id,
        ]);
        $foreignVariant = ProductVariant::factory()->create([
            'product_id' => $otherProduct->id,
            'business_id' => $user->business_id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}/variants/{$foreignVariant->id}/toggle")
            ->assertNotFound();

        $this->assertTrue($foreignVariant->fresh()->is_active);
    }
}
