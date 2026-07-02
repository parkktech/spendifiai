<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FACT-CHECK FIX (INT-03): add business_type + housing_status to income_optimization_profiles.
 *
 * IncomeOptimizerDataAssemblerService::readProfileFlags() currently omits business_type
 * and housing_status from its return array. These fields exist on UserFinancialProfile
 * and are required for entity/housing gates in Phase 11b detectors (D3/CONTEXT.md).
 *
 * This is a purely additive migration — two new nullable string columns appended after
 * the existing 'employment_type' column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_optimization_profiles', function (Blueprint $table) {
            // Non-sensitive flag: matches UserFinancialProfile.business_type
            // e.g. 'sole_proprietor', 'partnership', 'llc', 'corporation', null
            $table->string('business_type', 50)->nullable()->after('employment_type');

            // Non-sensitive flag: matches UserFinancialProfile.housing_status
            // e.g. 'own', 'rent', 'own_with_mortgage', null
            $table->string('housing_status', 30)->nullable()->after('business_type');
        });
    }

    public function down(): void
    {
        Schema::table('income_optimization_profiles', function (Blueprint $table) {
            $table->dropColumn(['business_type', 'housing_status']);
        });
    }
};
