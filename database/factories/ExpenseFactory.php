<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
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
            'date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'description' => fake()->sentence(4),
            'value' => fake()->numberBetween(1000, 500000),
            'scope' => 'operacional',
            'payment_method' => fake()->randomElement(Expense::PAYMENT_METHODS),
            'type_id' => fn (array $attributes) => ExpenseType::factory()
                ->create(['business_id' => $attributes['business_id']])
                ->id,
        ];
    }
}
