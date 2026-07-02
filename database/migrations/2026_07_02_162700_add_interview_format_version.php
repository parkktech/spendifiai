<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D20 — Interview session format versioning.
 *
 * Adds `format_version` integer column to `interview_sessions`.
 * When the orchestrator's FORMAT_VERSION constant is bumped, sessions with an
 * older format_version are rebuilt (stale-queue self-heal) on next startOrResume().
 *
 * SAFETY: Additive-only (ADD COLUMN). CLAUDE.md rule: never DROP columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table) {
            // D20: format version stamp; null = pre-D20 (treated as v0)
            if (! Schema::hasColumn('interview_sessions', 'format_version')) {
                $table->unsignedTinyInteger('format_version')->nullable()->after('initial_cap');
            }
        });
    }

    public function down(): void
    {
        // Per CLAUDE.md: additive-only. No-op in production.
        Schema::table('interview_sessions', function (Blueprint $table) {
            $table->dropColumnIfExists('format_version');
        });
    }
};
