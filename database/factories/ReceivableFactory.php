<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Receivable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receivable>
 */
class ReceivableFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 1000, 200000);

        return [
            'business_id' => Business::factory(),
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->numerify('3#########'),
            'customer_key' => fn (array $attributes) => 'phone:'.preg_replace('/\D+/', '', $attributes['customer_phone']),
            'amount' => $amount,
            'balance' => $amount,
            'status' => 'pending',
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'balance' => 0,
            'payment_method' => 'cash',
            'paid_at' => now(),
        ]);
    }
}
