<?php

namespace Database\Factories;

use App\Models\EmailConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailConnection>
 */
class EmailConnectionFactory extends Factory
{
    protected $model = EmailConnection::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'gmail',
            'email_address' => fake()->safeEmail(),
            'access_token' => 'ya29.test-' . fake()->uuid(),
            'refresh_token' => '1//test-' . fake()->uuid(),
            'token_expires_at' => now()->addHour(),
            'sync_status' => 'pending',
        ];
    }
}
