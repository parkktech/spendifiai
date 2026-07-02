<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the user_tax_facts append-only durable-facts store (STORE-01).
 *
 * Append-only: rows are NEVER updated-in-value or deleted (except GDPR cascadeOnDelete).
 * Supersession: a re-answer inserts a new row and flips is_current + superseded_by_id on
 * the old row, preserving full provenance and supersession chain.
 *
 * Partial unique index (idx_tax_facts_current) enforces exactly one is_current=true row
 * per (user_id, fact_key, COALESCE(entity_id,0), COALESCE(tax_year,0)).
 * Blueprint cannot express partial indexes — created via DB::statement.
 *
 * Confirm-before-write gate (D3/Decision 4): document_extraction facts enter as PROPOSALS
 * (is_current=false, confirmed_at=null) and never supersede user-entered facts until
 * the user confirms. This is enforced in UserTaxFact::recordFact() and ::confirmProposal().
 *
 * Additive-only migration (CLAUDE.md safety rules): no DROP/TRUNCATE of any existing object.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tax_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Non-PII namespaced key — indexed for skip-logic lookups.
            // e.g. 'ira.pretax_balance_range', 'employer.match_pct', 'method.mileage'
            // NEVER put PII in fact_key or label.
            $table->string('fact_key', 100);

            // Encrypted sensitive value on TEXT column (encrypted cast in model).
            // Money = integer-cents-as-string (e.g. '7250000' == $72,500.00).
            // NEVER index or query this column directly.
            $table->text('value')->nullable();

            // Plain non-PII human-readable label for display (not the value).
            $table->string('label', 200)->nullable();

            // Volatility tier governing carry-forward and reconfirmation.
            $table->string('volatility', 20)->default('stable'); // permanent | stable | annual

            // For stable-volatility facts: re-ask user to confirm after this date.
            $table->date('reconfirm_after')->nullable();

            // For annual-volatility facts: which tax year this fact applies to.
            $table->unsignedSmallInteger('tax_year')->nullable();

            // Optional link to a TaxProfileEntity (vehicle, property, business entity).
            // FK constraint to tax_profile_entities added after that table is created
            // (migration 100001) via ALTER TABLE to avoid ordering dependency.
            $table->unsignedBigInteger('entity_id')->nullable();

            // Provenance: how was this fact asserted?
            // interview_answer | document_extraction | detector | profile_field | user_edit
            $table->string('source_type', 30);

            // Originating record ID (e.g. ParsedEmail.id for document_extraction).
            $table->string('source_id', 100)->nullable();

            // When was this fact asserted by the user / detected.
            $table->timestamp('asserted_at');

            // When did the user confirm this fact (null = unconfirmed proposal).
            // document_extraction facts stay null until user explicitly confirms.
            $table->timestamp('confirmed_at')->nullable();

            // Append-only flag: true for the current version of this fact key.
            // The partial unique index enforces one is_current=true per key tuple.
            $table->boolean('is_current')->default(true);

            // Self-referencing supersession chain: when this row is superseded,
            // superseded_by_id points to the newer row.
            // Added via ALTER TABLE below (self-reference requires table to exist first).
            $table->unsignedBigInteger('superseded_by_id')->nullable();

            // Non-PII jsonb metadata for extraction confidence and other context.
            // For document_extraction: {'field_confidence': {'wages': 0.92, ...}}
            // NEVER store PII in this column.
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            // Performance indexes for skip-logic lookups (non-partial, named).
            $table->index(['user_id', 'fact_key'], 'idx_utf_user_key');
            $table->index(['user_id', 'is_current'], 'idx_utf_user_current');
        });

        // Self-referencing FK for supersession chain.
        // Must be added AFTER the table is created (circular reference).
        DB::statement(
            'ALTER TABLE user_tax_facts ADD CONSTRAINT fk_utf_superseded_by '.
            'FOREIGN KEY (superseded_by_id) REFERENCES user_tax_facts(id) ON DELETE SET NULL'
        );

        // Partial unique index: enforces at most one is_current=true row per
        // (user_id, fact_key, entity_id, tax_year) tuple.
        // COALESCE handles NULL entity_id/tax_year by treating them as 0.
        // Blueprint::uniqueIndex() cannot express WHERE clauses — raw statement required.
        DB::statement(
            'CREATE UNIQUE INDEX idx_tax_facts_current '.
            'ON user_tax_facts (user_id, fact_key, COALESCE(entity_id, 0), COALESCE(tax_year, 0)) '.
            'WHERE is_current = true'
        );
    }

    public function down(): void
    {
        // Drop the manually created partial index first.
        DB::statement('DROP INDEX IF EXISTS idx_tax_facts_current');
        // Drop self-referencing FK (otherwise DROP TABLE would need CASCADE).
        DB::statement('ALTER TABLE user_tax_facts DROP CONSTRAINT IF EXISTS fk_utf_superseded_by');
        Schema::dropIfExists('user_tax_facts');
    }
};
