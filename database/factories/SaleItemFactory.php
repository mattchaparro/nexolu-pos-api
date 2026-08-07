<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomFloat(2, 1000, 50000);

        return [
            'sale_id' => Sale::factory(),
            'product_id' => fn (array $attributes) => Product::factory()
                ->create(['business_id' => Sale::findOrFail($attributes['sale_id'])->business_id])
                ->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost_at_sale' => fake()->randomFloat(4, 500, 25000),
            'subtotal' => $unitPrice * $quantity,
            'discount_amount' => 0,
        ];
    }
}
