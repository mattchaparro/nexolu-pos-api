<?php

namespace Tests\Feature\Api\V1;

use App\Mail\PaymentMethodSupportRequestMail;
use App\Models\Business;
use App\Models\PosPaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BusinessPosPaymentMethodsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_sees_active_catalog_entries_unselected_when_business_has_not_migrated(): void
    {
        $business = Business::factory()->create(['payment_methods' => [['id' => 'efectivo', 'label' => 'Efectivo']]]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        PosPaymentMethod::factory()->create(['key' => 'cash', 'label' => 'Efectivo', 'is_active' => true]);
        PosPaymentMethod::factory()->create(['key' => 'retired', 'label' => 'Retirado', 'is_active' => false]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/business/payment-methods')
            ->assertOk()
            ->assertJsonPath('migrated', false)
            ->assertJsonPath('legacy_payment_methods.0.id', 'efectivo');

        $keys = collect($response->json('data'))->pluck('key');
        $this->assertContains('cash', $keys);
        $this->assertNotContains('retired', $keys);
        $this->assertFalse(collect($response->json('data'))->firstWhere('key', 'cash')['is_enabled']);
    }

    public function test_owner_can_select_catalog_methods_which_migrates_the_business_off_the_json_column(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash']);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi']);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business/payment-methods', [
                'methods' => [
                    ['pos_payment_method_id' => $cash->id, 'is_enabled' => true],
                    ['pos_payment_method_id' => $nequi->id, 'is_enabled' => false],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('migrated', true);

        $this->assertSame(['cash'], $business->fresh()->allowedPaymentMethodIds());
    }

    public function test_disabling_a_method_never_deletes_its_pivot_row(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash']);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi']);
        $business->posPaymentMethods()->attach([
            $cash->id => ['is_enabled' => true],
            $nequi->id => ['is_enabled' => true],
        ]);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business/payment-methods', [
                'methods' => [
                    ['pos_payment_method_id' => $nequi->id, 'is_enabled' => false],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('business_pos_payment_methods', [
            'business_id' => $business->id,
            'pos_payment_method_id' => $nequi->id,
            'is_enabled' => false,
        ]);
        // El otro medio de pago que no vino en el payload sigue intacto (syncWithoutDetaching).
        $this->assertDatabaseHas('business_pos_payment_methods', [
            'business_id' => $business->id,
            'pos_payment_method_id' => $cash->id,
            'is_enabled' => true,
        ]);
    }

    public function test_business_cannot_disable_every_payment_method(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash']);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business/payment-methods', [
                'methods' => [
                    ['pos_payment_method_id' => $cash->id, 'is_enabled' => false],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('methods');
    }

    public function test_regular_employee_cannot_update_payment_methods(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash']);

        $this->actingAs($employee, 'sanctum')
            ->putJson('/api/v1/business/payment-methods', [
                'methods' => [
                    ['pos_payment_method_id' => $cash->id, 'is_enabled' => true],
                ],
            ])
            ->assertForbidden();
    }

    public function test_regular_employee_cannot_view_payment_methods(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/business/payment-methods')
            ->assertForbidden();
    }

    public function test_any_business_user_can_request_support_for_a_missing_payment_method(): void
    {
        Mail::fake();

        $business = Business::factory()->create(['name' => 'Cafe Nexolu']);
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/business/payment-methods/support-request', [
                'message' => 'Necesitamos que agreguen Bancolombia a la lista.',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Mail::assertSent(PaymentMethodSupportRequestMail::class, function (PaymentMethodSupportRequestMail $mail) use ($business, $employee) {
            $mail->assertTo(config('mail.support_address'));

            return $mail->business->is($business)
                && $mail->requester->is($employee)
                && $mail->requestMessage === 'Necesitamos que agreguen Bancolombia a la lista.';
        });
    }

    public function test_support_request_requires_a_message(): void
    {
        Mail::fake();

        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/business/payment-methods/support-request', ['message' => ''])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }
}
