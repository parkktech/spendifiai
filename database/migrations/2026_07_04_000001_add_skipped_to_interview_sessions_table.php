<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive (forward-only) — add a `skipped` JSONB array to interview_sessions.
 *
 * DEFECT 2 fix: skips must be durably persisted so the stale-queue self-heal
 * (InterviewOrchestratorService::startOrResume) can EXCLUDE skipped keys when it
 * rebuilds the queue. Without this, a skipped finding-backed item is re-inserted at
 * position 1 on every reload ("skip just refreshes" infinite loop).
 *
 * SAFETY: additive column only. No existing column altered/dropped. Nullable-with-
 * default so existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table) {
            // Plain JSONB array of fact_key strings (non-PII identifiers), mirroring
            // the existing queue/asked columns. Not encrypted.
            $table->jsonb('skipped')->default('[]')->after('asked');
        });
    }

    public function down(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table) {
            $table->dropColumn('skipped');
        });
    }
};
