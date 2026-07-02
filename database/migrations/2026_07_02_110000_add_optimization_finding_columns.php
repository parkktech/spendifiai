<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FLAG-13: Additive OptimizationFinding extension.
 *
 * CLAUDE.md rule: ADD COLUMN only — no drops, no type changes, no renaming.
 * This migration is safe to run on a live database with existing rows.
 *
 * Column notes:
 *  - user_assertions: encrypted TEXT ($hidden on model) — never query directly
 *  - estimated_value_cents: plain bigInteger written ONLY by TaxRulesEngineService (SAFE-03)
 *  - legal_basis: STATIC config citation text, NEVER Claude output (T-11-03-04)
 *  - assumptions: STATIC config citations array, NEVER Claude output (T-11-03-04)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimization_findings', function (Blueprint $table) {
            // Core finding contract (FLAG-13, D6)
            $table->jsonb('transaction_ids')->nullable()->after('status');
            $table->text('treatment')->nullable()->after('transaction_ids');
            $table->text('legal_basis')->nullable()->after('treatment');        // static config citation, NEVER Claude
            $table->jsonb('assumptions')->nullable()->after('legal_basis');     // static config citations
            $table->string('band', 20)->nullable()->after('assumptions');       // mirrors rule band
            $table->text('user_assertions')->nullable()->after('band');         // encrypted TEXT, $hidden
            $table->jsonb('docs_captured')->nullable()->after('user_assertions');
            $table->jsonb('docs_missing')->nullable()->after('docs_captured');
            $table->bigInteger('estimated_value_cents')->nullable()->after('docs_missing'); // ONLY TaxRulesEngineService writes this
            $table->boolean('pro_export_ready')->default(false)->after('estimated_value_cents');

            // Year-end forward-compat fields (D6 — cheap now, expensive to add later)
            $table->date('deadline')->nullable()->after('pro_export_ready');
            $table->integer('lead_time_days')->nullable()->after('deadline');
            $table->bigInteger('net_cash_cost')->nullable()->after('lead_time_days');
            $table->bigInteger('tax_saved')->nullable()->after('net_cash_cost');
            $table->bigInteger('cliff_bonus_value')->nullable()->after('tax_saved');
            $table->boolean('reversible')->nullable()->after('cliff_bonus_value');
        });
    }

    public function down(): void
    {
        // Per CLAUDE.md: only additive migrations supported in production.
        // Dropping columns here is intentionally left as a no-op.
        // Manual rollback requires explicit owner sign-off.
    }
};
