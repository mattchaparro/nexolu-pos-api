<?php

namespace Database\Factories;

use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchasePayment>
 */
class PurchasePaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_id' => Purchase::factory(),
            'business_id' => fn (array $attributes) => Purchase::findOrFail($attributes['purchase_id'])->business_id,
            'amount' => fake()->randomFloat(2, 5000, 50000),
            'payment_method' => 'cash',
            'recorded_by_user_id' => fn (array $attributes) => User::factory()->create(['business_id' => $attributes['business_id']])->id,
        ];
    }
}
