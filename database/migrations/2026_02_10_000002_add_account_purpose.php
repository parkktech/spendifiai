<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Bank Account Purpose / Tagging ───
        Schema::table('bank_accounts', function (Blueprint $table) {
            // Core: what is this account used for?
            $table->string('purpose')->default('personal')
                ->after('mask')
                ->comment('personal, business, mixed, investment');

            // Optional: user can name it for dashboard clarity
            $table->string('nickname')->nullable()
                ->after('purpose')
                ->comment('User-facing label: "My Business Checking", "Personal Visa"');

            // If business, which business entity?
            $table->string('business_name')->nullable()
                ->after('nickname')
                ->comment('e.g. "EdgeTrades LLC", "Freelance Web Dev"');

            // Tax context: helps AI know which Schedule to map to
            $table->string('tax_entity_type')->nullable()
                ->after('business_name')
                ->comment('sole_prop, llc, s_corp, c_corp, partnership, personal');

            // EIN for business accounts (encrypted at rest via model cast)
            // TEXT required: Laravel encrypt() output is ~200+ chars for AES-256-CBC
            $table->text('ein')->nullable()
                ->after('tax_entity_type')
                ->comment('Employer Identification Number — encrypted at rest');

            // Should this account be included in personal spending analysis?
            $table->boolean('include_in_spending')->default(true)
                ->after('ein')
                ->comment('false to exclude from personal budget/spending views');

            // Should this account be included in tax deduction tracking?
            $table->boolean('include_in_tax_tracking')->default(false)
                ->after('include_in_spending');
        });

        // ─── Add account_purpose to transactions for fast filtering ───
        Schema::table('transactions', function (Blueprint $table) {
            // Denormalized from bank_account for faster queries
            $table->string('account_purpose')->default('personal')
                ->after('bank_account_id')
                ->comment('Inherited from bank_account.purpose on sync');

            $table->index(['user_id', 'account_purpose', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'purpose', 'nickname', 'business_name',
                'tax_entity_type', 'ein',
                'include_in_spending', 'include_in_tax_tracking',
            ]);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'account_purpose', 'transaction_date']);
            $table->dropColumn('account_purpose');
        });
    }
};
