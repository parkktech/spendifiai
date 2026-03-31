<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_financial_profiles', function (Blueprint $table) {
            // Student info
            $table->boolean('is_student')->default(false);
            $table->string('school_name', 200)->nullable();
            $table->string('enrollment_status', 20)->nullable();

            // Spouse info
            $table->string('spouse_name', 100)->nullable();
            $table->string('spouse_employment_type', 30)->nullable();
            $table->text('spouse_income')->nullable();
            $table->foreignId('spouse_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Tax-advantaged accounts
            $table->boolean('has_hsa')->default(false);
            $table->boolean('has_fsa')->default(false);
            $table->boolean('has_529_plan')->default(false);
            $table->boolean('has_ira')->default(false);
            $table->string('ira_type', 20)->nullable();

            // Additional deductions
            $table->boolean('has_student_loans')->default(false);
            $table->boolean('has_childcare_expenses')->default(false);
            $table->text('childcare_annual_cost')->nullable();
            $table->boolean('is_military')->default(false);
            $table->boolean('has_rental_property')->default(false);
            $table->boolean('education_credits_eligible')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_financial_profiles', function (Blueprint $table) {
            $table->dropForeign(['spouse_user_id']);
            $table->dropColumn([
                'is_student', 'school_name', 'enrollment_status',
                'spouse_name', 'spouse_employment_type', 'spouse_income', 'spouse_user_id',
                'has_hsa', 'has_fsa', 'has_529_plan', 'has_ira', 'ira_type',
                'has_student_loans', 'has_childcare_expenses', 'childcare_annual_cost',
                'is_military', 'has_rental_property', 'education_credits_eligible',
            ]);
        });
    }
};
