<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Configuracion de la tienda del lado del comerciante: abrirla, cerrarla y
 * editar su identidad publica.
 */
class BusinessStoreSettingsTest extends TestCase
{
    /** @return array{0: Business, 1: User} */
    private function ownerWithModule(bool $enabled = true): array
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => $enabled],
        ]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $owner->assignRole('admin');

        return [$business, $owner];
    }

    use DatabaseTransactions;

    public function test_reading_the_settings_creates_them_closed_the_first_time(): void
    {
        [$business, $owner] = $this->ownerWithModule();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/store-settings')
            ->assertOk()
            // Nace cerrada: habilitar el modulo no publica la tienda.
            ->assertJsonPath('is_active', false);

        $this->assertDatabaseHas('business_store_settings', [
            'business_id' => $business->id,
            'is_active' => false,
        ]);
    }

    public function test_the_owner_can_open_and_close_the_store(): void
    {
        [$business, $owner] = $this->ownerWithModule();

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/store-settings', ['is_active' => true, 'store_name' => 'Mi Tiendita'])
            ->assertOk()
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('store_name', 'Mi Tiendita');

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/store-settings', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_the_public_url_is_built_from_the_slug(): void
    {
        [$business, $owner] = $this->ownerWithModule();
        config(['app.storefront_url' => 'https://tienda.nexolu.co']);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/store-settings')
            ->assertOk()
            ->assertJsonPath('public_url', "https://tienda.nexolu.co/{$business->slug}");
    }

    public function test_it_counts_the_published_products(): void
    {
        [$business, $owner] = $this->ownerWithModule();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        Product::factory()->count(2)->create([
            'business_id' => $business->id, 'category_id' => $category->id,
            'is_published' => true, 'is_active' => true,
        ]);
        Product::factory()->create([
            'business_id' => $business->id, 'category_id' => $category->id,
            'is_published' => false, 'is_active' => true,
        ]);
        // Publicado pero pausado en el POS: no cuenta, porque no se ve.
        Product::factory()->create([
            'business_id' => $business->id, 'category_id' => $category->id,
            'is_published' => true, 'is_active' => false,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/store-settings')
            ->assertOk()
            ->assertJsonPath('published_products_count', 2);
    }

    public function test_a_colour_that_is_not_a_hex_is_rejected(): void
    {
        // El color va directo a un style del storefront.
        [$business, $owner] = $this->ownerWithModule();

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/store-settings', ['primary_color' => 'red; background:url(evil)'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('primary_color');
    }

    public function test_a_business_without_the_module_gets_403(): void
    {
        [$business, $owner] = $this->ownerWithModule(enabled: false);

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/store-settings')->assertForbidden();
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/store-settings', ['is_active' => true])->assertForbidden();
    }

    public function test_an_employee_cannot_open_the_store(): void
    {
        // Abrir la tienda al publico es decision del dueño, no del cajero.
        [$business] = $this->ownerWithModule();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->putJson('/api/v1/store-settings', ['is_active' => true])
            ->assertForbidden();
    }

    public function test_each_business_only_sees_its_own_settings(): void
    {
        [$mine, $owner] = $this->ownerWithModule();
        [$other, $otherOwner] = $this->ownerWithModule();

        $this->actingAs($otherOwner, 'sanctum')
            ->putJson('/api/v1/store-settings', ['store_name' => 'La del otro'])->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/store-settings')
            ->assertOk()
            ->assertJsonPath('store_name', null);
    }

    public function test_the_theme_falls_back_to_defaults_when_nothing_was_chosen(): void
    {
        // El storefront deriva toda la paleta de estas tres semillas, asi que
        // nunca puede recibirlas a medias.
        [$business, $owner] = $this->ownerWithModule();
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/store-settings', ['is_active' => true])->assertOk();

        $this->getJson("/api/v1/storefront/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('theme.primary', BusinessStoreSettings::DEFAULT_PRIMARY)
            ->assertJsonPath('theme.surface', BusinessStoreSettings::DEFAULT_SURFACE)
            ->assertJsonPath('theme.accent', BusinessStoreSettings::DEFAULT_ACCENT)
            ->assertJsonPath('theme.font', 'moderna');
    }

    public function test_an_unknown_font_preset_is_rejected(): void
    {
        [$business, $owner] = $this->ownerWithModule();

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/store-settings', ['font_preset' => 'comic-sans'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('font_preset');
    }

    /**
     * El home paso de tres ranuras fijas (hero/trust/story, siempre en ese
     * orden) a una LISTA ordenada de bloques -- ver StoreHomeBlocks. Este
     * test describia el contrato viejo; el nuevo vive en
     * StoreHomeBlocksTest, y aca queda el humo de que sigue publicandose.
     */
    public function test_home_blocks_are_saved_and_published(): void
    {
        [$business, $owner] = $this->ownerWithModule();

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/store-settings', [
            'is_active' => true,
            'home_blocks' => [
                ['id' => 'b1', 'type' => 'hero', 'title' => 'Café de finca, tostado cerca de ti', 'highlight' => 'cerca de ti'],
                ['id' => 'b2', 'type' => 'trust', 'items' => [
                    ['icon' => 'truck', 'title' => 'Envío a domicilio', 'text' => 'Entrega en 24-48h.'],
                    ['icon' => 'store', 'title' => 'Recogida en tienda', 'text' => 'Calle 45 #12-30.'],
                ]],
                ['id' => 'b3', 'type' => 'story', 'title' => 'Compramos directo', 'stats' => [['value' => '3', 'label' => 'Fincas aliadas']]],
            ],
        ])->assertOk();

        $this->getJson("/api/v1/storefront/{$business->slug}")
            ->assertOk()
            ->assertJsonCount(3, 'home_blocks')
            ->assertJsonPath('home_blocks.0.highlight', 'cerca de ti')
            ->assertJsonCount(2, 'home_blocks.1.items')
            ->assertJsonPath('home_blocks.2.stats.0.label', 'Fincas aliadas');
    }

    /**
     * Un bloque apagado no llega al comprador. (Antes esto se probaba con
     * "encendido pero vacio"; con la lista, un bloque sin contenido
     * directamente no se agrega.)
     */
    public function test_a_disabled_block_is_not_published(): void
    {
        [$business, $owner] = $this->ownerWithModule();

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/store-settings', [
            'is_active' => true,
            'home_blocks' => [
                ['id' => 'b1', 'type' => 'cta', 'title' => 'Visible'],
                ['id' => 'b2', 'type' => 'cta', 'title' => 'Apagado', 'enabled' => false],
            ],
        ])->assertOk();

        $this->getJson("/api/v1/storefront/{$business->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'home_blocks')
            ->assertJsonPath('home_blocks.0.title', 'Visible');
    }

    public function test_the_trust_strip_is_capped_at_three_items(): void
    {
        [$business, $owner] = $this->ownerWithModule();

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/store-settings', [
            'trust_items' => array_fill(0, 4, ['icon' => 'truck', 'title' => 'X', 'text' => 'Y']),
        ])->assertUnprocessable()->assertJsonValidationErrors('trust_items');
    }

    public function test_uploading_a_logo_stores_it_and_replaces_the_previous_one(): void
    {
        Storage::fake('public');
        [$business, $owner] = $this->ownerWithModule();

        $first = $this->actingAs($owner, 'sanctum')
            ->post('/api/v1/store-settings/images/logo', ['image' => UploadedFile::fake()->image('logo.jpg', 900, 900)])
            ->assertOk()
            ->json('logo_url');
        $this->assertNotNull($first);

        $settings = BusinessStoreSettings::withoutGlobalScopes()->where('business_id', $business->id)->firstOrFail();
        $oldPath = $settings->logo_path;
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAs($owner, 'sanctum')
            ->post('/api/v1/store-settings/images/logo', ['image' => UploadedFile::fake()->image('otro.png', 600, 600)])
            ->assertOk();

        // La anterior se borra: nadie va a limpiar el disco a mano.
        Storage::disk('public')->assertMissing($oldPath);
        $this->assertNotSame($oldPath, $settings->fresh()->logo_path);
    }

    public function test_an_unknown_image_slot_is_rejected(): void
    {
        Storage::fake('public');
        [$business, $owner] = $this->ownerWithModule();

        $this->actingAs($owner, 'sanctum')
            ->post('/api/v1/store-settings/images/favicon', ['image' => UploadedFile::fake()->image('x.jpg')])
            ->assertNotFound();
    }

    public function test_publishing_a_product_goes_through_the_normal_update(): void
    {
        [$business, $owner] = $this->ownerWithModule();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create([
            'business_id' => $business->id, 'category_id' => $category->id, 'is_published' => false,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}", [
                'name' => $product->name,
                'price' => $product->price,
                'category_id' => $category->id,
                'is_published' => true,
                'online_description' => 'Texto largo para la ficha publica.',
            ])
            ->assertOk()
            ->assertJsonPath('is_published', true)
            ->assertJsonPath('online_description', 'Texto largo para la ficha publica.');

        $this->assertTrue($product->fresh()->is_published);
    }
}
