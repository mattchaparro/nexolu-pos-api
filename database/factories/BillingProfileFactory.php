<?php

namespace Database\Factories;

use App\Models\BillingProfile;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingProfile>
 */
class BillingProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'document_type' => 'CC',
            'document_number' => (string) $this->faker->numberBetween(10_000_000, 1_199_999_999),
            'full_name' => $this->faker->name(),
            'phone' => '3'.$this->faker->numerify('#########'),
            'email' => $this->faker->safeEmail(),
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
        ];
    }
}
