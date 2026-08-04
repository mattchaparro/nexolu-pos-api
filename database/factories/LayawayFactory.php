<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Layaway;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Layaway>
 */
class LayawayFactory extends Factory
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
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->numerify('3#########'),
            'status' => 'open',
            'created_by_user_id' => fn (array $attributes) => User::factory()->create(['business_id' => $attributes['business_id']])->id,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'cancelled_by_user_id' => $attributes['created_by_user_id'] ?? User::factory(),
            'cancelled_at' => now(),
        ]);
    }
}
