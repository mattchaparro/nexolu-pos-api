<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SalePartialPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalePartialPayment>
 */
class SalePartialPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory()->state(['status' => 'open']),
            'amount' => fake()->randomFloat(2, 1000, 50000),
            'payment_method' => 'cash',
            'payer_label' => null,
        ];
    }
}
