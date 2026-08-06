<?php

namespace Tests\Feature\Console;

use App\Models\ExchangeRate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateFetchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_stores_todays_rate(): void
    {
        Http::fake(['www.datos.gov.co/*' => Http::response([['valor' => '3206.18']])]);

        $this->artisan('exchange-rate:fetch')->assertSuccessful();

        $this->assertSame(1, ExchangeRate::count());
    }

    public function test_succeeds_even_when_every_source_fails(): void
    {
        Http::fake([
            'www.datos.gov.co/*' => Http::response([], 500),
            'open.er-api.com/*' => Http::response([], 500),
        ]);

        $this->artisan('exchange-rate:fetch')->assertSuccessful();

        $this->assertSame(0, ExchangeRate::count());
    }
}
