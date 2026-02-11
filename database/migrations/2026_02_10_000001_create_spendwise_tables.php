<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Connected Email Accounts ───
        Schema::create('email_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('provider'); // gmail, outlook, yahoo, icloud
            $table->string('email_address');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status')->default('pending');
            $table->timestamps();
            $table->unique(['user_id', 'email_address']);
        });

        // ─── Connected Bank Accounts (Plaid) ───
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('plaid_item_id')->unique();
            $table->text('plaid_access_token');
            $table->string('institution_name');
            $table->string('institution_id');
            $table->string('status')->default('active'); // active, error, pending_reauth
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_cursor')->nullable(); // For Plaid transactions/sync
            $table->timestamps();
        });

        // ─── Individual Bank Accounts ───
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('bank_connection_id')->constrained()->onDelete('cascade');
            $table->string('plaid_account_id')->unique();
            $table->string('name');
            $table->string('official_name')->nullable();
            $table->string('type'); // depository, credit, loan, investment
            $table->string('subtype')->nullable(); // checking, savings, credit card
            $table->string('mask', 4)->nullable(); // Last 4 digits
            $table->decimal('current_balance', 12, 2)->nullable();
            $table->decimal('available_balance', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ─── All Transactions (from Plaid) ───
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('bank_account_id')->constrained()->onDelete('cascade');
            $table->string('plaid_transaction_id')->unique();
            $table->string('merchant_name')->nullable();
            $table->string('merchant_normalized')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2); // Positive = spending, negative = income
            $table->date('transaction_date');
            $table->date('authorized_date')->nullable();
            $table->string('payment_channel')->nullable(); // online, in store, other
            $table->string('plaid_category')->nullable();
            $table->string('plaid_detailed_category')->nullable();
            $table->json('plaid_metadata')->nullable();

            // AI categorization
            $table->string('ai_category')->nullable();
            $table->decimal('ai_confidence', 3, 2)->nullable(); // 0.00 - 1.00
            $table->string('user_category')->nullable(); // User override
            $table->string('expense_type')->default('personal'); // personal, business, mixed
            $table->boolean('tax_deductible')->default(false);
            $table->string('tax_category')->nullable();

            // Review status
            $table->string('review_status')->default('auto_categorized');
            // auto_categorized, needs_review, user_confirmed, ai_uncertain

            // Subscription detection
            $table->boolean('is_subscription')->default(false);
            $table->foreignId('subscription_id')->nullable();

            // Reconciliation with email orders
            $table->foreignId('matched_order_id')->nullable();
            $table->boolean('is_reconciled')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'transaction_date']);
            $table->index(['user_id', 'ai_category']);
            $table->index(['user_id', 'review_status']);
            $table->index(['user_id', 'is_subscription']);
        });

        // ─── Detected Subscriptions ───
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('merchant_name');
            $table->string('merchant_normalized');
            $table->decimal('amount', 10, 2);
            $table->string('frequency')->default('monthly'); // weekly, monthly, quarterly, annual
            $table->string('category')->nullable();
            $table->date('last_charge_date')->nullable();
            $table->date('next_expected_date')->nullable();
            $table->string('status')->default('active'); // active, paused, cancelled, unused
            $table->integer('months_active')->default(0);
            $table->timestamp('last_used_at')->nullable(); // For detecting unused subs
            $table->boolean('is_essential')->default(false);
            $table->decimal('annual_cost', 10, 2)->nullable();
            $table->json('charge_history')->nullable(); // Array of past charge amounts/dates
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // ─── AI Categorization Questions ───
        Schema::create('ai_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('transaction_id')->constrained()->onDelete('cascade');
            $table->text('question');
            $table->json('options'); // Array of possible answers
            $table->decimal('ai_confidence', 3, 2);
            $table->string('ai_best_guess')->nullable();
            $table->string('user_answer')->nullable();
            $table->string('status')->default('pending'); // pending, answered, skipped, expired
            $table->string('question_type'); // category, business_personal, split, confirm
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // ─── Parsed Email Orders ───
        Schema::create('parsed_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('email_connection_id')->constrained()->onDelete('cascade');
            $table->string('email_message_id')->unique();
            $table->string('email_thread_id')->nullable();
            $table->boolean('is_purchase')->default(false);
            $table->boolean('is_refund')->default(false);
            $table->boolean('is_subscription')->default(false);
            $table->json('raw_parsed_data')->nullable();
            $table->string('parse_status')->default('pending');
            $table->text('parse_error')->nullable();
            $table->timestamp('email_date')->nullable();
            $table->timestamps();
        });

        // ─── Orders Extracted from Emails ───
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('parsed_email_id')->constrained()->onDelete('cascade');
            $table->string('merchant');
            $table->string('merchant_normalized')->nullable();
            $table->string('order_number')->nullable();
            $table->date('order_date');
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('tax', 10, 2)->nullable();
            $table->decimal('shipping', 10, 2)->nullable();
            $table->decimal('total', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->foreignId('matched_transaction_id')->nullable();
            $table->boolean('is_reconciled')->default(false);
            $table->timestamps();
        });

        // ─── Individual Products in Orders ───
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('product_name');
            $table->text('product_description')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->string('ai_category')->nullable();
            $table->string('user_category')->nullable();
            $table->string('tax_category')->nullable();
            $table->boolean('tax_deductible')->default(false);
            $table->decimal('tax_deductible_confidence', 3, 2)->nullable();
            $table->string('expense_type')->default('personal');
            $table->json('ai_metadata')->nullable();
            $table->timestamps();
        });

        // ─── Expense Categories ───
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->string('color', 7)->nullable();
            $table->string('parent_slug')->nullable();
            $table->string('tax_schedule_line')->nullable(); // IRS Schedule C line
            $table->boolean('is_essential')->default(false);
            $table->boolean('is_typically_deductible')->default(false);
            $table->json('keywords')->nullable(); // AI matching hints
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // ─── Savings Recommendations ───
        Schema::create('savings_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description');
            $table->decimal('monthly_savings', 10, 2);
            $table->decimal('annual_savings', 10, 2);
            $table->string('difficulty'); // easy, medium, hard
            $table->string('category');
            $table->string('impact'); // high, medium, low
            $table->string('status')->default('active'); // active, applied, dismissed
            $table->json('related_transaction_ids')->nullable();
            $table->json('related_subscription_ids')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // ─── User Budget Goals ───
        Schema::create('budget_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('category_slug');
            $table->decimal('monthly_limit', 10, 2);
            $table->string('period')->default('monthly');
            $table->boolean('notify_at_80_pct')->default(true);
            $table->boolean('notify_at_100_pct')->default(true);
            $table->timestamps();
        });

        // ─── User Profile / Preferences for AI ───
        Schema::create('user_financial_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('employment_type')->nullable(); // employed, self_employed, freelancer, business_owner
            $table->string('business_type')->nullable(); // Description of their business
            $table->boolean('has_home_office')->default(false);
            $table->string('tax_filing_status')->nullable(); // single, married, head_of_household
            $table->integer('estimated_tax_bracket')->nullable(); // 10, 12, 22, 24, 32, 35, 37
            $table->decimal('monthly_income', 12, 2)->nullable();
            $table->decimal('monthly_savings_goal', 10, 2)->nullable();
            $table->json('custom_rules')->nullable(); // User-defined categorization rules
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_financial_profiles');
        Schema::dropIfExists('budget_goals');
        Schema::dropIfExists('savings_recommendations');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('parsed_emails');
        Schema::dropIfExists('ai_questions');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('bank_connections');
        Schema::dropIfExists('email_connections');
    }
};
