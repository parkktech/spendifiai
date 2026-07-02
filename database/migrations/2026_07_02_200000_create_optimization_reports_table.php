<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration: creates the optimization_reports table.
 *
 * SAFETY: forward-only (CREATE TABLE only) — no DROP, TRUNCATE, or ALTER on
 * existing tables. See CLAUDE.md safety rules.
 *
 * Purpose: RPT-01/02 — stores the assembled, ranked, sectioned optimization
 * report per user per tax year. Marked stale when inputs change; staleness
 * listeners and report API are wired in 12-04.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimization_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('tax_year');

            // Assembled report sections — an array of section objects.
            // Each section carries: title, section_key, findings (array of
            // finding ids + prose), disclaimer (RPT-03), narrator_prose.
            // Dollar figures are NEVER stored directly in this column — only
            // finding IDs that reference the OptimizationFinding records.
            $table->jsonb('sections');

            // Claude-narrated executive-summary overview (prose only, no $)
            $table->text('executive_summary')->nullable();

            // Staleness tracking — set true on: new doc extraction, bank sync,
            // interview answer, profile change (wired in 12-04).
            $table->boolean('is_stale')->default(true);
            $table->timestamp('stale_since')->nullable();
            $table->timestamp('rebuilt_at')->nullable();

            $table->timestamps();

            // Enforces at most one report per user per tax year (upsert key)
            $table->unique(['user_id', 'tax_year']);

            // Allows efficiently querying stale reports for a user
            $table->index(['user_id', 'is_stale']);
        });
    }

    public function down(): void
    {
        // Per CLAUDE.md: migrations should be additive. Down is provided for
        // local dev rollback only — never run in production.
        Schema::dropIfExists('optimization_reports');
    }
};
