<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus 401(k) eligibility (owner request 2026-07-06): "the optimization needs
 * to properly account for that bonus, its taxes and 401k contributions".
 * Whether the plan takes 401(k) deferrals from bonus checks varies by plan —
 * this flag lets the engine include the bonus in deferral-eligible comp.
 *
 * ADDITIVE ONLY — no drops, no type changes (CLAUDE.md safety rules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_financial_profiles', function (Blueprint $table) {
            // Null = unknown (engine treats as not eligible — conservative).
            $table->boolean('bonus_401k_eligible')->nullable()->after('bonus_structure_amount');
        });
    }

    public function down(): void
    {
        // Forward-only policy: down() intentionally does NOT drop the column.
    }
};
