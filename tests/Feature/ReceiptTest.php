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
            app(MessagingChannel::class),
        );

        Mail::assertSent(ReceiptMail::class, fn (ReceiptMail $mail) => $mail->hasTo('cliente@example.com')
            && $mail->pdfFilename !== ''
            && str_starts_with($mail->pdfContent, '%PDF'));
    }

    public function test_send_receipt_job_sends_a_whatsapp_document_with_a_signed_url(): void
    {
        $business = Business::factory()->create();
        $sale = Sale::factory()->create(['business_id' => $business->id]);

        $this->mock(MessagingChannel::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendDocument')
                ->once()
                ->withArgs(fn (string $to, string $url, string $filename, string $caption) => $to === '573001234567'
                    && str_contains($url, '/api/public/receipts/sale/')
                    && str_contains($url, 'signature=')
                    && $filename !== '');
        });

        (new SendReceiptJob('sale', $sale->id, 'whatsapp', '573001234567', 'Recibo de venta'))->handle(
            app(ReceiptPdfService::class),
            app(MessagingChannel::class),
        );
    }

    public function test_send_receipt_job_does_nothing_for_a_missing_entity(): void
    {
        Mail::fake();

        (new SendReceiptJob('sale', 999999, 'email', 'cliente@example.com', 'Recibo de venta'))->handle(
            app(ReceiptPdfService::class),
            app(MessagingChannel::class),
        );

        Mail::assertNothingSent();
    }
}
