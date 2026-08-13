<?php

namespace Database\Factories;

use App\Models\AiUsageDaily;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageDaily>
 */
class AiUsageDailyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'date' => now()->toDateString(),
            'messages_count' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_micros' => 0,
        ];
    }
}
