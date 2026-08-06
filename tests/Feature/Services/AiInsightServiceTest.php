<?php

namespace Tests\Feature\Services;

use App\Models\AiInsight;
use App\Models\Business;
use App\Models\CashClosing;
use App\Services\Ai\Insights\CashClosingHistoryInsight;
use App\Services\AiInsightService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiInsightServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const CONTEXT = ['business_id' => '1', 'user_id' => '1', 'is_admin' => true, 'permissions' => [], 'features' => [], 'channel' => 'web'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ia_core.api_key' => 'test-ia-core-key',
            'services.ia_core.base_url' => 'http://ia-core.test',
        ]);
    }

    private function service(): AiInsightService
    {
        return app(AiInsightService::class);
    }

    private function businessWithClosings(): Business
    {
        $business = Business::factory()->create(['feature_flags' => ['cash_closing' => true]]);
        CashClosing::factory()->create(['business_id' => $business->id, 'difference' => -5000]);

        return $business;
    }

    public function test_returns_null_when_the_business_lacks_the_required_feature(): void
    {
        $business = Business::factory()->create(['feature_flags' => []]);

        $result = $this->service()->get($business, new CashClosingHistoryInsight, self::CONTEXT);

        $this->assertNull($result);
    }

    public function test_returns_null_when_the_data_is_not_worth_showing(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['cash_closing' => true]]);

        $result = $this->service()->get($business, new CashClosingHistoryInsight, self::CONTEXT);

        $this->assertNull($result);
        $this->assertDatabaseCount('ai_insights', 0);
    }

    public function test_generates_and_caches_a_new_insight(): void
    {
        Http::fake(['ia-core.test/*' => Http::response([
            'text' => 'La caja viene cuadrando bien.', 'input_tokens' => 50, 'output_tokens' => 20, 'model' => 'test-model', 'cost_micros' => 100,
        ], 200)]);
        $business = $this->businessWithClosings();

        $result = $this->service()->get($business, new CashClosingHistoryInsight, self::CONTEXT);

        $this->assertNotNull($result);
        $this->assertSame('La caja viene cuadrando bien.', $result['text']);
        $this->assertFalse($result['from_cache']);
        $this->assertDatabaseHas('ai_insights', [
            'business_id' => $business->id, 'tipo' => 'cierres_historico', 'texto' => 'La caja viene cuadrando bien.',
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'http://ia-core.test/v1/completions'
            && $request->hasHeader('Authorization', 'Bearer test-ia-core-key'));
    }

    public function test_returns_the_cached_text_without_calling_the_provider_when_still_current(): void
    {
        Http::fake();
        $business = $this->businessWithClosings();
        AiInsight::query()->create([
            'business_id' => $business->id, 'tipo' => 'cierres_historico', 'texto' => 'Texto cacheado.',
            'datos' => ['total' => 1], 'generado_en' => now(), 'expira_en' => now()->addHour(),
        ]);

        $result = $this->service()->get($business, new CashClosingHistoryInsight, self::CONTEXT);

        $this->assertSame('Texto cacheado.', $result['text']);
        $this->assertTrue($result['from_cache']);
        Http::assertNothingSent();
    }

    public function test_force_refresh_regenerates_even_when_the_cache_is_still_current(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['text' => 'Texto nuevo.'], 200)]);
        $business = $this->businessWithClosings();
        AiInsight::query()->create([
            'business_id' => $business->id, 'tipo' => 'cierres_historico', 'texto' => 'Texto viejo.',
            'datos' => ['total' => 1], 'generado_en' => now(), 'expira_en' => now()->addHour(),
        ]);

        $result = $this->service()->get($business, new CashClosingHistoryInsight, self::CONTEXT, forceRefresh: true);

        $this->assertSame('Texto nuevo.', $result['text']);
        $this->assertFalse($result['from_cache']);
    }

    public function test_falls_back_to_the_cached_text_when_generation_fails(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['detail' => 'proveedor caido'], 503)]);
        $business = $this->businessWithClosings();
        AiInsight::query()->create([
            'business_id' => $business->id, 'tipo' => 'cierres_historico', 'texto' => 'Texto anterior.',
            'datos' => ['total' => 1], 'generado_en' => now()->subHours(2), 'expira_en' => now()->subHour(),
        ]);

        $result = $this->service()->get($business, new CashClosingHistoryInsight, self::CONTEXT);

        $this->assertSame('Texto anterior.', $result['text']);
        $this->assertTrue($result['from_cache']);
    }

    public function test_invalidate_expires_the_cached_row_so_the_next_get_regenerates(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['text' => 'Texto regenerado.'], 200)]);
        $business = $this->businessWithClosings();
        AiInsight::query()->create([
            'business_id' => $business->id, 'tipo' => 'cierres_historico', 'texto' => 'Texto viejo.',
            'datos' => ['total' => 1], 'generado_en' => now(), 'expira_en' => now()->addHour(),
        ]);

        $this->service()->invalidate($business->id, 'cierres_historico');
        $result = $this->service()->get($business, new CashClosingHistoryInsight, self::CONTEXT);

        $this->assertSame('Texto regenerado.', $result['text']);
        $this->assertFalse($result['from_cache']);
    }
}
