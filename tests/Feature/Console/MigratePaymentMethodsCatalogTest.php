<?php

namespace Tests\Feature\Console;

use App\Models\Business;
use App\Models\PosPaymentMethod;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MigratePaymentMethodsCatalogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_migrates_a_business_matching_spanish_labels_to_catalog_keys(): void
    {
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash']);
        $credit = PosPaymentMethod::factory()->create(['key' => 'credit']);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi']);
        $business = Business::factory()->create([
            'payment_methods' => [
                ['id' => 'efectivo', 'label' => 'Efectivo'],
                ['id' => 'fiado', 'label' => 'Fiado'],
                ['id' => 'nequi', 'label' => 'Nequi'],
            ],
        ]);

        $this->artisan('payment-methods:migrate-catalog')->assertSuccessful();

        $this->assertDatabaseHas('business_pos_payment_methods', ['business_id' => $business->id, 'pos_payment_method_id' => $cash->id, 'is_enabled' => true]);
        $this->assertDatabaseHas('business_pos_payment_methods', ['business_id' => $business->id, 'pos_payment_method_id' => $credit->id, 'is_enabled' => true]);
        $this->assertDatabaseHas('business_pos_payment_methods', ['business_id' => $business->id, 'pos_payment_method_id' => $nequi->id, 'is_enabled' => true]);
        $this->assertSame(['cash', 'credit', 'nequi'], $business->fresh()->allowedPaymentMethodIds());
    }

    public function test_dry_run_reports_without_writing(): void
    {
        PosPaymentMethod::factory()->create(['key' => 'cash']);
        $business = Business::factory()->create(['payment_methods' => [['id' => 'efectivo', 'label' => 'Efectivo']]]);

        $this->artisan('payment-methods:migrate-catalog', ['--dry-run' => true])
            ->expectsOutputToContain('migraria 1 medio(s)')
            ->assertSuccessful();

        $this->assertDatabaseMissing('business_pos_payment_methods', ['business_id' => $business->id]);
    }

    public function test_it_skips_a_business_that_already_has_any_catalog_row(): void
    {
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash']);
        $transfer = PosPaymentMethod::factory()->create(['key' => 'transfer']);
        $business = Business::factory()->create(['payment_methods' => [['id' => 'efectivo', 'label' => 'Efectivo']]]);
        // El admin ya migro manualmente desde Ajustes, con una seleccion propia.
        $business->posPaymentMethods()->attach([$transfer->id => ['is_enabled' => true]]);

        $this->artisan('payment-methods:migrate-catalog')->assertSuccessful();

        $this->assertDatabaseMissing('business_pos_payment_methods', ['business_id' => $business->id, 'pos_payment_method_id' => $cash->id]);
        $this->assertDatabaseHas('business_pos_payment_methods', ['business_id' => $business->id, 'pos_payment_method_id' => $transfer->id]);
    }

    public function test_a_business_with_no_matching_catalog_entries_is_left_untouched_and_reported(): void
    {
        // Catalogo vacio a proposito: nada matchea.
        $business = Business::factory()->create(['payment_methods' => [['id' => 'bitcoin', 'label' => 'Bitcoin']]]);

        $this->artisan('payment-methods:migrate-catalog')
            ->expectsOutputToContain('SIN NINGUN medio con match')
            ->assertSuccessful();

        $this->assertDatabaseMissing('business_pos_payment_methods', ['business_id' => $business->id]);
    }

    public function test_the_business_option_limits_the_scope(): void
    {
        PosPaymentMethod::factory()->create(['key' => 'cash']);
        $target = Business::factory()->create(['payment_methods' => [['id' => 'efectivo', 'label' => 'Efectivo']]]);
        $other = Business::factory()->create(['payment_methods' => [['id' => 'efectivo', 'label' => 'Efectivo']]]);

        $this->artisan('payment-methods:migrate-catalog', ['--business' => $target->id])->assertSuccessful();

        $this->assertDatabaseHas('business_pos_payment_methods', ['business_id' => $target->id]);
        $this->assertDatabaseMissing('business_pos_payment_methods', ['business_id' => $other->id]);
    }

    public function test_a_business_with_no_configured_payment_methods_falls_back_to_the_defaults(): void
    {
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash']);
        $transfer = PosPaymentMethod::factory()->create(['key' => 'transfer']);
        $credit = PosPaymentMethod::factory()->create(['key' => 'credit']);
        $business = Business::factory()->create(['payment_methods' => null]);

        $this->artisan('payment-methods:migrate-catalog')->assertSuccessful();

        $this->assertDatabaseHas('business_pos_payment_methods', ['business_id' => $business->id, 'pos_payment_method_id' => $cash->id]);
        $this->assertDatabaseHas('business_pos_payment_methods', ['business_id' => $business->id, 'pos_payment_method_id' => $transfer->id]);
        $this->assertDatabaseHas('business_pos_payment_methods', ['business_id' => $business->id, 'pos_payment_method_id' => $credit->id]);
    }
}
