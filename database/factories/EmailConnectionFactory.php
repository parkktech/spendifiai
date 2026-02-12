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
            'connection_type' => 'oauth',
            'email_address' => fake()->safeEmail(),
            'access_token' => 'ya29.test-'.fake()->uuid(),
            'refresh_token' => '1//test-'.fake()->uuid(),
            'token_expires_at' => now()->addHour(),
            'status' => 'active',
            'sync_status' => 'pending',
        ];
    }

    public function imap(string $provider = 'gmail'): static
    {
        return $this->state(fn () => [
            'provider' => $provider,
            'connection_type' => 'imap',
            'imap_host' => 'imap.gmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'token_expires_at' => null,
            'refresh_token' => '',
        ]);
    }

    public function syncing(): static
    {
        return $this->state(fn () => [
            'sync_status' => 'syncing',
        ]);
    }
}
