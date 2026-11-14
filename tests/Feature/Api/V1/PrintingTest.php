<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessPrintSettings;
use App\Models\Order;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePaymentSplit;
use App\Models\User;
use App\Services\PrintAgentService;
use App\Services\PrintJobService;
use App\Support\BusinessFeaturePresets;
use App\Support\Printing\EscPosBuilder;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Impresion automatica: vincular el agente, la cola (apartar, confirmar,
 * fallar), la factura con copias y cajon, y la pantalla de Impresoras.
 */
class PrintingTest extends TestCase
{
    use DatabaseTransactions;

    private const TOKEN = 'pa_test_token_123';

    private Business $business;

    private User $owner;

    private PrintAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'name' => 'Asadero Don Pepe',
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'auto_printing' => true, 'kitchen_printing' => true],
        ]);
        $this->owner = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => true]);
        $this->owner->assignRole('admin');
        $this->agent = PrintAgent::factory()->paired(self::TOKEN)->create(['business_id' => $this->business->id]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function agentHeaders(string $token = self::TOKEN): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function counterPrinter(array $attributes = []): Printer
    {
        return Printer::factory()->counter()->create([
            'business_id' => $this->business->id,
            'print_agent_id' => $this->agent->id,
            ...$attributes,
        ]);
    }

    private function sale(array $attributes = []): Sale
    {
        $sale = Sale::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'payment_method' => 'cash',
            'total' => 24000,
            'invoice_number' => 'FAC-000123',
            ...$attributes,
        ]);
        $product = Product::factory()->create(['business_id' => $this->business->id, 'name' => 'Pollo asado']);
        SaleItem::factory()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 24000,
            'subtotal' => 24000,
        ]);

        return $sale;
    }

    private function decode(PrintJob $job): string
    {
        return base64_decode($job->fresh()->payload);
    }

    // --- Vincular ---------------------------------------------------------

    public function test_the_admin_creates_a_device_and_the_agent_pairs_with_the_code_once(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/printing/agents', ['name' => 'Caja principal'])
            ->assertCreated()
            ->assertJsonPath('agent.status', 'pairing');
        $code = $response->json('pairing_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $token = $this->postJson('/api/v1/print-agent/pair', ['code' => $code, 'version' => '0.1.0'])
            ->assertOk()
            ->assertJsonPath('business.name', 'Asadero Don Pepe')
            ->json('token');
        $this->assertStringStartsWith('pa_', $token);

        // El codigo sirve una sola vez.
        $this->postJson('/api/v1/print-agent/pair', ['code' => $code])->assertUnprocessable();

        $this->postJson('/api/v1/print-agent/heartbeat', ['printers' => ['POS-80 USB']], $this->agentHeaders($token))
            ->assertOk();
        $agent = PrintAgent::withoutGlobalScopes()->find($response->json('agent.id'));
        $this->assertSame(['POS-80 USB'], $agent->reported_printers);
        $this->assertSame('0.1.0', $agent->version);
    }

    public function test_an_expired_code_does_not_pair(): void
    {
        $code = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/printing/agents', ['name' => 'Caja'])
            ->json('pairing_code');
        $this->travel(11)->minutes();

        $this->postJson('/api/v1/print-agent/pair', ['code' => $code])->assertUnprocessable();
    }

    public function test_the_agent_token_does_not_open_pos_routes(): void
    {
        $this->getJson('/api/v1/business', $this->agentHeaders())->assertUnauthorized();
    }

    public function test_an_employee_token_does_not_open_agent_routes(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/print-agent/jobs/claim')->assertUnauthorized();
    }

    public function test_a_revoked_agent_and_a_business_without_printing_are_turned_away(): void
    {
        $this->postJson('/api/v1/print-agent/jobs/claim', [], $this->agentHeaders('pa_wrong'))->assertUnauthorized();

        $this->business->update(['feature_flags' => [...BusinessFeaturePresets::full(), 'auto_printing' => false]]);
        $this->postJson('/api/v1/print-agent/jobs/claim', [], $this->agentHeaders())->assertForbidden();
    }

    public function test_regenerating_the_code_invalidates_the_old_token(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/printing/agents/{$this->agent->id}/pairing-code")
            ->assertOk()
            ->assertJsonPath('agent.status', 'pairing');
        TenantContext::forget();

        $this->postJson('/api/v1/print-agent/jobs/claim', [], $this->agentHeaders())->assertUnauthorized();
    }

    // --- Cola ---------------------------------------------------------------

    public function test_the_agent_claims_only_its_printers_jobs_and_acknowledges_them(): void
    {
        $printer = $this->counterPrinter();
        $job = app(PrintJobService::class)->enqueueTest($printer);

        $otherBusiness = Business::factory()->create(['feature_flags' => [...BusinessFeaturePresets::full(), 'auto_printing' => true]]);
        $otherAgent = PrintAgent::factory()->paired('pa_other')->create(['business_id' => $otherBusiness->id]);
        $otherPrinter = Printer::factory()->create(['business_id' => $otherBusiness->id, 'print_agent_id' => $otherAgent->id]);
        app(PrintJobService::class)->enqueueTest($otherPrinter);

        $this->postJson('/api/v1/print-agent/jobs/claim', [], $this->agentHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'jobs')
            ->assertJsonPath('jobs.0.id', $job->id)
            ->assertJsonPath('jobs.0.printer.connection', Printer::CONNECTION_WINDOWS)
            ->assertJsonPath('jobs.0.printer.address', 'POS-80 USB');
        $this->assertSame(PrintJob::STATUS_CLAIMED, $job->fresh()->status);

        // Apartado: otra consulta no lo vuelve a entregar.
        $this->postJson('/api/v1/print-agent/jobs/claim', [], $this->agentHeaders())->assertJsonCount(0, 'jobs');

        $this->postJson("/api/v1/print-agent/jobs/{$job->id}/ack", [], $this->agentHeaders())
            ->assertOk()
            ->assertJsonPath('status', PrintJob::STATUS_PRINTED);
        $this->postJson("/api/v1/print-agent/jobs/{$job->id}/ack", [], $this->agentHeaders())->assertOk();
        $this->assertNotNull($job->fresh()->printed_at);
    }

    public function test_an_agent_cannot_acknowledge_another_agents_job(): void
    {
        $otherAgent = PrintAgent::factory()->paired('pa_other')->create(['business_id' => $this->business->id]);
        $otherPrinter = Printer::factory()->create(['business_id' => $this->business->id, 'print_agent_id' => $otherAgent->id]);
        $job = app(PrintJobService::class)->enqueueTest($otherPrinter);

        $this->postJson("/api/v1/print-agent/jobs/{$job->id}/ack", [], $this->agentHeaders())->assertNotFound();
    }

    public function test_a_claim_that_expires_goes_back_to_the_queue(): void
    {
        $job = app(PrintJobService::class)->enqueueTest($this->counterPrinter());
        $this->postJson('/api/v1/print-agent/jobs/claim', [], $this->agentHeaders())->assertJsonCount(1, 'jobs');

        $this->travel(PrintJob::CLAIM_SECONDS + 1)->seconds();

        $this->postJson('/api/v1/print-agent/jobs/claim', [], $this->agentHeaders())
            ->assertJsonCount(1, 'jobs')
            ->assertJsonPath('jobs.0.attempt', 2);
        $this->assertSame(2, $job->fresh()->attempts);
    }

    public function test_a_failing_job_is_retried_and_then_left_failed_for_the_pos(): void
    {
        $job = app(PrintJobService::class)->enqueueTest($this->counterPrinter());

        foreach (range(1, PrintJob::MAX_ATTEMPTS) as $attempt) {
            $this->postJson('/api/v1/print-agent/jobs/claim', [], $this->agentHeaders())->assertJsonCount(1, 'jobs');
            $this->postJson("/api/v1/print-agent/jobs/{$job->id}/fail", ['error' => 'Sin papel'], $this->agentHeaders())->assertOk();

            if ($attempt < PrintJob::MAX_ATTEMPTS) {
                // Espera antes de reintentar: el ciclo siguiente (2 s) no lo vuelve a entregar.
                $this->travel(2)->seconds();
                $this->postJson('/api/v1/print-agent/jobs/claim', [], $this->agentHeaders())->assertJsonCount(0, 'jobs');
                $this->travel(PrintJob::RETRY_DELAYS[$attempt - 1])->seconds();
            }
        }

        $this->assertSame(PrintJob::STATUS_FAILED, $job->fresh()->status);
        $this->postJson('/api/v1/print-agent/jobs/claim', [], $this->agentHeaders())->assertJsonCount(0, 'jobs');

        TenantContext::forget();
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/printing/status')->assertJsonPath('failed_jobs', 1);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/printing')
            ->assertJsonPath('failed_jobs.0.last_error', 'Sin papel');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/printing/jobs/{$job->id}/retry")
            ->assertOk()
            ->assertJsonPath('status', PrintJob::STATUS_PENDING);
    }

    public function test_acknowledging_a_kitchen_ticket_moves_the_online_order_to_preparing(): void
    {
        $kitchen = Printer::factory()->kitchen()->create(['business_id' => $this->business->id, 'print_agent_id' => $this->agent->id]);
        $sale = $this->sale();
        $order = Order::factory()->create([
            'business_id' => $this->business->id,
            'sale_id' => $sale->id,
            'status' => Order::STATUS_CONFIRMED,
        ]);
        $job = app(PrintJobService::class)->enqueue($kitchen, PrintJob::TYPE_KITCHEN, new EscPosBuilder, $sale);

        $this->postJson('/api/v1/print-agent/jobs/claim', [], $this->agentHeaders());
        $this->postJson("/api/v1/print-agent/jobs/{$job->id}/ack", [], $this->agentHeaders())->assertOk();

        $this->assertSame(Order::STATUS_PREPARING, $order->fresh()->status);
    }

    // --- Factura ------------------------------------------------------------

    public function test_the_receipt_prints_two_copies_and_opens_the_drawer_for_cash(): void
    {
        $this->counterPrinter();
        BusinessPrintSettings::create(['business_id' => $this->business->id, 'receipt_copies' => 2]);

        $job = app(PrintJobService::class)->enqueueReceipt($this->sale());

        $bytes = $this->decode($job);
        $this->assertStringContainsString('COPIA CLIENTE', $bytes);
        $this->assertStringContainsString('COPIA LOCAL', $bytes);
        $this->assertStringContainsString('FAC-000123', $bytes);
        $this->assertSame(1, substr_count($bytes, "\x1Bp\x00"), 'el cajon se abre una sola vez');
        $this->assertSame(2, substr_count($bytes, "\x1DV\x42"), 'cada copia con su corte');
    }

    public function test_the_drawer_stays_closed_without_cash_and_split_payments_are_listed(): void
    {
        $this->counterPrinter();
        $sale = $this->sale(['payment_method' => 'mixed']);
        SalePaymentSplit::create(['sale_id' => $sale->id, 'payment_method' => 'nequi', 'amount' => 14000]);
        SalePaymentSplit::create(['sale_id' => $sale->id, 'payment_method' => 'card', 'amount' => 10000]);

        $bytes = $this->decode(app(PrintJobService::class)->enqueueReceipt($sale));

        $this->assertStringNotContainsString("\x1Bp\x00", $bytes);
        $this->assertStringNotContainsString('mixed', $bytes);
        $this->assertStringContainsString('$14.000', $bytes);
        $this->assertStringContainsString('$10.000', $bytes);
    }

    public function test_a_cash_line_in_a_split_payment_opens_the_drawer(): void
    {
        $this->counterPrinter();
        $sale = $this->sale(['payment_method' => 'mixed']);
        SalePaymentSplit::create(['sale_id' => $sale->id, 'payment_method' => 'cash', 'amount' => 4000]);
        SalePaymentSplit::create(['sale_id' => $sale->id, 'payment_method' => 'nequi', 'amount' => 20000]);

        $this->assertStringContainsString("\x1Bp\x00", $this->decode(app(PrintJobService::class)->enqueueReceipt($sale)));
    }

    public function test_the_same_receipt_is_not_queued_twice(): void
    {
        $this->counterPrinter();
        $sale = $this->sale();

        $first = app(PrintJobService::class)->enqueueReceipt($sale);
        $second = app(PrintJobService::class)->enqueueReceipt($sale);

        $this->assertSame($first->id, $second->id);
    }

    public function test_nothing_is_queued_without_a_printer_the_flag_or_the_auto_print_setting(): void
    {
        $service = app(PrintJobService::class);
        $sale = $this->sale();
        $this->assertNull($service->enqueueReceipt($sale), 'sin impresora');

        $this->counterPrinter();
        BusinessPrintSettings::create(['business_id' => $this->business->id, 'auto_print_receipts' => false]);
        $this->assertNull($service->enqueueReceipt($sale), 'factura automatica apagada');
        $this->assertNotNull($service->enqueueReceipt($sale, isReprint: true), 'reimprimir a mano si');

        $this->business->update(['feature_flags' => [...BusinessFeaturePresets::full(), 'auto_printing' => false]]);
        $this->assertNull($service->enqueueReceipt($sale->fresh()), 'sin la bandera');
    }

    public function test_a_printer_without_a_paired_agent_is_ignored(): void
    {
        $this->counterPrinter(['print_agent_id' => PrintAgent::factory()->create(['business_id' => $this->business->id])->id]);

        $this->assertNull(app(PrintJobService::class)->enqueueReceipt($this->sale()));
    }

    public function test_any_employee_can_reprint_a_receipt(): void
    {
        $this->counterPrinter();
        $sale = $this->sale();
        $cashier = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => false]);
        $cashier->assignRole('employee');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/printing/sales/{$sale->id}/receipt")->assertCreated();
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/printing/sales/{$sale->id}/receipt")->assertCreated();

        $jobs = PrintJob::withoutGlobalScopes()->where('sale_id', $sale->id)->get();
        $this->assertCount(2, $jobs, 'cada reimpresion es un ticket nuevo');
        $this->assertStringContainsString('REIMPRESI', base64_decode($jobs->first()->payload));
        $this->assertStringNotContainsString("\x1Bp\x00", base64_decode($jobs->first()->payload));
    }

    // --- Pantalla de Impresoras ---------------------------------------------

    public function test_the_admin_registers_a_network_printer_and_prints_a_test_page(): void
    {
        $printerId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/printing/printers', [
            'name' => 'Cocina',
            'role' => 'kitchen',
            'connection' => 'network',
            'address' => '192.168.1.50',
            'print_agent_id' => $this->agent->id,
        ])->assertCreated()
            ->assertJsonPath('address', '192.168.1.50:9100')
            ->assertJsonPath('paper_width', 80)
            ->assertJsonPath('is_active', true)
            ->json('id');

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/printing/printers/{$printerId}/test")
            ->assertCreated()
            ->assertJsonPath('type', PrintJob::TYPE_TEST);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/printing')
            ->assertOk()
            ->assertJsonPath('agents.0.status', 'paired')
            ->assertJsonPath('agents.0.is_online', true)
            ->assertJsonPath('printers.0.name', 'Cocina')
            ->assertJsonPath('settings.receipt_copies', 1);
    }

    public function test_a_printer_cannot_point_to_another_business_agent(): void
    {
        $otherAgent = PrintAgent::factory()->paired('pa_other')->create(['business_id' => Business::factory()->create()->id]);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/printing/printers', [
            'name' => 'Cocina',
            'role' => 'kitchen',
            'connection' => 'network',
            'address' => '192.168.1.50:9100',
            'print_agent_id' => $otherAgent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('print_agent_id');
    }

    public function test_the_admin_saves_the_receipt_settings(): void
    {
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/v1/printing/settings', [
            'receipt_copies' => 2,
            'open_cash_drawer' => false,
        ])->assertOk()->assertJsonPath('settings.receipt_copies', 2)->assertJsonPath('settings.open_cash_drawer', false);

        $this->actingAs($this->owner, 'sanctum')->putJson('/api/v1/printing/settings', ['receipt_copies' => 3])
            ->assertUnprocessable();
    }

    public function test_an_employee_cannot_configure_printers(): void
    {
        $cashier = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => false]);
        $cashier->assignRole('employee');

        $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/printing')->assertForbidden();
        $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/printing/agents', ['name' => 'X'])->assertForbidden();
        $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/printing/status')->assertOk();
    }

    public function test_deleting_a_device_revokes_its_token(): void
    {
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/printing/agents/{$this->agent->id}")->assertNoContent();

        $this->assertNull(PrintAgent::withoutGlobalScopes()->find($this->agent->id));
        $this->assertNull(app(PrintAgentService::class)->findByToken(self::TOKEN));
    }
}
