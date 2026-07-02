<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration: adds `built_against` JSONB column to optimization_reports.
 *
 * SAFETY: forward-only additive (ADD COLUMN only — no DROP, TRUNCATE, or ALTER
 * of existing columns). See CLAUDE.md safety rules.
 *
 * Purpose: Decision 13 (2026-07-02) — report staleness policy.
 * The generator snapshots the income/savings aggregates used at report generation
 * time into this column. The MarkOptimizationReportStale listener compares
 * incoming profile aggregates against this snapshot to detect material changes.
 *
 * Shape stored:
 *   {
 *     "income_cents":  <int>,           // bank_deposit_total at generation
 *     "savings_cents": <int>,           // sum of retirement/HSA contributions
 *     "finding_keys":  [<string>, ...], // finding_type values in the report
 *     "generated_at":  "<ISO 8601>"     // timestamp of last generation
 *   }
 *
 * Nullable: null means the report has never been built (legacy rows or
 * freshly initialized rows). The staleness policy treats null as "absent"
 * and flags any data-churn event as material in this case.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: idempotent — skip if column already exists (handles the case where
        // the migration was previously run under a different filename).
        if (Schema::hasColumn('optimization_reports', 'built_against')) {
            return;
        }

        Schema::table('optimization_reports', function (Blueprint $table) {
            $table->jsonb('built_against')->nullable()->after('rebuilt_at');
        });
    }

    public function down(): void
    {
        // Per CLAUDE.md: down is provided for local dev rollback only.
        Schema::table('optimization_reports', function (Blueprint $table) {
            $table->dropColumn('built_against');
        });
    }
};
