<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Printer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Printer>
 */
class PrinterFactory extends Factory
{
    protected $model = Printer::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'Impresora de cocina',
            'role' => Printer::ROLE_KITCHEN,
            'connection' => Printer::CONNECTION_NETWORK,
            'address' => '192.168.1.'.fake()->numberBetween(20, 250).':9100',
            'paper_width' => 80,
            'is_active' => true,
        ];
    }

    public function kitchen(): static
    {
        return $this->state(['name' => 'Impresora de cocina', 'role' => Printer::ROLE_KITCHEN]);
    }

    public function counter(): static
    {
        return $this->state([
            'name' => 'Impresora del mostrador',
            'role' => Printer::ROLE_COUNTER,
            'connection' => Printer::CONNECTION_WINDOWS,
            'address' => 'POS-80 USB',
        ]);
    }
}
