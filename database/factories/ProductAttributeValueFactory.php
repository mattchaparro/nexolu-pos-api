<?php

namespace Database\Factories;

use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAttributeValue>
 */
class ProductAttributeValueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_attribute_id' => ProductAttribute::factory(),
            'business_id' => fn (array $attributes) => ProductAttribute::findOrFail($attributes['product_attribute_id'])->business_id,
            'value' => fake()->unique()->word(),
            'sort_order' => 0,
        ];
    }
}
