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
 * Cubre el retrofit del flujo de ventas para productos con variantes: al
 * vender, se descuenta el stock de LA VARIANTE elegida (nunca
 * products.stock, columna fantasma para estos productos), dos variantes
 * distintas del mismo producto generan lineas separadas, y el reverso/
 * cancelacion restaura el stock de la variante correcta. Mismo patron que
 * RecipeSaleTest para productos con receta.
 */
class VariantSaleTest extends TestCase
{
    use DatabaseTransactions;

    private function businessAndUser(array $flags = []): array
    {
        $business = Business::factory()->create(['feature_flags' => ['variants' => true, 'open_tabs' => true, ...$flags]]);
        $user = User::factory()->create(['business_id' => $business->id]);

        return [$business, $user];
    }

    /** @return array{0: Product, 1: ProductVariant, 2: ProductVariant} */
    private function productWithTwoVariants(Business $business, int $stockSmall = 10, int $stockMedium = 5): array
    {
        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id, 'name' => 'Talla']);
        $small = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'S']);
        $medium = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'M']);

        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 0]);
        $variantS = $product->variants()->create(['business_id' => $business->id, 'sku' => 'CAM-S', 'price' => 45000, 'cost_price' => 20000, 'stock' => $stockSmall]);
        $variantS->attributeValues()->attach($small->id, ['product_attribute_id' => $attribute->id]);
        $variantM = $product->variants()->create(['business_id' => $business->id, 'sku' => 'CAM-M', 'price' => 47000, 'cost_price' => 21000, 'stock' => $stockMedium]);
        $variantM->attributeValues()->attach($medium->id, ['product_attribute_id' => $attribute->id]);

        return [$product, $variantS, $variantM];
    }

    public function test_selling_a_variant_deducts_only_that_variants_stock(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product, $variantS, $variantM] = $this->productWithTwoVariants($business);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 3]],
        ]);

        $response->assertCreated();
        $this->assertSame(7, $variantS->fresh()->stock);
        $this->assertSame(5, $variantM->fresh()->stock);
        $this->assertSame(0, $product->fresh()->stock, 'products.stock queda fantasma para un producto con variantes');
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id, 'product_variant_id' => $variantS->id, 'type' => StockMovement::TYPE_SALE, 'quantity' => -3,
        ]);
    }

    public function test_selling_two_different_variants_in_the_same_sale_creates_two_separate_lines(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product, $variantS, $variantM] = $this->productWithTwoVariants($business);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 2],
                ['product_id' => $product->id, 'product_variant_id' => $variantM->id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated()->assertJsonCount(2, 'items');
        $this->assertSame(8, $variantS->fresh()->stock);
        $this->assertSame(4, $variantM->fresh()->stock);
    }

    public function test_selling_a_product_with_variants_without_choosing_one_is_rejected(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product] = $this->productWithTwoVariants($business);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_variant_id');
    }

    public function test_selling_a_variant_that_does_not_belong_to_the_product_is_rejected(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product] = $this->productWithTwoVariants($business);
        $otherProduct = Product::factory()->create(['business_id' => $business->id]);
        $foreignVariant = $otherProduct->variants()->create(['business_id' => $business->id, 'sku' => 'OTHER', 'price' => 1000, 'stock' => 5]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'product_variant_id' => $foreignVariant->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_variant_id');
    }

    public function test_selling_more_than_a_specific_variants_stock_is_rejected_even_if_sibling_variant_has_stock(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product, $variantS, $variantM] = $this->productWithTwoVariants($business, stockSmall: 2, stockMedium: 999);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 5]],
        ])->assertStatus(422);

        $this->assertSame(2, $variantS->fresh()->stock);
    }

    public function test_reversing_a_sale_restores_the_correct_variants_stock(): void
    {
        [$business, $user] = $this->businessAndUser();
        $user->syncPermissions(['sales.reverse']);
        [$product, $variantS] = $this->productWithTwoVariants($business);

        $sale = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 4]],
        ])->json();
        $this->assertSame(6, $variantS->fresh()->stock);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/reverse")->assertNoContent();

        $this->assertSame(10, $variantS->fresh()->stock);
        $this->assertDatabaseMissing('sales', ['id' => $sale['id']]);
    }

    public function test_a_product_without_variants_still_sells_normally_when_the_variants_feature_is_active(): void
    {
        [$business, $user] = $this->businessAndUser();
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 10]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertSame(7, $product->fresh()->stock);
    }

    public function test_opening_a_tab_with_a_variant_deducts_that_variants_stock(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product, $variantS] = $this->productWithTwoVariants($business);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertSame(8, $variantS->fresh()->stock);
    }

    public function test_syncing_tab_items_adjusts_variant_stock_by_delta_and_keeps_variants_independent(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product, $variantS, $variantM] = $this->productWithTwoVariants($business);

        $open = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 2]],
        ])->json();
        $this->assertSame(8, $variantS->fresh()->stock);

        // Reemplaza el carrito: baja variante S de 2 a 1 (delta -1, restaura 1)
        // y agrega variante M x3 (delta +3, consume 3) - deben tratarse como
        // lineas independientes, no fusionarse por compartir product_id.
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/open-tabs/{$open['id']}/items", [
            'items' => [
                ['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 1],
                ['product_id' => $product->id, 'product_variant_id' => $variantM->id, 'quantity' => 3],
            ],
        ])->assertOk();

        $this->assertSame(9, $variantS->fresh()->stock);
        $this->assertSame(2, $variantM->fresh()->stock);
    }

    public function test_cancelling_a_tab_with_a_variant_restores_its_stock(): void
    {
        [$business, $user] = $this->businessAndUser();
        $user->syncPermissions(['sales.reverse']);
        [$product, $variantS] = $this->productWithTwoVariants($business);

        $open = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 3]],
        ])->json();
        $this->assertSame(7, $variantS->fresh()->stock);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/open-tabs/{$open['id']}")->assertNoContent();

        $this->assertSame(10, $variantS->fresh()->stock);
    }
}
