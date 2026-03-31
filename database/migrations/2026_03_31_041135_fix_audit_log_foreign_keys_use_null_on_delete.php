<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit logs are immutable (protected by PostgreSQL RULES).
     * However, cascadeOnDelete FKs conflict with these rules when
     * a user or document is deleted. Fix: remove cascade, make
     * columns nullable, and let the application handle orphaned
     * references. Audit logs are preserved with null FKs after
     * account deletion.
     *
     * Also replace PostgreSQL RULES with a BEFORE DELETE trigger.
     * Rules intercept ALL operations including FK cascades, which
     * breaks referential integrity. Triggers allow us to block
     * direct deletes while still allowing FK SET NULL updates.
     */
    public function up(): void
    {
        // Drop the blunt rules that block FK cascade operations
        DB::statement('DROP RULE IF EXISTS no_update_audit ON tax_vault_audit_logs;');
        DB::statement('DROP RULE IF EXISTS no_delete_audit ON tax_vault_audit_logs;');

        Schema::table('tax_vault_audit_logs', function (Blueprint $table) {
            // Drop existing FK constraints
            $table->dropForeign(['user_id']);
            $table->dropForeign(['tax_document_id']);

            // Make columns nullable (for when referenced rows are deleted)
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('tax_document_id')->nullable()->change();

            // Re-add with SET NULL on delete
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('tax_document_id')->references('id')->on('tax_documents')->nullOnDelete();
        });

        // Create trigger function that blocks direct deletes/updates
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_audit_log_mutation()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'Audit log entries are immutable and cannot be modified or deleted';
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        // BEFORE triggers on DELETE -- but only for direct operations.
        // FK SET NULL triggers fire AFTER, so this BEFORE trigger
        // blocks direct DELETE while FK operations use UPDATE (SET NULL).
        DB::statement('
            CREATE TRIGGER prevent_audit_delete
            BEFORE DELETE ON tax_vault_audit_logs
            FOR EACH ROW
            EXECUTE FUNCTION prevent_audit_log_mutation();
        ');

        // Block direct UPDATE of immutable fields (but allow FK SET NULL on user_id/tax_document_id)
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_audit_log_content_update()
            RETURNS TRIGGER AS \$\$
            BEGIN
                -- Allow FK SET NULL updates (only user_id or tax_document_id changed to NULL)
                IF (NEW.user_id IS DISTINCT FROM OLD.user_id AND NEW.user_id IS NULL)
                   OR (NEW.tax_document_id IS DISTINCT FROM OLD.tax_document_id AND NEW.tax_document_id IS NULL) THEN
                    -- Check no other fields changed
                    IF NEW.action = OLD.action
                       AND NEW.ip_address IS NOT DISTINCT FROM OLD.ip_address
                       AND NEW.user_agent IS NOT DISTINCT FROM OLD.user_agent
                       AND NEW.metadata IS NOT DISTINCT FROM OLD.metadata
                       AND NEW.previous_hash IS NOT DISTINCT FROM OLD.previous_hash
                       AND NEW.entry_hash = OLD.entry_hash
                       AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                        RETURN NEW;
                    END IF;
                END IF;
                RAISE EXCEPTION 'Audit log entries are immutable and cannot be modified';
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::statement('
            CREATE TRIGGER prevent_audit_content_update
            BEFORE UPDATE ON tax_vault_audit_logs
            FOR EACH ROW
            EXECUTE FUNCTION prevent_audit_log_content_update();
        ');
    }

    public function down(): void
    {
        // Drop triggers
        DB::statement('DROP TRIGGER IF EXISTS prevent_audit_delete ON tax_vault_audit_logs;');
        DB::statement('DROP TRIGGER IF EXISTS prevent_audit_content_update ON tax_vault_audit_logs;');
        DB::statement('DROP FUNCTION IF EXISTS prevent_audit_log_mutation();');
        DB::statement('DROP FUNCTION IF EXISTS prevent_audit_log_content_update();');

        Schema::table('tax_vault_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['tax_document_id']);

            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreignId('tax_document_id')->nullable(false)->change();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tax_document_id')->references('id')->on('tax_documents')->cascadeOnDelete();
        });

        // Restore original rules
        DB::statement('CREATE RULE no_update_audit AS ON UPDATE TO tax_vault_audit_logs DO INSTEAD NOTHING;');
        DB::statement('CREATE RULE no_delete_audit AS ON DELETE TO tax_vault_audit_logs DO INSTEAD NOTHING;');
    }
};
