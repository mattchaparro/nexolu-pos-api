<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RemoveWhatsAppCatalogItemJob;
use App\Jobs\SyncWhatsAppCatalogJob;
use App\Models\Business;
use App\Models\Product;
use App\Services\WhatsApp\NexoluCommsCatalogClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Catalogo saliente: el POS manda su lote publicable a Nexolu Connect
 * (POST /v1/catalog/sync) con el shape del items_batch oficial, y los
 * hooks de Product disparan sync (con debounce) o retiro puntual.
 */
class SyncWhatsAppCatalogJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.comms_core.api_key' => 'test-comms-key',
            'services.comms_core.base_url' => 'http://comms.test',
        ]);
    }

    private function publishedProduct(Business $business, array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'business_id' => $business->id,
            'name' => 'Granizado de Mango',
            'description' => '8 oz, fruta natural',
            'price' => 9000,
            'stock' => 10,
            'track_stock' => true,
            'available_on_whatsapp' => true,
            'image' => 'https://img.nexolu.co/mango.jpg',
        ], $attributes));
    }

    public function test_sends_the_publishable_batch_in_the_official_shape(): void
    {
        Http::fake(['comms.test/*' => Http::response(['sent' => 1, 'skipped' => 0, 'deleted' => 0, 'handle' => 'h-1', 'immediate_errors' => []], 200)]);
        $business = Business::factory()->create();
        $product = $this->publishedProduct($business);
        // No publicado: fuera del lote.
        Product::factory()->create(['business_id' => $business->id, 'available_on_whatsapp' => false]);

        (new SyncWhatsAppCatalogJob($business->id))->handle(app(NexoluCommsCatalogClient::class));

        Http::assertSent(function ($request) use ($product) {
            $items = $request['items'] ?? [];

            return str_contains($request->url(), '/v1/catalog/sync')
                && count($items) === 1
                && $items[0]['retailer_id'] === "b{$product->business_id}-{$product->sku}"
                && $items[0]['title'] === 'Granizado de Mango'
                && $items[0]['price'] === '9000 COP'
                && $items[0]['availability'] === 'in stock'
                && $items[0]['image_link'] === 'https://img.nexolu.co/mango.jpg'
                && $items[0]['description'] === '8 oz, fruta natural';
        });
    }

    public function test_products_without_image_stay_out_of_the_batch(): void
    {
        Http::fake();
        $business = Business::factory()->create();
        $this->publishedProduct($business, ['image' => null]);

        (new SyncWhatsAppCatalogJob($business->id))->handle(app(NexoluCommsCatalogClient::class));

        // Lote vacio => ni siquiera se llama a Connect.
        Http::assertNothingSent();
    }

    public function test_out_of_stock_products_are_marked_unavailable_not_removed(): void
    {
        Http::fake(['comms.test/*' => Http::response([], 200)]);
        $business = Business::factory()->create();
        $this->publishedProduct($business, ['stock' => 0]);

        (new SyncWhatsAppCatalogJob($business->id))->handle(app(NexoluCommsCatalogClient::class));

        Http::assertSent(fn ($request) => ($request['items'][0]['availability'] ?? null) === 'out of stock');
    }

    public function test_a_relative_image_uses_the_public_stable_route(): void
    {
        Http::fake(['comms.test/*' => Http::response([], 200)]);
        $business = Business::factory()->create();
        $product = $this->publishedProduct($business, ['image' => 'storage/productos/mango.jpg']);

        (new SyncWhatsAppCatalogJob($business->id))->handle(app(NexoluCommsCatalogClient::class));

        Http::assertSent(fn ($request) => str_contains(
            (string) ($request['items'][0]['image_link'] ?? ''),
            "/api/public/products/{$product->id}/image",
        ));
    }

    public function test_saving_a_published_product_dispatches_a_debounced_sync(): void
    {
        Queue::fake();
        $business = Business::factory()->create();

        $this->publishedProduct($business);
        // Segunda escritura dentro de la ventana de debounce: NO duplica.
        $this->publishedProduct($business);

        Queue::assertPushed(SyncWhatsAppCatalogJob::class, 1);
    }

    public function test_turning_the_switch_off_dispatches_a_targeted_removal(): void
    {
        $business = Business::factory()->create();
        $product = $this->publishedProduct($business);

        Queue::fake();
        $product->update(['available_on_whatsapp' => false]);

        Queue::assertPushed(
            RemoveWhatsAppCatalogItemJob::class,
            fn (RemoveWhatsAppCatalogItemJob $job) => $job->retailerId === "b{$business->id}-{$product->sku}",
        );
        Queue::assertNotPushed(SyncWhatsAppCatalogJob::class);
    }

    public function test_deleting_a_published_product_dispatches_a_removal(): void
    {
        $business = Business::factory()->create();
        $product = $this->publishedProduct($business);

        Queue::fake();
        $product->delete();

        Queue::assertPushed(RemoveWhatsAppCatalogItemJob::class);
    }
}
