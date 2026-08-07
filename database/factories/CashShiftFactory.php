<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashShift>
 */
class CashShiftFactory extends Factory
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
            'user_id' => fn (array $attributes) => User::factory()->create(['business_id' => $attributes['business_id']])->id,
            'opened_at' => now(),
            'opening_cash' => 50000,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'closed_at' => now(),
            'counted_cash' => 50000,
            'expected_cash' => 50000,
            'difference' => 0,
            'closed_via' => CashShift::CLOSED_VIA_MANUAL,
        ]);
    }
}
