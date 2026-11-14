<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\BusinessTable;
use App\Models\Ingredient;
use App\Models\KitchenSentLine;
use App\Models\Order;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Sale;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Comandera fisica: que sale impreso, donde y cuando, sin que nadie en la
 * cocina toque una pantalla. Y Despacho, donde la caja mueve el estado.
 */
class KitchenPrintingTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $owner;

    private Printer $kitchen;

    private Printer $counter;

    private Product $burger;

    private Product $lemonade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'subscription_plan' => 'full',
            // Comandera fisica SIN la digital: es el restaurante de este plan.
            'feature_flags' => [
                ...BusinessFeaturePresets::full(),
                'online_store' => true,
                'kitchen_board' => false,
                'auto_printing' => true,
                'kitchen_printing' => true,
                // Salsas, toppings y combos.
                'product_options' => true,
            ],
            'delivery_enabled' => true,
            'delivery_fee' => 4000,
            'service_charge_enabled' => false,
            'ipoconsumo_enabled' => false,
        ]);
        $this->owner = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => true, 'name' => 'Laura']);
        $this->owner->assignRole('admin');

        $agent = PrintAgent::factory()->paired()->create(['business_id' => $this->business->id]);
        $this->kitchen = Printer::factory()->kitchen()->create(['business_id' => $this->business->id, 'print_agent_id' => $agent->id]);
        $this->counter = Printer::factory()->counter()->create(['business_id' => $this->business->id, 'print_agent_id' => $agent->id]);

        $category = ProductCategory::factory()->create(['business_id' => $this->business->id, 'is_published' => true]);
        $this->burger = $this->product($category->id, 'Hamburguesa', 22000);
        $this->lemonade = $this->product($category->id, 'Limonada', 6000);
        Permission::findOrCreate('deliveries.handle', 'web');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function product(int $categoryId, string $name, float $price): Product
    {
        return Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $categoryId,
            'name' => $name,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
            'track_stock' => false,
            'price' => $price,
        ]);
    }

    /** @return list<array{type: string, printer: int, text: string}> */
    private function jobs(?int $saleId = null): array
    {
        return PrintJob::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->when($saleId, fn ($query) => $query->where('sale_id', $saleId))
            ->orderBy('id')
            ->get()
            ->map(fn (PrintJob $job) => [
                'type' => $job->type,
                'printer' => $job->printer_id,
                // Los tickets van en PC850: se pasan a UTF-8 para leerlos.
                'text' => mb_convert_encoding(base64_decode($job->payload), 'UTF-8', 'CP850'),
            ])
            ->all();
    }

    private function openTab(array $items): int
    {
        $table = BusinessTable::factory()->create(['business_id' => $this->business->id, 'name' => 'Mesa 4']);

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/open-tabs', [
            'table_id' => $table->id,
            'notes' => 'Sin cebolla',
            'items' => $items,
        ])->assertCreated()->json('id');
    }

    // --- Cuentas abiertas ---------------------------------------------------

    public function test_opening_a_tab_prints_the_order_in_the_kitchen(): void
    {
        $saleId = $this->openTab([['product_id' => $this->burger->id, 'quantity' => 2]]);

        $jobs = $this->jobs($saleId);
        $this->assertCount(1, $jobs);
        $this->assertSame(PrintJob::TYPE_KITCHEN, $jobs[0]['type']);
        $this->assertSame($this->kitchen->id, $jobs[0]['printer']);
        $this->assertStringContainsString('COMANDA', $jobs[0]['text']);
        $this->assertStringContainsString('MESA 4', $jobs[0]['text']);
        $this->assertStringContainsString('2 x Hamburguesa', $jobs[0]['text']);
        $this->assertStringContainsString('NOTA: Sin cebolla', $jobs[0]['text']);
        $this->assertStringContainsString('Atiende: Laura', $jobs[0]['text']);
    }

    public function test_adding_items_prints_only_what_is_new(): void
    {
        $saleId = $this->openTab([['product_id' => $this->burger->id, 'quantity' => 2]]);

        // Una hamburguesa mas (se suma a la linea existente) y una limonada.
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/open-tabs/{$saleId}/items", [
            'items' => [
                ['product_id' => $this->burger->id, 'quantity' => 1],
                ['product_id' => $this->lemonade->id, 'quantity' => 1],
            ],
        ])->assertOk();

        $jobs = $this->jobs($saleId);
        $this->assertCount(2, $jobs);
        $this->assertSame(PrintJob::TYPE_KITCHEN_ADDITION, $jobs[1]['type']);
        $this->assertStringContainsString('ADICIÓN', $jobs[1]['text']);
        $this->assertStringContainsString('1 x Hamburguesa', $jobs[1]['text']);
        $this->assertStringContainsString('1 x Limonada', $jobs[1]['text']);
        $this->assertStringNotContainsString('2 x Hamburguesa', $jobs[1]['text']);
    }

    public function test_editing_a_tab_prints_voids_and_additions_but_not_what_did_not_change(): void
    {
        $saleId = $this->openTab([
            ['product_id' => $this->burger->id, 'quantity' => 3],
            ['product_id' => $this->lemonade->id, 'quantity' => 1],
        ]);

        // Guardar sin cambios (syncItems recrea las lineas): no imprime nada.
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/open-tabs/{$saleId}/items", ['items' => [
            ['product_id' => $this->burger->id, 'quantity' => 3],
            ['product_id' => $this->lemonade->id, 'quantity' => 1],
        ]])->assertOk();
        $this->assertCount(1, $this->jobs($saleId));

        // Una hamburguesa menos y la limonada fuera.
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/open-tabs/{$saleId}/items", ['items' => [
            ['product_id' => $this->burger->id, 'quantity' => 2],
        ]])->assertOk();

        $jobs = $this->jobs($saleId);
        $this->assertCount(2, $jobs);
        $this->assertSame(PrintJob::TYPE_KITCHEN_VOID, $jobs[1]['type']);
        $this->assertStringContainsString('ANULADO', $jobs[1]['text']);
        $this->assertStringContainsString('1 x Hamburguesa', $jobs[1]['text']);
        $this->assertStringContainsString('1 x Limonada', $jobs[1]['text']);
        $this->assertSame(2, (int) KitchenSentLine::query()->where('sale_id', $saleId)->sum('quantity'));
    }

    public function test_cancelling_a_tab_voids_everything_the_kitchen_had(): void
    {
        $saleId = $this->openTab([['product_id' => $this->burger->id, 'quantity' => 2]]);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/open-tabs/{$saleId}")->assertSuccessful();

        $void = PrintJob::withoutGlobalScopes()->where('type', PrintJob::TYPE_KITCHEN_VOID)->firstOrFail();
        $this->assertNull($void->sale_id, 'la cuenta se borro, el ticket queda');
        $this->assertStringContainsString('2 x Hamburguesa', base64_decode($void->payload));
    }

    public function test_the_order_shows_every_chosen_option_and_what_a_combo_brings(): void
    {
        // Alitas con hasta dos salsas.
        $group = ProductOptionGroup::create(['business_id' => $this->business->id, 'name' => 'Salsa', 'min_choices' => 1, 'max_choices' => 2]);
        $this->burger->optionGroups()->attach($group->id);
        $bbq = ProductOption::create(['business_id' => $this->business->id, 'product_option_group_id' => $group->id, 'name' => 'BBQ', 'extra_price' => 0]);
        $hot = ProductOption::create(['business_id' => $this->business->id, 'product_option_group_id' => $group->id, 'name' => 'Picante', 'extra_price' => 1000]);

        // Combo pareja: 2 limonadas + papas (insumo: la cocina no lo necesita en el papel).
        $potatoes = Ingredient::factory()->create(['business_id' => $this->business->id, 'stock' => 1000]);
        $combo = $this->product($this->burger->category_id, 'Combo pareja', 40000);
        $combo->components()->createMany([
            ['business_id' => $this->business->id, 'component_product_id' => $this->lemonade->id, 'quantity' => 2, 'sort_order' => 0],
            ['business_id' => $this->business->id, 'ingredient_id' => $potatoes->id, 'quantity' => 200, 'sort_order' => 1],
        ]);

        $saleId = $this->openTab([
            ['product_id' => $this->burger->id, 'quantity' => 1, 'options' => [$bbq->id, $hot->id]],
            ['product_id' => $combo->id, 'quantity' => 2],
        ]);

        $text = $this->jobs($saleId)[0]['text'];
        $this->assertStringContainsString("1 x Hamburguesa\n", $text);
        $this->assertStringContainsString("    + BBQ\n    + Picante\n", $text);
        $this->assertStringContainsString("2 x Combo pareja\n", $text);
        $this->assertStringContainsString('    · 4 Limonada', $text, 'piezas multiplicadas por la cantidad');
        $this->assertStringNotContainsString(' '.$potatoes->name, $text);

        // Quitar un combo: el ANULADO dice que lleva ese combo.
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/open-tabs/{$saleId}/items", ['items' => [
            ['product_id' => $this->burger->id, 'quantity' => 1, 'options' => [$bbq->id, $hot->id]],
            ['product_id' => $combo->id, 'quantity' => 1],
        ]])->assertOk();
        $void = $this->jobs($saleId)[1]['text'];
        $this->assertStringContainsString('ANULADO', $void);
        $this->assertStringContainsString("1 x Combo pareja\n", $void);
        $this->assertStringContainsString('    · 2 Limonada', $void);
        $this->assertStringNotContainsString('Hamburguesa', $void);
    }

    public function test_closing_a_tab_prints_the_receipt_at_the_counter(): void
    {
        $saleId = $this->openTab([['product_id' => $this->burger->id, 'quantity' => 1]]);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/open-tabs/{$saleId}/close", ['payment_method' => 'cash'])->assertOk();

        $receipt = collect($this->jobs($saleId))->firstWhere('type', PrintJob::TYPE_RECEIPT);
        $this->assertSame($this->counter->id, $receipt['printer']);
        $this->assertStringContainsString('RECIBO DE VENTA', $receipt['text']);
    }

    public function test_without_physical_kitchen_nothing_goes_to_the_kitchen_printer(): void
    {
        $this->business->update(['feature_flags' => [...$this->business->feature_flags, 'kitchen_printing' => false]]);

        $saleId = $this->openTab([['product_id' => $this->burger->id, 'quantity' => 1]]);

        $this->assertSame([], $this->jobs($saleId));
    }

    // --- Mostrador y domicilios ---------------------------------------------

    public function test_a_counter_sale_prints_the_receipt_and_not_a_kitchen_ticket(): void
    {
        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->lemonade->id, 'quantity' => 1]],
        ])->assertCreated()->json('id');

        $jobs = $this->jobs($saleId);
        $this->assertCount(1, $jobs);
        $this->assertSame(PrintJob::TYPE_RECEIPT, $jobs[0]['type']);
    }

    public function test_a_counter_delivery_goes_to_the_kitchen_and_prints_receipt_and_ticket_when_it_leaves(): void
    {
        $courier = User::factory()->create(['business_id' => $this->business->id, 'name' => 'Pedro', 'is_business_owner' => false]);
        $courier->assignRole('employee');
        $courier->givePermissionTo('deliveries.handle');

        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'is_delivery' => true,
            'delivery_address' => 'Calle 10 # 5-20, Sibaté',
            'customer_name' => 'Marta',
            'customer_phone' => '3001112233',
            'items' => [['product_id' => $this->burger->id, 'quantity' => 1]],
        ])->assertCreated()->json('id');

        $jobs = $this->jobs($saleId);
        $this->assertCount(1, $jobs, 'solo la comanda: la factura espera a que salga');
        $this->assertStringContainsString('DOMICILIO', $jobs[0]['text']);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dispatch')
            ->assertOk()
            ->assertJsonPath('deliveries.0.id', $saleId)
            ->assertJsonPath('deliveries.0.is_ready', true)
            ->assertJsonPath('couriers.0.name', 'Pedro');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/dispatch/{$saleId}/send-out", ['courier_id' => $courier->id])
            ->assertOk()
            ->assertJsonPath('status', 'on_the_way')
            ->assertJsonPath('courier.name', 'Pedro');

        $out = collect($this->jobs($saleId))->firstWhere('type', PrintJob::TYPE_DELIVERY);
        $this->assertSame($this->counter->id, $out['printer']);
        $this->assertLessThan(strpos($out['text'], 'Calle 10'), strpos($out['text'], 'RECIBO DE VENTA'), 'factura primero, luego el ticket');
        $this->assertStringContainsString('Tel: 3001112233', $out['text']);
        $this->assertStringContainsString('COBRAR', $out['text']);
        $this->assertStringContainsString('Domiciliario: Pedro', $out['text']);
        $this->assertSame(2, substr_count(base64_decode(PrintJob::withoutGlobalScopes()->where('type', PrintJob::TYPE_DELIVERY)->value('payload')), "\x1DV\x42"));

        // El domiciliario vuelve a la caja y la cajera lo cierra.
        $cashier = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => false]);
        $cashier->assignRole('employee');
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/dispatch/{$saleId}/delivered")
            ->assertOk()
            ->assertJsonPath('status', 'delivered');
    }

    public function test_the_courier_taking_it_prints_the_same_as_the_counter(): void
    {
        $courier = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => false]);
        $courier->assignRole('employee');
        $courier->givePermissionTo('deliveries.handle');
        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'is_delivery' => true,
            'delivery_address' => 'Cra 3 # 4-5',
            'items' => [['product_id' => $this->burger->id, 'quantity' => 1]],
        ])->assertCreated()->json('id');

        // Sin comandera digital nadie marca "listo": el domiciliario lo puede tomar ya.
        $this->actingAs($courier, 'sanctum')->postJson("/api/v1/deliveries/{$saleId}/take")->assertOk();

        $this->assertNotNull(collect($this->jobs($saleId))->firstWhere('type', PrintJob::TYPE_DELIVERY));
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/dispatch/{$saleId}/send-out")
            ->assertUnprocessable();
    }

    public function test_dispatching_without_courier_and_rejecting_someone_who_does_not_deliver(): void
    {
        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'is_delivery' => true,
            'delivery_address' => 'Cra 3 # 4-5',
            'items' => [['product_id' => $this->burger->id, 'quantity' => 1]],
        ])->assertCreated()->json('id');
        $notCourier = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => false]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/dispatch/{$saleId}/send-out", ['courier_id' => $notCourier->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('courier_id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/dispatch/{$saleId}/send-out")
            ->assertOk()
            ->assertJsonPath('courier', null);
    }

    public function test_reprinting_the_kitchen_ticket_marks_it_and_keeps_the_record(): void
    {
        $saleId = $this->openTab([['product_id' => $this->burger->id, 'quantity' => 2]]);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/dispatch/{$saleId}/kitchen-ticket")->assertCreated();

        $jobs = $this->jobs($saleId);
        $this->assertCount(2, $jobs);
        $this->assertStringContainsString('REIMPRESIÓN', $jobs[1]['text']);
        $this->assertStringContainsString('2 x Hamburguesa', $jobs[1]['text']);
        $this->assertSame(2, (int) KitchenSentLine::query()->where('sale_id', $saleId)->sum('quantity'));
    }

    // --- Pedidos online -----------------------------------------------------

    public function test_a_confirmed_pickup_order_prints_kitchen_ticket_and_receipt_and_the_counter_hands_it_over(): void
    {
        BusinessStoreSettings::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);
        $this->burger->update(['is_published' => true]);
        $this->postJson("/api/v1/storefront/{$this->business->slug}/orders", [
            'customer_name' => 'Ana',
            'customer_phone' => '3001234567',
            'is_pickup' => true,
            'shipping_notes' => 'Bien asada',
            'items' => [['product_id' => $this->burger->id, 'quantity' => 1]],
        ])->assertCreated();
        TenantContext::forget();
        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => Order::STATUS_CONFIRMED])
            ->assertOk();
        $saleId = (int) $order->fresh()->sale_id;

        $types = collect($this->jobs($saleId))->pluck('type')->all();
        $this->assertSame([PrintJob::TYPE_KITCHEN, PrintJob::TYPE_RECEIPT], $types);
        $kitchen = $this->jobs($saleId)[0]['text'];
        $this->assertStringContainsString('PARA RECOGER', $kitchen);
        $this->assertStringContainsString('Pedido online #'.$order->number, $kitchen);
        $this->assertStringContainsString('NOTA: Bien asada', $kitchen);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dispatch')->assertJsonPath('pickups.0.id', $saleId);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/dispatch/{$saleId}/picked-up")
            ->assertOk()
            ->assertJsonPath('order_status', Order::STATUS_DELIVERED);
    }

    public function test_dispatch_needs_the_physical_kitchen_flag(): void
    {
        $this->business->update(['feature_flags' => [...$this->business->feature_flags, 'kitchen_printing' => false]]);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dispatch')->assertForbidden();
    }

    public function test_a_tab_in_another_branch_uses_that_branch_printer(): void
    {
        $saleId = $this->openTab([['product_id' => $this->burger->id, 'quantity' => 1]]);
        $sale = Sale::withoutGlobalScopes()->find($saleId);

        $this->assertSame($this->kitchen->id, $this->jobs($saleId)[0]['printer'], 'la impresora sin sede sirve a todas');
        $this->assertNotNull($sale->branch_id);
    }
}
