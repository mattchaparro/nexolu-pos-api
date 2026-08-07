<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SalePaymentSplit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalePaymentSplit>
 */
class SalePaymentSplitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'payment_method' => 'cash',
            'amount' => fake()->randomFloat(2, 1000, 50000),
            'payer_label' => null,
        ];
    }
}
