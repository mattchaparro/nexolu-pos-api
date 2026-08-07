<?php

namespace Database\Factories;

use App\Models\AccountingPeriodClosing;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingPeriodClosing>
 */
class AccountingPeriodClosingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'year' => now()->year,
            'month' => now()->month,
            'status' => AccountingPeriodClosing::STATUS_CLOSED,
            'summary' => [],
            'notes' => null,
            'closed_by_user_id' => User::factory(),
            'closed_at' => now(),
        ];
    }
}
