<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Reseñas de la tienda online.
 *
 * Lo que estas pruebas defienden: el `public_token` de un pedido es una
 * credencial, y solo habilita reseñar LO QUE ESE PEDIDO LLEVO. Sin ese
 * candado, un token cualquiera serviria para inundar de reseñas el catalogo
 * entero de un negocio -- o el de otro.
 */
class StorefrontReviewTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    private function storefrontBusiness(): Business
    {
        $business = Business::factory()->create(['feature_flags' => ['online_store' => true]]);

        BusinessStoreSettings::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
        ]);

        return $business;
    }

    private function publishedProduct(Business $business, array $attributes = []): Product
    {
        return Product::factory()->create([
            'business_id' => $business->id,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
            ...$attributes,
        ]);
    }

    /** Un pedido entregado con ese producto: el escenario que SI habilita reseñar. */
    private function deliveredOrder(Business $business, Product $product): Order
    {
        $order = Order::factory()->delivered()->create(['business_id' => $business->id]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
        ]);

        return $order;
    }

    public function test_un_comprador_puede_calificar_lo_que_compro(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);
        $order = $this->deliveredOrder($business, $product);

        $response = $this->postJson(
            "/api/v1/storefront/{$business->slug}/orders/{$order->public_token}/reviews",
            ['product_id' => $product->id, 'rating' => 5, 'comment' => 'Excelente']
        );

        $response->assertCreated();
        $this->assertDatabaseHas('product_reviews', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'rating' => 5,
            // Nace pendiente: nada llega a internet sin que el comerciante lo apruebe.
            'status' => ProductReview::STATUS_PENDING,
        ]);
    }

    /** El candado principal: el token no habilita el catalogo entero. */
    public function test_no_se_puede_calificar_un_producto_que_no_estaba_en_el_pedido(): void
    {
        $business = $this->storefrontBusiness();
        $comprado = $this->publishedProduct($business);
        $otro = $this->publishedProduct($business);
        $order = $this->deliveredOrder($business, $comprado);

        $this->postJson(
            "/api/v1/storefront/{$business->slug}/orders/{$order->public_token}/reviews",
            ['product_id' => $otro->id, 'rating' => 5]
        )->assertStatus(422);

        $this->assertDatabaseMissing('product_reviews', ['product_id' => $otro->id]);
    }

    /** Ni el catalogo de OTRO negocio. */
    public function test_el_token_de_un_negocio_no_sirve_para_calificar_en_otro(): void
    {
        $businessA = $this->storefrontBusiness();
        $businessB = $this->storefrontBusiness();

        $productA = $this->publishedProduct($businessA);
        $productB = $this->publishedProduct($businessB);
        $order = $this->deliveredOrder($businessA, $productA);

        // El token es de A, pero se intenta usar por la URL publica de B.
        $this->postJson(
            "/api/v1/storefront/{$businessB->slug}/orders/{$order->public_token}/reviews",
            ['product_id' => $productB->id, 'rating' => 5]
        )->assertNotFound();

        $this->assertDatabaseMissing('product_reviews', ['product_id' => $productB->id]);
    }

    public function test_no_se_puede_calificar_antes_de_recibir_el_pedido(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);

        $order = Order::factory()->create([
            'business_id' => $business->id,
            'status' => Order::STATUS_PENDING,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
        ]);

        $this->postJson(
            "/api/v1/storefront/{$business->slug}/orders/{$order->public_token}/reviews",
            ['product_id' => $product->id, 'rating' => 5]
        )->assertStatus(422);
    }

    public function test_no_se_puede_calificar_dos_veces_el_mismo_producto(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);
        $order = $this->deliveredOrder($business, $product);

        $url = "/api/v1/storefront/{$business->slug}/orders/{$order->public_token}/reviews";
        $payload = ['product_id' => $product->id, 'rating' => 4];

        $this->postJson($url, $payload)->assertCreated();
        $this->postJson($url, $payload)->assertStatus(422);

        $this->assertSame(1, ProductReview::withoutGlobalScopes()->where('order_id', $order->id)->count());
    }

    public function test_la_calificacion_tiene_que_estar_entre_1_y_5(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);
        $order = $this->deliveredOrder($business, $product);

        $url = "/api/v1/storefront/{$business->slug}/orders/{$order->public_token}/reviews";

        $this->postJson($url, ['product_id' => $product->id, 'rating' => 0])->assertStatus(422);
        $this->postJson($url, ['product_id' => $product->id, 'rating' => 6])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Lectura publica
    // -----------------------------------------------------------------

    public function test_solo_se_publican_las_resenas_aprobadas(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);
        $order = $this->deliveredOrder($business, $product);

        ProductReview::factory()->approved()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'comment' => 'Aprobada y visible',
        ]);

        $otroPedido = $this->deliveredOrder($business, $product);
        ProductReview::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'order_id' => $otroPedido->id,
            'comment' => 'Pendiente, no se ve',
        ]);

        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products/{$product->id}/reviews");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['comment' => 'Aprobada y visible']);
        $response->assertJsonMissing(['comment' => 'Pendiente, no se ve']);
    }

    /** La reseña publicada no puede filtrar los datos de contacto del comprador. */
    public function test_la_resena_publica_no_expone_datos_del_comprador(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);
        $order = $this->deliveredOrder($business, $product);

        ProductReview::factory()->approved()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
        ]);

        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products/{$product->id}/reviews");

        $response->assertOk();
        $response->assertJsonMissing(['order_id' => $order->id]);
        $response->assertDontSee($order->customer_phone);
        $response->assertDontSee($order->customer_email);
    }

    public function test_el_promedio_llega_en_la_ficha_del_producto(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);

        foreach ([5, 4] as $rating) {
            $order = $this->deliveredOrder($business, $product);
            ProductReview::factory()->approved()->create([
                'business_id' => $business->id,
                'product_id' => $product->id,
                'order_id' => $order->id,
                'rating' => $rating,
            ]);
        }

        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products/{$product->id}");

        // La ficha individual NO viaja envuelta en `data` (a diferencia de la
        // coleccion del catalogo), ver JsonResource::withoutWrapping.
        $response->assertOk();
        $response->assertJsonPath('rating.average', 4.5);
        $response->assertJsonPath('rating.count', 2);
    }
}
