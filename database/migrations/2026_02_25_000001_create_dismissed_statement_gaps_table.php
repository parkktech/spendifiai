<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dismissed_statement_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gap_key', 50); // format: "{bank_account_id}:{YYYY-MM}"
            $table->timestamps();

            $table->unique(['user_id', 'gap_key']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dismissed_statement_gaps');
    }
};
