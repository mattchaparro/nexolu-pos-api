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

    /**
     * Vencido hace un mes, sin pago y ya sin dias de gracia: el negocio que
     * dejo de usar la app. Quien lo use debe apagar
     * subscriptions.lockout_not_before, que corre el bloqueo.
     */
    public function lockedOut(): static
    {
        return $this->state(['trial_ends_at' => now()->subDays(30), 'paid_until' => null]);
    }
}
