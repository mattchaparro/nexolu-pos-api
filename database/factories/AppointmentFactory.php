<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+2 weeks');

        return [
            'business_id' => Business::factory(),
            'client_name' => fake()->name(),
            'client_phone' => fake()->numerify('3#########'),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+1 hour'),
            'status' => 'pending',
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
