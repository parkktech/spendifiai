<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accountant_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accountant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('invited_by', 20)->default('client');
            $table->timestamps();

            $table->unique(['accountant_id', 'client_id']);
            $table->index('client_id');
            $table->index(['accountant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accountant_clients');
    }
};
