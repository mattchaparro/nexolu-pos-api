<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCostHistory>
 */
class ProductCostHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'business_id' => fn (array $attributes) => Product::findOrFail($attributes['product_id'])->business_id,
            'cost_before' => fake()->randomFloat(4, 1000, 10000),
            'cost_after' => fake()->randomFloat(4, 1000, 10000),
            'source' => ProductCostHistory::SOURCE_PURCHASE,
            'user_id' => fn (array $attributes) => User::factory()->create(['business_id' => $attributes['business_id']])->id,
        ];
    }
}
