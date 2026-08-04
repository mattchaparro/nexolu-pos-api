<?php

namespace Database\Factories;

use App\Models\Layaway;
use App\Models\LayawayItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LayawayItem>
 */
class LayawayItemFactory extends Factory
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
            'product_id' => fn (array $attributes) => Product::factory()
                ->create(['business_id' => Layaway::findOrFail($attributes['layaway_id'])->business_id])
                ->id,
            'quantity' => fake()->numberBetween(1, 5),
            'unit_price' => fake()->randomFloat(2, 1000, 50000),
        ];
    }
}
