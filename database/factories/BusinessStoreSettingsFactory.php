<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessStoreSettings>
 */
class BusinessStoreSettingsFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'is_active' => false,
            'store_name' => fake()->company(),
            'description' => fake()->optional()->sentence(),
            'primary_color' => '#4f46e5',
            'shipping_flat_fee' => 0,
            'min_order_amount' => 0,
            'pickup_enabled' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}
