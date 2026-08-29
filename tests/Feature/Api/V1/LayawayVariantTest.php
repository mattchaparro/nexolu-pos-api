<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre el retrofit de apartados para productos con variantes: al crear/
 * editar un apartado, se reserva el stock de LA VARIANTE elegida (nunca
 * products.stock, columna fantasma para estos productos), y cancelar/editar
 * a la baja libera la reserva de la variante correcta. Mismo patron que
 * VariantSaleTest para ventas y RecipeSaleTest/LayawayRecipeTest para receta.
 */
class LayawayVariantTest extends TestCase
{
    use DatabaseTransactions;

    private function businessAndUser(): array
    {
        $business = Business::factory()->create(['feature_flags' => ['variants' => true, 'layaway' => true]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    /** @return array{0: Product, 1: ProductVariant, 2: ProductVariant} */
    private function productWithTwoVariants(Business $business, int $stockSmall = 10, int $stockMedium = 5): array
    {
        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id]);
        $small = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'S']);
        $medium = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'M']);

        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 0]);
        $variantS = $product->variants()->create(['business_id' => $business->id, 'sku' => 'CAM-S-'.uniqid(), 'price' => 45000, 'stock' => $stockSmall]);
        $variantS->attributeValues()->attach($small->id, ['product_attribute_id' => $attribute->id]);
        $variantM = $product->variants()->create(['business_id' => $business->id, 'sku' => 'CAM-M-'.uniqid(), 'price' => 47000, 'stock' => $stockMedium]);
        $variantM->attributeValues()->attach($medium->id, ['product_attribute_id' => $attribute->id]);

        return [$product, $variantS, $variantM];
    }

    public function test_creating_a_layaway_with_a_variant_reserves_only_that_variants_stock(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product, $variantS, $variantM] = $this->productWithTwoVariants($business);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 3]],
        ]);

        $response->assertCreated()->assertJsonPath('items.0.product_variant.sku', $variantS->sku);
        $this->assertSame(7, $variantS->fresh()->stock);
        $this->assertSame(5, $variantM->fresh()->stock);
        $this->assertSame(0, $product->fresh()->stock, 'products.stock queda fantasma para un producto con variantes');
    }

    public function test_creating_a_layaway_without_choosing_a_variant_is_rejected(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product] = $this->productWithTwoVariants($business);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_variant_id');
    }

    public function test_updating_layaway_items_adjusts_variant_stock_by_delta_and_keeps_variants_independent(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product, $variantS, $variantM] = $this->productWithTwoVariants($business);

        $layaway = $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 2]],
        ])->json();
        $this->assertSame(8, $variantS->fresh()->stock);

        // Reemplaza el carrito: baja variante S de 2 a 1 (delta -1, restaura 1)
        // y agrega variante M x3 (delta +3, consume 3) - lineas independientes,
        // no deben fusionarse por compartir product_id.
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/layaways/{$layaway['id']}/items", [
            'items' => [
                ['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 1],
                ['product_id' => $product->id, 'product_variant_id' => $variantM->id, 'quantity' => 3],
            ],
        ])->assertOk();

        $this->assertSame(9, $variantS->fresh()->stock);
        $this->assertSame(2, $variantM->fresh()->stock);
    }

    public function test_cancelling_a_layaway_with_a_variant_restores_its_stock(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product, $variantS] = $this->productWithTwoVariants($business);

        $layaway = $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 4]],
        ])->json();
        $this->assertSame(6, $variantS->fresh()->stock);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/layaways/{$layaway['id']}/cancel")->assertNoContent();

        $this->assertSame(10, $variantS->fresh()->stock);
    }

    public function test_selling_more_than_a_variants_stock_is_rejected_even_if_sibling_variant_has_stock(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$product, $variantS] = $this->productWithTwoVariants($business, stockSmall: 2, stockMedium: 999);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variantS->id, 'quantity' => 5]],
        ])->assertStatus(422);

        $this->assertSame(2, $variantS->fresh()->stock);
    }

    public function test_a_product_without_variants_still_works_normally_when_the_variants_feature_is_active(): void
    {
        [$business, $user] = $this->businessAndUser();
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 10]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertSame(7, $product->fresh()->stock);
    }

    /**
     * Bug real encontrado al verificar el picker de productos de Apartados
     * en el navegador: el filtro for_layaway comparaba products.stock > 0
     * directo por SQL - esa columna es "fantasma" para un producto con
     * variantes (siempre 0, ver ProductAvailability), asi que CUALQUIER
     * producto con variantes desaparecia del selector de apartados aunque
     * tuviera stock real en alguna variante. Ver ProductController::index().
     */
    public function test_for_layaway_filter_includes_a_variant_product_with_stock_in_at_least_one_variant(): void
    {
        [$business, $user] = $this->businessAndUser();
        [$withStock] = $this->productWithTwoVariants($business, stockSmall: 10, stockMedium: 5);
        [$outOfStock] = $this->productWithTwoVariants($business, stockSmall: 0, stockMedium: 0);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/products?for_layaway=1');

        $response->assertOk()->assertJsonFragment(['id' => $withStock->id]);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($outOfStock->id), 'Un producto con variantes sin stock en ninguna no debe aparecer.');
    }
}
