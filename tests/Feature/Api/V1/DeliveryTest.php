<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Domicilios: el domiciliario es un empleado con el permiso
 * deliveries.handle. Ve lo que esta listo para salir (venga de Vender, de una
 * cuenta abierta o de un pedido online), lo toma y lo marca entregado.
 */
class DeliveryTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'digital_menu' => true, 'online_store' => false, 'kitchen_board' => true],
            'delivery_enabled' => true,
            'delivery_fee' => 4000,
            'service_charge_enabled' => false,
            'ipoconsumo_enabled' => false,
        ]);
        BusinessStoreSettings::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);
        $this->owner = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => true]);
        $this->owner->assignRole('admin');

        $category = ProductCategory::factory()->create(['business_id' => $this->business->id, 'is_published' => true]);
        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
            'track_stock' => false,
            'price' => 20000,
        ]);
        Permission::findOrCreate('deliveries.handle', 'web');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function courier(string $name = 'Pedro'): User
    {
        $courier = User::factory()->create(['business_id' => $this->business->id, 'name' => $name, 'is_business_owner' => false]);
        $courier->assignRole('employee');
        $courier->givePermissionTo('deliveries.handle');

        return $courier;
    }

    private function counterDelivery(array $payload = []): int
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'is_delivery' => true,
            'delivery_address' => 'Calle 10 # 5-20, Sibaté',
            'customer_name' => 'Marta',
            'customer_phone' => '3001112233',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            ...$payload,
        ])->assertCreated()->json('id');
    }

    private function kitchenReady(int $saleId): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$saleId}/status", ['kitchen_status' => 'ready'])
            ->assertOk();
    }

    public function test_a_counter_delivery_goes_through_the_kitchen_before_the_courier(): void
    {
        $saleId = $this->counterDelivery();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertCreated();

        // La venta del mostrador sin domicilio no va a cocina; la de domicilio si.
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/kitchen/tickets')
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $saleId)
            ->assertJsonPath('0.delivery_address', 'Calle 10 # 5-20, Sibaté');
        $courier = $this->courier();
        $this->actingAs($courier, 'sanctum')->getJson('/api/v1/deliveries')
            ->assertJsonPath('0.is_ready', false);

        $this->kitchenReady($saleId);

        $this->actingAs($courier, 'sanctum')
            ->getJson('/api/v1/deliveries')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $saleId)
            ->assertJsonPath('0.address', 'Calle 10 # 5-20, Sibaté')
            ->assertJsonPath('0.status', 'pending')
            ->assertJsonPath('0.is_ready', true)
            ->assertJsonPath('0.total', 24000)
            ->assertJsonPath('0.payment_method', 'cash');
    }

    public function test_the_courier_takes_it_and_only_they_mark_it_delivered(): void
    {
        $saleId = $this->counterDelivery();
        $this->kitchenReady($saleId);
        $pedro = $this->courier('Pedro');
        $juan = $this->courier('Juan');

        $this->actingAs($pedro, 'sanctum')->postJson("/api/v1/deliveries/{$saleId}/take")
            ->assertOk()
            ->assertJsonPath('status', 'on_the_way')
            ->assertJsonPath('courier.name', 'Pedro');

        $this->actingAs($juan, 'sanctum')->postJson("/api/v1/deliveries/{$saleId}/take")
            ->assertUnprocessable()
            ->assertJsonPath('errors.delivery.0', 'Este domicilio ya lo lleva Pedro.');
        $this->actingAs($juan, 'sanctum')->postJson("/api/v1/deliveries/{$saleId}/deliver")
            ->assertUnprocessable();

        $this->actingAs($pedro, 'sanctum')->postJson("/api/v1/deliveries/{$saleId}/deliver")
            ->assertOk()
            ->assertJsonPath('status', 'delivered');

        // Los entregados de hoy siguen en la lista, para cuadrar la plata, y
        // ya no estan en la comandera.
        $this->actingAs($pedro, 'sanctum')->getJson('/api/v1/deliveries')
            ->assertJsonPath('0.status', 'delivered');
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/kitchen/tickets')->assertJsonCount(0);
    }

    public function test_without_the_permission_an_employee_cannot_see_deliveries(): void
    {
        $cashier = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => false]);
        $cashier->assignRole('employee');

        $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/deliveries')->assertForbidden();
    }

    public function test_an_online_order_waits_for_the_kitchen_and_the_buyer_sees_it_on_the_way(): void
    {
        $this->postJson("/api/v1/storefront/{$this->business->slug}/orders", [
            'customer_name' => 'Ana',
            'customer_phone' => '3001234567',
            'is_pickup' => false,
            'shipping_address' => 'Cra 7 # 1-10',
            'shipping_city' => 'Sibaté',
            'shipping_notes' => 'Timbre dañado',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertCreated();
        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => Order::STATUS_CONFIRMED])
            ->assertOk();
        $saleId = $order->fresh()->sale_id;
        $courier = $this->courier();

        $this->actingAs($courier, 'sanctum')->getJson('/api/v1/deliveries')
            ->assertJsonPath('0.order_number', $order->number)
            ->assertJsonPath('0.address', 'Cra 7 # 1-10, Sibaté')
            ->assertJsonPath('0.notes', 'Timbre dañado')
            ->assertJsonPath('0.is_ready', false);
        $this->actingAs($courier, 'sanctum')->postJson("/api/v1/deliveries/{$saleId}/take")
            ->assertUnprocessable()
            ->assertJsonPath('errors.delivery.0', 'Todavía está en cocina.');

        // La comandera no lo despacha: sale cuando lo toma el domiciliario.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$saleId}/status", ['kitchen_status' => 'ready'])
            ->assertOk();
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$saleId}/dispatch")
            ->assertUnprocessable();

        $this->actingAs($courier, 'sanctum')->postJson("/api/v1/deliveries/{$saleId}/take")->assertOk();
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/kitchen/tickets')->assertJsonCount(0);

        $this->actingAs($courier, 'sanctum')->postJson("/api/v1/deliveries/{$saleId}/deliver")->assertOk();
        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
    }

    public function test_an_open_tab_delivery_is_collected_on_delivery(): void
    {
        $tabId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/open-tabs', [
            'customer_name' => 'Luis',
            'is_delivery' => true,
            'delivery_address' => 'Av 3 # 4-5',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertCreated()->json('id');
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$tabId}/status", ['kitchen_status' => 'ready'])
            ->assertOk();

        $this->actingAs($this->courier(), 'sanctum')->getJson('/api/v1/deliveries')
            ->assertJsonPath('0.id', $tabId)
            ->assertJsonPath('0.is_open_tab', true)
            ->assertJsonPath('0.is_ready', true)
            ->assertJsonPath('0.total', 24000);
    }

    public function test_a_sale_with_name_and_phone_creates_the_client_or_reuses_it(): void
    {
        $first = $this->counterDelivery(['customer_name' => 'Rosa Nueva', 'customer_phone' => '3109998877']);
        $client = Client::query()->where('phone', '3109998877')->firstOrFail();
        $this->assertSame('Rosa Nueva', $client->name);
        $this->assertSame($client->id, Sale::findOrFail($first)->client_id);
        $this->assertSame('Calle 10 # 5-20, Sibaté', $client->address, 'Su primera entrega queda como su direccion');

        // Mismo telefono: la misma ficha, no una repetida.
        $second = $this->counterDelivery(['customer_name' => 'Rosa', 'customer_phone' => '3109998877']);
        $this->assertSame($client->id, Sale::findOrFail($second)->client_id);
        $this->assertSame(1, Client::query()->where('phone', '3109998877')->count());

        // Solo el nombre (la cuenta "Juan" de una mesa) no crea ficha.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'customer_name' => 'Juan',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('client_id', null);
    }

    public function test_the_client_keeps_a_usual_address_apart_from_each_delivery(): void
    {
        $client = Client::factory()->create(['business_id' => $this->business->id, 'address' => null]);

        // Sin direccion habitual: la primera entrega la llena.
        $this->counterDelivery(['client_id' => $client->id, 'delivery_address' => 'Casa: Calle 1']);
        $this->assertSame('Casa: Calle 1', $client->fresh()->address);

        // Pidio a la oficina: la ficha no cambia salvo que se pida.
        $office = $this->counterDelivery(['client_id' => $client->id, 'delivery_address' => 'Oficina: Calle 99']);
        $this->assertSame('Casa: Calle 1', $client->fresh()->address);
        $this->assertSame('Oficina: Calle 99', Sale::findOrFail($office)->delivery_address);

        $this->counterDelivery([
            'client_id' => $client->id,
            'delivery_address' => 'Nueva casa: Calle 50',
            'save_address_to_client' => true,
        ]);
        $this->assertSame('Nueva casa: Calle 50', $client->fresh()->address);
        $this->assertSame('Oficina: Calle 99', Sale::findOrFail($office)->delivery_address, 'Cada entrega conserva la suya');
    }
}
