<?php

namespace Database\Factories;

use App\Models\AiMessagePackPurchase;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiMessagePackPurchase>
 */
class AiMessagePackPurchaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'messages' => 1000,
            'price_cop' => 15000,
            'created_by_user_id' => null,
            'notes' => null,
        ];
    }
}
