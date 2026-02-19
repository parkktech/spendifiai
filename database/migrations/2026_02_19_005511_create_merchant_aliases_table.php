<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('normalized_name');
            $table->string('email_domain')->nullable();
            $table->string('source')->default('hardcoded'); // hardcoded, reconciliation, user
            $table->integer('match_count')->default(1);
            $table->timestamps();

            $table->unique(['bank_name', 'normalized_name']);
            $table->index('bank_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_aliases');
    }
};
