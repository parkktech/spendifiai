<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D20.3 — Allow self-initiated document requests from the interview escape hatch.
 *
 * The original design required accounting_firm_id and accountant_id (accountant-client
 * workflow). D20.3 adds a self-service path: the user selects "get this from my paystub"
 * in the interview → a DocumentRequest is filed with these FKs as null.
 *
 * FORWARD-ONLY: Relaxing NOT NULL constraints never affects existing rows (all
 * have values). No data is dropped or truncated. The FK constraints remain in
 * place; PostgreSQL allows NULL references with a nullable FK column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            // Relax NOT NULL — existing rows keep their values; self-initiated
            // requests will have null (no accounting firm / no assigned accountant).
            $table->unsignedBigInteger('accounting_firm_id')->nullable()->change();
            $table->unsignedBigInteger('accountant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverse only in local dev; never run on production
    }
};
