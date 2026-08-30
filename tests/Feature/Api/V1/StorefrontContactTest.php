<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreInteraction;
use App\Models\BusinessStoreSettings;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El boton de WhatsApp de la tienda pasa por la API antes de ir a wa.me,
 * para poder contarlo.
 */
class StorefrontContactTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);
        BusinessStoreSettings::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'whatsapp_number' => '3001234567',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_a_click_is_counted_and_redirected_to_whatsapp(): void
    {
        $this->get("/api/v1/storefront/{$this->business->slug}/whatsapp")
            ->assertRedirectContains('wa.me/573001234567');

        $this->assertDatabaseHas('business_store_interactions', [
            'business_id' => $this->business->id,
            'type' => BusinessStoreInteraction::TYPE_WHATSAPP,
            'context' => 'home',
        ]);
    }

    public function test_the_context_says_where_the_click_came_from(): void
    {
        $this->get("/api/v1/storefront/{$this->business->slug}/whatsapp?context=product:Camiseta+azul")
            ->assertRedirectContains('wa.me/');

        $this->assertDatabaseHas('business_store_interactions', [
            'business_id' => $this->business->id,
            'context' => 'product:Camiseta azul',
        ]);
    }

    /**
     * La guarda que de verdad importa: el destino se arma con el numero
     * GUARDADO del negocio. Aceptarlo por parametro convertiria una URL de
     * tienda.nexolu.co en un redirector abierto.
     */
    public function test_the_destination_cannot_be_chosen_by_the_caller(): void
    {
        $response = $this->get(
            "/api/v1/storefront/{$this->business->slug}/whatsapp?url=https://sitio-malicioso.test&phone=573009999999"
        );

        $response->assertRedirectContains('wa.me/573001234567');
        $this->assertStringNotContainsString('sitio-malicioso', (string) $response->headers->get('Location'));
    }

    public function test_a_context_with_a_strange_shape_is_rejected(): void
    {
        $this->getJson("/api/v1/storefront/{$this->business->slug}/whatsapp?context=".urlencode('<script>alert(1)</script>'))
            ->assertUnprocessable();

        $this->assertDatabaseCount('business_store_interactions', 0);
    }

    public function test_a_store_without_whatsapp_has_nowhere_to_send(): void
    {
        BusinessStoreSettings::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->update(['whatsapp_number' => null]);

        $this->get("/api/v1/storefront/{$this->business->slug}/whatsapp")->assertNotFound();
        $this->assertDatabaseCount('business_store_interactions', 0);
    }

    public function test_a_closed_store_does_not_record_anything(): void
    {
        BusinessStoreSettings::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->update(['is_active' => false]);

        $this->get("/api/v1/storefront/{$this->business->slug}/whatsapp")->assertNotFound();
        $this->assertDatabaseCount('business_store_interactions', 0);
    }
}
