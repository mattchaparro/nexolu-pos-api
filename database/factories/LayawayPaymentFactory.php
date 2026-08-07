<?php

namespace Database\Factories;

use App\Models\Layaway;
use App\Models\LayawayPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LayawayPayment>
 */
class LayawayPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'layaway_id' => Layaway::factory(),
            'business_id' => fn (array $attributes) => Layaway::findOrFail($attributes['layaway_id'])->business_id,
            'amount' => fake()->randomFloat(2, 1000, 50000),
            'payment_method' => 'cash',
            'recorded_by_user_id' => fn (array $attributes) => User::factory()->create(['business_id' => $attributes['business_id']])->id,
        ];
    }
}
