<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La bandeja de pedidos de WhatsApp: listar, aceptar (misma conversion que
 * el camino automatico, ya con el empleado como responsable) y rechazar
 * (con aviso al cliente).
 */
class WhatsappOrderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.comms_core.driver' => 'whatsapp_direct',
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200)]);
    }

    private function adminUser(): User
    {
        $business = Business::factory()->create(['feature_flags' => null]);
        $admin = User::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function pendingOrder(Business $business, Product $product): WhatsappOrder
    {
        return WhatsappOrder::query()->create([
            'business_id' => $business->id,
            'wamid' => 'wamid.pending-'.uniqid(),
            'phone' => '573001112233',
            'customer_name' => 'Laura Cliente',
            'raw_order' => [
                'catalog_id' => 'cat-1',
                'text' => 'sin cebolla porfa',
                'product_items' => [
                    ['product_retailer_id' => $product->whatsappRetailerId(), 'quantity' => 2],
                ],
            ],
            'status' => WhatsappOrder::STATUS_PENDING_REVIEW,
            'error' => 'No hay stock suficiente.',
        ]);
    }

    public function test_lists_orders_of_the_business_with_raw_lines(): void
    {
        $admin = $this->adminUser();
        $product = Product::factory()->create(['business_id' => $admin->business_id, 'price' => 9000]);
        $this->pendingOrder($admin->business, $product);

        // Pedido de OTRO negocio: no aparece (BelongsToBusiness).
        $other = $this->adminUser();
        $otherProduct = Product::factory()->create(['business_id' => $other->business_id]);
        $this->pendingOrder($other->business, $otherProduct);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/whatsapp-orders');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('sin cebolla porfa', $response->json('data.0.note'));
        $this->assertSame(2, $response->json('data.0.items.0.quantity'));
    }

    public function test_accepting_converts_it_into_an_open_sale(): void
    {
        $admin = $this->adminUser();
        $product = Product::factory()->create([
            'business_id' => $admin->business_id,
            'price' => 9000,
            'stock' => 10,
            'track_stock' => true,
        ]);
        $order = $this->pendingOrder($admin->business, $product);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/whatsapp-orders/{$order->id}/accept");

        $response->assertOk();
        $this->assertSame(WhatsappOrder::STATUS_CREATED, $order->refresh()->status);

        $sale = Sale::query()->findOrFail($order->sale_id);
        $this->assertSame('open', $sale->status);
        $this->assertSame(18000.0, (float) $sale->total);
        $this->assertSame($admin->id, (int) $sale->user_id);

        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'confirmado'));
    }

    public function test_accepting_twice_is_rejected(): void
    {
        $admin = $this->adminUser();
        $product = Product::factory()->create([
            'business_id' => $admin->business_id, 'price' => 9000, 'stock' => 10,
        ]);
        $order = $this->pendingOrder($admin->business, $product);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/whatsapp-orders/{$order->id}/accept")->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/whatsapp-orders/{$order->id}/accept")
            ->assertStatus(422);
    }

    public function test_rejecting_notifies_the_customer_with_the_reason(): void
    {
        $admin = $this->adminUser();
        $product = Product::factory()->create(['business_id' => $admin->business_id]);
        $order = $this->pendingOrder($admin->business, $product);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/whatsapp-orders/{$order->id}/reject", ['reason' => 'Cerramos temprano hoy']);

        $response->assertOk();
        $this->assertSame(WhatsappOrder::STATUS_REJECTED, $order->refresh()->status);
        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'Cerramos temprano hoy'));
    }

    public function test_an_order_from_another_business_is_not_reachable(): void
    {
        $admin = $this->adminUser();
        $other = $this->adminUser();
        $otherProduct = Product::factory()->create(['business_id' => $other->business_id]);
        $foreign = $this->pendingOrder($other->business, $otherProduct);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/whatsapp-orders/{$foreign->id}/accept")
            ->assertNotFound();
    }
}
