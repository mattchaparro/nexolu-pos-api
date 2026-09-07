<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El atajo "Frecuentes" de Vender. Devuelve ids ordenados por rotacion, no
 * productos: el frontend ya tiene el catalogo y solo lo reordena.
 */
class FrequentProductsTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->cashier = User::factory()->create(['business_id' => $this->business->id]);
        $this->cashier->assignRole('admin');
    }

    private function sell(Product $product, int $quantity, array $saleAttributes = []): Sale
    {
        $sale = Sale::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'user_id' => $this->cashier->id,
            'status' => 'closed',
            'is_non_revenue' => false,
            'is_credit' => false,
            'closed_at' => now(),
        ], $saleAttributes));

        DB::table('sale_items')->insert([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => 1000,
            'subtotal' => 1000 * $quantity,
            'discount_amount' => 0,
        ]);

        return $sale;
    }

    public function test_returns_product_ids_ordered_by_units_sold(): void
    {
        $poco = Product::factory()->create(['business_id' => $this->business->id]);
        $mucho = Product::factory()->create(['business_id' => $this->business->id]);

        $this->sell($poco, 2);
        $this->sell($mucho, 9);

        $response = $this->actingAs($this->cashier, 'sanctum')->getJson('/api/v1/products/frequent');

        $response->assertOk();
        $this->assertSame([$mucho->id, $poco->id], $response->json('product_ids'));
    }

    public function test_ignores_courtesies_credit_and_sales_outside_the_window(): void
    {
        $cortesia = Product::factory()->create(['business_id' => $this->business->id]);
        $fiado = Product::factory()->create(['business_id' => $this->business->id]);
        $viejo = Product::factory()->create(['business_id' => $this->business->id]);
        $valido = Product::factory()->create(['business_id' => $this->business->id]);

        $this->sell($cortesia, 50, ['is_non_revenue' => true]);
        $this->sell($fiado, 50, ['is_credit' => true]);
        $this->sell($viejo, 50, ['closed_at' => now()->subDays(60)]);
        $this->sell($valido, 1);

        $response = $this->actingAs($this->cashier, 'sanctum')->getJson('/api/v1/products/frequent');

        $response->assertOk();
        $this->assertSame([$valido->id], $response->json('product_ids'));
    }

    public function test_excludes_single_sale_products_and_other_businesses(): void
    {
        $ventaUnica = Product::factory()->create(['business_id' => $this->business->id, 'is_single_sale' => true]);
        $normal = Product::factory()->create(['business_id' => $this->business->id]);
        $this->sell($ventaUnica, 30);
        $this->sell($normal, 1);

        // Negocio ajeno vendiendo muchisimo: no debe filtrarse aca.
        $otro = Business::factory()->create();
        $otroUser = User::factory()->create(['business_id' => $otro->id]);
        $otroProducto = Product::factory()->create(['business_id' => $otro->id]);
        $otraVenta = Sale::factory()->create([
            'business_id' => $otro->id, 'user_id' => $otroUser->id,
            'status' => 'closed', 'is_non_revenue' => false, 'is_credit' => false, 'closed_at' => now(),
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $otraVenta->id, 'product_id' => $otroProducto->id,
            'quantity' => 99, 'unit_price' => 1000, 'subtotal' => 99000, 'discount_amount' => 0,
        ]);

        $response = $this->actingAs($this->cashier, 'sanctum')->getJson('/api/v1/products/frequent');

        $response->assertOk();
        $this->assertSame([$normal->id], $response->json('product_ids'));
    }

    public function test_a_business_without_sales_gets_an_empty_list(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->getJson('/api/v1/products/frequent')
            ->assertOk()
            ->assertExactJson(['product_ids' => []]);
    }
}
