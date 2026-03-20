<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_deductions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description');
            $table->string('category', 30); // above_the_line, itemized, schedule_c, credit, new_2026, lesser_known
            $table->string('subcategory')->nullable();
            $table->decimal('max_amount', 12, 2)->nullable();
            $table->decimal('max_amount_mfj', 12, 2)->nullable();
            $table->boolean('is_credit')->default(false);
            $table->boolean('is_refundable')->default(false);
            $table->string('irs_form')->nullable();
            $table->string('irs_line')->nullable();
            $table->jsonb('eligibility_rules')->nullable();
            $table->string('detection_method', 30)->default('manual'); // transaction_scan, profile_question, both, manual
            $table->jsonb('transaction_keywords')->nullable();
            $table->text('question_text')->nullable();
            $table->jsonb('question_options')->nullable();
            $table->text('help_text')->nullable();
            $table->string('irs_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('category');
            $table->index('detection_method');
        });

        Schema::create('user_tax_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_deduction_id')->constrained('tax_deductions')->cascadeOnDelete();
            $table->smallInteger('tax_year');
            $table->string('status', 30)->default('needs_review'); // eligible, claimed, not_eligible, skipped, needs_review
            $table->decimal('estimated_amount', 12, 2)->nullable();
            $table->decimal('actual_amount', 12, 2)->nullable();
            $table->jsonb('answer')->nullable();
            $table->string('detected_from', 30)->nullable(); // ai_scan, questionnaire, manual
            $table->decimal('detection_confidence', 3, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tax_deduction_id', 'tax_year']);
            $table->index(['user_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tax_deductions');
        Schema::dropIfExists('tax_deductions');
    }
};
