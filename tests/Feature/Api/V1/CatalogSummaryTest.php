<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CatalogSummaryTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(array $businessAttributes = []): User
    {
        $business = Business::factory()->create($businessAttributes);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_products_summary_counts_low_stock_out_of_stock_and_single_sale(): void
    {
        $admin = $this->admin(['feature_flags' => ['ingredients' => false]]);

        Product::factory()->create(['business_id' => $admin->business_id, 'stock' => 0, 'is_service' => false]);
        Product::factory()->create([
            'business_id' => $admin->business_id, 'stock' => 3, 'low_stock_alert_threshold' => 5, 'is_service' => false,
        ]);
        Product::factory()->create([
            'business_id' => $admin->business_id, 'stock' => 100, 'low_stock_alert_threshold' => 5, 'is_service' => false,
        ]);
        Product::factory()->create([
            'business_id' => $admin->business_id, 'is_single_sale' => true, 'stock' => 1, 'is_service' => false,
        ]);
        Product::factory()->create([
            'business_id' => $admin->business_id, 'is_active' => false, 'stock' => 20, 'is_service' => false,
        ]);
        // Un servicio no cuenta para ninguno de los totales de inventario.
        Product::factory()->create(['business_id' => $admin->business_id, 'is_service' => true, 'stock' => 0]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/products/summary');

        $response->assertOk()->assertJson([
            'low_stock_count' => 3,
            'out_of_stock_count' => 1,
            'single_sale_count' => 1,
            'with_recipe_count' => 0,
            'inactive_count' => 1,
            'show_inventory_value_card' => true,
        ]);
        $this->assertIsNumeric($response->json('inventory_value_cop'));
    }

    /**
     * Cubre ProductController::summary() y Product::sumInventoryRetailValueCop()
     * variant-aware (tareas de Reportes): products.stock/price quedan
     * "fantasma" para un producto con variantes, asi que los conteos y el
     * valor de inventario deben mirar el stock/precio de cada VARIANTE, no
     * la columna cruda del padre (bug real: antes, cualquier producto con
     * variantes contaba a la vez como bajo Y sin stock, sin importar su
     * stock real).
     */
    public function test_products_summary_is_variant_aware(): void
    {
        $admin = $this->admin(['feature_flags' => ['ingredients' => false, 'variants' => true]]);
        $businessId = $admin->business_id;

        // Una variante con 50 y otra agotada: NO esta sin stock (la talla S
        // se sigue vendiendo) pero SI cuenta como inventario bajo, porque la
        // talla M hay que reponerla - "bajo" se evalua variante por variante,
        // igual que en el correo de alertas (ver LowStockAlertReport).
        $healthy = Product::factory()->create(['business_id' => $businessId, 'track_stock' => true, 'stock' => 0, 'is_service' => false]);
        $healthy->variants()->create(['business_id' => $businessId, 'sku' => 'HL-1', 'price' => 1000, 'stock' => 50]);
        $healthy->variants()->create(['business_id' => $businessId, 'sku' => 'HL-2', 'price' => 1200, 'stock' => 0]);

        // Variante unica bajo el umbral -> cuenta como bajo.
        $low = Product::factory()->create(['business_id' => $businessId, 'track_stock' => true, 'stock' => 0, 'low_stock_alert_threshold' => 5, 'is_service' => false]);
        $low->variants()->create(['business_id' => $businessId, 'sku' => 'LW-1', 'price' => 1000, 'stock' => 2]);

        // Ninguna variante con stock -> sin stock (y tambien bajo).
        $out = Product::factory()->create(['business_id' => $businessId, 'track_stock' => true, 'stock' => 0, 'is_service' => false]);
        $out->variants()->create(['business_id' => $businessId, 'sku' => 'OT-1', 'price' => 1000, 'stock' => 0]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/products/summary');

        $response->assertOk()->assertJson([
            'low_stock_count' => 3,
            'out_of_stock_count' => 1,
        ]);
        // Valor inventario: 50*1000 (variante sana) + 0 + 2*1000 (variante
        // baja) + 0 = 52000, nunca products.stock*price (que seria 0).
        $this->assertSame(52000.0, (float) $response->json('inventory_value_cop'));
    }

    public function test_products_summary_hides_inventory_value_card_when_ingredients_feature_enabled(): void
    {
        $admin = $this->admin(['feature_flags' => ['ingredients' => true]]);
        Product::factory()->create(['business_id' => $admin->business_id, 'is_service' => false]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/products/summary');

        $response->assertOk()->assertJson([
            'show_inventory_value_card' => false,
            'inventory_value_cop' => null,
        ]);
    }

    public function test_ingredients_summary_counts_active_and_low_stock(): void
    {
        $admin = $this->admin(['feature_flags' => ['ingredients' => true]]);

        Ingredient::factory()->create(['business_id' => $admin->business_id, 'stock' => 1, 'min_stock' => 5, 'is_active' => true]);
        Ingredient::factory()->create(['business_id' => $admin->business_id, 'stock' => 50, 'min_stock' => 5, 'is_active' => true]);
        // Inactivo pero con stock alto: no debe sumar a low_stock_count (que
        // no filtra por is_active, igual que el legacy) para que el assert
        // de abajo no dependa de los defaults de la factory.
        Ingredient::factory()->create(['business_id' => $admin->business_id, 'stock' => 50, 'min_stock' => 5, 'is_active' => false]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/ingredients/summary');

        $response->assertOk()->assertJson(['active_count' => 2, 'low_stock_count' => 1]);
    }

    public function test_services_summary_counts_total_and_variable_vs_fixed_price(): void
    {
        $admin = $this->admin(['feature_flags' => ['services' => true]]);

        Product::factory()->create([
            'business_id' => $admin->business_id, 'is_service' => true, 'track_stock' => false, 'price_varies_at_sale' => true,
        ]);
        Product::factory()->create([
            'business_id' => $admin->business_id, 'is_service' => true, 'track_stock' => false, 'price_varies_at_sale' => false,
        ]);
        Product::factory()->create([
            'business_id' => $admin->business_id, 'is_service' => true, 'track_stock' => false, 'price_varies_at_sale' => false,
        ]);
        // No es servicio: no debe contar.
        Product::factory()->create(['business_id' => $admin->business_id, 'is_service' => false]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/products/services-summary');

        $response->assertOk()->assertJson([
            'total_count' => 3,
            'variable_price_count' => 1,
            'fixed_price_count' => 2,
        ]);
    }

    public function test_services_summary_requires_the_services_feature(): void
    {
        $admin = $this->admin(['feature_flags' => ['services' => false]]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/products/services-summary')
            ->assertForbidden();
    }
}
