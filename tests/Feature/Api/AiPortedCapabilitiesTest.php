<?php

namespace Tests\Feature\Api;

use App\Capabilities\Registry;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\Ingredient;
use App\Models\Layaway;
use App\Models\LayawayItem;
use App\Models\LayawayPayment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Receivable;
use App\Models\Reminder;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las 28 capacidades portadas del chat del legacy (2026-09-02).
 *
 * Las 8 originales viven en AiToolInvokeTest, que ademas cubre el endpoint en
 * si (API key, contexto, permisos). Aca solo se prueba el comportamiento de
 * cada capacidad nueva.
 */
class AiPortedCapabilitiesTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ia_core.api_key' => 'test-ia-core-key']);
        PermissionCatalog::sync();

        $this->business = Business::factory()->create();
        $this->admin = User::factory()->create(['business_id' => $this->business->id]);
        $this->admin->assignRole('admin');
    }

    /** @param  array<string, mixed>  $arguments */
    private function invoke(string $tool, array $arguments = []): array
    {
        $response = $this->withHeader('Authorization', 'Bearer test-ia-core-key')
            ->postJson('/api/ai/tools/invoke', [
                'tool' => $tool,
                'arguments' => $arguments,
                'context' => [
                    'business_id' => (string) $this->business->id,
                    'user_id' => (string) $this->admin->id,
                ],
            ]);

        $response->assertOk();

        return $response->json('data');
    }

    private function closedSale(array $attributes = []): Sale
    {
        return Sale::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'closed_by_user_id' => $this->admin->id,
            'status' => 'closed',
            'is_non_revenue' => false,
            'is_credit' => false,
            'closed_at' => now()->subDay(),
        ], $attributes));
    }

    /**
     * El riesgo real de agregar 28 herramientas de un golpe: un permiso mal
     * escrito. No rompe ningun test de comportamiento (el admin pasa por rol)
     * y en produccion deja la herramienta inaccesible para todo empleado, sin
     * error visible. Este test lo vuelve imposible.
     */
    public function test_every_registered_permission_exists_in_the_catalog(): void
    {
        $registry = new Registry;
        $known = PermissionCatalog::permissions();

        foreach ($registry->names() as $tool) {
            $permission = $registry->resolve($tool)->requiredPermission();

            if ($permission !== null) {
                $this->assertContains($permission, $known, "La herramienta '{$tool}' pide un permiso que no existe: '{$permission}'.");
            }
        }
    }

    public function test_ventas_historico_summarises_the_whole_history_month_by_month(): void
    {
        $this->closedSale(['total' => 100000, 'closed_at' => now()->subMonths(2)]);
        $this->closedSale(['total' => 300000, 'closed_at' => now()->subDay()]);
        // Una cortesia no es ingreso y no debe entrar en el total.
        $this->closedSale(['total' => 999999, 'is_non_revenue' => true]);

        $data = $this->invoke('ventas_historico');

        $this->assertTrue($data['tiene_ventas']);
        $this->assertEquals(400000.0, $data['total_vendido']);
        $this->assertSame(2, $data['meses_con_ventas']);
        $this->assertEquals(200000.0, $data['promedio_mensual']);
        $this->assertEquals(300000.0, $data['mejor_mes']['total']);
    }

    public function test_ventas_historico_says_so_when_there_are_no_sales(): void
    {
        $data = $this->invoke('ventas_historico');

        $this->assertFalse($data['tiene_ventas']);
    }

    public function test_ventas_por_vendedor_groups_by_who_closed_the_sale(): void
    {
        $other = User::factory()->create(['business_id' => $this->business->id, 'name' => 'Marta Lopez']);
        $this->closedSale(['total' => 50000]);
        $this->closedSale(['total' => 70000, 'closed_by_user_id' => $other->id]);

        $data = $this->invoke('ventas_por_vendedor', ['nombre_vendedor' => 'marta']);

        $this->assertCount(1, $data['vendedores']);
        $this->assertSame('Marta Lopez', $data['vendedores'][0]['vendedor']);
        $this->assertEquals(70000.0, $data['vendedores'][0]['total_vendido']);
    }

    public function test_productos_top_orders_by_units_sold(): void
    {
        $popular = Product::factory()->create(['business_id' => $this->business->id, 'name' => 'Empanada']);
        $rare = Product::factory()->create(['business_id' => $this->business->id, 'name' => 'Torta']);
        $sale = $this->closedSale();

        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $popular->id, 'quantity' => 10, 'subtotal' => 20000]);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $rare->id, 'quantity' => 1, 'subtotal' => 8000]);

        $data = $this->invoke('productos_top');

        $this->assertSame('Empanada', $data['productos'][0]['producto']);
        $this->assertEquals(10.0, $data['productos'][0]['unidades']);
    }

    public function test_cuentas_abiertas_only_lists_open_sales(): void
    {
        $this->closedSale();
        Sale::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'closed_at' => null,
            'total' => 45000,
        ]);

        $data = $this->invoke('cuentas_abiertas');

        $this->assertSame(1, $data['total_cuentas_abiertas']);
        $this->assertEquals(45000.0, $data['consumo_total']);
    }

    public function test_historial_cuenta_needs_a_client_or_a_sale_id(): void
    {
        $data = $this->invoke('historial_cuenta');

        $this->assertArrayHasKey('error', $data);
    }

    public function test_historial_cuenta_finds_the_sale_by_id_with_its_items(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id, 'name' => 'Chocorramo']);
        $sale = $this->closedSale(['customer_name' => 'Juan', 'total' => 3000]);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 1, 'subtotal' => 3000]);

        $data = $this->invoke('historial_cuenta', ['venta_id' => $sale->id]);

        $this->assertCount(1, $data['resultados']);
        $this->assertSame('Juan', $data['resultados'][0]['cliente']);
        $this->assertSame('Chocorramo', $data['resultados'][0]['items'][0]['producto']);
    }

    /**
     * El punto de haber dejado de leer la tabla `customers` del legacy: este
     * POS no la escribe, asi que una venta hecha aca tiene que aparecer igual.
     */
    public function test_clientes_frecuentes_is_computed_from_sales_not_from_the_legacy_customers_table(): void
    {
        $this->closedSale(['customer_name' => 'Pedro Ruiz', 'customer_phone' => '3001112233', 'total' => 20000]);
        $this->closedSale(['customer_name' => 'Pedro Ruiz', 'customer_phone' => '3001112233', 'total' => 30000]);
        $this->closedSale(['customer_name' => 'Ana Diaz', 'customer_phone' => '3009998877', 'total' => 5000]);

        $data = $this->invoke('clientes_frecuentes');

        $this->assertSame('Pedro Ruiz', $data['clientes'][0]['cliente']);
        $this->assertSame(2, $data['clientes'][0]['visitas']);
        $this->assertEquals(50000.0, $data['clientes'][0]['total_gastado']);
        $this->assertEquals(25000.0, $data['clientes'][0]['ticket_promedio']);
    }

    public function test_clientes_frecuentes_lost_clients_excludes_one_time_buyers(): void
    {
        $this->closedSale(['customer_name' => 'Habitual', 'customer_phone' => '300111', 'closed_at' => now()->subMonths(3)]);
        $this->closedSale(['customer_name' => 'Habitual', 'customer_phone' => '300111', 'closed_at' => now()->subMonths(2)]);
        $this->closedSale(['customer_name' => 'De paso', 'customer_phone' => '300222', 'closed_at' => now()->subYear()]);

        $data = $this->invoke('clientes_frecuentes', ['orden' => 'hace_mas_que_no_vienen']);

        $this->assertCount(1, $data['clientes']);
        $this->assertSame('Habitual', $data['clientes'][0]['cliente']);
    }

    public function test_fiados_pendientes_groups_by_customer_key(): void
    {
        Receivable::factory()->count(2)->create([
            'business_id' => $this->business->id,
            'customer_key' => 'juan-300',
            'customer_name' => 'Juan',
            'status' => 'pending',
            'balance' => 15000,
        ]);
        Receivable::factory()->create([
            'business_id' => $this->business->id,
            'customer_key' => 'otro-juan-301',
            'customer_name' => 'Juan',
            'status' => 'pending',
            'balance' => 5000,
        ]);

        $data = $this->invoke('fiados_pendientes');

        $this->assertSame(2, $data['total_clientes_con_deuda']);
        $this->assertEquals(35000.0, $data['saldo_total_pendiente']);
        $this->assertEquals(30000.0, $data['clientes'][0]['saldo_pendiente']);
    }

    public function test_gastos_resumen_breaks_down_by_type(): void
    {
        $type = ExpenseType::factory()->create(['business_id' => $this->business->id, 'name' => 'Arriendo']);
        Expense::factory()->create([
            'business_id' => $this->business->id,
            'type_id' => $type->id,
            'value' => 800000,
            'date' => now()->subDay()->toDateString(),
        ]);

        $data = $this->invoke('gastos_resumen');

        $this->assertEquals(800000.0, $data['total_gastos']);
        $this->assertSame('Arriendo', $data['por_tipo'][0]['tipo']);
    }

    public function test_proveedores_reports_purchase_history_per_supplier(): void
    {
        $supplier = Supplier::factory()->create(['business_id' => $this->business->id, 'name' => 'Postobon']);
        $purchase = Purchase::factory()->create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->admin->id,
            'purchased_at' => now()->subDays(3)->toDateString(),
        ]);
        PurchaseLine::factory()->create(['purchase_id' => $purchase->id, 'line_total_cop' => 60000]);

        $data = $this->invoke('proveedores');

        $this->assertSame('Postobon', $data['proveedores'][0]['proveedor']);
        $this->assertSame(1, $data['proveedores'][0]['numero_compras']);
        $this->assertEquals(60000.0, $data['proveedores'][0]['total_comprado']);
    }

    public function test_cuentas_por_pagar_only_lists_purchases_with_balance(): void
    {
        $supplier = Supplier::factory()->create(['business_id' => $this->business->id, 'name' => 'Bavaria']);
        $pending = Purchase::factory()->create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->admin->id,
            'payment_status' => 'pending',
            'purchased_at' => now()->subDays(10)->toDateString(),
        ]);
        PurchaseLine::factory()->create(['purchase_id' => $pending->id, 'line_total_cop' => 100000]);

        $paid = Purchase::factory()->create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->admin->id,
            'payment_status' => 'paid',
            'purchased_at' => now()->subDays(5)->toDateString(),
        ]);
        PurchaseLine::factory()->create(['purchase_id' => $paid->id, 'line_total_cop' => 500000]);

        $data = $this->invoke('cuentas_por_pagar');

        $this->assertSame(1, $data['total_proveedores_con_deuda']);
        $this->assertEquals(100000.0, $data['saldo_total_pendiente']);
    }

    public function test_apartados_reports_paid_and_outstanding(): void
    {
        $layaway = Layaway::factory()->create(['business_id' => $this->business->id, 'customer_name' => 'Sofia']);
        LayawayItem::factory()->create(['layaway_id' => $layaway->id, 'quantity' => 2, 'unit_price' => 50000]);
        LayawayPayment::factory()->create(['layaway_id' => $layaway->id, 'business_id' => $this->business->id, 'amount' => 30000]);

        $data = $this->invoke('apartados');

        $this->assertEquals(100000.0, $data['apartados'][0]['total']);
        $this->assertEquals(30000.0, $data['apartados'][0]['abonado']);
        $this->assertEquals(70000.0, $data['apartados'][0]['saldo_pendiente']);
    }

    /** Preguntar por citas es preguntar por lo que VIENE, no por lo que paso. */
    public function test_citas_agendadas_looks_forward_by_default(): void
    {
        Appointment::factory()->create([
            'business_id' => $this->business->id,
            'starts_at' => now()->addDays(2),
            'client_name' => 'Camila',
            'status' => 'confirmed',
        ]);
        Appointment::factory()->create([
            'business_id' => $this->business->id,
            'starts_at' => now()->subDays(10),
            'client_name' => 'Vieja',
            'status' => 'confirmed',
        ]);

        $data = $this->invoke('citas_agendadas');

        $this->assertSame(1, $data['total_citas']);
        $this->assertSame('Camila', $data['citas'][0]['cliente']);
    }

    public function test_recordatorios_pendientes_flags_overdue_ones(): void
    {
        Reminder::factory()->create([
            'business_id' => $this->business->id,
            'created_by_user_id' => $this->admin->id,
            'title' => 'Pagarle a Postobon',
            'due_date' => now()->subDays(3)->toDateString(),
            'status' => 'pending',
        ]);

        $data = $this->invoke('recordatorios_pendientes');

        $this->assertSame(1, $data['total_pendientes']);
        $this->assertTrue($data['recordatorios'][0]['vencido']);
        $this->assertSame(-3, $data['recordatorios'][0]['dias_desde_hoy']);
    }

    public function test_calcular_precio_derives_the_price_from_a_target_margin(): void
    {
        $data = $this->invoke('calcular_precio', ['costo' => 10000, 'margen_deseado' => 30]);

        // Margen sobre el PRECIO, no markup sobre el costo: 10000 / 0.7.
        $this->assertSame(14285.71, $data['precio_sugerido']);
        $this->assertSame(4285.71, $data['utilidad_por_unidad']);
    }

    public function test_calcular_precio_warns_when_the_price_is_below_cost(): void
    {
        $data = $this->invoke('calcular_precio', ['costo' => 10000, 'precio_venta' => 8000]);

        $this->assertEquals(-2000.0, $data['utilidad_por_unidad']);
        $this->assertArrayHasKey('nota', $data);
    }

    public function test_calcular_precio_rejects_giving_both_margin_and_price(): void
    {
        $this->withHeader('Authorization', 'Bearer test-ia-core-key')
            ->postJson('/api/ai/tools/invoke', [
                'tool' => 'calcular_precio',
                'arguments' => ['costo' => 1000, 'margen_deseado' => 30, 'precio_venta' => 2000],
                'context' => ['business_id' => (string) $this->business->id, 'user_id' => (string) $this->admin->id],
            ])
            ->assertStatus(422);
    }

    public function test_historial_stock_excludes_sales_and_reports_who_moved_it(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Gaseosa de manzana 500 ml',
            'track_stock' => true,
        ]);

        StockMovement::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'user_id' => $this->admin->id,
            'type' => 'entry',
            'quantity' => 12,
        ]);
        StockMovement::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'user_id' => $this->admin->id,
            'type' => 'sale',
            'quantity' => -1,
        ]);

        // Escrito como lo diria una persona: ni el plural ni el "500 ml"
        // coinciden literalmente con el nombre del catalogo.
        $data = $this->invoke('historial_stock', ['nombre' => 'gaseosas de manzana']);

        $this->assertCount(1, $data['resultados']);
        $this->assertCount(1, $data['resultados'][0]['movimientos']);
        $this->assertSame('entrada', $data['resultados'][0]['movimientos'][0]['tipo_movimiento']);
    }

    public function test_variacion_costo_producto_compares_suppliers_by_weighted_average(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id, 'name' => 'Arroz']);
        $cheap = Supplier::factory()->create(['business_id' => $this->business->id, 'name' => 'Distribuidora Sur']);
        $pricey = Supplier::factory()->create(['business_id' => $this->business->id, 'name' => 'Tienda Norte']);

        foreach ([[$cheap, 2000.0], [$pricey, 3000.0]] as [$supplier, $unitCost]) {
            $purchase = Purchase::factory()->create([
                'business_id' => $this->business->id,
                'supplier_id' => $supplier->id,
                'user_id' => $this->admin->id,
                'purchased_at' => now()->subDays(2)->toDateString(),
            ]);
            PurchaseLine::factory()->create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost_cop' => $unitCost,
                'line_total_cop' => $unitCost * 10,
            ]);
        }

        $data = $this->invoke('variacion_costo_producto', ['nombre_producto' => 'arroz']);

        $this->assertEquals(2000.0, $data['costo_minimo']);
        $this->assertEquals(3000.0, $data['costo_maximo']);
        $this->assertEquals(50.0, $data['variacion_porcentaje']);
        $this->assertSame('Distribuidora Sur', $data['comparativa_proveedores'][0]['proveedor']);
    }

    public function test_crear_recordatorio_creates_it(): void
    {
        $data = $this->invoke('crear_recordatorio', [
            'titulo' => 'Revisar la nevera',
            'fecha' => now()->addWeek()->toDateString(),
            'recurrencia' => 'weekly',
        ]);

        $this->assertDatabaseHas('reminders', [
            'id' => $data['id'],
            'business_id' => $this->business->id,
            'title' => 'Revisar la nevera',
            'recurrence' => 'weekly',
        ]);
    }

    public function test_crear_entrada_inventario_adds_to_the_current_stock(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Empanada de pollo',
            'track_stock' => true,
            'stock' => 5,
        ]);

        $data = $this->invoke('crear_entrada_inventario', ['producto' => 'empanadas de pollo', 'cantidad' => 15]);

        // SUMA, no reemplaza: es la confusion que mas dano hace en esta
        // herramienta.
        $this->assertEquals(20.0, $data['stock_resultante']);
    }

    public function test_crear_entrada_inventario_refuses_to_guess_between_two_products(): void
    {
        Product::factory()->create(['business_id' => $this->business->id, 'name' => 'Gaseosa de manzana', 'track_stock' => true]);
        Product::factory()->create(['business_id' => $this->business->id, 'name' => 'Gaseosa de naranja', 'track_stock' => true]);

        $response = $this->withHeader('Authorization', 'Bearer test-ia-core-key')
            ->postJson('/api/ai/tools/invoke', [
                'tool' => 'crear_entrada_inventario',
                'arguments' => ['producto' => 'gaseosas', 'cantidad' => 10],
                'context' => ['business_id' => (string) $this->business->id, 'user_id' => (string) $this->admin->id],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('coincide con varios', $response->json('error'));
    }

    public function test_crear_compra_registers_the_purchase_and_moves_stock(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Gaseosa Postobon',
            'track_stock' => true,
            'stock' => 0,
        ]);
        Supplier::factory()->create(['business_id' => $this->business->id, 'name' => 'Postobon']);

        $data = $this->invoke('crear_compra', [
            'articulo' => 'gaseosa postobon',
            'cantidad' => 20,
            'valor_total' => 60000,
            'proveedor' => 'postobon',
        ]);

        $this->assertEquals(60000.0, $data['total']);
        $this->assertEquals(20.0, (float) $product->fresh()->stock);
    }

    public function test_crear_proveedor_registers_it(): void
    {
        $data = $this->invoke('crear_proveedor', ['nombre' => 'Postobon', 'telefono' => '3001234567']);

        $this->assertDatabaseHas('suppliers', [
            'id' => $data['id'],
            'business_id' => $this->business->id,
            'name' => 'Postobon',
        ]);
    }

    /**
     * Duplicar un proveedor parte su historial de compras en dos, y a partir
     * de ahi "cuanto le he comprado a X" responde mal para siempre.
     */
    public function test_crear_proveedor_refuses_to_duplicate_an_existing_one(): void
    {
        Supplier::factory()->create(['business_id' => $this->business->id, 'name' => 'Postobon SA']);

        $response = $this->withHeader('Authorization', 'Bearer test-ia-core-key')
            ->postJson('/api/ai/tools/invoke', [
                'tool' => 'crear_proveedor',
                'arguments' => ['nombre' => 'postobon sa'],
                'context' => ['business_id' => (string) $this->business->id, 'user_id' => (string) $this->admin->id],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Ya existe un proveedor', $response->json('error'));
    }

    public function test_crear_entrada_ingrediente_returns_the_configured_unit(): void
    {
        $this->business->update(['feature_flags' => ['ingredients' => true, 'inventory' => true]]);
        $ingredient = Ingredient::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Queso',
            'unit' => 'kg',
            'stock' => 2,
        ]);

        $data = $this->invoke('crear_entrada_ingrediente', ['ingrediente' => 'queso', 'cantidad' => 5]);

        $this->assertSame('kg', $data['unidad']);
        $this->assertEquals(7.0, $data['stock_resultante']);
        $this->assertEquals(7.0, (float) $ingredient->fresh()->stock);
    }
}
