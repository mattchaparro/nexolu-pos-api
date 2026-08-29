<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Confirma que los descuentos siguen funcionando sin cambios de codigo
 * sobre una linea de venta de una variante: Discount::resolveActive() solo
 * necesita el subtotal de la linea (unitPrice * quantity), que
 * SaleService::applyItems() ya calcula desde variant.price cuando la linea
 * trae product_variant_id (ver la Fase 1 de "productos con variaciones") -
 * discount_id/discount_amount en sale_items son columnas independientes de
 * product_variant_id, asi que no necesitaron ningun retrofit. Este test
 * documenta esa garantia en vez de solo confiar en que "no cambio nada".
 */
class DiscountVariantTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_item_discount_reduces_the_total_of_a_variant_line(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['variants' => true, 'discounts' => true]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->syncPermissions(['discounts.apply']);

        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id]);
        $small = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'S']);
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 0]);
        $variant = $product->variants()->create(['business_id' => $business->id, 'sku' => 'CAM-S', 'price' => 10000, 'stock' => 10]);
        $variant->attributeValues()->attach($small->id, ['product_attribute_id' => $attribute->id]);

        $discount = Discount::factory()->fixed()->itemScoped()->create(['business_id' => $business->id, 'value' => 2000]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => 1,
                'discount_id' => $discount->id,
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('total', '8000.00')
            ->assertJsonPath('items.0.discount_amount', '2000.00')
            ->assertJsonPath('items.0.unit_price', '10000.00')
            ->assertJsonPath('items.0.product_variant.sku', 'CAM-S');

        $this->assertSame(9, $variant->fresh()->stock);
    }

    public function test_a_cart_discount_still_applies_when_the_cart_has_a_variant_line(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['variants' => true, 'discounts' => true]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->syncPermissions(['discounts.apply']);

        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id]);
        $small = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'S']);
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 0]);
        $variant = $product->variants()->create(['business_id' => $business->id, 'sku' => 'CAM-S', 'price' => 10000, 'stock' => 10]);
        $variant->attributeValues()->attach($small->id, ['product_attribute_id' => $attribute->id]);

        $discount = Discount::factory()->create(['business_id' => $business->id, 'type' => 'percentage', 'scope' => 'cart', 'value' => 10]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'cart_discount_id' => $discount->id,
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('total', '9000.00')
            ->assertJsonPath('cart_discount_amount', '1000.00');
    }
}
