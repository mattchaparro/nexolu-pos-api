<?php

namespace Database\Factories;

use App\Models\CronJobLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CronJobLog>
 */
class CronJobLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_key' => fake()->slug(2, false),
            'status' => CronJobLog::STATUS_SUCCESS,
            'output' => fake()->sentence(),
            'triggered_by' => CronJobLog::TRIGGERED_BY_SCHEDULER,
            'ran_at' => now(),
        ];
    }
}
