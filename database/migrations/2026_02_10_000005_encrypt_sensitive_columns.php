<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security hardening: Change column types to support Laravel's encrypt() cast.
 *
 * Laravel's AES-256-CBC encryption produces ~200+ character ciphertext even for
 * short plaintext values. JSON and DECIMAL columns can't store this — they need
 * to be TEXT columns.
 *
 * Fields being encrypted in this migration:
 * - transactions.plaid_metadata       (json → text)   Raw Plaid API response
 * - parsed_emails.raw_parsed_data     (json → text)   Parsed financial email data
 * - user_financial_profiles.monthly_income  (decimal → text)   User's stated income
 * - user_financial_profiles.custom_rules    (json → text)   User categorization rules
 *
 * Fields already correctly typed as TEXT (no change needed):
 * - bank_connections.plaid_access_token     ✓ TEXT + encrypted cast
 * - email_connections.access_token          ✓ TEXT + encrypted cast
 * - email_connections.refresh_token         ✓ TEXT + encrypted cast
 * - users.two_factor_secret                 ✓ TEXT + encrypted cast
 * - users.two_factor_recovery_codes         ✓ TEXT + encrypted cast
 *
 * Already fixed in migration 000002:
 * - bank_accounts.ein                       ✓ TEXT + encrypted cast
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Transactions: encrypt raw Plaid API metadata ───
        Schema::table('transactions', function (Blueprint $table) {
            $table->text('plaid_metadata')->nullable()->change();
        });

        // ─── Parsed Emails: encrypt parsed financial data from user emails ───
        Schema::table('parsed_emails', function (Blueprint $table) {
            $table->text('raw_parsed_data')->nullable()->change();
        });

        // ─── Financial Profile: encrypt income and custom rules ───
        Schema::table('user_financial_profiles', function (Blueprint $table) {
            // monthly_income: decimal(12,2) → text for encrypted cast
            $table->text('monthly_income')->nullable()->change();

            // custom_rules: json → text for encrypted:array cast
            $table->text('custom_rules')->nullable()->change();
        });
    }

    public function down(): void
    {
        // WARNING: Rolling back will DESTROY encrypted data in these columns.
        // Only safe on empty/test databases.

        Schema::table('transactions', function (Blueprint $table) {
            $table->json('plaid_metadata')->nullable()->change();
        });

        Schema::table('parsed_emails', function (Blueprint $table) {
            $table->json('raw_parsed_data')->nullable()->change();
        });

        Schema::table('user_financial_profiles', function (Blueprint $table) {
            $table->decimal('monthly_income', 12, 2)->nullable()->change();
            $table->json('custom_rules')->nullable()->change();
        });
    }
};
