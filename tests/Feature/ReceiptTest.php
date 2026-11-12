<?php

namespace Tests\Feature;

use App\Jobs\SendReceiptJob;
use App\Mail\ReceiptMail;
use App\Models\Business;
use App\Models\Layaway;
use App\Models\LayawayItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\ReceiptPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Mockery\MockInterface;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): array
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    // --- Descarga (imprimir) ---

    public function test_sale_receipt_downloads_a_pdf(): void
    {
        [$business, $user] = $this->admin();
        $sale = Sale::factory()->create(['business_id' => $business->id]);
        SaleItem::factory()->create(['sale_id' => $sale->id]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/v1/sales/{$sale->id}/receipt");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_service_order_receipt_downloads_a_pdf(): void
    {
        [$business, $user] = $this->admin();
        $order = ServiceOrder::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/v1/service-orders/{$order->id}/receipt");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_layaway_receipt_downloads_a_pdf(): void
    {
        [$business, $user] = $this->admin();
        $layaway = Layaway::factory()->create(['business_id' => $business->id]);
        LayawayItem::factory()->create(['layaway_id' => $layaway->id]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/v1/layaways/{$layaway->id}/receipt");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_cannot_download_a_receipt_for_another_businesss_sale(): void
    {
        [, $user] = $this->admin();
        $otherBusiness = Business::factory()->create();
        $sale = Sale::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/sales/{$sale->id}/receipt")
            ->assertNotFound();
    }

    // --- Version HTML para imprimir directo (distinta del PDF de arriba,
    // pensada para que el navegador dispare window.print() contra la
    // impresora termica sin pasar por un visor de PDF) ---

    public function test_sale_receipt_print_returns_printable_html(): void
    {
        [$business, $user] = $this->admin();
        $sale = Sale::factory()->create(['business_id' => $business->id]);
        SaleItem::factory()->create(['sale_id' => $sale->id]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/v1/sales/{$sale->id}/receipt/print");

        $response->assertOk();
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $response->assertSee('window.print()', false);
        $response->assertSee('Recibo de venta');
    }

    public function test_sale_receipt_print_shows_unit_price_and_line_discount(): void
    {
        [$business, $user] = $this->admin();
        $sale = Sale::factory()->create(['business_id' => $business->id]);
        SaleItem::factory()->create([
            'sale_id' => $sale->id,
            'quantity' => 2,
            'unit_price' => 15000,
            'subtotal' => 30000,
            'discount_amount' => 3000,
        ]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/v1/sales/{$sale->id}/receipt/print");

        $response->assertOk();
        $response->assertSee('2 x $15.000');
        $response->assertSee('-$3.000');
    }

    public function test_service_order_receipt_print_returns_printable_html(): void
    {
        [$business, $user] = $this->admin();
        $order = ServiceOrder::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/v1/service-orders/{$order->id}/receipt/print");

        $response->assertOk();
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $response->assertSee('window.print()', false);
    }

    public function test_layaway_receipt_print_returns_printable_html(): void
    {
        [$business, $user] = $this->admin();
        $layaway = Layaway::factory()->create(['business_id' => $business->id]);
        LayawayItem::factory()->create(['layaway_id' => $layaway->id]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/v1/layaways/{$layaway->id}/receipt/print");

        $response->assertOk();
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $response->assertSee('window.print()', false);
    }

    public function test_receipt_print_omits_the_auto_print_script_when_auto_print_is_false(): void
    {
        [$business, $user] = $this->admin();
        $sale = Sale::factory()->create(['business_id' => $business->id]);
        SaleItem::factory()->create(['sale_id' => $sale->id]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/v1/sales/{$sale->id}/receipt/print?auto_print=0");

        $response->assertOk();
        $response->assertDontSee('<script>window.print();</script>', false);
    }

    public function test_cannot_print_a_receipt_for_another_businesss_sale(): void
    {
        [, $user] = $this->admin();
        $otherBusiness = Business::factory()->create();
        $sale = Sale::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/sales/{$sale->id}/receipt/print")
            ->assertNotFound();
    }

    // --- Envio (dispara el job en cola) ---

    public function test_sending_a_sale_receipt_queues_the_job(): void
    {
        Bus::fake();
        [$business, $user] = $this->admin();
        $sale = Sale::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sales/{$sale->id}/receipt/send", ['channel' => 'email', 'to' => 'cliente@example.com'])
            ->assertOk()
            ->assertJson(['queued' => true]);

        Bus::assertDispatched(SendReceiptJob::class, fn (SendReceiptJob $job) => $job->entityType === 'sale'
            && $job->entityId === $sale->id
            && $job->channel === 'email'
            && $job->to === 'cliente@example.com');
    }

    public function test_send_receipt_requires_a_valid_channel(): void
    {
        [$business, $user] = $this->admin();
        $sale = Sale::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sales/{$sale->id}/receipt/send", ['channel' => 'carrier-pigeon', 'to' => 'x'])
            ->assertUnprocessable();
    }

    // --- El fetch publico firmado que usa el envio por WhatsApp ---

    public function test_public_receipt_route_serves_the_pdf_with_a_valid_signature(): void
    {
        $business = Business::factory()->create();
        $sale = Sale::factory()->create(['business_id' => $business->id]);

        $url = URL::temporarySignedRoute('receipts.public.show', now()->addHour(), ['type' => 'sale', 'id' => $sale->id]);

        $this->get($url)->assertOk();
    }

    public function test_public_receipt_route_rejects_a_tampered_signature(): void
    {
        $business = Business::factory()->create();
        $sale = Sale::factory()->create(['business_id' => $business->id]);

        $this->get("/api/public/receipts/sale/{$sale->id}")->assertForbidden();
    }

    public function test_public_receipt_route_works_across_businesses_since_it_has_no_authenticated_user(): void
    {
        // Sin usuario autenticado el global scope de BelongsToBusiness no
        // aplica - el fetch publico depende solo de la firma de la URL, no
        // de una sesion, asi que debe poder traer el recibo de cualquier
        // negocio (quien lo llama es Meta/Nexolu Communications, no un
        // usuario del negocio).
        $business = Business::factory()->create();
        $order = ServiceOrder::factory()->create(['business_id' => $business->id]);

        $url = URL::temporarySignedRoute('receipts.public.show', now()->addHour(), ['type' => 'service-order', 'id' => $order->id]);

        $this->get($url)->assertOk();
    }

    // --- SendReceiptJob: la entrega real ---

    public function test_send_receipt_job_emails_the_pdf(): void
    {
        Mail::fake();
        $business = Business::factory()->create();
        $sale = Sale::factory()->create(['business_id' => $business->id]);

        (new SendReceiptJob('sale', $sale->id, 'email', 'cliente@example.com', 'Recibo de venta'))->handle(
            app(ReceiptPdfService::class),
        );

        Mail::assertSent(ReceiptMail::class, fn (ReceiptMail $mail) => $mail->hasTo('cliente@example.com')
            && $mail->pdfFilename !== ''
            && str_starts_with($mail->pdfContent, '%PDF'));
    }

    /**
     * Por plantilla con el PDF en el encabezado: fuera de la ventana de 24h
     * (el caso normal de un comprobante) Meta solo entrega plantillas. Antes
     * iba como documento libre y fallaban todos.
     */
    public function test_a_whatsapp_receipt_goes_by_template_with_the_pdf_and_the_country_code(): void
    {
        config(['services.whatsapp.templates.comprobante.name' => 'sale_receipt']);
        [$business, $user] = $this->admin();
        $business->update(['name' => 'Central Cell']);
        $sale = Sale::factory()->create(['business_id' => $business->id]);

        $this->mock(MessagingChannel::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendTemplate')
                ->once()
                ->withArgs(function (string $to, string $name, string $lang, array $components) {
                    $document = $components[0]['parameters'][0]['document'] ?? [];

                    return $to === '573001234567'
                        && $name === 'sale_receipt'
                        && str_contains($document['link'] ?? '', '/api/public/receipts/sale/')
                        && str_contains($document['link'] ?? '', 'signature=')
                        && ($components[1]['parameters'][0]['text'] ?? null) === 'Central Cell'
                        && ($components[1]['parameters'][1]['text'] ?? null) === 'recibo de venta';
                })
                ->andReturnTrue();
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sales/{$sale->id}/receipt/send", ['channel' => 'whatsapp', 'to' => '300 123 4567'])
            ->assertOk()
            ->assertJson(['sent' => true]);
    }

    public function test_the_cashier_learns_when_whatsapp_did_not_take_the_receipt(): void
    {
        config(['services.whatsapp.templates.comprobante.name' => 'sale_receipt']);
        [$business, $user] = $this->admin();
        $sale = Sale::factory()->create(['business_id' => $business->id]);
        $this->mock(MessagingChannel::class, fn (MockInterface $mock) => $mock->shouldReceive('sendTemplate')->andReturnFalse());

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sales/{$sale->id}/receipt/send", ['channel' => 'whatsapp', 'to' => '3001234567'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_a_whatsapp_receipt_without_the_template_says_it_is_not_active(): void
    {
        config(['services.whatsapp.templates.comprobante.name' => '']);
        [$business, $user] = $this->admin();
        $sale = Sale::factory()->create(['business_id' => $business->id]);
        $this->mock(MessagingChannel::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendTemplate'));

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sales/{$sale->id}/receipt/send", ['channel' => 'whatsapp', 'to' => '3001234567'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('channel');
    }

    public function test_a_whatsapp_receipt_rejects_a_number_that_is_not_a_cellphone(): void
    {
        config(['services.whatsapp.templates.comprobante.name' => 'sale_receipt']);
        [$business, $user] = $this->admin();
        $sale = Sale::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sales/{$sale->id}/receipt/send", ['channel' => 'whatsapp', 'to' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_send_receipt_job_does_nothing_for_a_missing_entity(): void
    {
        Mail::fake();

        (new SendReceiptJob('sale', 999999, 'email', 'cliente@example.com', 'Recibo de venta'))->handle(
            app(ReceiptPdfService::class),
        );

        Mail::assertNothingSent();
    }

    // --- Feature flag cash_receipts_pdf: gatea la descarga/envio del PDF,
    // no la vista de impresion HTML (esa no depende del flag). ---

    private function adminWithoutCashReceiptsPdf(): array
    {
        $business = Business::factory()->create(['feature_flags' => ['cash_receipts_pdf' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    public function test_sale_receipt_pdf_is_blocked_without_the_feature(): void
    {
        [$business, $user] = $this->adminWithoutCashReceiptsPdf();
        $sale = Sale::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/sales/{$sale->id}/receipt")
            ->assertForbidden();
    }

    public function test_layaway_receipt_pdf_is_blocked_without_the_feature(): void
    {
        [$business, $user] = $this->adminWithoutCashReceiptsPdf();
        $layaway = Layaway::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/layaways/{$layaway->id}/receipt")
            ->assertForbidden();
    }

    public function test_service_order_receipt_pdf_is_blocked_without_the_feature(): void
    {
        [$business, $user] = $this->adminWithoutCashReceiptsPdf();
        $order = ServiceOrder::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/service-orders/{$order->id}/receipt")
            ->assertForbidden();
    }

    public function test_sending_a_sale_receipt_is_blocked_without_the_feature(): void
    {
        [$business, $user] = $this->adminWithoutCashReceiptsPdf();
        $sale = Sale::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sales/{$sale->id}/receipt/send", ['channel' => 'email', 'to' => 'cliente@example.com'])
            ->assertForbidden();
    }

    public function test_sale_receipt_print_stays_available_without_the_feature(): void
    {
        [$business, $user] = $this->adminWithoutCashReceiptsPdf();
        $sale = Sale::factory()->create(['business_id' => $business->id]);
        SaleItem::factory()->create(['sale_id' => $sale->id]);

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/sales/{$sale->id}/receipt/print")
            ->assertOk();
    }
}
