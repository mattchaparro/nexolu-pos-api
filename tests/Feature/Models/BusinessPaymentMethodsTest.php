<?php

namespace Tests\Feature\Models;

use App\Models\Business;
use App\Models\PosPaymentMethod;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BusinessPaymentMethodsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_business_without_catalog_rows_falls_back_to_the_legacy_json_column(): void
    {
        $business = Business::factory()->create([
            'payment_methods' => [['id' => 'efectivo', 'label' => 'Efectivo']],
        ]);

        $methods = $business->paymentMethods();

        $this->assertSame('efectivo', $methods[0]['id']);
        $this->assertArrayNotHasKey('enabled', $methods[0]);
    }

    public function test_a_business_with_catalog_rows_reads_from_the_catalog_instead_of_the_json_column(): void
    {
        $business = Business::factory()->create([
            'payment_methods' => [['id' => 'efectivo', 'label' => 'Efectivo']],
        ]);
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash', 'label' => 'Efectivo', 'sort_order' => 1]);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi', 'label' => 'Nequi', 'sort_order' => 2]);
        $business->posPaymentMethods()->attach([
            $cash->id => ['is_enabled' => true],
            $nequi->id => ['is_enabled' => false],
        ]);

        $methods = $business->fresh()->paymentMethods();

        $this->assertSame(
            [
                ['id' => 'cash', 'label' => 'Efectivo', 'enabled' => true],
                ['id' => 'nequi', 'label' => 'Nequi', 'enabled' => false],
            ],
            $methods,
        );
    }

    public function test_allowed_payment_method_ids_excludes_disabled_catalog_entries(): void
    {
        $business = Business::factory()->create();
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash']);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi']);
        $business->posPaymentMethods()->attach([
            $cash->id => ['is_enabled' => true],
            $nequi->id => ['is_enabled' => false],
        ]);

        $allowed = $business->fresh()->allowedPaymentMethodIds();

        $this->assertSame(['cash'], $allowed);
    }

    public function test_disabling_a_catalog_entry_keeps_it_resolvable_for_historical_labels(): void
    {
        $business = Business::factory()->create();
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi', 'label' => 'Nequi']);
        $business->posPaymentMethods()->attach([$nequi->id => ['is_enabled' => false]]);

        $methods = $business->fresh()->paymentMethods();

        // Sigue apareciendo en paymentMethods() (para labels de ventas viejas
        // con ese medio) aunque ya no este en allowedPaymentMethodIds().
        $this->assertSame('Nequi', $methods[0]['label']);
        $this->assertFalse($methods[0]['enabled']);
        $this->assertSame([], $business->allowedPaymentMethodIds());
    }

    public function test_enabled_payment_methods_excludes_disabled_catalog_entries(): void
    {
        $business = Business::factory()->create();
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash', 'label' => 'Efectivo', 'sort_order' => 1]);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi', 'label' => 'Nequi', 'sort_order' => 2]);
        $business->posPaymentMethods()->attach([
            $cash->id => ['is_enabled' => true],
            $nequi->id => ['is_enabled' => false],
        ]);

        $enabled = $business->fresh()->enabledPaymentMethods();

        $this->assertSame([['id' => 'cash', 'label' => 'Efectivo']], $enabled);
    }

    public function test_payment_method_labels_map_includes_disabled_catalog_entries(): void
    {
        $business = Business::factory()->create();
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash', 'label' => 'Efectivo']);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi', 'label' => 'Nequi']);
        $business->posPaymentMethods()->attach([
            $cash->id => ['is_enabled' => true],
            $nequi->id => ['is_enabled' => false],
        ]);

        $labels = $business->fresh()->paymentMethodLabelsMap();

        $this->assertSame(['cash' => 'Efectivo', 'nequi' => 'Nequi'], $labels);
    }
}
