<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tax_profile_entities table (STORE-01/STORE-02).
 *
 * Entities represent non-Person tax-relevant objects: vehicles, properties, business entities.
 * People (dependents, household members) already have Dependent/Household models — NOT here.
 *
 * STORE-02 basis ledger: for entity_type=property, the encrypted attributes JSON accumulates
 * capital-improvement entries, each referencing its Vault receipt by tax_document_id.
 * Rebates reduce basis; maintenance entries are excluded; recapture years are tracked.
 * Enforcement is in TaxProfileEntity::addBasisEntry().
 *
 * This migration also adds the deferred FK from user_tax_facts.entity_id, which could not
 * be added in migration 100000 because this table did not yet exist.
 *
 * Additive-only migration (CLAUDE.md safety rules): no DROP/TRUNCATE of any existing object.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_profile_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Type discriminator: vehicle | property | business_entity
            $table->string('entity_type', 20);

            // Non-PII user-facing display label (e.g. '2020 Toyota Camry', '123 Main St').
            // NEVER store PII (SSN, account numbers) in this column.
            $table->string('label', 200);

            // Encrypted JSON attributes bag (TEXT for encrypted storage).
            // For entity_type=property: includes basis_entries[] array (STORE-02).
            // For entity_type=vehicle: includes method election, business_use_pct, etc.
            // Money values inside attributes = integer-cents-as-string.
            $table->text('attributes')->nullable();

            // Soft supersession (same pattern as user_tax_facts for consistency).
            $table->boolean('is_current')->default(true);
            $table->unsignedBigInteger('superseded_by_id')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'entity_type'], 'idx_tpe_user_type');
            $table->index(['user_id', 'is_current'], 'idx_tpe_user_current');
        });

        // Self-referencing FK for entity supersession chain.
        DB::statement(
            'ALTER TABLE tax_profile_entities ADD CONSTRAINT fk_tpe_superseded_by '.
            'FOREIGN KEY (superseded_by_id) REFERENCES tax_profile_entities(id) ON DELETE SET NULL'
        );

        // Deferred FK: user_tax_facts.entity_id → tax_profile_entities.id
        // Could not be added in migration 100000 because tax_profile_entities didn't exist yet.
        DB::statement(
            'ALTER TABLE user_tax_facts ADD CONSTRAINT fk_utf_entity '.
            'FOREIGN KEY (entity_id) REFERENCES tax_profile_entities(id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        // Remove the deferred FK from user_tax_facts first.
        DB::statement('ALTER TABLE user_tax_facts DROP CONSTRAINT IF EXISTS fk_utf_entity');
        // Remove self-referencing FK.
        DB::statement('ALTER TABLE tax_profile_entities DROP CONSTRAINT IF EXISTS fk_tpe_superseded_by');
        Schema::dropIfExists('tax_profile_entities');
    }
};
