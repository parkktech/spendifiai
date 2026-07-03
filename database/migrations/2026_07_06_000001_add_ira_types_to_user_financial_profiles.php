<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration — IRA multi-select support (Fix 1).
 *
 * Adds ira_types (JSON array) alongside the legacy ira_type (string) column.
 * Backfills existing single-value ira_type rows so both fields stay consistent.
 * ira_type is kept for backward compatibility — it is always the first selected type.
 *
 * NEVER drops ira_type — existing callers (IncomeOptimizerDataAssemblerService,
 * ProfileConformanceDetector) are updated additively to prefer ira_types when present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_financial_profiles', function (Blueprint $table) {
            // JSON array of selected IRA types: ['traditional', 'roth', 'sep', 'simple']
            // nullable — null means the user has not set this field yet (legacy rows with only
            // ira_type set are backfilled below so null = truly unset).
            $table->json('ira_types')->nullable()->after('ira_type');
        });

        // Backfill: rows with an existing ira_type get ira_types = [ira_type]
        // Rows with ira_type IS NULL keep ira_types = NULL.
        DB::statement('
            UPDATE user_financial_profiles
            SET ira_types = json_build_array(ira_type)
            WHERE ira_type IS NOT NULL
              AND ira_types IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('user_financial_profiles', function (Blueprint $table) {
            $table->dropColumn('ira_types');
        });
    }
};
