<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create interview_sessions table for the persisted state machine (INT-01 / D3).
 *
 * SCHEMA DESIGN (from 11-CONTEXT D3):
 *   - user_id:     FK → users (cascadeOnDelete for GDPR compliance)
 *   - tax_year:    the tax year being interviewed
 *   - status:      created | in_progress | paused | completed
 *   - queue:       JSONB array of fact_keys yet to be asked
 *   - asked:       JSONB array of fact_keys already asked in this session
 *   - assertions:  TEXT (encrypted) — ordered Q/A transcript for provenance
 *   - initial_cap: integer — maximum questions to ask in the initial pass (INT-03)
 *
 * PARTIAL UNIQUE INDEX: one in_progress session per (user_id, tax_year).
 *   Prevents a second active session via partial index on status='in_progress'.
 *   (paused sessions are allowed to coexist — the uniqueness is on in_progress only)
 *
 * ENCRYPTED assertions: TEXT column for Laravel 'encrypted' cast (AES-256-GCM).
 *   Never VARCHAR for encrypted columns — the ciphertext is significantly longer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->string('status', 20)->default('created');
            // queue/asked: plain JSONB arrays of fact_key strings (not encrypted —
            // fact_keys are non-PII namespace identifiers like 'ira.balance_range')
            $table->jsonb('queue')->default('[]');
            $table->jsonb('asked')->default('[]');
            // assertions: encrypted TEXT — contains the ordered Q/A transcript.
            // TEXT is required for encrypted columns (ciphertext is longer than original).
            $table->text('assertions')->nullable();
            // initial_cap: max questions per initial pass (INT-03 / D5)
            $table->unsignedSmallInteger('initial_cap')->default(10);
            $table->timestamps();

            $table->index(['user_id', 'tax_year', 'status']);
        });

        // Partial unique index: only ONE in_progress session per (user_id, tax_year).
        // Using DB::statement for Postgres partial unique index syntax —
        // Blueprint::unique() doesn't support WHERE clauses.
        DB::statement(
            "CREATE UNIQUE INDEX idx_interview_sessions_one_in_progress
             ON interview_sessions (user_id, tax_year)
             WHERE status = 'in_progress'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_interview_sessions_one_in_progress');
        Schema::dropIfExists('interview_sessions');
    }
};
