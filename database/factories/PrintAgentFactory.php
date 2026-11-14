<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\PrintAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintAgent>
 */
class PrintAgentFactory extends Factory
{
    protected $model = PrintAgent::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'Computador de la caja',
        ];
    }

    /** Agente ya vinculado con este token en claro (los tests lo usan como Bearer). */
    public function paired(string $token = 'pa_test_token'): static
    {
        return $this->state([
            'token_hash' => hash('sha256', $token),
            'last_seen_at' => now(),
        ]);
    }
}
