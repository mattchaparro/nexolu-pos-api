<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Product;
use App\Models\User;
use App\Services\CrossSellService;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ventas cruzadas: que sugerir a quien lleva un producto.
 *
 * Lo que se protege: que un comercio no pueda sugerir productos de otro, y
 * que la tienda publica nunca ofrezca algo que el comprador no puede comprar.
 */
class CrossSellTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);
        BusinessStoreSettings::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);
        $this->owner = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => true]);
        $this->owner->assignRole('admin');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    private function product(array $attributes = [], ?Business $business = null): Product
    {
        return Product::factory()->create([
            'business_id' => ($business ?? $this->business)->id,
            'is_active' => true,
            'is_published' => true,
            'is_service' => false,
            ...$attributes,
        ]);
    }

    private function sync(Product $product, array $ids)
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}/cross-sells", ['cross_sell_ids' => $ids]);
    }

    public function test_el_comerciante_configura_sugerencias_en_orden(): void
    {
        $hamburguesa = $this->product(['name' => 'Hamburguesa']);
        $papas = $this->product(['name' => 'Papas']);
        $gaseosa = $this->product(['name' => 'Gaseosa']);

        // El orden que manda es el de la lista, no el del catalogo.
        $this->sync($hamburguesa, [$gaseosa->id, $papas->id])->assertOk();

        $sugeridos = app(CrossSellService::class)->forProduct($hamburguesa);
        $this->assertSame(['Gaseosa', 'Papas'], $sugeridos->pluck('name')->all());
    }

    /** Es un reemplazo completo: lo que no viene, se va. */
    public function test_volver_a_guardar_reemplaza_la_lista(): void
    {
        $base = $this->product();
        $uno = $this->product();
        $dos = $this->product();

        $this->sync($base, [$uno->id, $dos->id])->assertOk();
        $this->sync($base, [$dos->id])->assertOk();

        $this->assertSame([$dos->id], app(CrossSellService::class)->forProduct($base)->pluck('id')->all());
        $this->assertDatabaseMissing('product_cross_sells', [
            'product_id' => $base->id,
            'related_product_id' => $uno->id,
        ]);
    }

    /** El candado principal: no se puede sugerir el catalogo de otro negocio. */
    public function test_no_se_pueden_sugerir_productos_de_otro_negocio(): void
    {
        $otro = Business::factory()->create();
        $ajeno = $this->product([], $otro);
        $propio = $this->product();

        $this->sync($propio, [$ajeno->id])->assertStatus(422);

        $this->assertDatabaseMissing('product_cross_sells', ['related_product_id' => $ajeno->id]);
    }

    public function test_un_producto_no_puede_sugerirse_a_si_mismo(): void
    {
        $producto = $this->product();

        $this->sync($producto, [$producto->id])->assertStatus(422);
    }

    public function test_hay_un_tope_de_sugerencias(): void
    {
        $base = $this->product();
        $ids = collect(range(1, CrossSellService::MAX_PER_PRODUCT + 1))
            ->map(fn () => $this->product()->id)
            ->all();

        $this->sync($base, $ids)->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Lo que ve la tienda publica
    // -----------------------------------------------------------------

    /**
     * El cajero puede vender algo que no esta publicado; la tienda no. Es la
     * diferencia entre los dos consumidores del mismo servicio.
     */
    public function test_la_tienda_no_sugiere_lo_que_no_esta_publicado(): void
    {
        $base = $this->product();
        $publicado = $this->product(['name' => 'Publicado']);
        $interno = $this->product(['name' => 'Solo mostrador', 'is_published' => false]);

        $this->sync($base, [$publicado->id, $interno->id])->assertOk();

        // Para el mostrador vienen los dos.
        $this->assertCount(2, app(CrossSellService::class)->forProduct($base));

        // Para internet, solo el publicado.
        $response = $this->getJson("/api/v1/storefront/{$this->business->slug}/products/{$base->id}");
        $response->assertOk();
        $this->assertSame(['Publicado'], array_column($response->json('cross_sells'), 'name'));
    }

    /** En la ficha viaja siempre (aunque vacia); en el listado no aplica. */
    public function test_las_cruzadas_solo_viajan_en_la_ficha(): void
    {
        $producto = $this->product();

        $this->getJson("/api/v1/storefront/{$this->business->slug}/products/{$producto->id}")
            ->assertOk()
            ->assertJsonPath('cross_sells', []);

        $listado = $this->getJson("/api/v1/storefront/{$this->business->slug}/products");
        $listado->assertOk();
        $this->assertNull($listado->json('data.0.cross_sells'));
    }

    /**
     * El estatico del Resource sobrevive a toda la peticion: sin limpiarlo,
     * una ficha vista antes dejaba sus sugerencias pegadas al listado.
     */
    public function test_el_listado_no_hereda_las_sugerencias_de_una_ficha(): void
    {
        $base = $this->product();
        $sugerido = $this->product(['name' => 'Sugerido']);
        $this->sync($base, [$sugerido->id])->assertOk();

        $this->getJson("/api/v1/storefront/{$this->business->slug}/products/{$base->id}")->assertOk();

        $listado = $this->getJson("/api/v1/storefront/{$this->business->slug}/products");
        $listado->assertOk();

        foreach ($listado->json('data') as $item) {
            $this->assertNull($item['cross_sells']);
        }
    }
}
