<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre el unico endpoint por el que el Nexolu IA Core ejecuta capacidades
 * del negocio: autenticacion por API key de aplicacion, validacion del
 * contexto contra la base de datos, permisos/features, argumentos, y el
 * resultado de cada una de las 8 herramientas del catalogo del POS.
 */
class AiToolInvokeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ia_core.api_key' => 'test-ia-core-key']);
        PermissionCatalog::sync();
    }

    private function invoke(array $payload, ?string $key = 'test-ia-core-key')
    {
        $request = $this->postJson('/api/ai/tools/invoke', $payload);

        return $key ? $this->withHeader('Authorization', "Bearer {$key}")->postJson('/api/ai/tools/invoke', $payload) : $request;
    }

    private function context(User $user, array $overrides = []): array
    {
        return array_merge([
            'business_id' => (string) $user->business_id,
            'user_id' => (string) $user->id,
            'is_admin' => false,
            'permissions' => [],
            'features' => [],
            'channel' => 'web',
        ], $overrides);
    }

    public function test_rejects_request_without_a_valid_api_key(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->invoke(['tool' => 'ventas_resumen', 'context' => $this->context($user)], null)
            ->assertStatus(401);

        $this->invoke(['tool' => 'ventas_resumen', 'context' => $this->context($user)], 'wrong-key')
            ->assertStatus(401);
    }

    public function test_rejects_an_unknown_tool(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->invoke(['tool' => 'borrar_todo', 'context' => $this->context($user)])
            ->assertStatus(404);
    }

    public function test_rejects_when_context_user_does_not_belong_to_context_business(): void
    {
        $user = User::factory()->create();
        $otherBusiness = Business::factory()->create();

        $this->invoke([
            'tool' => 'ventas_resumen',
            'context' => $this->context($user, ['business_id' => (string) $otherBusiness->id]),
        ])->assertStatus(422);
    }

    public function test_rejects_when_user_lacks_the_required_permission(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        // Sin 'reports.sales' asignado directo.

        $this->invoke(['tool' => 'ventas_resumen', 'context' => $this->context($employee)])
            ->assertStatus(403);
    }

    public function test_admin_bypasses_the_permission_check_by_role(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->invoke(['tool' => 'ventas_resumen', 'context' => $this->context($admin)])
            ->assertOk()
            ->assertJsonStructure(['data' => ['numero_ventas', 'total_vendido', 'ticket_promedio']]);
    }

    public function test_rejects_when_the_business_feature_is_disabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['expenses' => false]]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->invoke([
            'tool' => 'crear_gasto',
            'arguments' => ['concepto' => 'Papeleria', 'monto' => 5000],
            'context' => $this->context($admin),
        ])->assertStatus(403);
    }

    public function test_validates_tool_arguments(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->invoke([
            'tool' => 'ventas_por_dia',
            'arguments' => [],
            'context' => $this->context($admin),
        ])->assertStatus(422);
    }

    public function test_ventas_resumen_returns_totals_scoped_to_the_context_business(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        Sale::factory()->create(['business_id' => $business->id, 'total' => 10000, 'status' => 'closed']);
        Sale::factory()->create(['business_id' => $business->id, 'total' => 20000, 'status' => 'closed']);
        // Venta de OTRO negocio: no debe contarse.
        Sale::factory()->create(['total' => 999999, 'status' => 'closed']);

        $response = $this->invoke(['tool' => 'ventas_resumen', 'context' => $this->context($admin)]);

        $response->assertOk()
            ->assertJsonPath('data.numero_ventas', 2)
            ->assertJsonPath('data.total_vendido', 30000)
            ->assertJsonPath('data.ticket_promedio', 15000);
    }

    public function test_ventas_por_dia_groups_by_date(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        Sale::factory()->create([
            'business_id' => $business->id, 'total' => 5000, 'status' => 'closed',
            'created_at' => now()->startOfDay(),
        ]);
        Sale::factory()->create([
            'business_id' => $business->id, 'total' => 7000, 'status' => 'closed',
            'created_at' => now()->startOfDay(),
        ]);

        $response = $this->invoke([
            'tool' => 'ventas_por_dia',
            'arguments' => ['desde' => now()->toDateString(), 'hasta' => now()->toDateString()],
            'context' => $this->context($admin),
        ]);

        $response->assertOk();
        $dias = $response->json('data.dias');
        $this->assertCount(1, $dias);
        $this->assertSame(2, $dias[0]['numero_ventas']);
        $this->assertEquals(12000, $dias[0]['total_vendido']);
    }

    public function test_estado_caja_reports_no_open_shift(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->invoke(['tool' => 'estado_caja', 'context' => $this->context($admin)])
            ->assertOk()
            ->assertJsonPath('data.caja_abierta', false);
    }

    public function test_estado_caja_reports_the_open_shift(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        CashShift::factory()->create([
            'business_id' => $business->id,
            'user_id' => $admin->id,
            'opening_cash' => 50000,
        ]);

        $this->invoke(['tool' => 'estado_caja', 'context' => $this->context($admin)])
            ->assertOk()
            ->assertJsonPath('data.caja_abierta', true)
            ->assertJsonPath('data.efectivo_apertura', 50000);
    }

    public function test_inventario_lists_products_filtered_by_category(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $category = ProductCategory::factory()->create(['business_id' => $business->id, 'name' => 'Bebidas']);
        Product::factory()->create(['business_id' => $business->id, 'category_id' => $category->id, 'name' => 'Gaseosa']);
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Otro producto']);

        $response = $this->invoke([
            'tool' => 'inventario',
            'arguments' => ['categoria' => 'Bebidas'],
            'context' => $this->context($admin),
        ]);

        $response->assertOk();
        $productos = $response->json('data.productos');
        $this->assertCount(1, $productos);
        $this->assertSame('Gaseosa', $productos[0]['nombre']);
    }

    public function test_stock_producto_returns_the_current_stock(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Camiseta Talla M', 'stock' => 12]);

        $this->invoke([
            'tool' => 'stock_producto',
            'arguments' => ['producto' => 'Camiseta'],
            'context' => $this->context($admin),
        ])->assertOk()->assertJsonPath('data.stock', 12);
    }

    public function test_stock_producto_errors_when_nothing_matches(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->invoke([
            'tool' => 'stock_producto',
            'arguments' => ['producto' => 'No Existe'],
            'context' => $this->context($admin),
        ])->assertStatus(422);
    }

    public function test_crear_gasto_creates_the_expense_and_resolves_the_type_by_name(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $response = $this->invoke([
            'tool' => 'crear_gasto',
            'arguments' => ['concepto' => 'Papeleria de oficina', 'monto' => 15000, 'tipo_gasto' => 'Insumos'],
            'context' => $this->context($admin),
        ]);

        $response->assertOk()->assertJsonPath('data.tipo_gasto', 'Insumos');

        $this->assertDatabaseHas('expenses', [
            'business_id' => $business->id,
            'description' => 'Papeleria de oficina',
            'value' => 15000,
        ]);
        $this->assertDatabaseHas('expense_types', [
            'business_id' => $business->id,
            'name' => 'Insumos',
        ]);
    }

    public function test_crear_producto_creates_the_product_and_resolves_the_category_by_name(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $response = $this->invoke([
            'tool' => 'crear_producto',
            'arguments' => ['nombre' => 'Cafe Americano', 'precio' => 6000, 'costo' => 2000, 'categoria' => 'Bebidas'],
            'context' => $this->context($admin),
        ]);

        $response->assertOk()->assertJsonPath('data.categoria', 'Bebidas');

        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'name' => 'Cafe Americano',
            'price' => 6000,
        ]);
        $this->assertDatabaseHas('product_categories', [
            'business_id' => $business->id,
            'name' => 'Bebidas',
        ]);
    }

    public function test_crear_cliente_creates_the_client(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $response = $this->invoke([
            'tool' => 'crear_cliente',
            'arguments' => ['nombre' => 'Juan Perez', 'telefono' => '3001234567'],
            'context' => $this->context($admin),
        ]);

        $response->assertOk()->assertJsonPath('data.nombre', 'Juan Perez');

        $this->assertDatabaseHas('clients', [
            'business_id' => $business->id,
            'name' => 'Juan Perez',
            'phone' => '3001234567',
        ]);
    }

    public function test_an_employee_with_the_direct_permission_can_use_a_tool(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['clients.manage']);

        $this->invoke([
            'tool' => 'crear_cliente',
            'arguments' => ['nombre' => 'Cliente Empleado'],
            'context' => $this->context($employee),
        ])->assertOk();
    }
}
