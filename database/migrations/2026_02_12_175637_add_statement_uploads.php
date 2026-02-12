<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Make Plaid-specific columns nullable so manual uploads work
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->text('plaid_access_token')->nullable()->change();
            $table->string('plaid_item_id')->nullable()->change();
            $table->string('institution_id')->nullable()->change();
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('plaid_account_id')->nullable()->change();
        });

        // Drop unique constraint on plaid_account_id (manual accounts won't have one)
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropUnique(['plaid_account_id']);
        });

        // Drop unique constraint on plaid_item_id (manual connections won't have one)
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->dropUnique(['plaid_item_id']);
        });

        // Make plaid_transaction_id nullable on transactions (uploaded transactions won't have one)
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('plaid_transaction_id')->nullable()->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['plaid_transaction_id']);
        });

        // Create statement_uploads table
        Schema::create('statement_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('bank_account_id')->nullable()->constrained()->onDelete('set null');
            $table->string('file_name');
            $table->string('original_file_name');
            $table->string('file_path');
            $table->string('file_type', 10); // pdf, csv, ofx
            $table->string('bank_name');
            $table->string('account_type');
            $table->string('status')->default('uploading'); // uploading, parsing, extracting, analyzing, complete, error
            $table->unsignedInteger('total_extracted')->default(0);
            $table->unsignedInteger('duplicates_found')->default(0);
            $table->unsignedInteger('transactions_imported')->default(0);
            $table->date('date_range_from')->nullable();
            $table->date('date_range_to')->nullable();
            $table->json('processing_notes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_uploads');

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('plaid_transaction_id')->nullable(false)->unique()->change();
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('plaid_account_id')->nullable(false)->unique()->change();
        });

        Schema::table('bank_connections', function (Blueprint $table) {
            $table->string('plaid_item_id')->nullable(false)->unique()->change();
            $table->text('plaid_access_token')->nullable(false)->change();
            $table->string('institution_id')->nullable(false)->change();
        });
    }
};
