<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\ProductReviewService;
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

    /** Dueño del negocio: la moderacion va detras de `business-admin`. */
    private function admin(Business $business): User
    {
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return $user;
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

    // -----------------------------------------------------------------
    // Moderacion
    // -----------------------------------------------------------------

    /**
     * Aprobar tiene que PERSISTIR. `status` no esta en el Fillable (a
     * proposito), asi que un `update()` masivo lo descartaba en silencio y la
     * resena seguia pendiente para siempre.
     */
    public function test_aprobar_una_resena_la_publica(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);
        $order = $this->deliveredOrder($business, $product);

        $review = ProductReview::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
        ]);

        $user = User::factory()->create(['business_id' => $business->id]);

        app(ProductReviewService::class)->moderate($review, ProductReview::STATUS_APPROVED, $user);

        $this->assertDatabaseHas('product_reviews', [
            'id' => $review->id,
            'status' => ProductReview::STATUS_APPROVED,
            'moderated_by' => $user->id,
        ]);

        // Y ahora si se ve desde internet.
        $this->getJson("/api/v1/storefront/{$business->slug}/products/{$product->id}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** Ocultar una ya publicada la saca de internet. */
    public function test_ocultar_una_resena_la_retira(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);
        $order = $this->deliveredOrder($business, $product);

        $review = ProductReview::factory()->approved()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
        ]);

        $user = User::factory()->create(['business_id' => $business->id]);
        app(ProductReviewService::class)->moderate($review, ProductReview::STATUS_HIDDEN, $user);

        $this->getJson("/api/v1/storefront/{$business->slug}/products/{$product->id}/reviews")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** El comprador no puede autopublicarse mandando `status` en el payload. */
    public function test_el_comprador_no_puede_publicar_su_propia_resena(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);
        $order = $this->deliveredOrder($business, $product);

        $this->postJson(
            "/api/v1/storefront/{$business->slug}/orders/{$order->public_token}/reviews",
            ['product_id' => $product->id, 'rating' => 5, 'status' => 'approved']
        )->assertCreated();

        $this->assertDatabaseHas('product_reviews', [
            'order_id' => $order->id,
            'status' => ProductReview::STATUS_PENDING,
        ]);
    }

    /** La bandeja del POS: por defecto trae lo que hay que atender. */
    public function test_la_bandeja_de_moderacion_muestra_las_pendientes(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);

        $pendiente = ProductReview::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'order_id' => $this->deliveredOrder($business, $product)->id,
        ]);
        ProductReview::factory()->approved()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'order_id' => $this->deliveredOrder($business, $product)->id,
        ]);

        $response = $this->actingAs($this->admin($business))->getJson('/api/v1/store-reviews');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $pendiente->id);
    }

    /** Un negocio no puede moderar las resenas de otro. */
    public function test_no_se_pueden_moderar_las_resenas_de_otro_negocio(): void
    {
        $businessA = $this->storefrontBusiness();
        $businessB = $this->storefrontBusiness();

        $product = $this->publishedProduct($businessB);
        $review = ProductReview::factory()->create([
            'business_id' => $businessB->id,
            'product_id' => $product->id,
            'order_id' => $this->deliveredOrder($businessB, $product)->id,
        ]);

        $this->actingAs($this->admin($businessA))
            ->patchJson("/api/v1/store-reviews/{$review->id}", ['status' => 'approved'])
            ->assertNotFound();

        $this->assertDatabaseHas('product_reviews', [
            'id' => $review->id,
            'status' => ProductReview::STATUS_PENDING,
        ]);
    }

    /** Solo 'approved' u 'hidden': el comerciante no inventa estados. */
    public function test_el_estado_de_moderacion_esta_acotado(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business);
        $review = ProductReview::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'order_id' => $this->deliveredOrder($business, $product)->id,
        ]);

        $this->actingAs($this->admin($business))
            ->patchJson("/api/v1/store-reviews/{$review->id}", ['status' => 'lo-que-sea'])
            ->assertStatus(422);
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
