<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_document_transaction', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('link_reason', 100)->nullable();
            $table->timestamps();

            $table->unique(['tax_document_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_document_transaction');
    }
};
