<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\InventoryReportService;
use App\Support\LowStockAlertReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre el escenario que la Fase 1 no habia contemplado: activar variantes
 * sobre un catalogo QUE YA VENIA FUNCIONANDO. Un producto convertido (que
 * ya tenia stock, precio y costo propios antes de recibir variantes)
 * conserva esas columnas: syncVariants() no las pisa, asi que quedan como
 * datos fantasma que ningun lado debe seguir contando. Ademas fija la
 * consistencia entre las tarjetas del Catalogo, los filtros del listado y
 * el reporte de alertas, que llegaron a contradecirse entre si.
 */
class VariantCatalogConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory' => true, 'inventory_advanced' => true, 'ingredients' => false, 'variants' => true],
            'low_stock_alert_threshold' => 5,
        ]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return $admin;
    }

    /** Producto que ya existia con stock/precio/costo y despues recibe variantes. */
    private function convertedProduct(int $businessId, int $variantStock = 3): Product
    {
        $product = Product::factory()->create([
            'business_id' => $businessId, 'is_service' => false, 'is_active' => true, 'is_single_sale' => false,
            'track_stock' => true, 'stock' => 50, 'price' => 1000, 'cost_price' => 600,
        ]);
        $product->variants()->create([
            'business_id' => $businessId, 'sku' => 'CONV-'.uniqid(), 'price' => 2000, 'cost_price' => 900, 'stock' => $variantStock,
        ]);

        return $product;
    }

    /**
     * La invariante que hace innecesario que cada consumidor futuro se
     * acuerde de excluir estos productos: guardar variantes deja
     * products.stock en 0, aunque el producto viniera con existencias.
     */
    public function test_converting_a_product_to_variants_zeroes_its_own_stock_column(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create([
            'business_id' => $admin->business_id, 'is_service' => false,
            'track_stock' => true, 'stock' => 50, 'price' => 1000,
        ]);
        $attribute = ProductAttribute::factory()->create(['business_id' => $admin->business_id]);
        $small = ProductAttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id, 'business_id' => $admin->business_id, 'value' => 'S',
        ]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/products/{$product->id}", [
            'name' => $product->name,
            'price' => 1000,
            'variants' => [
                ['sku' => 'ZR-1', 'price' => 2000, 'stock' => 3, 'attribute_value_ids' => [$small->id]],
            ],
        ])->assertOk();

        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertSame(3, (int) $product->fresh()->variants()->first()->stock);
    }

    public function test_inventory_value_ignores_the_stock_a_product_had_before_getting_variants(): void
    {
        $admin = $this->admin();
        $this->convertedProduct($admin->business_id);

        $value = (float) $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/products/summary')->json('inventory_value_cop');

        // Solo la variante: 3 x 2000. El 50 x 1000 del padre es fantasma.
        $this->assertSame(6000.0, $value);
    }

    public function test_inventory_report_summary_ignores_the_phantom_stock_of_a_converted_product(): void
    {
        $admin = $this->admin();
        $this->convertedProduct($admin->business_id);

        $summary = app(InventoryReportService::class)->summary($admin->business);

        $this->assertSame(6000.0, $summary['inventory_retail_cop']);
        $this->assertSame(2700.0, $summary['inventory_cost_products_cop']);
    }

    public function test_margins_excludes_products_with_variants_instead_of_showing_stale_parent_numbers(): void
    {
        $admin = $this->admin();
        $converted = $this->convertedProduct($admin->business_id);
        $simple = Product::factory()->create([
            'business_id' => $admin->business_id, 'is_service' => false, 'is_active' => true, 'is_single_sale' => false,
            'track_stock' => true, 'stock' => 10, 'price' => 5000, 'cost_price' => 2000,
        ]);

        $rows = collect(app(InventoryReportService::class)->margins($admin->business, [])['margin_rows']);

        $this->assertNull($rows->firstWhere('id', $converted->id), 'El producto con variantes no debe aparecer con precio/costo del padre');
        $this->assertNotNull($rows->firstWhere('id', $simple->id), 'Un producto normal debe seguir apareciendo');
    }

    public function test_out_of_stock_filter_matches_the_summary_card(): void
    {
        $admin = $this->admin();

        $withStock = Product::factory()->create(['business_id' => $admin->business_id, 'is_service' => false, 'track_stock' => true, 'stock' => 0]);
        $withStock->variants()->create(['business_id' => $admin->business_id, 'sku' => 'OK-1', 'price' => 1000, 'stock' => 30]);

        $withoutStock = Product::factory()->create(['business_id' => $admin->business_id, 'is_service' => false, 'track_stock' => true, 'stock' => 0]);
        $withoutStock->variants()->create(['business_id' => $admin->business_id, 'sku' => 'NO-1', 'price' => 1000, 'stock' => 0]);

        $card = (int) $this->actingAs($admin, 'sanctum')->getJson('/api/v1/products/summary')->json('out_of_stock_count');
        $ids = collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/products?is_service=0&filter=out_of_stock')->json('data'))->pluck('id');

        $this->assertSame(1, $card);
        $this->assertSame(1, $ids->count(), 'El filtro debe devolver exactamente lo que cuenta la tarjeta');
        $this->assertTrue($ids->contains($withoutStock->id));
        $this->assertFalse($ids->contains($withStock->id));
    }

    /**
     * La tarjeta contaba la SUMA de variantes contra el umbral del padre
     * mientras el correo de alertas evaluaba cada variante contra el suyo:
     * 3 variantes de 4 unidades con umbral 5 daban "0 productos bajos" en el
     * Catalogo y "3 items bajos" en la alerta, para el mismo producto.
     */
    public function test_low_stock_card_filter_and_alert_report_agree_on_variants(): void
    {
        $admin = $this->admin();
        $business = $admin->business;

        $product = Product::factory()->create([
            'business_id' => $business->id, 'is_service' => false, 'is_active' => true,
            'is_single_sale' => false, 'track_stock' => true, 'stock' => 0,
        ]);
        foreach (['A', 'B', 'C'] as $letter) {
            $product->variants()->create(['business_id' => $business->id, 'sku' => "LS-{$letter}", 'price' => 1000, 'stock' => 4]);
        }

        $card = (int) $this->actingAs($admin, 'sanctum')->getJson('/api/v1/products/summary')->json('low_stock_count');
        $ids = collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/products?is_service=0&filter=low_stock')->json('data'))->pluck('id');
        $alerts = LowStockAlertReport::forBusiness($business->fresh())['items'];

        // La tarjeta y el filtro cuentan PRODUCTOS (1); la alerta lista
        // VARIANTES (3). Lo que no puede pasar es que una diga 0 y la otra 3.
        $this->assertSame(1, $card);
        $this->assertTrue($ids->contains($product->id));
        $this->assertSame(3, $alerts->count());
    }

    public function test_a_variant_above_its_threshold_is_not_reported_as_low(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create([
            'business_id' => $admin->business_id, 'is_service' => false, 'is_active' => true,
            'is_single_sale' => false, 'track_stock' => true, 'stock' => 0,
        ]);
        $product->variants()->create(['business_id' => $admin->business_id, 'sku' => 'HI-1', 'price' => 1000, 'stock' => 80]);

        $card = (int) $this->actingAs($admin, 'sanctum')->getJson('/api/v1/products/summary')->json('low_stock_count');
        $ids = collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/products?is_service=0&filter=low_stock')->json('data'))->pluck('id');

        $this->assertSame(0, $card);
        $this->assertFalse($ids->contains($product->id));
    }

    public function test_products_without_variants_keep_the_previous_stock_filter_behaviour(): void
    {
        $admin = $this->admin();
        $out = Product::factory()->create(['business_id' => $admin->business_id, 'is_service' => false, 'track_stock' => true, 'stock' => 0]);
        $low = Product::factory()->create(['business_id' => $admin->business_id, 'is_service' => false, 'track_stock' => true, 'stock' => 2, 'low_stock_alert_threshold' => 5]);
        $fine = Product::factory()->create(['business_id' => $admin->business_id, 'is_service' => false, 'track_stock' => true, 'stock' => 99, 'low_stock_alert_threshold' => 5]);

        $outIds = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/products?is_service=0&filter=out_of_stock')->json('data'))->pluck('id');
        $lowIds = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/products?is_service=0&filter=low_stock')->json('data'))->pluck('id');

        $this->assertTrue($outIds->contains($out->id));
        $this->assertTrue($lowIds->contains($low->id));
        $this->assertFalse($lowIds->contains($fine->id));
        $this->assertFalse($outIds->contains($fine->id));
    }
}
