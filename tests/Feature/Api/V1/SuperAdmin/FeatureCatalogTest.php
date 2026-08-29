<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Support\SystemConfigStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class FeatureCatalogTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_returns_the_full_feature_catalog_with_plan_prices(): void
    {
        $response = $this->actingAs($this->superadmin(), 'sanctum')
            ->getJson('/api/v1/superadmin/feature-catalog');

        $response->assertOk();
        $this->assertCount(23, $response->json('features'));

        $openTabs = collect($response->json('features'))->firstWhere('key', 'open_tabs');
        $this->assertSame('POS y ventas', $openTabs['group']);
        $this->assertFalse($openTabs['basic']);
        $this->assertTrue($openTabs['full']);

        // La tienda online viene con el plan Full y no existe en Basico.
        $onlineStore = collect($response->json('features'))->firstWhere('key', 'online_store');
        $this->assertSame('Canales de venta', $onlineStore['group']);
        $this->assertFalse($onlineStore['basic']);
        $this->assertTrue($onlineStore['full']);

        // Variaciones sigue siendo exclusiva de Full: es lo que el wizard de
        // registro usa para recomendar el plan a quien vende por talla/color.
        $variants = collect($response->json('features'))->firstWhere('key', 'variants');
        $this->assertFalse($variants['basic']);
        $this->assertTrue($variants['full']);

        $this->assertSame(65000, $response->json('plans.basic.price_cop'));
        $this->assertSame(85000, $response->json('plans.full.price_cop'));
    }

    public function test_reflects_a_system_config_price_override(): void
    {
        SystemConfigStore::putMany(['plans.full_price_cop' => 99000]);

        $response = $this->actingAs($this->superadmin(), 'sanctum')
            ->getJson('/api/v1/superadmin/feature-catalog');

        $response->assertOk();
        $this->assertSame(99000, $response->json('plans.full.price_cop'));
    }

    public function test_requires_superadmin(): void
    {
        $this->getJson('/api/v1/superadmin/feature-catalog')->assertStatus(401);
    }
}
