<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── FK column indexes (foreign keys without individual indexes) ──

        Schema::table('bank_connections', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->index('bank_connection_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index('bank_account_id');
            $table->index('subscription_id');
            $table->index('matched_order_id');
        });

        Schema::table('parsed_emails', function (Blueprint $table) {
            $table->index('email_connection_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('parsed_email_id');
            $table->index('matched_transaction_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index('order_id');
            $table->index('user_id');
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('budget_goals', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('user_financial_profiles', function (Blueprint $table) {
            $table->index('user_id');
        });

        // ── Composite indexes for common query patterns ──

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'expense_type']);
            $table->index(['user_id', 'tax_deductible']);
        });

        Schema::table('bank_connections', function (Blueprint $table) {
            $table->index(['user_id', 'status']);
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->index(['user_id', 'is_active']);
        });

        // ── PostgreSQL partial indexes for hot query paths ──

        DB::statement("
            CREATE INDEX idx_txn_pending_categorization
            ON transactions (user_id)
            WHERE review_status IN ('pending_ai', 'needs_review', 'ai_uncertain')
        ");

        DB::statement("
            CREATE INDEX idx_subs_active
            ON subscriptions (user_id)
            WHERE status = 'active'
        ");

        DB::statement("
            CREATE INDEX idx_questions_pending
            ON ai_questions (user_id)
            WHERE status = 'pending'
        ");
    }

    public function down(): void
    {
        // Drop partial indexes
        DB::statement('DROP INDEX IF EXISTS idx_questions_pending');
        DB::statement('DROP INDEX IF EXISTS idx_subs_active');
        DB::statement('DROP INDEX IF EXISTS idx_txn_pending_categorization');

        // Drop composite indexes
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_active']);
        });

        Schema::table('bank_connections', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'tax_deductible']);
            $table->dropIndex(['user_id', 'expense_type']);
        });

        // Drop FK indexes
        Schema::table('user_financial_profiles', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('budget_goals', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['order_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['matched_transaction_id']);
            $table->dropIndex(['parsed_email_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('parsed_emails', function (Blueprint $table) {
            $table->dropIndex(['email_connection_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['matched_order_id']);
            $table->dropIndex(['subscription_id']);
            $table->dropIndex(['bank_account_id']);
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['bank_connection_id']);
        });

        Schema::table('bank_connections', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
