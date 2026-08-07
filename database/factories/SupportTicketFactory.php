<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => fn (array $attributes) => User::factory()->create(['business_id' => $attributes['business_id']])->id,
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'priority' => 'normal',
            'status' => 'open',
        ];
    }
}
