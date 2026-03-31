<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_firm_id')->constrained('accounting_firms');
            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('accountant_id')->constrained('users');
            $table->text('description');
            $table->integer('tax_year')->nullable();
            $table->string('category')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('fulfilled_document_id')->nullable()->constrained('tax_documents');
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index('accounting_firm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
    }
};
