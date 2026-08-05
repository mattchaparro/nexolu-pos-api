<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\SaasSubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaasSubscriptionPayment>
 */
class SaasSubscriptionPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'amount_cop' => 65000,
            'period_label' => 'Mensual',
            'days_granted' => 30,
            'paid_at' => now()->toDateString(),
            'payment_method' => 'manual_panel',
        ];
    }
}
