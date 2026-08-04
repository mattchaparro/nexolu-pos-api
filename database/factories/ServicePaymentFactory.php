<?php

namespace Database\Factories;

use App\Models\ServiceOrder;
use App\Models\ServicePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServicePayment>
 */
class ServicePaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_order_id' => ServiceOrder::factory(),
            'business_id' => fn (array $attributes) => ServiceOrder::findOrFail($attributes['service_order_id'])->business_id,
            'amount' => fake()->randomFloat(2, 5000, 50000),
            'payment_method' => 'cash',
            'recorded_by_user_id' => fn (array $attributes) => User::factory()->create(['business_id' => $attributes['business_id']])->id,
        ];
    }
}
