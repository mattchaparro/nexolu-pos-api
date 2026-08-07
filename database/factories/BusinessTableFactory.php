<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessTable>
 */
class BusinessTableFactory extends Factory
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
            'name' => 'Mesa '.fake()->unique()->numberBetween(1, 999),
            'number' => fake()->unique()->numberBetween(1, 999),
            'is_active' => true,
        ];
    }
}
