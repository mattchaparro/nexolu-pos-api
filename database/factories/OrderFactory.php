<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(2, 10000, 200000);
        $shipping = 5000;

        return [
            'number' => $this->faker->unique()->numberBetween(1, 999999),
            'status' => Order::STATUS_PENDING,
            'subtotal' => $subtotal,
            'shipping_fee' => $shipping,
            'total' => $subtotal + $shipping,
            'customer_name' => $this->faker->name(),
            'customer_phone' => $this->faker->numerify('3#########'),
            'customer_email' => $this->faker->safeEmail(),
            'is_pickup' => false,
            'shipping_address' => $this->faker->streetAddress(),
            'shipping_city' => $this->faker->city(),
            'public_token' => Str::random(40),
            'expires_at' => now()->addDay(),
        ];
    }

    /** Un pedido ya entregado: el unico estado que habilita reseñas. */
    public function delivered(): static
    {
        return $this->state(fn () => [
            'status' => Order::STATUS_DELIVERED,
            'confirmed_at' => now()->subDays(2),
        ]);
    }
}
