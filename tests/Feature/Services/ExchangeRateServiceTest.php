<?php

namespace Tests\Feature\Services;

use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La tasa del dia es lo que hace que un costo en dolares se pueda leer en
 * pesos sin que el historico se reescriba solo cada vez que se mueve el dolar.
 */
class ExchangeRateServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): ExchangeRateService
    {
        return app(ExchangeRateService::class);
    }

    private function trmResponds(string $value): void
    {
        Http::fake([
            'www.datos.gov.co/*' => Http::response([
                ['valor' => $value, 'unidad' => 'COP', 'vigenciadesde' => '2026-07-30T00:00:00.000'],
            ]),
        ]);
    }

    public function test_stores_the_official_trm_of_the_day(): void
    {
        $this->trmResponds('3206.18');

        $result = $this->service()->fetchAndStoreToday();

        $this->assertTrue($result['ok']);
        $this->assertSame(ExchangeRateService::SOURCE_TRM, $result['source']);
        $this->assertSame(3206.18, round($result['rate'], 2));
        $this->assertSame(1, ExchangeRate::count());
    }

    /**
     * Correrlo dos veces el mismo dia actualiza, no duplica. Se usa una
     * secuencia y no dos Http::fake() seguidos: el primer stub registrado
     * que coincide con la URL es el que gana.
     */
    public function test_is_idempotent_within_the_same_day(): void
    {
        Http::fake([
            'www.datos.gov.co/*' => Http::sequence()
                ->push([['valor' => '3206.18']])
                ->push([['valor' => '3210.50']]),
        ]);

        $this->service()->fetchAndStoreToday();
        $this->service()->fetchAndStoreToday();

        $this->assertSame(1, ExchangeRate::count());
        $this->assertSame('3210.5000', ExchangeRate::first()->usd_cop);
    }

    public function test_falls_back_to_the_market_source_when_the_official_one_fails(): void
    {
        Http::fake([
            'www.datos.gov.co/*' => Http::response([], 500),
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['COP' => 3207.64]]),
        ]);

        $result = $this->service()->fetchAndStoreToday();

        $this->assertTrue($result['ok']);
        $this->assertSame(ExchangeRateService::SOURCE_MARKET, $result['source']);
    }

    /**
     * Con las dos fuentes caidas NO se inventa una tasa: escribir un 0, o la
     * tasa de otro dia como si fuera la de hoy, corrompe el historico de
     * costos en silencio.
     */
    public function test_does_not_write_a_made_up_rate_when_every_source_fails(): void
    {
        Http::fake([
            'www.datos.gov.co/*' => Http::response([], 500),
            'open.er-api.com/*' => Http::response([], 500),
        ]);

        $result = $this->service()->fetchAndStoreToday();

        $this->assertFalse($result['ok']);
        $this->assertSame(0, ExchangeRate::count());
    }

    public function test_discards_a_value_outside_the_plausible_range(): void
    {
        Http::fake([
            'www.datos.gov.co/*' => Http::response([['valor' => '0']]),
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['COP' => 999999]]),
        ]);

        $result = $this->service()->fetchAndStoreToday();

        $this->assertFalse($result['ok']);
        $this->assertSame(0, ExchangeRate::count());
    }

    /** Un gasto se valora con la tasa de SU dia, no con la de hoy. */
    public function test_each_day_is_valued_with_its_own_rate(): void
    {
        ExchangeRate::create(['date' => '2026-07-01', 'usd_cop' => 3100, 'source' => 'trm', 'fetched_at' => now()]);
        ExchangeRate::create(['date' => '2026-07-15', 'usd_cop' => 3400, 'source' => 'trm', 'fetched_at' => now()]);

        $this->assertSame(3100.0, $this->service()->rateForDate('2026-07-01'));
        $this->assertSame(3400.0, $this->service()->rateForDate('2026-07-15'));
    }

    public function test_a_day_without_its_own_rate_uses_the_previous_one_not_the_next(): void
    {
        ExchangeRate::create(['date' => '2026-07-01', 'usd_cop' => 3100, 'source' => 'trm', 'fetched_at' => now()]);
        ExchangeRate::create(['date' => '2026-07-15', 'usd_cop' => 3400, 'source' => 'trm', 'fetched_at' => now()]);

        $this->assertSame(3100.0, $this->service()->rateForDate('2026-07-10'));
    }

    public function test_returns_null_without_any_rate_registered(): void
    {
        $this->assertNull($this->service()->rateForDate('2026-07-10'));
    }
}
