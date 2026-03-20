<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plaid_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_connection_id')->constrained('bank_connections')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('plaid_statement_id')->unique();
            $table->string('plaid_account_id')->nullable();
            $table->smallInteger('month');
            $table->smallInteger('year');
            $table->date('date_posted')->nullable();
            $table->string('file_path')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('total_extracted')->default(0);
            $table->unsignedInteger('duplicates_found')->default(0);
            $table->unsignedInteger('transactions_imported')->default(0);
            $table->text('error_message')->nullable();
            $table->jsonb('processing_notes')->nullable();
            $table->date('date_range_from')->nullable();
            $table->date('date_range_to')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'bank_connection_id']);
            $table->index(['bank_connection_id', 'year', 'month']);
        });

        // Add statements columns to bank_connections
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->boolean('statements_supported')->nullable();
            $table->timestamp('statements_last_refreshed_at')->nullable();
            $table->string('statements_refresh_status', 30)->nullable();
        });

        // Add onboarding column to users
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaid_statements');

        Schema::table('bank_connections', function (Blueprint $table) {
            $table->dropColumn(['statements_supported', 'statements_last_refreshed_at', 'statements_refresh_status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
