<?php

namespace Database\Factories;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceOrderItem>
 */
class ServiceOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_order_id' => ServiceOrder::factory(),
            'name' => fake()->words(2, true),
            'quantity' => 1,
            'unit_price' => fake()->randomFloat(2, 10000, 100000),
            'sort_order' => 0,
        ];
    }
}
