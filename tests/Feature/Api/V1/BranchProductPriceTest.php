<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Un mismo producto puede costar distinto segun el local (el clasico: el
 * punto del centro comercial cobra mas que el de fabrica), sin duplicar el
 * producto ni partir su inventario.
 *
 * Lo importante no es que se pueda guardar el precio, sino que el precio de
 * la sede llegue hasta la VENTA: si la pantalla muestra uno y la venta cobra
 * otro, el negocio pierde plata sin enterarse.
 */
class BranchProductPriceTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        BranchContext::forget();

        parent::tearDown();
    }

    public function test_the_branch_price_is_what_the_sale_actually_charges(): void
    {
        [$business, $main, $second, $admin] = $this->scenario();
        $product = Product::factory()->for($business)->create(['price' => 10000, 'stock' => 20]);
        BranchStock::add($business->id, $second->id, 'product_id', $product->id, 20);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}/branch-prices", [
                'prices' => [['branch_id' => $second->id, 'price' => 13500]],
            ])->assertOk();

        $totalIn = fn (Branch $branch) => $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $branch->id)
            ->postJson('/api/v1/sales', [
                'payment_method' => 'cash',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertCreated()->json('total');

        $this->assertSame('10000.00', $totalIn($main), 'La sede sin override cobra el precio del catalogo.');
        $this->assertSame('13500.00', $totalIn($second));

        // Y queda registrado en la linea, no solo en el total: el historial
        // tiene que poder explicar por que esa venta costo mas.
        BranchContext::set($second);
        $this->assertSame('13500.00', Sale::latest('id')->first()->items->first()->unit_price);
    }

    public function test_the_catalog_shows_the_price_of_the_active_branch(): void
    {
        [$business, $main, $second, $admin] = $this->scenario();
        $product = Product::factory()->for($business)->create(['price' => 8000, 'stock' => 5]);

        BranchProductPrice::create([
            'business_id' => $business->id,
            'branch_id' => $second->id,
            'product_id' => $product->id,
            'price' => 9500,
        ]);

        $priceIn = fn (Branch $branch) => collect(
            $this->actingAs($admin, 'sanctum')
                ->withHeader('X-Branch-Id', (string) $branch->id)
                ->getJson('/api/v1/products/sellable')->assertOk()->json()
        )->firstWhere('id', $product->id)['price'];

        // String con 2 decimales, igual que siempre: el tipo del campo no
        // cambia porque el negocio tenga varias sedes.
        $this->assertSame('8000.00', $priceIn($main));
        $this->assertSame('9500.00', $priceIn($second));
    }

    public function test_a_null_price_removes_the_override_instead_of_saving_zero(): void
    {
        [$business, , $second, $admin] = $this->scenario();
        $product = Product::factory()->for($business)->create(['price' => 7000, 'stock' => 5]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}/branch-prices", [
                'prices' => [['branch_id' => $second->id, 'price' => 9000]],
            ])->assertOk();

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}/branch-prices", [
                'prices' => [['branch_id' => $second->id, 'price' => null]],
            ])->assertOk();

        $this->assertSame([], $response->json('branch_prices'));
        $this->assertSame(7000.0, $product->fresh()->priceAt($second->id), 'Vuelve al precio del catalogo.');
    }

    public function test_zero_is_a_real_price_and_not_a_removal(): void
    {
        [$business, , $second, $admin] = $this->scenario();
        $product = Product::factory()->for($business)->create(['price' => 7000, 'stock' => 5]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}/branch-prices", [
                'prices' => [['branch_id' => $second->id, 'price' => 0]],
            ])->assertOk();

        $this->assertSame(0.0, $product->fresh()->priceAt($second->id));
    }

    public function test_a_branch_of_another_business_is_rejected(): void
    {
        [$business, , , $admin] = $this->scenario();
        $product = Product::factory()->for($business)->create(['price' => 7000, 'stock' => 5]);
        $foreign = Branch::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}/branch-prices", [
                'prices' => [['branch_id' => $foreign->id, 'price' => 100]],
            ])->assertStatus(422)->assertJsonValidationErrors('prices.0.branch_id');
    }

    public function test_a_variant_of_another_product_is_rejected(): void
    {
        [$business, , $second, $admin] = $this->scenario();
        $product = Product::factory()->for($business)->create(['price' => 7000, 'stock' => 5]);
        $other = Product::factory()->for($business)->create(['price' => 7000, 'stock' => 5]);
        $foreignVariant = $other->variants()->create([
            'business_id' => $business->id, 'name' => 'Talla M', 'price' => 8000, 'stock' => 1, 'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}/branch-prices", [
                'prices' => [['branch_id' => $second->id, 'product_variant_id' => $foreignVariant->id, 'price' => 100]],
            ])->assertStatus(422)->assertJsonValidationErrors('prices.0.product_variant_id');
    }

    public function test_a_business_without_multi_branch_has_no_branch_prices(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['inventory' => true, 'multi_branch' => false]]);
        Branch::factory()->for($business)->main()->create();
        $admin = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $admin->assignRole('admin');
        $product = Product::factory()->for($business)->create(['price' => 100, 'stock' => 1]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}/branch-prices")
            ->assertForbidden();
    }

    /** @return array{0: Business, 1: Branch, 2: Branch, 3: User} */
    private function scenario(): array
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory' => true, 'multi_branch' => true, 'variants' => true],
        ]);
        $main = Branch::factory()->for($business)->main()->create();
        $second = Branch::factory()->for($business)->create();

        $admin = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $admin->assignRole('admin');

        return [$business, $main, $second, $admin];
    }
}
