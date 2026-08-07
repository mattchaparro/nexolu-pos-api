<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\SubscriptionCheckoutOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionCheckoutOrder>
 */
class SubscriptionCheckoutOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'order_key' => Str::uuid()->toString(),
            'amount_cop' => 65000,
            'subscription_days' => 30,
            'status' => 'pending',
            'provider' => 'wompi',
        ];
    }
}
