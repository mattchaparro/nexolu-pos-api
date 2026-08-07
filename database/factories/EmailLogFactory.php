<?php

namespace Database\Factories;

use App\Models\EmailLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailLog>
 */
class EmailLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'trial_reminder',
            'to_email' => fake()->safeEmail(),
            'subject' => fake()->sentence(3),
            'status' => EmailLog::STATUS_SENT,
        ];
    }
}
