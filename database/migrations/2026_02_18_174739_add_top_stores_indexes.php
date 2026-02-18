<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Partial index for merchant spending aggregation (amount > 0 only)
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_txn_merchant_spending
            ON transactions (user_id, transaction_date)
            WHERE amount > 0
        ');

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'merchant_normalized'], 'idx_txn_user_merchant_normalized');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_txn_merchant_spending');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_txn_user_merchant_normalized');
        });
    }
};
