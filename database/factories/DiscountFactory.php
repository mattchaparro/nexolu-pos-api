<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
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
            'type' => 'percentage',
            'value' => fake()->randomFloat(2, 5, 30),
            'scope' => 'cart',
            'is_active' => true,
        ];
    }

    public function fixed(): static
    {
        return $this->state(fn () => ['type' => 'fixed']);
    }

    public function itemScoped(): static
    {
        return $this->state(fn () => ['scope' => 'item']);
    }
}
