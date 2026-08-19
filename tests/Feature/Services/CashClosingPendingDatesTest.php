<?php

namespace Tests\Feature\Services;

use App\Models\Business;
use App\Models\CashClosing;
use App\Models\Sale;
use App\Services\CashClosingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * pendingDates() es la cola de "dias sin cerrar" que la pantalla de Cierre
 * de caja le muestra al dueño cuando se le acumularon varios dias - en vez
 * de dejarlo adivinar que fecha usar en un selector libre.
 */
class CashClosingPendingDatesTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): CashClosingService
    {
        return app(CashClosingService::class);
    }

    public function test_a_business_with_no_activity_has_no_pending_dates(): void
    {
        $business = Business::factory()->create();

        $this->assertSame([], $this->service()->pendingDates($business->id));
    }

    public function test_today_is_never_listed_as_pending(): void
    {
        $business = Business::factory()->create();
        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->assertSame([], $this->service()->pendingDates($business->id));
    }

    public function test_a_sale_from_days_ago_with_no_closing_shows_up_as_pending(): void
    {
        $business = Business::factory()->create();
        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'closed_at' => now()->subDays(3),
        ]);

        $this->assertSame(
            [now()->subDays(3)->toDateString()],
            $this->service()->pendingDates($business->id)
        );
    }

    public function test_days_already_covered_by_a_closing_are_not_pending(): void
    {
        $business = Business::factory()->create();
        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'closed_at' => now()->subDays(2),
        ]);
        CashClosing::factory()->create(['business_id' => $business->id, 'date' => now()->subDays(2)->toDateString()]);

        $this->assertSame([], $this->service()->pendingDates($business->id));
    }

    /**
     * El caso central del pedido del usuario: el dueño se olvido de cerrar
     * varios dias seguidos. La cola debe traerlos todos, en orden.
     */
    public function test_multiple_missed_days_are_returned_in_order(): void
    {
        $business = Business::factory()->create();
        Sale::factory()->create(['business_id' => $business->id, 'status' => 'closed', 'closed_at' => now()->subDays(4)]);
        Sale::factory()->create(['business_id' => $business->id, 'status' => 'closed', 'closed_at' => now()->subDays(3)]);
        // El dia intermedio (hace 2 dias) no tuvo ninguna venta, pero sigue
        // siendo un dia "sin cerrar" dentro del rango - no debe saltarselo.
        Sale::factory()->create(['business_id' => $business->id, 'status' => 'closed', 'closed_at' => now()->subDays(1)]);

        $this->assertSame(
            [
                now()->subDays(4)->toDateString(),
                now()->subDays(3)->toDateString(),
                now()->subDays(1)->toDateString(),
            ],
            $this->service()->pendingDates($business->id)
        );
    }

    public function test_lookback_is_capped_so_a_never_closed_business_does_not_scan_forever(): void
    {
        $business = Business::factory()->create();
        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'closed_at' => now()->subDays(200),
        ]);

        $dates = $this->service()->pendingDates($business->id, maxLookbackDays: 5);

        $this->assertNotContains(now()->subDays(200)->toDateString(), $dates);
        $this->assertLessThanOrEqual(5, count($dates));
    }
}
