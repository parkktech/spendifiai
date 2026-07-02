<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make ai_questions.transaction_id nullable.
 *
 * Phase 11-04 / FEED-01: QuestionType::Optimization questions have no associated
 * transaction (they represent tax-optimization opportunities, not categorization
 * questions). The original schema required transaction_id as NOT NULL because all
 * question types were transaction-based. Making it nullable is strictly additive —
 * no existing rows are affected, no foreign key is dropped.
 *
 * The cascade-delete relationship to transactions is preserved: when a transaction
 * is deleted, any question referencing it is also deleted. Null transaction_id
 * optimization questions are not affected by transaction deletions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_questions', function (Blueprint $table) {
            // Drop the old NOT NULL foreign key and recreate as nullable.
            // In PostgreSQL, we must drop the constraint before changing nullability.
            $table->foreignId('transaction_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        // NOTE: Only safe to reverse if all ai_questions have a transaction_id.
        // Do not run in production without verifying zero null transaction_id rows.
        Schema::table('ai_questions', function (Blueprint $table) {
            $table->foreignId('transaction_id')
                ->nullable(false)
                ->change();
        });
    }
};
