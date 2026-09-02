<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreCart;
use App\Models\User;
use App\Services\AbandonedCartReminder;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Carrito abandonado.
 *
 * Guardar el carrito en el servidor revierte una decision explicita (vivia
 * solo en el navegador, para no acumular basura anonima), asi que lo que
 * estas pruebas defienden es que las condiciones que lo justifican se
 * cumplan: se le escribe UNA sola vez, nunca a quien ya compro, nunca a
 * quien no dejo como contactarlo, y el enlace de recuperacion no es una
 * llave permanente.
 */
class AbandonedCartTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    private function tienda(): Business
    {
        $business = Business::factory()->create(['feature_flags' => ['online_store' => true]]);
        BusinessStoreSettings::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        return $business;
    }

    private function carrito(Business $business, array $attrs = []): StoreCart
    {
        return StoreCart::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'token' => Str::random(64),
            'items' => [['product_id' => 1, 'quantity' => 2, 'name' => 'Camiseta', 'unit_price' => 45000]],
            'subtotal' => 90000,
            'contact_email' => 'compradora@example.com',
            'last_activity_at' => now()->subHours(3),
            ...$attrs,
        ]);
    }

    public function test_la_tienda_puede_espejar_el_carrito(): void
    {
        $business = $this->tienda();
        $token = Str::random(64);

        $this->postJson("/api/v1/storefront/{$business->slug}/cart", [
            'token' => $token,
            'items' => [['product_id' => 1, 'quantity' => 2, 'name' => 'Camiseta', 'unit_price' => 45000]],
            'contact_email' => 'compradora@example.com',
        ])->assertOk();

        $cart = StoreCart::withoutGlobalScopes()->where('token', $token)->first();
        $this->assertNotNull($cart);
        $this->assertSame(90000.0, (float) $cart->subtotal);
        $this->assertSame('compradora@example.com', $cart->contact_email);
    }

    /** Vaciar el carrito no es abandonarlo: es sacar lo que se había puesto. */
    public function test_un_carrito_vaciado_se_borra(): void
    {
        $business = $this->tienda();
        $cart = $this->carrito($business);

        $this->postJson("/api/v1/storefront/{$business->slug}/cart", [
            'token' => $cart->token,
            'items' => [],
        ])->assertOk();

        $this->assertNull(StoreCart::withoutGlobalScopes()->find($cart->id));
    }

    /**
     * Un formulario que el comprador vacio no puede borrar el correo que ya
     * habia dado: seria perder la unica forma de recuperarlo.
     */
    public function test_sincronizar_sin_contacto_no_borra_el_que_ya_estaba(): void
    {
        $business = $this->tienda();
        $cart = $this->carrito($business);

        $this->postJson("/api/v1/storefront/{$business->slug}/cart", [
            'token' => $cart->token,
            'items' => [['product_id' => 1, 'quantity' => 1, 'name' => 'Camiseta', 'unit_price' => 45000]],
            'contact_email' => '',
        ])->assertOk();

        $this->assertSame('compradora@example.com', $cart->fresh()->contact_email);
    }

    public function test_escribe_al_carrito_abandonado(): void
    {
        Mail::fake();
        $business = $this->tienda();
        $cart = $this->carrito($business);

        $enviados = app(AbandonedCartReminder::class)->run($business);

        $this->assertSame(1, $enviados);
        $this->assertNotNull($cart->fresh()->reminded_at);
    }

    /** Insistirle a quien no compró es la vía rápida a que marque spam. */
    public function test_solo_escribe_una_vez(): void
    {
        Mail::fake();
        $business = $this->tienda();
        $this->carrito($business);

        $servicio = app(AbandonedCartReminder::class);
        $this->assertSame(1, $servicio->run($business));
        $this->assertSame(0, $servicio->run($business), 'Un carrito ya recordado no vuelve a la cola.');
    }

    public function test_no_escribe_a_quien_ya_compro(): void
    {
        Mail::fake();
        $business = $this->tienda();
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
        ]);
        $order = Order::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'number' => 1,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 90000,
            'shipping_fee' => 0,
            'total' => 90000,
            'customer_name' => 'Compradora',
            'customer_phone' => '3001112233',
            'is_pickup' => true,
            'public_token' => Str::random(40),
            'expires_at' => now()->addMinutes(20),
        ]);
        $this->carrito($business, ['order_id' => $order->id]);
        $this->assertNotNull($product);

        $this->assertSame(0, app(AbandonedCartReminder::class)->run($business));
    }

    /** Sin correo ni teléfono no hay a quién escribirle. */
    public function test_no_escribe_a_quien_no_dejo_contacto(): void
    {
        Mail::fake();
        $business = $this->tienda();
        $this->carrito($business, ['contact_email' => null, 'contact_phone' => null]);

        $this->assertSame(0, app(AbandonedCartReminder::class)->run($business));
    }

    /** Todavía está comprando en otra pestaña. */
    public function test_no_escribe_a_un_carrito_recien_tocado(): void
    {
        Mail::fake();
        $business = $this->tienda();
        $this->carrito($business, ['last_activity_at' => now()->subMinutes(5)]);

        $this->assertSame(0, app(AbandonedCartReminder::class)->run($business));
    }

    /** Un carrito de hace tres días no se recupera con un correo. */
    public function test_no_escribe_a_un_carrito_demasiado_viejo(): void
    {
        Mail::fake();
        $business = $this->tienda();
        $this->carrito($business, ['last_activity_at' => now()->subDays(4)]);

        $this->assertSame(0, app(AbandonedCartReminder::class)->run($business));
    }

    public function test_recuperar_exige_una_url_firmada(): void
    {
        $business = $this->tienda();
        $cart = $this->carrito($business);

        // Sin firma: un token adivinado no puede abrir el carrito de otro.
        $this->getJson("/api/v1/storefront/{$business->slug}/cart/recover?token={$cart->token}")
            ->assertForbidden();
    }

    public function test_una_url_firmada_devuelve_el_carrito(): void
    {
        $business = $this->tienda();
        $cart = $this->carrito($business);

        $url = URL::temporarySignedRoute(
            'api.v1.storefront.cart.recover',
            now()->addHour(),
            ['business' => $business->slug, 'token' => $cart->token],
        );

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('token', $cart->token)
            ->assertJsonCount(1, 'items');
    }

    /** El negocio sin tienda online no tiene carritos que recuperar. */
    public function test_un_negocio_sin_tienda_no_manda_nada(): void
    {
        Mail::fake();
        $business = Business::factory()->create(['feature_flags' => ['online_store' => false]]);

        $this->assertSame(0, app(AbandonedCartReminder::class)->run($business));
    }
}
