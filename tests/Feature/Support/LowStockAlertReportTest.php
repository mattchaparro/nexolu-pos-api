<?php

namespace Tests\Feature\Support;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Support\LowStockAlertReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre el retrofit de LowStockAlertReport para productos con variantes
 * (tarea de Reportes): decision de negocio de la Fase 1 ("low-stock por
 * variante, no agregado al producto") - cada variante activa compara su
 * propio stock/umbral, y el producto padre nunca aparece por si mismo
 * porque products.stock queda "fantasma" para el.
 */
class LowStockAlertReportTest extends TestCase
{
    use DatabaseTransactions;

    private function business(): Business
    {
        return Business::factory()->create(['feature_flags' => ['variants' => true], 'low_stock_alert_threshold' => 5]);
    }

    /** @return array{0: Product, 1: ProductVariant, 2: ProductVariant} */
    private function productWithTwoVariants(Business $business, int $stockSmall, int $stockMedium): array
    {
        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id]);
        $small = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'S']);
        $medium = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'M']);

        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 0, 'is_active' => true, 'is_single_sale' => false]);
        $variantS = $product->variants()->create(['business_id' => $business->id, 'sku' => 'CAM-S-'.uniqid(), 'price' => 45000, 'stock' => $stockSmall]);
        $variantS->attributeValues()->attach($small->id, ['product_attribute_id' => $attribute->id]);
        $variantM = $product->variants()->create(['business_id' => $business->id, 'sku' => 'CAM-M-'.uniqid(), 'price' => 47000, 'stock' => $stockMedium]);
        $variantM->attributeValues()->attach($medium->id, ['product_attribute_id' => $attribute->id]);

        return [$product, $variantS, $variantM];
    }

    public function test_each_variant_is_checked_independently_against_the_business_default_threshold(): void
    {
        $business = $this->business();
        [$product, $variantS, $variantM] = $this->productWithTwoVariants($business, stockSmall: 2, stockMedium: 50);

        $result = LowStockAlertReport::forBusiness($business);

        $this->assertSame(1, $result['count']);
        $item = $result['items']->first();
        $this->assertSame('product_variant', $item['kind']);
        $this->assertSame($variantS->id, $item['id']);
        $this->assertSame("{$product->name} (S)", $item['name']);
        $this->assertSame(2.0, $item['stock']);
    }

    public function test_the_parent_product_is_never_reported_by_itself_when_it_has_variants(): void
    {
        $business = $this->business();
        // products.stock queda en 0 (fantasma) - si se evaluara el producto
        // como un todo, siempre apareceria como bajo/sin stock sin importar
        // sus variantes.
        $this->productWithTwoVariants($business, stockSmall: 999, stockMedium: 999);

        $result = LowStockAlertReport::forBusiness($business);

        $this->assertSame(0, $result['count']);
        $this->assertFalse($result['items']->contains(fn (array $i) => $i['kind'] === 'product'));
    }

    public function test_a_variants_own_threshold_overrides_the_business_default(): void
    {
        $business = $this->business();
        [, $variantS] = $this->productWithTwoVariants($business, stockSmall: 8, stockMedium: 50);
        $variantS->update(['low_stock_alert_threshold' => 10]);

        $result = LowStockAlertReport::forBusiness($business);

        // 8 esta por debajo de su propio umbral (10), aunque supere el
        // default del negocio (5).
        $this->assertSame(1, $result['count']);
        $this->assertSame($variantS->id, $result['items']->first()['id']);
    }

    public function test_inactive_variants_are_not_reported(): void
    {
        $business = $this->business();
        [, $variantS] = $this->productWithTwoVariants($business, stockSmall: 1, stockMedium: 50);
        $variantS->update(['is_active' => false]);

        $result = LowStockAlertReport::forBusiness($business);

        $this->assertSame(0, $result['count']);
    }

    public function test_ignores_variants_when_the_variants_feature_is_disabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['variants' => false], 'low_stock_alert_threshold' => 5]);
        [$product] = $this->productWithTwoVariants($business, stockSmall: 1, stockMedium: 1);

        $result = LowStockAlertReport::forBusiness($business);

        $this->assertFalse($result['items']->contains(fn (array $i) => $i['kind'] === 'product_variant'));
    }

    /**
     * Cubre StockUrgency::productVariantsVelocityBatch() a traves del
     * reporte: coverage_days debe reflejar la velocidad de venta de LA
     * VARIANTE puntual, no la del producto padre ni la de su variante
     * hermana.
     */
    public function test_coverage_days_reflects_the_variants_own_sale_velocity(): void
    {
        $business = $this->business();
        [$product, $variantS, $variantM] = $this->productWithTwoVariants($business, stockSmall: 10, stockMedium: 10);
        // Umbral bajo el default (5) para que ambas entren al reporte.
        $variantS->update(['low_stock_alert_threshold' => 20]);
        $variantM->update(['low_stock_alert_threshold' => 20]);

        // Variante S vende rapido (2/dia en promedio); variante M no se mueve.
        StockMovement::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_variant_id' => $variantS->id,
            'type' => 'sale',
            'quantity' => -20,
            'created_at' => now()->subDays(5),
        ]);

        $result = LowStockAlertReport::forBusiness($business);

        $itemS = $result['items']->firstWhere('id', $variantS->id);
        $itemM = $result['items']->firstWhere('id', $variantM->id);

        $this->assertNotNull($itemS['coverage_days']);
        $this->assertNull($itemM['coverage_days']);
    }
}
