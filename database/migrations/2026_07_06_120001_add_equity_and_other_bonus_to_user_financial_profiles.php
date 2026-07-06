<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equity + other-bonus income (owner reconciliation 2026-07-06): HR total-rewards
 * statement showed $25k/yr stock and a $2,554.37 FLI bonus the system was missing
 * — $27.5k of taxable income. Both are annual income for tax/MAGI math; neither
 * joins the 401(k) deferral base.
 *
 * ADDITIVE ONLY — no drops, no type changes (CLAUDE.md safety rules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_financial_profiles', function (Blueprint $table) {
            // Annual stock/RSU value (taxable as it vests; modeled as annual income).
            $table->decimal('equity_annual_amount', 12, 2)->nullable()->after('bonus_401k_eligible');
            // Other annual cash bonuses beyond the main bonus structure (e.g. FLI).
            $table->decimal('other_bonus_annual_amount', 12, 2)->nullable()->after('equity_annual_amount');
        });
    }

    public function down(): void
    {
        // Forward-only policy: down() intentionally does NOT drop the column.
    }
};
