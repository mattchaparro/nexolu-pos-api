<?php

namespace Database\Factories;

use App\Models\AiMessagePackCheckoutOrder;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiMessagePackCheckoutOrder>
 */
class AiMessagePackCheckoutOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'order_key' => 'NEXPACK-'.Str::upper(Str::random(10)),
            'messages' => 1000,
            'price_cop' => 15000,
            'status' => 'pending',
            'provider' => 'wompi',
        ];
    }
}
