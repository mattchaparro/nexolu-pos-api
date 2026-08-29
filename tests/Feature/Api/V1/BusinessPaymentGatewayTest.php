<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessPaymentGateway;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conectar la pasarela propia de un negocio.
 *
 * Lo que mas importa aca: las llaves del proveedor NO se guardan en este
 * repo (viven en el Payments Core), y lo que si se guarda va cifrado.
 */
class BusinessPaymentGatewayTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payments_core.base_url' => 'http://payments.test',
            'services.payments_core.provisioning_key' => 'prov-key',
        ]);

        $this->business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);
        $this->owner = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => true]);
        $this->owner->assignRole('admin');
    }

    private function fakeCoreOk(): void
    {
        Http::fake([
            'http://payments.test/v1/admin/merchants' => Http::response(['id' => 'mch_1', 'slug' => 'neg-x'], 201),
            'http://payments.test/v1/admin/merchants/mch_1/integrations' => Http::response([
                'id' => 'int_1', 'api_key' => 'nxl_secret_key', 'webhook_secret' => 'whsec_abc',
            ], 201),
            'http://payments.test/v1/admin/merchants/mch_1/providers/bold' => Http::response(['configured' => true], 201),
        ]);
    }

    public function test_connecting_bold_stores_only_our_own_credential(): void
    {
        $this->fakeCoreOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/payment-gateways', [
                'provider_slug' => 'bold',
                'credentials' => ['identity_key' => 'ident_abc', 'secret_key' => 'sec_abc'],
            ])
            ->assertCreated()
            ->assertJsonPath('is_connected', true);

        $gateway = BusinessPaymentGateway::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame('nxl_secret_key', $gateway->integration_api_key);
        $this->assertSame('whsec_abc', $gateway->webhook_secret);

        // La llave de Bold nunca toca esta base: vive cifrada en el Core.
        $raw = DB::table('business_payment_gateways')->find($gateway->id);
        $this->assertStringNotContainsString('ident_abc', json_encode($raw));
        $this->assertStringNotContainsString('nxl_secret_key', json_encode($raw), 'Lo que si guardamos va cifrado');
    }

    public function test_the_empty_sandbox_secret_is_accepted(): void
    {
        $this->fakeCoreOk();

        // En sandbox Bold firma con la cadena vacia: es un valor legitimo,
        // no una credencial faltante.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/payment-gateways', [
                'provider_slug' => 'bold',
                'environment' => 'sandbox',
                'credentials' => ['identity_key' => 'ident_abc', 'secret_key' => ''],
            ])
            ->assertCreated();
    }

    public function test_a_missing_credential_is_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/payment-gateways', [
                'provider_slug' => 'bold',
                'credentials' => ['identity_key' => 'ident_abc'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credentials.secret_key');
    }

    public function test_a_failure_in_the_core_leaves_the_reason_visible_and_does_not_activate(): void
    {
        Http::fake([
            'http://payments.test/v1/admin/merchants' => Http::response(['detail' => 'Provisioning key invalida.'], 401),
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/payment-gateways', [
                'provider_slug' => 'bold',
                'credentials' => ['identity_key' => 'x', 'secret_key' => 'y'],
            ])
            ->assertStatus(502);

        $gateway = BusinessPaymentGateway::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();
        $this->assertFalse($gateway->is_active);
        $this->assertNotNull($gateway->last_error, 'El comerciante tiene que poder ver por que fallo');
    }

    public function test_the_index_never_leaks_secrets(): void
    {
        $this->fakeCoreOk();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/payment-gateways', [
            'provider_slug' => 'bold',
            'credentials' => ['identity_key' => 'ident_abc', 'secret_key' => 'sec_abc'],
        ])->assertCreated();

        $response = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/payment-gateways')->assertOk();

        $body = $response->getContent();
        foreach (['ident_abc', 'sec_abc', 'nxl_secret_key', 'whsec_abc'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_a_business_without_the_module_cannot_reach_it(): void
    {
        $other = Business::factory()->create(['feature_flags' => BusinessFeaturePresets::full()]);
        $user = User::factory()->create(['business_id' => $other->id, 'is_business_owner' => true]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/payment-gateways')->assertForbidden();
    }

    public function test_disconnecting_turns_it_off(): void
    {
        $this->fakeCoreOk();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/payment-gateways', [
            'provider_slug' => 'bold',
            'credentials' => ['identity_key' => 'ident_abc', 'secret_key' => 'sec_abc'],
        ])->assertCreated();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson('/api/v1/payment-gateways/bold')
            ->assertOk()
            ->assertJsonPath('is_connected', false);

        $this->assertNull($this->business->fresh()->activePaymentGateway());
    }
}
