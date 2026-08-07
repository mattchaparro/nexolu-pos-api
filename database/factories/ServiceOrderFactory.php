<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceOrder>
 */
class ServiceOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => fn (array $attributes) => User::factory()->create(['business_id' => $attributes['business_id']])->id,
            'service_name' => fake()->words(2, true),
            'total' => fake()->randomFloat(2, 10000, 200000),
            'amount_paid' => 0,
            'status' => 'pending',
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
