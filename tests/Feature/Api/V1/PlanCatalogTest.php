<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PlanCatalogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_returns_the_feature_catalog_and_plan_prices_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertOk();
        $this->assertCount(24, $response->json('features'));
        $this->assertSame(65000, $response->json('plans.basic.price_cop'));
        $this->assertSame(85000, $response->json('plans.full.price_cop'));
    }
}
