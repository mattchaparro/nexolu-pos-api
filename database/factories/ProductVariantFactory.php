<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'business_id' => fn (array $attributes) => Product::findOrFail($attributes['product_id'])->business_id,
            'sku' => 'VAR-'.fake()->unique()->numerify('#####'),
            'price' => fake()->randomFloat(2, 1000, 100000),
            'cost_price' => 0,
            'stock' => fake()->numberBetween(0, 50),
            'is_active' => true,
        ];
    }
}
