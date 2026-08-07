<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->unique()->word(),
            'unit' => fake()->randomElement(['und', 'kg', 'g', 'lt', 'ml']),
            'stock' => fake()->randomFloat(2, 0, 100),
            'cost_price' => fake()->randomFloat(4, 500, 20000),
            'min_stock' => 0,
            'is_active' => true,
        ];
    }
}
