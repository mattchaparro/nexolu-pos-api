<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessPaymentSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BusinessPaymentSource>
 */
class BusinessPaymentSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'provider_slug' => 'wompi',
            'payment_source_id' => (string) $this->faker->numberBetween(100000, 999999),
            'type' => 'CARD',
            'label' => 'Visa •••• '.Str::padLeft((string) $this->faker->numberBetween(1000, 9999), 4, '0'),
            'status' => 'active',
        ];
    }
}
