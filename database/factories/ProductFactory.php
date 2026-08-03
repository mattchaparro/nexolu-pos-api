<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
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
            'category_id' => fn (array $attributes) => ProductCategory::factory()
                ->create(['business_id' => $attributes['business_id']])
                ->id,
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 1000, 100000),
            'cost_price' => fake()->randomFloat(2, 500, 50000),
            'stock' => fake()->numberBetween(0, 100),
            'track_stock' => true,
            'is_single_sale' => false,
            'is_service' => false,
            'price_varies_at_sale' => false,
            'is_active' => true,
        ];
    }

    public function service(): static
    {
        return $this->state(fn () => [
            'is_service' => true,
            'track_stock' => false,
            'stock' => 0,
        ]);
    }
}
