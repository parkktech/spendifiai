<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_vault_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_document_id')->constrained('tax_documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('entry_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(['tax_document_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // PostgreSQL rules to prevent mutation of audit log entries
        DB::statement('CREATE RULE no_update_audit AS ON UPDATE TO tax_vault_audit_logs DO INSTEAD NOTHING;');
        DB::statement('CREATE RULE no_delete_audit AS ON DELETE TO tax_vault_audit_logs DO INSTEAD NOTHING;');
    }

    public function down(): void
    {
        DB::statement('DROP RULE IF EXISTS no_update_audit ON tax_vault_audit_logs;');
        DB::statement('DROP RULE IF EXISTS no_delete_audit ON tax_vault_audit_logs;');
        Schema::dropIfExists('tax_vault_audit_logs');
    }
};
