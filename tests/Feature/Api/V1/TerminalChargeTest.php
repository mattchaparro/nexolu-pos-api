<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessPaymentGateway;
use App\Models\BusinessPaymentTerminal;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TerminalCharge;
use App\Models\User;
use App\Services\TerminalChargeService;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobro con datafono desde la caja.
 *
 * Lo que mas importa: la venta NO nace al disparar el cobro, sino cuando se
 * confirma que entro la plata; y un cobro aprobado no puede facturarse dos
 * veces ni por un monto distinto del que se cobro.
 */
class TerminalChargeTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $cashier;

    private BusinessPaymentTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payments_core.base_url' => 'http://payments.test',
            'services.payments_core.provisioning_key' => 'prov-key',
        ]);

        $this->business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => BusinessFeaturePresets::full(),
            'payment_methods' => [['id' => 'cash', 'label' => 'Efectivo'], ['id' => 'bold', 'label' => 'Bold']],
        ]);
        $this->cashier = User::factory()->create([
            'business_id' => $this->business->id,
            'is_business_owner' => true,
            'email' => 'cajero@negocio.test',
        ]);
        $this->cashier->assignRole('admin');

        BusinessPaymentGateway::create([
            'business_id' => $this->business->id,
            'provider_slug' => 'bold',
            'payments_core_merchant_id' => 'mch_1',
            'integration_api_key' => 'nxl_key',
            'webhook_secret' => 'whsec_negocio',
            'is_active' => true,
        ]);

        $this->terminal = BusinessPaymentTerminal::create([
            'business_id' => $this->business->id,
            'serial' => 'N860W000000',
            'model' => 'N86',
            'name' => 'Caja 1',
            'status' => 'BINDED',
            'is_active' => true,
        ]);
    }

    private function fakeChargeAccepted(): void
    {
        Http::fake([
            'http://payments.test/v1/payments/terminals/charge' => Http::response([
                'reference' => 'pay_term_1',
                'provider_charge_id' => 'e1eeb06d',
                'status' => 'pending',
            ], 201),
        ]);
    }

    // -----------------------------------------------------------------
    // Disparar el cobro
    // -----------------------------------------------------------------

    public function test_starting_a_charge_does_not_create_a_sale(): void
    {
        $this->fakeChargeAccepted();

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/terminals/charges', ['terminal_id' => $this->terminal->id, 'amount' => 45000])
            ->assertCreated()
            ->assertJsonPath('status', TerminalCharge::STATUS_PENDING);

        // El monto recien aparecio en la pantalla del aparato: todavia no
        // hay venta ni movimiento de stock.
        $this->assertDatabaseCount('terminal_charges', 1);
        $this->assertNull(TerminalCharge::withoutGlobalScopes()->first()->sale_id);
    }

    public function test_the_seller_email_sent_is_the_cashier(): void
    {
        $this->fakeChargeAccepted();

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/terminals/charges', ['terminal_id' => $this->terminal->id, 'amount' => 45000])
            ->assertCreated();

        Http::assertSent(function ($request) {
            return $request['seller_email'] === 'cajero@negocio.test'
                && $request['terminal_serial'] === 'N860W000000'
                && $request['terminal_model'] === 'N86';
        });
    }

    public function test_a_business_without_a_connected_gateway_cannot_charge(): void
    {
        BusinessPaymentGateway::withoutGlobalScopes()->where('business_id', $this->business->id)->update(['is_active' => false]);

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/terminals/charges', ['terminal_id' => $this->terminal->id, 'amount' => 45000])
            ->assertUnprocessable();
    }

    public function test_an_unbound_terminal_is_rejected(): void
    {
        $this->terminal->update(['status' => 'UNBINDED']);

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/terminals/charges', ['terminal_id' => $this->terminal->id, 'amount' => 45000])
            ->assertUnprocessable();
    }

    // -----------------------------------------------------------------
    // Cerrar la venta con el cobro
    // -----------------------------------------------------------------

    private function approvedCharge(float $amount): TerminalCharge
    {
        return TerminalCharge::create([
            'business_id' => $this->business->id,
            'user_id' => $this->cashier->id,
            'business_payment_terminal_id' => $this->terminal->id,
            'reference' => 'pay_term_'.uniqid(),
            'provider_slug' => 'bold',
            'amount' => $amount,
            'status' => TerminalCharge::STATUS_APPROVED,
        ]);
    }

    private function sellPayload(Product $product, int $quantity, ?string $reference): array
    {
        return array_filter([
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'payment_method' => 'bold',
            'terminal_charge_reference' => $reference,
        ]);
    }

    private function product(float $price): Product
    {
        return Product::factory()->create([
            'business_id' => $this->business->id,
            'price' => $price,
            'stock' => 20,
            'is_active' => true,
            'is_service' => false,
            'track_stock' => true,
        ]);
    }

    public function test_an_approved_charge_closes_the_sale_and_is_consumed(): void
    {
        $product = $this->product(45000);
        $charge = $this->approvedCharge(45000);

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/sales', $this->sellPayload($product, 1, $charge->reference))
            ->assertCreated();

        $charge->refresh();
        $this->assertSame(TerminalCharge::STATUS_CONSUMED, $charge->status);
        $this->assertNotNull($charge->sale_id);
    }

    /**
     * La guarda que de verdad importa: sin ella se podria facturar una venta
     * de $200.000 con un cobro de $20.000 aprobado.
     */
    public function test_a_charge_for_a_different_amount_is_rejected_and_no_sale_is_left_behind(): void
    {
        $product = $this->product(200000);
        $charge = $this->approvedCharge(20000);
        $salesBefore = Sale::withoutGlobalScopes()->count();

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/sales', $this->sellPayload($product, 1, $charge->reference))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terminal_charge_reference');

        $this->assertSame($salesBefore, Sale::withoutGlobalScopes()->count(), 'La venta se deshace entera');
        $this->assertSame(TerminalCharge::STATUS_APPROVED, $charge->fresh()->status);
    }

    public function test_the_same_charge_cannot_be_billed_twice(): void
    {
        $product = $this->product(45000);
        $charge = $this->approvedCharge(45000);

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/sales', $this->sellPayload($product, 1, $charge->reference))
            ->assertCreated();

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/sales', $this->sellPayload($product, 1, $charge->reference))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terminal_charge_reference');
    }

    public function test_a_pending_charge_cannot_close_a_sale(): void
    {
        $product = $this->product(45000);
        $charge = $this->approvedCharge(45000);
        $charge->update(['status' => TerminalCharge::STATUS_PENDING]);

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/sales', $this->sellPayload($product, 1, $charge->reference))
            ->assertUnprocessable();
    }

    public function test_a_charge_from_another_business_cannot_be_used(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreign = TerminalCharge::create([
            'business_id' => $otherBusiness->id,
            'user_id' => User::factory()->create(['business_id' => $otherBusiness->id])->id,
            'reference' => 'pay_ajeno',
            'provider_slug' => 'bold',
            'amount' => 45000,
            'status' => TerminalCharge::STATUS_APPROVED,
        ]);

        $product = $this->product(45000);

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/sales', $this->sellPayload($product, 1, $foreign->reference))
            ->assertUnprocessable();

        $this->assertSame(TerminalCharge::STATUS_APPROVED, $foreign->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Espera y vencimiento
    // -----------------------------------------------------------------

    public function test_the_cashier_can_poll_the_charge(): void
    {
        $charge = $this->approvedCharge(45000);

        $this->actingAs($this->cashier, 'sanctum')
            ->getJson('/api/v1/terminals/charges/'.$charge->reference)
            ->assertOk()
            ->assertJsonPath('status', TerminalCharge::STATUS_APPROVED)
            ->assertJsonPath('terminal', 'Caja 1');
    }

    public function test_a_charge_nobody_resolved_expires(): void
    {
        $charge = $this->approvedCharge(45000);
        $charge->forceFill(['status' => TerminalCharge::STATUS_PENDING, 'created_at' => now()->subHours(2)])->save();

        app(TerminalChargeService::class)->expireStale();

        $this->assertSame(TerminalCharge::STATUS_EXPIRED, $charge->fresh()->status);
    }

    public function test_expiring_never_touches_a_charge_already_resolved(): void
    {
        $charge = $this->approvedCharge(45000);
        $charge->forceFill(['created_at' => now()->subHours(2)])->save();

        app(TerminalChargeService::class)->expireStale();

        $this->assertSame(TerminalCharge::STATUS_APPROVED, $charge->fresh()->status);
    }
}
