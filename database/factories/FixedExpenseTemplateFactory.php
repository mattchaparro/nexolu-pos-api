<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ExpenseType;
use App\Models\FixedExpenseTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixedExpenseTemplate>
 */
class FixedExpenseTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->words(3, true),
            'amount' => fake()->numberBetween(50000, 2000000),
            'expense_type_id' => fn (array $attributes) => ExpenseType::factory()
                ->create(['business_id' => $attributes['business_id']])
                ->id,
            'active' => true,
            'scope' => 'administrativo',
            'day_of_month' => fake()->numberBetween(1, 28),
        ];
    }
}
