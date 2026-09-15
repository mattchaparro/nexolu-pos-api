<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessWhatsAppOrder;
use App\Models\AiChannelIdentity;
use App\Models\Business;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappOrder;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\WhatsApp\WhatsAppOrderConverter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El circuito del carrito entrante (webhook `order` de Meta -> venta del
 * POS), decision "auto con fallback": valida -> cuenta abierta + confirmacion;
 * no valida -> pending_review + aviso al negocio y al cliente. El precio es
 * SIEMPRE del servidor (el item_price del catalogo de Meta se ignora).
 */
class ProcessWhatsAppOrderTest extends TestCase
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

    private function businessWithAdmin(): Business
    {
        $business = Business::factory()->create(['feature_flags' => null]);
        $user = User::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        $user->assignRole('admin');

        AiChannelIdentity::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'external_id' => '573000000001',
            'verified_at' => now(),
        ]);

        return $business;
    }

    private function product(Business $business, array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'business_id' => $business->id,
            'price' => 9000,
            'stock' => 10,
            'track_stock' => true,
        ], $attributes));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function runJob(array $items, ?string $businessId = null, string $wamid = 'wamid.order-1'): void
    {
        $job = new ProcessWhatsAppOrder(
            from: '573001112233',
            order: ['catalog_id' => 'cat-1', 'product_items' => $items],
            wamid: $wamid,
            businessId: $businessId,
            profileName: 'Laura Cliente',
        );

        $job->handle(app(WhatsAppOrderConverter::class), app(MessagingChannel::class));
    }

    public function test_a_valid_cart_becomes_an_open_sale_with_server_prices(): void
    {
        $business = $this->businessWithAdmin();
        $product = $this->product($business);

        // item_price mentiroso a proposito: el catalogo de Meta puede estar
        // desfasado y el POS no le cree.
        $this->runJob([[
            'product_retailer_id' => $product->whatsappRetailerId(),
            'quantity' => 2,
            'item_price' => 1,
            'currency' => 'COP',
        ]]);

        $whatsappOrder = WhatsappOrder::query()->where('wamid', 'wamid.order-1')->firstOrFail();
        $this->assertSame(WhatsappOrder::STATUS_CREATED, $whatsappOrder->status);

        $sale = Sale::query()->findOrFail($whatsappOrder->sale_id);
        $this->assertSame('open', $sale->status);
        $this->assertSame(18000.0, (float) $sale->total);
        $this->assertSame('pending', $sale->kitchen_status);

        // El cliente quedo creado con el nombre del perfil y recibio la
        // confirmacion con el total real.
        $client = Client::query()->where('business_id', $business->id)->where('phone', '573001112233')->first();
        $this->assertNotNull($client);
        $this->assertSame('Laura Cliente', $client->name);
        $this->assertSame($client->id, (int) $sale->client_id);

        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', '18.000')
            && ($request['to'] ?? null) === '573001112233');
    }

    public function test_resolves_the_business_from_the_retailer_prefix_when_there_is_no_header(): void
    {
        $business = $this->businessWithAdmin();
        $product = $this->product($business);

        $this->runJob([[
            'product_retailer_id' => "b{$business->id}-{$product->sku}",
            'quantity' => 1,
        ]], businessId: null);

        $this->assertSame(
            WhatsappOrder::STATUS_CREATED,
            WhatsappOrder::query()->where('wamid', 'wamid.order-1')->value('status'),
        );
    }

    public function test_an_unknown_product_falls_back_to_pending_review_and_notifies(): void
    {
        $business = $this->businessWithAdmin();

        $this->runJob([[
            'product_retailer_id' => "b{$business->id}-PROD-999",
            'quantity' => 1,
        ]], businessId: (string) $business->id);

        $whatsappOrder = WhatsappOrder::query()->where('wamid', 'wamid.order-1')->firstOrFail();
        $this->assertSame(WhatsappOrder::STATUS_PENDING_REVIEW, $whatsappOrder->status);
        $this->assertStringContainsString('PROD-999', (string) $whatsappOrder->error);
        $this->assertNull($whatsappOrder->sale_id);

        // Al cliente: "lo estamos confirmando". Al admin vinculado: la alerta.
        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'confirmando'));
        Http::assertSent(fn ($request) => ($request['to'] ?? null) === '573000000001'
            && str_contains($request['text']['body'] ?? '', 'por revisar'));
    }

    public function test_insufficient_stock_falls_back_to_pending_review(): void
    {
        $business = $this->businessWithAdmin();
        $product = $this->product($business, ['stock' => 1]);

        $this->runJob([[
            'product_retailer_id' => $product->whatsappRetailerId(),
            'quantity' => 5,
        ]]);

        $whatsappOrder = WhatsappOrder::query()->where('wamid', 'wamid.order-1')->firstOrFail();
        $this->assertSame(WhatsappOrder::STATUS_PENDING_REVIEW, $whatsappOrder->status);
        $this->assertStringContainsString('stock', mb_strtolower((string) $whatsappOrder->error));
    }

    public function test_the_same_wamid_never_creates_two_sales(): void
    {
        $business = $this->businessWithAdmin();
        $product = $this->product($business);
        $line = [['product_retailer_id' => $product->whatsappRetailerId(), 'quantity' => 1]];

        $this->runJob($line);
        $this->runJob($line);

        $this->assertSame(1, WhatsappOrder::query()->where('wamid', 'wamid.order-1')->count());
        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());
    }

    public function test_an_existing_client_is_matched_by_phone_even_with_local_format(): void
    {
        $business = $this->businessWithAdmin();
        $product = $this->product($business);
        // Como lo teclean en caja: sin indicativo.
        $existing = Client::factory()->create([
            'business_id' => $business->id,
            'name' => 'Laura de Siempre',
            'phone' => '3001112233',
        ]);

        $this->runJob([[
            'product_retailer_id' => $product->whatsappRetailerId(),
            'quantity' => 1,
        ]]);

        $whatsappOrder = WhatsappOrder::query()->where('wamid', 'wamid.order-1')->firstOrFail();
        $sale = Sale::query()->findOrFail($whatsappOrder->sale_id);
        $this->assertSame($existing->id, (int) $sale->client_id);
        $this->assertSame(1, Client::query()->where('business_id', $business->id)->count());
    }
}
