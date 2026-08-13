<?php

namespace Tests\Feature\Support;

use App\Support\AiQuotaSettings;
use App\Support\SystemConfigStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AiQuotaSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_defaults_come_from_config_when_no_override_is_stored(): void
    {
        $this->assertSame(300, AiQuotaSettings::monthlyIncludedMessages());
        $this->assertSame(1000, AiQuotaSettings::packSize());
        $this->assertSame(15000, AiQuotaSettings::packPriceCop());
        $this->assertSame(0.6, AiQuotaSettings::employeeQuotaShare());
    }

    public function test_system_config_override_wins_over_config_default(): void
    {
        SystemConfigStore::putMany([
            'ai.monthly_included_messages' => 500,
            'ai.pack_size' => 2000,
            'ai.pack_price_cop' => 20000,
        ]);

        $this->assertSame(500, AiQuotaSettings::monthlyIncludedMessages());
        $this->assertSame(2000, AiQuotaSettings::packSize());
        $this->assertSame(20000, AiQuotaSettings::packPriceCop());
    }

    public function test_employee_quota_share_is_clamped_between_point_one_and_one(): void
    {
        config(['ai.addon.employee_daily_share' => 0.05]);
        $this->assertSame(0.1, AiQuotaSettings::employeeQuotaShare());

        config(['ai.addon.employee_daily_share' => 1.5]);
        $this->assertSame(1.0, AiQuotaSettings::employeeQuotaShare());
    }
}
