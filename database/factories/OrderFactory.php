<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\ParsedEmail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 200);
        $tax = round($subtotal * 0.08, 2);
        $shipping = fake()->randomFloat(2, 0, 15);

        return [
            'user_id' => User::factory(),
            'parsed_email_id' => ParsedEmail::factory(),
            'merchant' => fake()->company(),
            'order_number' => fake()->bothify('ORD-###-???'),
            'order_date' => fake()->dateTimeBetween('-3 months'),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'shipping' => $shipping,
            'total' => round($subtotal + $tax + $shipping, 2),
        ];
    }
}
