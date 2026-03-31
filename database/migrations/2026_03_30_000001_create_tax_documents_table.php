<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename', 255);
            $table->string('stored_path', 500);
            $table->string('disk', 20)->default('local');
            $table->string('mime_type', 50);
            $table->unsignedBigInteger('file_size');
            $table->string('file_hash', 64);
            $table->unsignedSmallInteger('tax_year')->index();
            $table->string('category', 30)->nullable();
            $table->string('status', 20)->default('upload');
            $table->decimal('classification_confidence', 3, 2)->nullable();
            $table->text('extracted_data')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'tax_year']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_documents');
    }
};
