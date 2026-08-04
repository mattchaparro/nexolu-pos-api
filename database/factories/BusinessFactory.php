<?php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(),
            'owner_name' => fake()->name(),
            'phone' => fake()->numerify('3#########'),
            'active' => true,
            'trial_ends_at' => now()->addDays(Business::TRIAL_DAYS),
            'subscription_plan' => 'basic',
            'payment_methods' => null,
            'feature_flags' => null,
        ];
    }
}
