<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\CashClosing;
use App\Models\Expense;
use App\Models\Layaway;
use App\Models\LayawayPayment;
use App\Models\PosPaymentMethod;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceOrder;
use App\Models\ServicePayment;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(?Business $business = null): array
    {
        $business ??= Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return [$business, $admin];
    }

    // ─── daily ────────────────────────────────────────────────────────────────

    public function test_daily_summary_returns_expected_structure(): void
    {
        [$business, $admin] = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/daily?date='.today()->toDateString());

        $response->assertOk()
            ->assertJsonStructure([
                'date', 'total_sales', 'closed_sales_revenue', 'sales_count',
                'open_count', 'open_total', 'total_all', 'payment_breakdown',
                'top_products', 'recent_sales', 'open_sales',
                'recent_service_orders', 'recent_layaways', 'recent_receivables',
            ]);
    }

    public function test_daily_summary_includes_recent_receivables_with_customer_and_payment_method(): void
    {
        [$business, $admin] = $this->admin();

        Receivable::factory()->for($business)->paid()->create([
            'amount' => 12000, 'payment_method' => 'transfer',
            'customer_name' => 'Cliente Fiado', 'customer_phone' => '3001234567',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/daily?date='.today()->toDateString())
            ->assertOk();

        $receivable = collect($response->json('recent_receivables'))->first();
        $this->assertSame('Cliente Fiado', $receivable['customer_name']);
        $this->assertSame('3001234567', $receivable['customer_phone']);
        $this->assertSame('transfer', $receivable['payment_method']);
        $this->assertSame(12000, $receivable['amount']);
    }

    public function test_daily_summary_top_products_excludes_single_sale_products(): void
    {
        [$business, $admin] = $this->admin();
        $tracked = Product::factory()->create(['business_id' => $business->id, 'name' => 'Producto normal']);
        // Precio libre/una sola vez - no tiene "rotacion" real que reportar
        // aunque se venda mucho, mismo criterio que BusinessOverviewService.
        $freePrice = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Tatuaje',
            'is_single_sale' => true,
        ]);
        $sale = Sale::factory()->create(['business_id' => $business->id, 'status' => 'closed', 'closed_at' => now(), 'total' => 100000]);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $tracked->id, 'quantity' => 1, 'subtotal' => 20000]);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $freePrice->id, 'quantity' => 50, 'subtotal' => 80000]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/daily?date='.today()->toDateString())
            ->assertOk();

        $names = collect($response->json('top_products'))->pluck('name');
        $this->assertContains('Producto normal', $names);
        $this->assertNotContains('Tatuaje', $names);
    }

    public function test_daily_summary_counts_closed_sales_for_the_date(): void
    {
        [$business, $admin] = $this->admin();
        $date = '2026-01-15';

        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 50000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => $date.' 10:00:00',
        ]);
        // venta de otro dia no debe aparecer
        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 20000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => '2026-01-14 10:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/sales/daily?date={$date}");

        $response->assertOk()
            ->assertJsonPath('sales_count', 1)
            ->assertJsonPath('closed_sales_revenue', 50000);
    }

    public function test_daily_summary_requires_reports_daily_summary_permission(): void
    {
        PermissionCatalog::sync();
        [$business, $admin] = $this->admin();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/daily')
            ->assertForbidden();

        // El viejo reports.sales (antes cubria los 4 reportes de ventas) ya
        // no alcanza para este - cada uno tiene su propio permiso ahora.
        $employee->syncPermissions(['reports.sales']);
        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/daily')
            ->assertForbidden();

        $employee->syncPermissions(['reports.daily_summary']);
        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/daily?date='.today()->toDateString())
            ->assertOk();
    }

    public function test_sales_history_requires_reports_sales_permission(): void
    {
        PermissionCatalog::sync();
        [$business, $admin] = $this->admin();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/history?from=2026-03-01&to=2026-03-31')
            ->assertForbidden();

        $employee->syncPermissions(['reports.daily_summary']);
        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/history?from=2026-03-01&to=2026-03-31')
            ->assertForbidden();

        $employee->syncPermissions(['reports.sales']);
        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/history?from=2026-03-01&to=2026-03-31')
            ->assertOk();
    }

    public function test_sales_by_seller_requires_reports_sales_by_seller_permission(): void
    {
        PermissionCatalog::sync();
        [$business, $admin] = $this->admin();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/by-seller?from=2026-03-01&to=2026-03-31')
            ->assertForbidden();

        $employee->syncPermissions(['reports.sales']);
        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/by-seller?from=2026-03-01&to=2026-03-31')
            ->assertForbidden();

        $employee->syncPermissions(['reports.sales_by_seller']);
        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/by-seller?from=2026-03-01&to=2026-03-31')
            ->assertOk();
    }

    public function test_daily_summary_breaks_down_income_by_channel_and_payment_method(): void
    {
        [$business, $admin] = $this->admin();
        $date = today()->toDateString();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 40000,
            'is_credit' => false, 'is_non_revenue' => false, 'payment_method' => 'cash',
            'closed_at' => $date.' 09:00:00',
        ]);

        Receivable::factory()->for($business)->paid()->create(['amount' => 10000, 'payment_method' => 'transfer']);

        $order = ServiceOrder::factory()->for($business)->create();
        ServicePayment::factory()->for($order, 'order')->create(['business_id' => $business->id, 'amount' => 5000, 'payment_method' => 'cash']);

        $layaway = Layaway::factory()->for($business)->create();
        LayawayPayment::factory()->for($layaway, 'layaway')->create(['business_id' => $business->id, 'amount' => 7000, 'payment_method' => 'transfer']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/sales/daily?date={$date}")
            ->assertOk();

        $channels = collect($response->json('channels'))->keyBy('key');

        // json_decode devuelve int cuando el float no tiene parte decimal
        // (mismo comportamiento documentado en DashboardTest).
        $response->assertJsonPath('total_sales', 62000);
        $this->assertSame(40000, $channels['sales']['total']);
        $this->assertSame(1, $channels['sales']['count']);
        $this->assertSame(5000, $channels['services']['total']);
        $this->assertSame(7000, $channels['layaways']['total']);
        $this->assertSame(10000, $channels['receivables']['total']);

        // El canal "ventas" solo debe traer efectivo en su desglose; el de
        // apartados solo transferencia - no deben mezclarse entre canales
        // (esto no existia en ningun reporte del legacy).
        $salesByMethod = collect($channels['sales']['by_payment_method'])->keyBy('id');
        $this->assertSame(40000, $salesByMethod['cash']['total']);
        $layawaysByMethod = collect($channels['layaways']['by_payment_method'])->keyBy('id');
        $this->assertSame(7000, $layawaysByMethod['transfer']['total']);
        $this->assertSame(0, $layawaysByMethod['cash']['total']);
    }

    public function test_daily_summary_payment_breakdown_excludes_disabled_methods_without_activity(): void
    {
        [$business, $admin] = $this->admin();
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash', 'label' => 'Efectivo', 'sort_order' => 1]);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi', 'label' => 'Nequi', 'sort_order' => 2]);
        $business->posPaymentMethods()->attach([
            $cash->id => ['is_enabled' => true],
            $nequi->id => ['is_enabled' => false],
        ]);
        $date = today()->toDateString();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 40000,
            'is_credit' => false, 'is_non_revenue' => false, 'payment_method' => 'cash',
            'closed_at' => $date.' 09:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/sales/daily?date={$date}")
            ->assertOk();

        $channels = collect($response->json('channels'))->keyBy('key');
        $salesByMethod = collect($channels['sales']['by_payment_method'])->keyBy('id');

        $this->assertSame(40000, $salesByMethod['cash']['total']);
        $this->assertFalse($salesByMethod->has('nequi'), 'un medio deshabilitado sin ventas ese dia no deberia ocupar columna en $0');
    }

    public function test_daily_summary_payment_breakdown_still_includes_a_disabled_method_with_real_activity(): void
    {
        // Si el medio SI se uso ese dia (estaba habilitado cuando se genero
        // la venta, se desactivo despues), el desglose no debe esconder el
        // dato real - solo deja de "ofrecerse de oficio" en $0.
        [$business, $admin] = $this->admin();
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash', 'label' => 'Efectivo', 'sort_order' => 1]);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi', 'label' => 'Nequi', 'sort_order' => 2]);
        $business->posPaymentMethods()->attach([
            $cash->id => ['is_enabled' => true],
            $nequi->id => ['is_enabled' => false],
        ]);
        $date = today()->toDateString();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 12000,
            'is_credit' => false, 'is_non_revenue' => false, 'payment_method' => 'nequi',
            'closed_at' => $date.' 09:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/sales/daily?date={$date}")
            ->assertOk();

        $channels = collect($response->json('channels'))->keyBy('key');
        $salesByMethod = collect($channels['sales']['by_payment_method'])->keyBy('id');

        $this->assertSame(12000, $salesByMethod['nequi']['total']);
    }

    public function test_daily_summary_merges_legacy_spanish_payment_method_into_configured_bucket(): void
    {
        // Bug relacionado al de sales history: sin normalizar, una venta
        // guardada como 'efectivo' (vocabulario legacy) generaba una
        // segunda fila "Efectivo" separada de la configurada ('cash'), en
        // vez de sumarse a ella - ver docs/CUTOVER_TODO.md #1.
        [$business, $admin] = $this->admin();
        $date = today()->toDateString();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 40000,
            'is_credit' => false, 'is_non_revenue' => false, 'payment_method' => 'cash',
            'closed_at' => $date.' 09:00:00',
        ]);
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 25000,
            'is_credit' => false, 'is_non_revenue' => false, 'payment_method' => 'efectivo',
            'closed_at' => $date.' 10:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/sales/daily?date={$date}")
            ->assertOk();

        $channels = collect($response->json('channels'))->keyBy('key');
        $salesByMethod = collect($channels['sales']['by_payment_method'])->keyBy('id');

        $this->assertSame(65000, $salesByMethod['cash']['total']);
        $this->assertFalse($salesByMethod->has('efectivo'), 'no debe existir un bucket separado "efectivo" - debe sumarse al de "cash"');
    }

    public function test_daily_summary_supports_a_date_range_via_date_from_and_date_to(): void
    {
        [$business, $admin] = $this->admin();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 10000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-02-01 10:00:00',
        ]);
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 20000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-02-03 10:00:00',
        ]);
        // fuera del rango solicitado
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 99999,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-02-10 10:00:00',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/daily?date_from=2026-02-01&date_to=2026-02-03')
            ->assertOk()
            ->assertJsonPath('sales_count', 2)
            ->assertJsonPath('closed_sales_revenue', 30000)
            ->assertJsonPath('date_from', '2026-02-01')
            ->assertJsonPath('date_to', '2026-02-03');
    }

    public function test_daily_summary_computes_net_as_income_minus_expenses(): void
    {
        [$business, $admin] = $this->admin();
        $date = today()->toDateString();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 50000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => $date.' 09:00:00',
        ]);
        Expense::factory()->for($business)->create(['value' => 8000, 'scope' => 'operacional', 'date' => $date]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/sales/daily?date={$date}")
            ->assertOk()
            ->assertJsonPath('total_sales', 50000)
            ->assertJsonPath('total_expenses', 8000)
            ->assertJsonPath('net', 42000);
    }

    // ─── history ──────────────────────────────────────────────────────────────

    public function test_sales_history_returns_paginated_sales_within_range(): void
    {
        [$business, $admin] = $this->admin();

        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 80000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => '2026-03-01 12:00:00',
        ]);
        // fuera de rango
        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 10000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => '2026-04-01 12:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/history?from=2026-03-01&to=2026-03-31');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['data', 'meta', 'payment_method_options', 'payment_method_labels']);
    }

    public function test_sales_history_payment_method_options_excludes_disabled_catalog_entries(): void
    {
        [$business, $admin] = $this->admin();
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash', 'label' => 'Efectivo', 'sort_order' => 1]);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi', 'label' => 'Nequi', 'sort_order' => 2]);
        $business->posPaymentMethods()->attach([
            $cash->id => ['is_enabled' => true],
            $nequi->id => ['is_enabled' => false],
        ]);
        // Venta vieja con un medio que el negocio ya desactivo - debe seguir
        // resolviendo su label real (via payment_method_labels), aunque ya
        // no aparezca como opcion del dropdown de filtro.
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 15000,
            'is_credit' => false, 'is_non_revenue' => false, 'payment_method' => 'nequi',
            'closed_at' => '2026-03-01 12:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/history?from=2026-03-01&to=2026-03-31')
            ->assertOk();

        $optionIds = collect($response->json('payment_method_options'))->pluck('id');
        $this->assertTrue($optionIds->contains('cash'));
        $this->assertFalse($optionIds->contains('nequi'), 'un medio deshabilitado no deberia ofrecerse en el dropdown de filtro');

        $this->assertSame('Nequi', $response->json('payment_method_labels.nequi'), 'el label de un medio deshabilitado debe seguir resolviendo para ventas historicas');
    }

    public function test_sales_history_search_matches_sold_product_name(): void
    {
        [$business, $admin] = $this->admin();

        $matchingProduct = Product::factory()->create(['business_id' => $business->id, 'name' => 'Croissant de almendras']);
        $saleWithProduct = Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'customer_name' => 'Cliente Mostrador',
            'closed_at' => '2026-03-01 12:00:00',
        ]);
        SaleItem::factory()->create([
            'sale_id' => $saleWithProduct->id,
            'product_id' => $matchingProduct->id,
        ]);

        // no debe aparecer: ni el nombre de producto ni el resto de campos buscables coinciden
        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'customer_name' => 'Otro cliente',
            'closed_at' => '2026-03-02 12:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/history?from=2026-03-01&to=2026-03-31&search=croissant');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $saleWithProduct->id);
    }

    public function test_sales_history_payment_method_filter_matches_legacy_spanish_alias(): void
    {
        // Negocio configurado en ingles (default: cash/transfer/credit) pero
        // con ventas guardadas por el legacy en espanol - bug real reportado:
        // filtrar por "Efectivo" (id cash) no devolvia nada porque la venta
        // esta guardada como payment_method='efectivo', no 'cash'.
        [$business, $admin] = $this->admin();

        $cashSpanish = Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed',
            'payment_method' => 'efectivo', 'closed_at' => '2026-03-01 12:00:00',
        ]);
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed',
            'payment_method' => 'transferencia', 'closed_at' => '2026-03-01 12:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/history?from=2026-03-01&to=2026-03-31&payment_method=cash');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $cashSpanish->id)
            // Normalizado al vocabulario configurado del negocio en la
            // respuesta, no el string crudo "efectivo" guardado en la fila.
            ->assertJsonPath('data.0.payment_method', 'cash');
    }

    public function test_sales_history_can_still_filter_by_a_disabled_payment_method(): void
    {
        // El dropdown ya no OFRECE un medio deshabilitado (ver
        // test_sales_history_payment_method_options_excludes_disabled_catalog_entries),
        // pero seguir pudiendo consultar ventas historicas por ese medio
        // (ej. via un link guardado) es un caso valido - normalizeFilters()
        // valida contra TODOS los ids conocidos, no solo los habilitados.
        [$business, $admin] = $this->admin();
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash', 'label' => 'Efectivo']);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi', 'label' => 'Nequi']);
        $business->posPaymentMethods()->attach([
            $cash->id => ['is_enabled' => true],
            $nequi->id => ['is_enabled' => false],
        ]);
        $nequiSale = Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed',
            'payment_method' => 'nequi', 'closed_at' => '2026-03-01 12:00:00',
        ]);
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed',
            'payment_method' => 'cash', 'closed_at' => '2026-03-01 12:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/history?from=2026-03-01&to=2026-03-31&payment_method=nequi');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $nequiSale->id);
    }

    public function test_sales_history_sort_by_total(): void
    {
        [$business, $admin] = $this->admin();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed',
            'total' => 20000, 'closed_at' => '2026-03-01 12:00:00',
        ]);
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed',
            'total' => 90000, 'closed_at' => '2026-03-02 12:00:00',
        ]);

        $ascending = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/history?from=2026-03-01&to=2026-03-31&sort=total&direction=asc');
        $ascending->assertOk();
        $this->assertSame([20000, 90000], collect($ascending->json('data'))->pluck('total')->all());

        $descending = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/history?from=2026-03-01&to=2026-03-31&sort=total&direction=desc');
        $descending->assertOk();
        $this->assertSame([90000, 20000], collect($descending->json('data'))->pluck('total')->all());
    }

    public function test_sales_history_export_returns_csv(): void
    {
        [$business, $admin] = $this->admin();

        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 30000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => '2026-03-05 09:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/reports/sales/history/export?from=2026-03-01&to=2026-03-31');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ─── by-seller ────────────────────────────────────────────────────────────

    public function test_sales_by_seller_groups_by_closed_by_user(): void
    {
        [$business, $admin] = $this->admin();
        $seller = User::factory()->create(['business_id' => $business->id]);

        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 60000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_by_user_id' => $seller->id,
            'closed_at' => '2026-03-10 15:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/by-seller?from=2026-03-01&to=2026-03-31');

        $response->assertOk()
            ->assertJsonPath('totals.sales_count', 1)
            ->assertJsonPath('totals.gross_total', 60000)
            ->assertJsonCount(1, 'sellers');
    }

    public function test_sales_by_seller_resolves_the_real_label_for_a_disabled_payment_method(): void
    {
        [$business, $admin] = $this->admin();
        $seller = User::factory()->create(['business_id' => $business->id]);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi', 'label' => 'Nequi']);
        $business->posPaymentMethods()->attach([$nequi->id => ['is_enabled' => false]]);

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 20000,
            'is_credit' => false, 'is_non_revenue' => false, 'payment_method' => 'nequi',
            'closed_by_user_id' => $seller->id, 'closed_at' => '2026-03-10 15:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/by-seller?from=2026-03-01&to=2026-03-31')
            ->assertOk();

        $methods = collect($response->json('sellers.0.methods'))->keyBy('id');
        $this->assertSame('Nequi', $methods['nequi']['label'], 'un medio deshabilitado debe seguir mostrando su label real, no el id crudo, en este reporte');
    }

    public function test_by_seller_export_returns_csv(): void
    {
        [, $admin] = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/reports/sales/by-seller/export?from=2026-03-01&to=2026-03-31');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ─── cash closings ────────────────────────────────────────────────────────

    public function test_cash_closings_report_requires_cash_closing_feature(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['cash_closing' => false]]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/cash-closings')
            ->assertForbidden();
    }

    public function test_cash_closings_report_returns_closings_in_date_range(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['cash_closing' => true]]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        CashClosing::factory()->create([
            'business_id' => $business->id,
            'date' => '2026-03-10',
            'total_sales' => 100000,
            'actual_cash' => 80000,
            'expected_cash' => 80000,
            'difference' => 0,
        ]);
        // otro mes - no debe aparecer
        CashClosing::factory()->create([
            'business_id' => $business->id,
            'date' => '2026-02-10',
            'total_sales' => 50000,
            'actual_cash' => 50000,
            'expected_cash' => 50000,
            'difference' => 0,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/cash-closings?month=2026-03');

        $response->assertOk()
            ->assertJsonCount(1, 'closings')
            ->assertJsonPath('closings.0.total_sales', 100000);
    }

    public function test_cash_closings_export_returns_csv(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['cash_closing' => true]]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/reports/cash-closings/export?month=2026-03');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ─── tenant isolation ─────────────────────────────────────────────────────

    public function test_daily_summary_isolates_by_business(): void
    {
        [$business, $admin] = $this->admin();
        $otherBusiness = Business::factory()->create();
        $date = '2026-02-01';

        Sale::factory()->create([
            'business_id' => $otherBusiness->id,
            'status' => 'closed',
            'total' => 99000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => $date.' 10:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/sales/daily?date={$date}");

        $response->assertOk()->assertJsonPath('sales_count', 0);
    }
}
