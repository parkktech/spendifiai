<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimization_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('tax_year');

            // Stable identifier for idempotent upsert, e.g. 'w2_deposit_mismatch'
            $table->string('finding_key');

            // Classification: 'income_discrepancy', 'contribution_gap', etc.
            $table->string('finding_type');

            // Severity level: 'low', 'medium', 'high' — may be null for info-only findings
            $table->string('severity')->nullable();

            // Deterministic comparison payload (gap_cents, gap_pct, w2_cents, bank_cents).
            // All cent values are integers for precision; gap_pct is a float 0..1.
            // Description is intentionally NOT stored here — it is derived from this payload
            // by a Phase 11 Claude call. Phase 10 leaves description null.
            $table->jsonb('details')->default('{}');

            // Reserved for Phase 11 Claude narration — null in Phase 10.
            $table->text('description')->nullable();

            // Lifecycle status: 'open' (default), 'resolved', 'dismissed'
            $table->string('status')->default('open');

            $table->timestamps();

            // Idempotent upsert: one finding per user + year + key
            $table->unique(['user_id', 'tax_year', 'finding_key']);

            // Query access pattern: fetch all findings for a user-year
            $table->index(['user_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optimization_findings');
    }
};
