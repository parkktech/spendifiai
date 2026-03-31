<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->nullable()->constrained('households')->cascadeOnDelete();
            $table->string('name', 100);
            $table->date('date_of_birth');
            $table->string('relationship', 30);
            $table->text('ssn_last_four')->nullable();
            $table->boolean('is_student')->default(false);
            $table->boolean('is_disabled')->default(false);
            $table->boolean('lives_with_you')->default(true);
            $table->smallInteger('months_lived_with_you')->default(12);
            $table->text('gross_income')->nullable();
            $table->boolean('is_claimed')->default(true);
            $table->smallInteger('tax_year');
            $table->timestamps();

            $table->index(['user_id', 'tax_year']);
            $table->index(['household_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dependents');
    }
};
