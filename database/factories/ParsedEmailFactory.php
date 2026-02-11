<?php

namespace Database\Factories;

use App\Models\EmailConnection;
use App\Models\ParsedEmail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParsedEmail>
 */
class ParsedEmailFactory extends Factory
{
    protected $model = ParsedEmail::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email_connection_id' => EmailConnection::factory(),
            'email_message_id' => fake()->uuid(),
            'is_purchase' => true,
            'is_refund' => false,
            'is_subscription' => false,
            'raw_parsed_data' => [
                'merchant' => fake()->company(),
                'total' => fake()->randomFloat(2, 10, 200),
                'items' => [],
            ],
            'parse_status' => 'parsed',
            'email_date' => fake()->dateTimeBetween('-3 months'),
        ];
    }
}
