<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dueDate = fake()->dateTimeBetween('now', '+2 weeks')->format('Y-m-d');

        return [
            'business_id' => Business::factory(),
            'created_by_user_id' => fn (array $attributes) => User::factory()->create(['business_id' => $attributes['business_id']])->id,
            'title' => fake()->sentence(3),
            'notes' => null,
            'due_date' => $dueDate,
            'notify_time' => null,
            'notify_whatsapp' => false,
            'series_anchor_date' => $dueDate,
            'recurrence' => 'none',
            'end_date' => null,
            'status' => Reminder::STATUS_PENDING,
        ];
    }

    public function recurring(string $recurrence): static
    {
        return $this->state(fn () => ['recurrence' => $recurrence]);
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status' => Reminder::STATUS_DONE,
            'completed_at' => now(),
        ]);
    }
}
