<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ExpenseType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseType>
 */
class ExpenseTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'business_id' => Business::factory(),
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ];
    }

    public function global(): static
    {
        return $this->state(fn () => ['business_id' => null]);
    }
}
