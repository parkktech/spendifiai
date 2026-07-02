<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the scenario_fact_sets versioned fact-citation store (SCENARIOS-SPEC §A.8.2).
 *
 * A frozen, HMAC-hashed, encrypted snapshot of the facts a scenario was computed
 * from. Scenario/checklist surfaces cite the row id (M9) so they can prove
 * "based on N facts you provided" and detect supersession (isStale → recompute).
 *
 * fact_set_hash: HMAC-SHA256 (64 hex chars) keyed on config('app.key') — money
 * values have a small search space, so a bare hash of the unencrypted column
 * would be brute-forceable.
 *
 * resolved_facts: encrypted TEXT (encrypted cast in the ScenarioFactSet model),
 * holding fact_key => ResolvedFact JSON. NEVER index or query this column.
 *
 * GDPR: cascadeOnDelete on user_id wipes rows when the account is deleted.
 *
 * Additive-only migration (CLAUDE.md safety rules): creates a NEW table only.
 * No DROP/ALTER/TRUNCATE of any existing object.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_fact_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // GDPR cascade
            $table->integer('tax_year');

            // HMAC-SHA256 of canonical_json(fact_key => [source_ref, value]).
            $table->string('fact_set_hash', 64);

            // Encrypted JSON: fact_key => ResolvedFact. NEVER index/query directly.
            $table->text('resolved_facts');

            $table->timestamps();

            $table->index(['user_id', 'tax_year'], 'idx_sfs_user_year');
        });
    }

    public function down(): void
    {
        // Drops only the NEW table this migration created (no existing object touched).
        Schema::dropIfExists('scenario_fact_sets');
    }
};
