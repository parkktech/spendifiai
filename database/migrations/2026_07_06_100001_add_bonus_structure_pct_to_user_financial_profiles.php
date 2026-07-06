<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus-structure setting (owner request 2026-07-06): "my bonus is a 25% bonus
 * annually based on my current years income". A structure percentage beats a
 * derived dollar lump — it recomputes as income changes and avoids the
 * YTD-annualization overstatement when the bonus is a one-time payment.
 *
 * ADDITIVE ONLY — no drops, no type changes (CLAUDE.md safety rules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_financial_profiles', function (Blueprint $table) {
            // Owner refinement: the user expresses their yearly bonus either way —
            // 'percent' (of base salary) or 'flat' (annual dollar amount). Nullable —
            // absent means no structured bonus; the optimizer falls back to the
            // paystub-derived income.bonus_annual_cents fact.
            $table->string('bonus_structure_type', 10)->nullable()->after('monthly_income');
            // Annual bonus as a percent of base salary (e.g. 25.00) — used when type='percent'.
            $table->decimal('bonus_structure_pct', 5, 2)->nullable()->after('bonus_structure_type');
            // Annual bonus as a flat dollar amount — used when type='flat'.
            $table->decimal('bonus_structure_amount', 12, 2)->nullable()->after('bonus_structure_pct');
        });
    }

    public function down(): void
    {
        // Forward-only policy: down() intentionally does NOT drop the column.
    }
};
