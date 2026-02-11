<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plaid_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_type');         // TRANSACTIONS, ITEM, etc.
            $table->string('webhook_code');          // SYNC_UPDATES_AVAILABLE, ERROR, etc.
            $table->string('item_id');               // Plaid item_id
            $table->json('payload')->nullable();     // Raw webhook payload for audit
            $table->string('status')->default('processed');  // processed, ignored, failed
            $table->text('error')->nullable();       // Error message if processing failed
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Index for idempotency checks: same item + code within time window
            $table->index(['item_id', 'webhook_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaid_webhook_logs');
    }
};
