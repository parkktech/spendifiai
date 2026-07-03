<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill confirmed_at = asserted_at for existing user_edit facts where confirmed_at is NULL.
 *
 * user_edit IS the strongest confirmation tier — the act of writing a user_edit fact
 * IS the user's explicit assent. These facts should have been written with confirmed_at
 * set from the start (now enforced in UserTaxFact::recordFact()). This migration
 * back-fills the gap for facts written before the fix.
 *
 * Known affected rows: user_id=1, fact_id=21 (w4.dependents_claimed, user_edit,
 * confirmed_at=null, asserted_at=2026-07-03T00:07:21Z).
 *
 * SAFE: purely additive — sets a previously-null timestamp column on existing rows.
 * Does NOT change values, is_current, or superseded_by_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_tax_facts')
            ->where('source_type', 'user_edit')
            ->whereNull('confirmed_at')
            ->whereNotNull('asserted_at')
            ->update(['confirmed_at' => DB::raw('asserted_at')]);
    }

    public function down(): void
    {
        // Cannot safely reverse: we don't know which rows had null before the backfill.
        // Leave confirmed_at in place — a null confirmed_at on user_edit is invalid state.
    }
};
