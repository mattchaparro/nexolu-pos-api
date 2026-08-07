<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CashClosing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashClosing>
 */
class CashClosingFactory extends Factory
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
            'date' => now()->toDateString(),
            'opening_cash' => 50000,
            'expected_cash' => 50000,
            'actual_cash' => 50000,
            'base_for_next_day' => 50000,
            'closed_by' => fn (array $attributes) => User::factory()->create(['business_id' => $attributes['business_id']])->id,
            'created_via' => CashClosing::CREATED_VIA_MANUAL,
        ];
    }
}
