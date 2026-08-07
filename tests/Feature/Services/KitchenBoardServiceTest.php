<?php

namespace Tests\Feature\Services;

use App\Models\Business;
use App\Models\BusinessTable;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\KitchenBoardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class KitchenBoardServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): KitchenBoardService
    {
        return app(KitchenBoardService::class);
    }

    public function test_open_tickets_only_returns_open_sales_with_items(): void
    {
        $business = Business::factory()->create();

        $withItems = Sale::factory()->create(['business_id' => $business->id, 'status' => 'open']);
        SaleItem::factory()->create(['sale_id' => $withItems->id, 'product_id' => Product::factory()->create(['business_id' => $business->id])->id]);

        // Sin items: no es una comanda real todavia, no debe listarse.
        Sale::factory()->create(['business_id' => $business->id, 'status' => 'open']);
        // Cerrada: ya no es una comanda activa.
        $closed = Sale::factory()->create(['business_id' => $business->id, 'status' => 'closed', 'closed_at' => now()]);
        SaleItem::factory()->create(['sale_id' => $closed->id, 'product_id' => Product::factory()->create(['business_id' => $business->id])->id]);

        $tickets = $this->service()->openTickets($business->id);

        $this->assertCount(1, $tickets);
        $this->assertSame($withItems->id, $tickets->first()->id);
    }

    public function test_update_status_for_specific_items_and_syncs_sale_rollup_as_the_least_advanced_status(): void
    {
        $business = Business::factory()->create();
        $sale = Sale::factory()->create(['business_id' => $business->id, 'status' => 'open']);
        $itemA = SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => Product::factory()->create(['business_id' => $business->id])->id]);
        $itemB = SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => Product::factory()->create(['business_id' => $business->id])->id]);

        $this->service()->updateStatus($sale, 'ready', [$itemA->id]);

        $this->assertSame('ready', $itemA->fresh()->kitchen_status);
        $this->assertNull($itemB->fresh()->kitchen_status);
        // itemB sigue "pending" (normalizado desde null) -> la venta completa
        // se muestra pendiente aunque itemA ya este listo.
        $this->assertSame('pending', $sale->fresh()->kitchen_status);

        $this->service()->updateStatus($sale, 'ready', [$itemB->id]);

        $this->assertSame('ready', $sale->fresh()->kitchen_status);
    }

    public function test_update_status_without_item_ids_applies_to_every_item(): void
    {
        $business = Business::factory()->create();
        $sale = Sale::factory()->create(['business_id' => $business->id, 'status' => 'open']);
        $itemA = SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => Product::factory()->create(['business_id' => $business->id])->id]);
        $itemB = SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => Product::factory()->create(['business_id' => $business->id])->id]);

        $this->service()->updateStatus($sale, 'preparing');

        $this->assertSame('preparing', $itemA->fresh()->kitchen_status);
        $this->assertSame('preparing', $itemB->fresh()->kitchen_status);
        $this->assertSame('preparing', $sale->fresh()->kitchen_status);
    }

    public function test_normalize_status_defaults_unknown_values_to_pending(): void
    {
        $this->assertSame('pending', KitchenBoardService::normalizeStatus(null));
        $this->assertSame('pending', KitchenBoardService::normalizeStatus('garbage'));
        $this->assertSame('preparing', KitchenBoardService::normalizeStatus('preparing'));
        $this->assertSame('ready', KitchenBoardService::normalizeStatus('ready'));
    }

    public function test_open_tickets_scopes_by_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $saleA = Sale::factory()->create(['business_id' => $businessA->id, 'status' => 'open']);
        SaleItem::factory()->create(['sale_id' => $saleA->id, 'product_id' => Product::factory()->create(['business_id' => $businessA->id])->id]);

        $saleB = Sale::factory()->create(['business_id' => $businessB->id, 'status' => 'open']);
        SaleItem::factory()->create(['sale_id' => $saleB->id, 'product_id' => Product::factory()->create(['business_id' => $businessB->id])->id]);

        $tickets = $this->service()->openTickets($businessA->id);

        $this->assertCount(1, $tickets);
        $this->assertSame($saleA->id, $tickets->first()->id);
    }

    public function test_open_tickets_orders_by_created_at(): void
    {
        $business = Business::factory()->create();
        $table = BusinessTable::factory()->create(['business_id' => $business->id, 'name' => 'Mesa 1']);

        $older = Sale::factory()->create(['business_id' => $business->id, 'status' => 'open', 'table_id' => $table->id, 'created_at' => now()->subMinutes(10)]);
        SaleItem::factory()->create(['sale_id' => $older->id, 'product_id' => Product::factory()->create(['business_id' => $business->id])->id]);

        $newer = Sale::factory()->create(['business_id' => $business->id, 'status' => 'open', 'created_at' => now()]);
        SaleItem::factory()->create(['sale_id' => $newer->id, 'product_id' => Product::factory()->create(['business_id' => $business->id])->id]);

        $tickets = $this->service()->openTickets($business->id);

        $this->assertSame($older->id, $tickets->first()->id);
        $this->assertSame('Mesa 1', $tickets->first()->table->name);
    }
}
