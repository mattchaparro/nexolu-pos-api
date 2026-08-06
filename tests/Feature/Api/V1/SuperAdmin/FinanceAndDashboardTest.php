<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\SaasSubscriptionPayment;
use App\Models\WhatsAppUsageDaily;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class FinanceAndDashboardTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Sin esto, PlatformFinanceService::expensesCop() intenta un GET real
        // a services.ia_core.base_url (costo de IA) en cada test de esta
        // clase - se deja explicito y controlado, igual que el resto de la
        // suite hace con el IA Core. Cada test que llega al endpoint de
        // Finance registra su propio Http::fake(): Http::fake() APILA
        // stubs (Factory::fake() hace stubUrl() -> merge, no reemplaza), asi
        // que un fake por defecto aca en setUp() ganaria siempre sobre el
        // que un test especifico quisiera registrar despues para el mismo patron.
        config(['services.ia_core.platform_api_key' => 'test-platform-key']);
    }

    private function fakePlatformUsage(array $breakdown = []): void
    {
        Http::fake(['*/v1/platform/usage*' => Http::response(['breakdown' => $breakdown], 200)]);
    }

    public function test_finance_summary_sums_payments_for_the_requested_month(): void
    {
        $this->fakePlatformUsage();
        $admin = $this->superadmin();
        $business = Business::factory()->create(['paid_until' => now()->addDays(20), 'subscription_plan' => 'full']);
        SaasSubscriptionPayment::factory()->create([
            'business_id' => $business->id,
            'amount_cop' => 65000,
            'paid_at' => now()->startOfMonth()->addDays(2),
        ]);
        SaasSubscriptionPayment::factory()->create([
            'business_id' => $business->id,
            'amount_cop' => 20000,
            'paid_at' => now()->subMonths(2), // fuera del mes consultado
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/finance?year='.now()->year.'&month='.now()->month);

        $response->assertOk()
            ->assertJsonPath('summary.income.total_cop', 65000)
            ->assertJsonPath('summary.income.count', 1)
            ->assertJsonPath('mrr_cop', 85000);
    }

    public function test_finance_summary_includes_expenses_and_margin_valued_at_the_fallback_rate(): void
    {
        $this->fakePlatformUsage();
        $admin = $this->superadmin();
        WhatsAppUsageDaily::create([
            'business_id' => null, 'date' => now()->startOfMonth()->addDay(),
            'category' => WhatsAppUsageDaily::CATEGORY_UTILITY, 'messages_count' => 10, 'cost_micros' => 2_000_000,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/finance?year='.now()->year.'&month='.now()->month);

        $response->assertOk();
        // Sin fila en exchange_rates ni en system_configs, cae al default
        // 4000 COP/USD (ver PlatformFinanceService::expensesCop()).
        $response->assertJsonPath('summary.expenses.usd_to_cop_rate', 4000);
        $response->assertJsonPath('summary.expenses.whatsapp_cop', 8000); // $2 USD * 4000
        $response->assertJsonPath('summary.expenses.ai_cost_available', true);
        $response->assertJsonPath('summary.expenses.ai_cop', 0);
        $this->assertIsInt($response->json('summary.margin.cop'));
    }

    public function test_finance_summary_flags_ai_cost_as_unavailable_when_the_ia_core_fails(): void
    {
        Http::fake(['*/v1/platform/usage*' => Http::response(['detail' => 'no disponible'], 503)]);
        $admin = $this->superadmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/finance?year='.now()->year.'&month='.now()->month);

        $response->assertOk()
            ->assertJsonPath('summary.expenses.ai_cost_available', false)
            ->assertJsonPath('summary.expenses.ai_cop', 0);
    }

    public function test_dashboard_returns_business_counts_and_expiring_businesses(): void
    {
        $admin = $this->superadmin();
        Business::factory()->create(['active' => true, 'paid_until' => now()->addDays(3)]);
        Business::factory()->create(['active' => true, 'trial_ends_at' => now()->addDays(5)]);
        Business::factory()->create(['active' => false]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/dashboard');

        $response->assertOk()
            ->assertJsonPath('stats.total_businesses', 3)
            ->assertJsonPath('stats.paid', 1)
            ->assertJsonCount(2, 'expiring_businesses');
    }
}
