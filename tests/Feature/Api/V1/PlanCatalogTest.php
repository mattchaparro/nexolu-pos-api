<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Services\SubscriptionPricingService;
use App\Support\SystemConfigStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PlanCatalogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_returns_the_feature_catalog_and_plan_prices_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertOk();
        $this->assertCount(26, $response->json('features'));
        $this->assertSame(65000, $response->json('plans.basic.price_cop'));
        $this->assertSame(85000, $response->json('plans.full.price_cop'));
    }

    public function test_exposes_what_the_public_home_announces(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertOk();
        $this->assertSame(Business::TRIAL_DAYS, $response->json('trial_days'));
        $this->assertSame(15, $response->json('annual_discount_percent'));
        $this->assertSame(
            app(SubscriptionPricingService::class)->currentPromo(),
            $response->json('promo'),
        );
    }

    public function test_promo_and_annual_discount_follow_the_superadmin_settings(): void
    {
        SystemConfigStore::putMany([
            'plans.annual_discount_percent' => 20,
            'marketing.promo_discount_percent' => 30,
            'marketing.promo_months' => 3,
        ]);

        $response = $this->getJson('/api/v1/plans');

        $this->assertSame(20, $response->json('annual_discount_percent'));
        $this->assertSame(['discount_percent' => 30, 'months' => 3], $response->json('promo'));
    }
}
