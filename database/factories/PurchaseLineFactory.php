<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseLine>
 */
class PurchaseLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 20);
        $unitCost = fake()->randomFloat(4, 1000, 20000);

        return [
            'purchase_id' => Purchase::factory(),
            'product_id' => fn (array $attributes) => Product::factory()
                ->create(['business_id' => Purchase::findOrFail($attributes['purchase_id'])->business_id])
                ->id,
            'quantity' => $quantity,
            'unit_cost_cop' => $unitCost,
            'line_total_cop' => round($quantity * $unitCost, 2),
        ];
    }
}
