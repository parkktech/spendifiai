<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create optimization_checklist_items table (§D.5 — additive, forward-only).
 *
 * Persists the D9 action checklist generated from a user's chosen scenario option.
 *
 * Security:
 *  - user_id cascadeOnDelete (GDPR compliance — T-14-08-02)
 *  - fact_set_id FK for citation linkage (M9)
 *  - benefit_line_params JSON on user-scoped authed rows (OptimizationFinding.estimated_value_cents precedent)
 *  - source_type='scenario_choice' for scoped-delete on re-choose (§D.6)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimization_checklist_items', function (Blueprint $table) {
            $table->id();

            // Ownership (GDPR cascade — T-14-08-02)
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->integer('tax_year');

            // Source tracking (scoped-delete key — §D.6)
            $table->string('source_type', 50)->default('scenario_choice');
            $table->unsignedBigInteger('source_id')->nullable(); // chosen UserTaxFact id

            // Citation linkage (M9 — §A.8.2)
            $table->foreignId('fact_set_id')
                ->nullable()
                ->constrained('scenario_fact_sets')
                ->nullOnDelete();

            // Knob group + step key
            $table->string('knob', 20);          // 'k1'|'k2'|'k3'|'k4'|'k5'|'k6'|'header'
            $table->string('step_key', 50);       // e.g. 'k3_directive', 'k3_confirm_ask'

            // Fact-gated kind (§D.5 / D9.2)
            $table->string('kind', 20);           // 'directive'|'confirm_ask'

            // Engine-computed benefit parameters (integer cents — user-scoped authed row)
            $table->json('benefit_line_params')->nullable();

            // Ordering within the checklist (groups ordered by annual benefit DESC — Δ3)
            $table->integer('position')->default(0);

            // Completion tracking (§D.5 done-toggle)
            $table->timestamp('done_at')->nullable();

            $table->timestamps();

            // Query index: per user+year checklist lookup
            $table->index(['user_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optimization_checklist_items');
    }
};
