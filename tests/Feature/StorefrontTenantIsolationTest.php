<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * El aislamiento multi-tenant de este repo cuelga del usuario autenticado, y
 * la tienda online es el primer consumidor publico de la API: sus visitantes
 * no tienen sesion. Estas pruebas cubren el reemplazo de esa autoridad
 * (TenantContext + ResolveStorefrontTenant) y son el candado que impide que
 * un endpoint publico devuelva datos de otro negocio.
 */
class StorefrontTenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Una ruta publica minima con el middleware real, para no tener que
        // esperar a que exista el storefront completo para probar la garantia.
        Route::middleware(['api', 'storefront.tenant'])
            ->get('/api/test-storefront/{business}/products', function () {
                return response()->json([
                    'names' => Product::query()->orderBy('name')->pluck('name'),
                ]);
            });
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    /**
     * Negocio con el modulo habilitado Y la tienda publicada: hacen falta los
     * dos interruptores para que el storefront responda (ver
     * ResolveStorefrontTenant).
     */
    private function storefrontBusiness(array $attributes = []): Business
    {
        $business = Business::factory()->create([
            'feature_flags' => ['online_store' => true],
            ...$attributes,
        ]);

        BusinessStoreSettings::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
        ]);

        return $business;
    }

    // -----------------------------------------------------------------
    // La garantia principal: no cruzarse de negocio
    // -----------------------------------------------------------------

    public function test_storefront_only_returns_products_of_the_business_in_the_url(): void
    {
        $mine = $this->storefrontBusiness();
        $other = $this->storefrontBusiness();

        Product::factory()->create(['business_id' => $mine->id, 'name' => 'Camiseta propia']);
        Product::factory()->create(['business_id' => $other->id, 'name' => 'Camiseta ajena']);

        $this->getJson("/api/test-storefront/{$mine->slug}/products")
            ->assertOk()
            ->assertJsonPath('names', ['Camiseta propia']);
    }

    public function test_product_of_another_business_is_invisible_through_a_foreign_slug(): void
    {
        $mine = $this->storefrontBusiness();
        $other = $this->storefrontBusiness();
        $foreignProduct = Product::factory()->create(['business_id' => $other->id]);

        TenantContext::set($mine);

        $this->assertNull(Product::find($foreignProduct->id));
    }

    public function test_scoping_applies_without_any_authenticated_user(): void
    {
        $business = $this->storefrontBusiness();
        $mineId = Product::factory()->create(['business_id' => $business->id])->id;
        $foreignId = Product::factory()->create()->id;

        $this->assertGuest();
        TenantContext::set($business);

        $this->assertSame([$mineId], Product::query()->pluck('id')->all());
        $this->assertNotContains($foreignId, Product::query()->pluck('id')->all());
    }

    public function test_creating_a_record_takes_the_business_id_from_the_tenant_context(): void
    {
        $business = $this->storefrontBusiness();
        $intruder = Business::factory()->create();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $attributes = Product::factory()->make([
            'business_id' => $intruder->id,
            'category_id' => $category->id,
            'name' => 'Producto creado por la tienda',
        ])->getAttributes();

        TenantContext::set($business);

        // business_id esta en fillable: un payload crudo no puede colar otro tenant.
        $product = Product::create($attributes);

        $this->assertSame($business->id, $product->business_id);
    }

    // -----------------------------------------------------------------
    // Precedencia y comportamiento existente
    // -----------------------------------------------------------------

    public function test_the_explicit_tenant_wins_over_the_authenticated_user(): void
    {
        // Un empleado con sesion abierta que navega la tienda publica de otro
        // comercio tiene que ver ESE catalogo, no el suyo.
        $employerBusiness = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $employerBusiness->id]);
        $storefront = $this->storefrontBusiness();

        $this->actingAs($employee, 'sanctum');
        TenantContext::set($storefront);

        $this->assertSame($storefront->id, TenantContext::businessId());
    }

    public function test_without_an_explicit_tenant_the_session_still_rules(): void
    {
        // Las rutas normales del POS no fijan tenant: ahi manda la sesion,
        // igual que siempre.
        $employerBusiness = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $employerBusiness->id]);

        $this->actingAs($employee, 'sanctum');

        $this->assertSame($employerBusiness->id, TenantContext::businessId());
    }

    public function test_without_user_and_without_tenant_nothing_is_scoped(): void
    {
        // Comportamiento deliberado y preexistente: comandos, jobs y seeders
        // ven todos los negocios. Cambiarlo romperia el scheduler.
        Product::factory()->create();
        Product::factory()->create();

        $this->assertNull(TenantContext::businessId());
        $this->assertGreaterThanOrEqual(2, Product::query()->count());
    }

    // -----------------------------------------------------------------
    // Visibilidad de la tienda: siempre 404, nunca 403
    // -----------------------------------------------------------------

    public function test_unknown_slug_returns_404(): void
    {
        $this->getJson('/api/test-storefront/no-existe-este-negocio/products')->assertNotFound();
    }

    public function test_business_without_the_online_store_feature_returns_404(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['online_store' => false]]);

        $this->getJson("/api/test-storefront/{$business->slug}/products")->assertNotFound();
    }

    public function test_inactive_business_returns_404(): void
    {
        $business = $this->storefrontBusiness(['active' => false]);

        $this->getJson("/api/test-storefront/{$business->slug}/products")->assertNotFound();
    }

    public function test_soft_deleted_business_returns_404(): void
    {
        $business = $this->storefrontBusiness();
        $slug = $business->slug;
        $business->delete();

        $this->getJson("/api/test-storefront/{$slug}/products")->assertNotFound();
    }

    // -----------------------------------------------------------------
    // La tienda nunca se enciende sola
    // -----------------------------------------------------------------

    public function test_legacy_business_without_feature_flags_does_not_get_the_online_store(): void
    {
        // Los negocios mas antiguos no tienen JSON de flags y hasFeature() les
        // devuelve true para todo. Un modulo que publica el catalogo en
        // internet no puede heredar esa permisividad.
        $legacy = Business::factory()->create(['feature_flags' => null]);

        $this->assertTrue($legacy->hasFeature('inventory'));
        $this->assertFalse($legacy->hasFeature('online_store'));

        $this->getJson("/api/test-storefront/{$legacy->slug}/products")->assertNotFound();
    }

    public function test_online_store_is_off_by_default_in_both_plans(): void
    {
        $this->assertFalse(BusinessFeaturePresets::basic()['online_store']);
        $this->assertFalse(BusinessFeaturePresets::full()['online_store']);
    }

    public function test_business_with_flags_but_without_the_key_falls_back_to_the_plan_default(): void
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => ['inventory' => true],
        ]);

        $this->assertFalse($business->hasFeature('online_store'));
    }

    public function test_superadmin_can_turn_the_online_store_on_for_a_business(): void
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => BusinessFeaturePresets::full(),
        ]);
        $superadmin = User::factory()->create(['business_id' => null]);
        $superadmin->assignRole('superadmin');

        $this->actingAs($superadmin, 'sanctum')
            ->patchJson("/api/v1/superadmin/businesses/{$business->id}/config", [
                'subscription_plan' => 'full',
                'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
            ])
            ->assertOk();

        $this->assertTrue($business->fresh()->hasFeature('online_store'));
    }
}
