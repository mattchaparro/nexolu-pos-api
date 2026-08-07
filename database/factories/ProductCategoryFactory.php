<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'icon' => 'inventory_2',
        ];
    }
}
