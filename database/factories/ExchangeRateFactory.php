<?php

namespace Database\Factories;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => now()->toDateString(),
            'usd_cop' => fake()->randomFloat(4, 3800, 4200),
            'source' => 'trm_superfinanciera',
            'fetched_at' => now(),
        ];
    }
}
