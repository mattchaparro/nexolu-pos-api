<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La tienda online despacha desde UNA sede.
 *
 * Con varias sedes, "hay 12 unidades" deja de ser una respuesta: el comprador
 * de internet compra de un inventario concreto, el que se va a empacar.
 * Mostrarle el agregado del negocio le permitiria comprar algo que solo
 * existe en el otro local, a dos horas.
 */
class StorefrontFulfillmentBranchTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        BranchContext::forget();

        parent::tearDown();
    }

    public function test_the_storefront_shows_the_stock_of_the_fulfillment_branch(): void
    {
        [$business, $main, $second, $product] = $this->storeWithTwoBranches();

        // 10 en la principal (sembradas al crear el producto) y 3 en la otra.
        BranchStock::add($business->id, $second->id, 'product_id', $product->id, 3);

        $this->assertSame(10, $this->storefrontStock($business, $product));

        $business->storeSettings()->withoutGlobalScopes()->first()
            ->update(['fulfillment_branch_id' => $second->id]);

        $this->assertSame(3, $this->storefrontStock($business, $product));
    }

    public function test_the_storefront_shows_the_price_of_the_fulfillment_branch(): void
    {
        [$business, , $second, $product] = $this->storeWithTwoBranches();

        BranchProductPrice::create([
            'business_id' => $business->id,
            'branch_id' => $second->id,
            'product_id' => $product->id,
            'price' => 15000,
        ]);

        $this->assertSame(10000.0, $this->storefrontPrice($business, $product));

        $business->storeSettings()->withoutGlobalScopes()->first()
            ->update(['fulfillment_branch_id' => $second->id]);

        $this->assertSame(15000.0, $this->storefrontPrice($business, $product));
    }

    /**
     * Sin sede configurada la tienda despacha desde la principal, que es lo
     * correcto para el monosede y evita obligar al comerciante a tomar una
     * decision que no tiene.
     */
    public function test_without_a_configured_branch_it_falls_back_to_the_main_one(): void
    {
        [$business, $main] = $this->storeWithTwoBranches();
        $settings = $business->storeSettings()->withoutGlobalScopes()->first();

        $this->assertNull($settings->fulfillment_branch_id);
        $this->assertSame($main->id, $settings->fulfillmentBranchId());
    }

    public function test_the_merchant_can_choose_the_fulfillment_branch(): void
    {
        [$business, , $second] = $this->storeWithTwoBranches();
        $admin = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/store-settings', ['fulfillment_branch_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('fulfillment_branch_id', $second->id)
            ->assertJsonPath('resolved_fulfillment_branch_id', $second->id);
    }

    public function test_a_branch_of_another_business_is_rejected(): void
    {
        [$business] = $this->storeWithTwoBranches();
        $foreign = Branch::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/store-settings', ['fulfillment_branch_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fulfillment_branch_id');
    }

    /** La tienda expone el stock como `available.quantity`, no como columna. */
    private function storefrontStock(Business $business, Product $product): int
    {
        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products")->assertOk();

        return (int) collect($response->json('data'))
            ->firstWhere('id', $product->id)['available']['quantity'];
    }

    private function storefrontPrice(Business $business, Product $product): float
    {
        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products")->assertOk();

        return (float) collect($response->json('data'))->firstWhere('id', $product->id)['price'];
    }

    /** @return array{0: Business, 1: Branch, 2: Branch, 3: Product} */
    private function storeWithTwoBranches(): array
    {
        $business = Business::factory()->create([
            'feature_flags' => array_merge(BusinessFeaturePresets::full(), [
                'online_store' => true,
                'multi_branch' => true,
            ]),
        ]);
        $main = Branch::factory()->for($business)->main()->create();
        $second = Branch::factory()->for($business)->create();

        BusinessStoreSettings::factory()->create(['business_id' => $business->id, 'is_active' => true]);

        $category = ProductCategory::factory()->create(['business_id' => $business->id, 'is_published' => true]);
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'category_id' => $category->id,
            'price' => 10000,
            'stock' => 10,
            'is_active' => true,
            'is_published' => true,
        ]);

        return [$business, $main, $second, $product];
    }
}
