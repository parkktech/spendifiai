<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration: create detection_merchants knowledge table.
 *
 * Pattern: follows CancellationProvider / CancellationProviderSeeder precedent
 * (aliases as JSON array for merchant name matching).
 *
 * PURPOSE: reference table powering the FLAG-10 category library detector.
 * Each row maps a known merchant to a category, subdetector, and defensibility
 * rating. Reference data; no user_id; not scope-guarded.
 *
 * ADDITIVE ONLY — never run migrate:fresh/rollback on production (CLAUDE.md §1-7).
 * Seed via: php artisan db:seed --class=DetectionMerchantSeeder
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detection_merchants', function (Blueprint $table) {
            $table->id();

            // ── Company identification ─────────────────────────────────────
            $table->string('company_name')->unique();

            // Aliases stored as JSONB array (same pattern as cancellation_providers)
            // Each alias is an UPPERCASE string matching bank-feed merchant_normalized
            $table->jsonb('aliases')->default('[]');

            // ── Category and routing ───────────────────────────────────────
            // Category maps to a CategoryLibraryDetector module:
            //   vehicle | solar_energy | pool_spa | landscaping | home_improvement
            //   animals_security | medical | travel | rv_boat | gambling
            $table->string('category');

            // Subdetector key identifies the specific hypothesis within the category
            // e.g. 'vehicle_parts', 'solar_loan_servicer', 'pool_builder', 'gambling_signal'
            $table->string('subdetector_key');

            // ── Defensibility and gray-area ───────────────────────────────
            // Static config-sourced defensibility rating for gray-area modules.
            // Values: 'auto' | 'conditional' | 'specialist' | 'suppress'
            // suppress → gambling, never-surface-as-deductible rules
            $table->string('defensibility_rating')->default('conditional');

            // Gray-area modules: true → emit question + doc checklist + pro-routing ONLY
            // Never assert deductibility for gray_area=true rows
            $table->boolean('gray_area')->default(false);

            // ── Notes and rule linkage ────────────────────────────────────
            $table->text('notes')->nullable();

            // Nullable rule_id links to config/tax-detection.php rules registry.
            // When set, registerFinding() calls validateRule(rule_id) before emission.
            // gambling_losses_fully_deductible → suppress band → findings never emitted.
            $table->string('rule_id')->nullable()->index();

            $table->timestamps();

            // Index for category-based queries (CategoryLibraryDetector)
            $table->index('category');
            $table->index('subdetector_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detection_merchants');
    }
};
