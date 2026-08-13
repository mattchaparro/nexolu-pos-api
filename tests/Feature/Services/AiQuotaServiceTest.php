<?php

namespace Tests\Feature\Services;

use App\Exceptions\AiQuotaExceededException;
use App\Exceptions\AiSubscriptionExpiredException;
use App\Models\AiUsageDaily;
use App\Models\Business;
use App\Models\User;
use App\Services\AiQuotaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AiQuotaServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AiQuotaService $quota;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quota = app(AiQuotaService::class);
        config(['ai.addon.monthly_included_messages' => 10, 'ai.addon.employee_daily_share' => 0.6]);
    }

    public function test_assert_access_throws_when_the_plan_is_expired(): void
    {
        $business = Business::factory()->create(['trial_ends_at' => now()->subDay(), 'paid_until' => null]);

        $this->expectException(AiSubscriptionExpiredException::class);
        $this->quota->assertAccess($business->fresh());
    }

    public function test_assert_access_passes_for_a_business_on_trial(): void
    {
        $business = Business::factory()->create()->fresh();

        $this->quota->assertAccess($business);
        $this->addToAssertionCount(1);
    }

    public function test_consume_message_creates_todays_usage_row_and_increments_it(): void
    {
        $business = Business::factory()->create()->fresh();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->quota->consumeMessage($business, $admin);

        $this->assertDatabaseHas('ai_usage_daily', [
            'business_id' => $business->id,
            'date' => now()->toDateString(),
            'messages_count' => 1,
        ]);
    }

    public function test_employee_quota_is_capped_at_the_configured_share_with_a_floor_of_one(): void
    {
        // monthly = 10, share = 0.6 -> floor(6) = 6 mensajes disponibles para empleados.
        $business = Business::factory()->create()->fresh();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        for ($i = 0; $i < 6; $i++) {
            $this->quota->consumeMessage($business, $employee);
        }

        // El 7mo mensaje ya supera el 60% reservado -> cae a paquetes, sin balance -> excepcion.
        $this->expectException(AiQuotaExceededException::class);
        $this->quota->consumeMessage($business, $employee);
    }

    public function test_admin_can_use_the_full_monthly_quota_even_after_employees_exhausted_their_share(): void
    {
        $business = Business::factory()->create()->fresh();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        for ($i = 0; $i < 6; $i++) {
            $this->quota->consumeMessage($business, $employee);
        }

        // El dueno conserva el resto del cupo mensual (10) aunque los empleados ya gastaron su 60%.
        $this->quota->consumeMessage($business, $admin->fresh());
        $this->assertDatabaseHas('ai_usage_daily', [
            'business_id' => $business->id,
            'date' => now()->toDateString(),
            'messages_count' => 7,
        ]);
    }

    public function test_falls_back_to_the_message_pack_balance_once_the_monthly_quota_is_exhausted(): void
    {
        config(['ai.addon.monthly_included_messages' => 1]);
        $business = Business::factory()->create(['ai_message_pack_balance' => 5])->fresh();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->quota->consumeMessage($business, $admin); // consume el cupo mensual (1)
        $this->quota->consumeMessage($business, $admin); // cae a paquetes

        $this->assertSame(4, $business->fresh()->ai_message_pack_balance);
    }

    public function test_throws_a_quota_exceeded_exception_with_a_purchase_hint_for_admins(): void
    {
        config(['ai.addon.monthly_included_messages' => 0]);
        $business = Business::factory()->create(['ai_message_pack_balance' => 0])->fresh();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        try {
            $this->quota->consumeMessage($business, $admin);
            $this->fail('Se esperaba AiQuotaExceededException.');
        } catch (AiQuotaExceededException $e) {
            $this->assertStringContainsString('Compra un paquete', $e->getMessage());
        }
    }

    public function test_throws_a_quota_exceeded_exception_pointing_employees_to_the_owner(): void
    {
        config(['ai.addon.monthly_included_messages' => 0]);
        $business = Business::factory()->create(['ai_message_pack_balance' => 0])->fresh();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        // El piso de 1 mensaje siempre aplica, incluso con cupo mensual en 0.
        $this->quota->consumeMessage($business, $employee);

        try {
            $this->quota->consumeMessage($business, $employee);
            $this->fail('Se esperaba AiQuotaExceededException.');
        } catch (AiQuotaExceededException $e) {
            $this->assertStringContainsString('dueno del negocio', $e->getMessage());
        }
    }

    public function test_a_per_business_override_wins_over_the_global_default(): void
    {
        $business = Business::factory()->create(['ai_chat_daily_messages' => 2])->fresh();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $state = $this->quota->state($business, $admin);

        $this->assertSame(2, $state['monthly_quota']);
    }

    public function test_state_reports_consumed_remaining_and_pack_balance(): void
    {
        $business = Business::factory()->create(['ai_message_pack_balance' => 42])->fresh();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        AiUsageDaily::factory()->create([
            'business_id' => $business->id,
            'date' => now()->toDateString(),
            'messages_count' => 3,
        ]);

        $state = $this->quota->state($business, $admin);

        $this->assertSame(10, $state['monthly_quota']);
        $this->assertSame(10, $state['applicable_quota']);
        $this->assertSame(3, $state['consumed_this_month']);
        $this->assertSame(7, $state['remaining_quota']);
        $this->assertSame(42, $state['pack_balance']);
        $this->assertSame(1000, $state['pack_size']);
        $this->assertSame(15000, $state['pack_price_cop']);
        $this->assertTrue($state['is_admin']);
    }
}
