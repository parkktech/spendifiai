<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create user_document_type_exclusions table.
 *
 * Stores user-declared "Not applicable" preferences for specific document
 * types in the DocumentUploadFlow grid. Excluded types count as populated
 * for the accordion tri-state and are never requested by Stage-0/doc-cascade.
 *
 * One row per (user, document_type) pair; unique index prevents duplicate exclusions.
 * Additive migration — no existing tables altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_document_type_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 64);
            $table->timestamp('excluded_at')->useCurrent();
            $table->timestamps();

            // One exclusion per (user, type) — re-excluding the same type is idempotent.
            $table->unique(['user_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_document_type_exclusions');
    }
};
