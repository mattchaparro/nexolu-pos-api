<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre purchase_lines.product_variant_id: comprar stock de un producto con
 * variantes se aplica sobre LA VARIANTE (su propio stock y costo promedio
 * ponderado), nunca sobre products.stock/cost_price - mismo patron que
 * PurchaseIngredientLineTest para ingredientes. No repite la logica de
 * lineas de producto sin variantes, ya cubierta por PurchaseTest.
 */
class PurchaseVariantLineTest extends TestCase
{
    use DatabaseTransactions;

    private function businessAndUser(): array
    {
        $business = Business::factory()->create(['feature_flags' => ['variants' => true]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    /** @return array{0: Product, 1: ProductVariant, 2: ProductVariant} */
    private function productWithTwoVariants(Business $business): array
    {
        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id]);
        $small = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'S']);
        $medium = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'M']);

        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 0]);
        $suffix = uniqid();
        $variantS = $product->variants()->create(['business_id' => $business->id, 'sku' => "CAM-S-{$suffix}", 'price' => 45000, 'cost_price' => 1000, 'stock' => 10]);
        $variantS->attributeValues()->attach($small->id, ['product_attribute_id' => $attribute->id]);
        $variantM = $product->variants()->create(['business_id' => $business->id, 'sku' => "CAM-M-{$suffix}", 'price' => 47000, 'cost_price' => 1000, 'stock' => 5]);
        $variantM->attributeValues()->attach($medium->id, ['product_attribute_id' => $attribute->id]);

        return [$product, $variantS, $variantM];
    }

    public function test_registering_a_purchase_for_a_variant_adds_its_stock_and_updates_its_own_weighted_average_cost(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product, $variantS] = $this->productWithTwoVariants($business);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [
                ['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 10, 'line_total_cop' => 20000],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('total', 20000);

        // variante: stock 10 @1000 + 10 @2000 -> promedio ponderado 1500.
        $variantS->refresh();
        $this->assertSame(20, $variantS->stock);
        $this->assertEquals(1500, (float) $variantS->cost_price);

        // producto padre: nunca se toca (columna fantasma para variantes).
        $this->assertSame(0, $product->fresh()->stock);

        $lineId = $response->json('lines.0.id');
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'product_variant_id' => $variantS->id,
            'purchase_line_id' => $lineId,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 10,
            'unit_cost_cop' => 2000,
        ]);
    }

    public function test_purchasing_two_different_variants_updates_each_ones_stock_independently(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product, $variantS, $variantM] = $this->productWithTwoVariants($business);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [
                ['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 4, 'line_total_cop' => 8000],
                ['product_id' => $product->id, 'product_variant_id' => $variantM->id, 'quantity' => 2, 'line_total_cop' => 6000],
            ],
        ])->assertCreated();

        $this->assertSame(14, $variantS->fresh()->stock);
        $this->assertSame(7, $variantM->fresh()->stock);
    }

    public function test_purchasing_a_variant_product_without_choosing_a_variant_is_rejected(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product] = $this->productWithTwoVariants($business);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'line_total_cop' => 1000]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.product_variant_id');
    }

    public function test_purchasing_with_a_variant_that_belongs_to_a_different_product_is_rejected(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product] = $this->productWithTwoVariants($business);
        [$otherProduct, $otherVariant] = $this->productWithTwoVariants($business);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'product_variant_id' => $otherVariant->id,
                'quantity' => 1,
                'line_total_cop' => 1000,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.product_variant_id');
    }

    public function test_a_product_without_variants_is_still_purchasable_normally(): void
    {
        [$business, $user] = $this->businessAndUser();
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 0, 'cost_price' => 0]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'line_total_cop' => 10000]],
        ])->assertCreated();

        $this->assertSame(5, $product->fresh()->stock);
    }
}
