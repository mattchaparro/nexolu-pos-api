<?php

namespace Tests\Feature\Support;

use App\Support\BusinessFeaturePresets;
use App\Support\SystemConfigStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BusinessFeaturePresetsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_plan_price_cop_defaults_when_nothing_configured(): void
    {
        $this->assertSame(85000, BusinessFeaturePresets::planPriceCop('full'));
        $this->assertSame(65000, BusinessFeaturePresets::planPriceCop('basic'));
    }

    public function test_plan_price_cop_reflects_a_system_config_override(): void
    {
        SystemConfigStore::putMany([
            'plans.full_price_cop' => 99000,
            'plans.basic_price_cop' => 70000,
        ]);

        $this->assertSame(99000, BusinessFeaturePresets::planPriceCop('full'));
        $this->assertSame(70000, BusinessFeaturePresets::planPriceCop('basic'));
    }

    public function test_catalog_covers_every_key_from_basic_and_full_exactly_once(): void
    {
        $catalog = BusinessFeaturePresets::catalog();
        $keys = collect($catalog)->pluck('key');

        $this->assertEqualsCanonicalizing(array_keys(BusinessFeaturePresets::basic()), $keys->all());
        $this->assertSame($keys->count(), $keys->unique()->count());
    }

    public function test_catalog_entries_match_the_basic_and_full_preset_values(): void
    {
        $catalog = collect(BusinessFeaturePresets::catalog())->keyBy('key');
        $basic = BusinessFeaturePresets::basic();
        $full = BusinessFeaturePresets::full();

        foreach ($basic as $key => $value) {
            $this->assertSame($value, $catalog[$key]['basic'], "basic mismatch for {$key}");
            $this->assertSame($full[$key], $catalog[$key]['full'], "full mismatch for {$key}");
            $this->assertNotEmpty($catalog[$key]['label']);
            $this->assertNotEmpty($catalog[$key]['description']);
            $this->assertNotEmpty($catalog[$key]['group']);
        }
    }
}
