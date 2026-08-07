<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
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
            'purchased_at' => now()->toDateString(),
            'user_id' => fn (array $attributes) => User::factory()->create(['business_id' => $attributes['business_id']])->id,
            'payment_status' => 'paid',
            'paid_at' => now(),
        ];
    }

    public function credit(): static
    {
        return $this->state(fn () => [
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);
    }
}
