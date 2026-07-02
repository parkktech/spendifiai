<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D19 — Structured narration output contract.
 *
 * Adds `narration_structured` JSONB column to `optimization_findings`.
 * Schema: {hook: string, detail: string, action_cue: string}
 *
 * SAFETY: Additive-only (ADD COLUMN). Existing `description` rows are
 * preserved and rendered under the display clamp until naturally regenerated.
 * No mass re-narration (D17). CLAUDE.md rule: never DROP columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimization_findings', function (Blueprint $table) {
            // D19 structured output: {hook ≤120 chars, detail ≤2 sentences, action_cue ≤1 sentence}
            // Null means not yet narrated in structured format (falls back to description + clamp).
            $table->jsonb('narration_structured')->nullable()->after('description');
        });

        Schema::table('optimization_reports', function (Blueprint $table) {
            // D19: sections now store narrator_prose as {summary, bullets[]} instead of a prose string.
            // narrator_prose_structured: {summary: string, bullets: string[]}
            // Null means not yet upgraded; renderer falls back to narrator_prose + clamp.
            // The existing sections JSONB column already contains narrator_prose per-section —
            // we add a top-level column for the executive_summary structured contract.
            $table->jsonb('executive_summary_structured')->nullable()->after('executive_summary');
        });
    }

    public function down(): void
    {
        // Per CLAUDE.md: additive-only. Down is a no-op in production.
        // For local dev rollback only.
        Schema::table('optimization_findings', function (Blueprint $table) {
            $table->dropColumnIfExists('narration_structured');
        });
        Schema::table('optimization_reports', function (Blueprint $table) {
            $table->dropColumnIfExists('executive_summary_structured');
        });
    }
};
