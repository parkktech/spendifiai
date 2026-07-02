<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_optimization_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('tax_year');

            // ── Income signals — ALL encrypted TEXT (never plain integer for financial amounts) ──
            // Convention: store as integer CENTS in encrypted string, e.g. '7250000' == $72,500.00
            $table->text('w2_wages')->nullable();                    // encrypted cents
            $table->text('self_employment_income')->nullable();       // encrypted cents
            $table->text('interest_income')->nullable();              // encrypted cents
            $table->text('dividend_income')->nullable();              // encrypted cents
            $table->text('retirement_distributions')->nullable();     // encrypted cents
            $table->text('bank_deposit_total')->nullable();           // encrypted cents

            // ── Deduction signals — encrypted TEXT ──
            $table->text('mortgage_interest')->nullable();            // encrypted cents (from 1098)
            $table->text('property_tax_paid')->nullable();            // encrypted cents
            $table->text('student_loan_interest')->nullable();        // encrypted cents (from 1098-E)
            $table->text('charitable_contributions')->nullable();     // encrypted cents

            // ── Retirement contribution signals — encrypted TEXT ──
            $table->text('traditional_401k_ytd')->nullable();        // encrypted cents
            $table->text('roth_401k_ytd')->nullable();               // encrypted cents
            $table->text('ira_ytd')->nullable();                     // encrypted cents
            $table->text('hsa_ytd')->nullable();                     // encrypted cents

            // ── Non-sensitive computed flags (plain columns — not financial amounts) ──
            $table->string('filing_status', 30)->nullable();
            $table->boolean('has_home_office')->default(false);
            $table->boolean('has_self_employment')->default(false);
            $table->boolean('has_hsa_eligible_plan')->default(false);
            $table->boolean('has_ira')->default(false);
            $table->string('ira_type', 20)->nullable();              // 'traditional' / 'roth' / 'both'
            $table->string('employment_type', 30)->nullable();
            $table->unsignedTinyInteger('estimated_age')->nullable();

            // ── Metadata (non-sensitive) ──
            $table->jsonb('data_sources')->default('{}');            // {doc_ids: [], transaction_range: {}}
            $table->unsignedSmallInteger('doc_count')->default(0);
            $table->string('profile_hash', 64)->nullable();          // SHA-256 of inputs; staleness detection
            $table->timestamp('built_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tax_year']);
            $table->index(['user_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_optimization_profiles');
    }
};
