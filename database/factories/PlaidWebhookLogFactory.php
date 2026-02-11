<?php

namespace Database\Factories;

use App\Models\PlaidWebhookLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaidWebhookLog>
 */
class PlaidWebhookLogFactory extends Factory
{
    protected $model = PlaidWebhookLog::class;

    public function definition(): array
    {
        return [
            'webhook_type' => 'TRANSACTIONS',
            'webhook_code' => fake()->randomElement(['SYNC_UPDATES_AVAILABLE', 'DEFAULT_UPDATE', 'INITIAL_UPDATE']),
            'item_id' => 'item_' . fake()->uuid(),
            'payload' => ['webhook_type' => 'TRANSACTIONS', 'webhook_code' => 'SYNC_UPDATES_AVAILABLE'],
            'status' => 'processed',
            'processed_at' => now(),
        ];
    }
}
