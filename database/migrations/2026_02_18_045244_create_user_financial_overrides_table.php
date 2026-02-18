<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_financial_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('override_type', 30);
            $table->string('override_key', 255);
            $table->string('classification', 10);
            $table->timestamps();

            $table->unique(['user_id', 'override_type', 'override_key'], 'user_overrides_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_financial_overrides');
    }
};
