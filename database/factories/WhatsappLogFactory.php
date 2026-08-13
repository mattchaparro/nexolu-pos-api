<?php

namespace Database\Factories;

use App\Models\WhatsappLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappLog>
 */
class WhatsappLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'recordatorio',
            'to_phone' => '573'.fake()->numerify('#########'),
            'status' => WhatsappLog::STATUS_SENT,
        ];
    }
}
