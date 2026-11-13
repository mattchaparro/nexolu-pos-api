<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Página de enlaces (la de la bio de Instagram/TikTok): para todos los
 * negocios, con o sin tienda online.
 */
class LinkPageTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Business, 1: User} */
    private function owner(bool $onlineStore = false, array $attributes = []): array
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => $onlineStore],
            ...$attributes,
        ]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $owner->assignRole('admin');

        return [$business, $owner];
    }

    public function test_a_business_without_online_store_publishes_its_page_with_what_it_has(): void
    {
        [$business, $owner] = $this->owner(false, [
            'whatsapp_number' => '3001234567',
            'instagram_handle' => '@mitienda',
            'tiktok_handle' => 'mitienda',
            'address' => 'Calle 10 # 5-20',
        ]);

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/link-page', [
            'enabled' => true,
            'bio_links' => [['label' => 'Pide por Rappi', 'url' => 'https://www.rappi.com.co/mitienda']],
        ])
            ->assertOk()
            ->assertJsonPath('published', true)
            ->assertJsonPath('has_store', false)
            ->assertJsonPath('public_url', rtrim((string) config('app.storefront_url'), '/')."/{$business->slug}/enlaces");

        $this->getJson("/api/v1/link-page/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('name', $business->name)
            ->assertJsonPath('has_store', false)
            ->assertJsonPath('has_whatsapp', true)
            ->assertJsonPath('instagram_url', 'https://www.instagram.com/mitienda')
            ->assertJsonPath('tiktok_url', 'https://www.tiktok.com/@mitienda')
            ->assertJsonPath('address', 'Calle 10 # 5-20')
            ->assertJsonPath('bio_links.0.label', 'Pide por Rappi')
            // Nada interno del negocio sale a internet.
            ->assertJsonMissingPath('nit')
            ->assertJsonMissingPath('phone');
    }

    /**
     * Sin publicar responde 404 igual que un slug inexistente: si no, se
     * podría listar quién es cliente de Nexolú probando nombres.
     */
    public function test_an_unpublished_page_is_indistinguishable_from_a_missing_one(): void
    {
        [$business] = $this->owner(false, ['whatsapp_number' => '3001234567']);

        $this->getJson("/api/v1/link-page/{$business->slug}")->assertNotFound();
        $this->getJson('/api/v1/link-page/no-existe-este-negocio')->assertNotFound();
    }

    public function test_an_open_store_publishes_the_page_and_its_data_wins(): void
    {
        [$business] = $this->owner(true, ['whatsapp_number' => '3000000000', 'instagram_handle' => 'viejo']);
        BusinessStoreSettings::create([
            'business_id' => $business->id,
            'is_active' => true,
            'store_name' => 'Mi Tienda',
            'instagram_url' => 'https://www.instagram.com/mitienda.oficial',
        ]);

        $this->getJson("/api/v1/link-page/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('name', 'Mi Tienda')
            ->assertJsonPath('has_store', true)
            ->assertJsonPath('instagram_url', 'https://www.instagram.com/mitienda.oficial');
    }

    public function test_an_inactive_business_has_no_page(): void
    {
        [$business, $owner] = $this->owner();
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/link-page', ['enabled' => true])->assertOk();
        $business->update(['active' => false]);

        $this->getJson("/api/v1/link-page/{$business->slug}")->assertNotFound();
    }

    public function test_a_bio_link_that_is_not_http_is_rejected(): void
    {
        [, $owner] = $this->owner();

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/link-page', [
            'bio_links' => [['label' => 'Truco', 'url' => 'javascript:alert(1)']],
        ])->assertUnprocessable()->assertJsonValidationErrors('bio_links.0.url');
    }

    public function test_bio_links_are_capped(): void
    {
        [, $owner] = $this->owner();

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/link-page', [
            'bio_links' => array_fill(0, BusinessStoreSettings::MAX_BIO_LINKS + 1, [
                'label' => 'Enlace',
                'url' => 'https://example.com',
            ]),
        ])->assertUnprocessable()->assertJsonValidationErrors('bio_links');
    }

    public function test_an_employee_cannot_change_the_page(): void
    {
        [$business] = $this->owner();
        $employee = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($employee, 'sanctum')
            ->putJson('/api/v1/link-page', ['enabled' => true])
            ->assertForbidden();
    }

    /**
     * Sin tienda online también se personaliza: nombre, descripción, colores,
     * tipografía y redes. Son los mismos campos del tema de la tienda.
     */
    public function test_a_business_without_store_customizes_its_page(): void
    {
        [$business, $owner] = $this->owner();

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/link-page', [
            'enabled' => true,
            'name' => 'Uñas de Ana',
            'description' => 'Uñas, cejas y pestañas en Sibaté.',
            'primary_color' => '#be185d',
            'surface_color' => '#fdf2f8',
            'font_preset' => 'calida',
            'instagram' => '@unasdeana',
            'tiktok' => 'unasdeana',
            'whatsapp' => '3001234567',
            'facebook_url' => 'https://www.facebook.com/unasdeana',
        ])
            ->assertOk()
            ->assertJsonPath('settings.instagram', '@unasdeana')
            ->assertJsonPath('settings.font_preset', 'calida');

        $this->getJson("/api/v1/link-page/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('name', 'Uñas de Ana')
            ->assertJsonPath('description', 'Uñas, cejas y pestañas en Sibaté.')
            ->assertJsonPath('theme.primary', '#be185d')
            ->assertJsonPath('theme.surface', '#fdf2f8')
            ->assertJsonPath('theme.font', 'calida')
            ->assertJsonPath('instagram_url', 'https://www.instagram.com/unasdeana')
            ->assertJsonPath('tiktok_url', 'https://www.tiktok.com/@unasdeana')
            ->assertJsonPath('facebook_url', 'https://www.facebook.com/unasdeana')
            ->assertJsonPath('has_whatsapp', true);

        // Las redes quedan en el negocio: salen también en Ajustes.
        $this->assertSame('@unasdeana', $business->fresh()->instagram_handle);
    }

    /** Con tienda, su propio Instagram ganaba y el cambio no se veía. */
    public function test_changing_instagram_also_updates_the_store_one(): void
    {
        [$business, $owner] = $this->owner(true);
        BusinessStoreSettings::create([
            'business_id' => $business->id,
            'is_active' => true,
            'instagram_url' => 'https://www.instagram.com/viejo',
        ]);

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/link-page', ['instagram' => 'nuevo'])->assertOk();

        $this->getJson("/api/v1/link-page/{$business->slug}")
            ->assertJsonPath('instagram_url', 'https://www.instagram.com/nuevo');
    }

    public function test_a_colour_that_is_not_a_hex_is_rejected(): void
    {
        [, $owner] = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/link-page', ['primary_color' => 'red; background:url(x)'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('primary_color');
    }

    public function test_the_whatsapp_button_counts_the_click_and_goes_to_the_saved_number(): void
    {
        [$business, $owner] = $this->owner(false, ['whatsapp_number' => '300 123 4567']);
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/link-page', ['enabled' => true])->assertOk();

        $response = $this->get("/api/v1/link-page/{$business->slug}/whatsapp");

        $response->assertRedirect();
        $this->assertStringStartsWith('https://wa.me/573001234567?text=', $response->headers->get('Location'));
        $this->assertDatabaseHas('business_store_interactions', [
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'context' => 'bio',
        ]);
    }
}
