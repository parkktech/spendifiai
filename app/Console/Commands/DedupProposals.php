<?php

namespace App\Console\Commands;

use App\Models\UserTaxFact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DedupProposals — Fix-1 data repair.
 *
 * Before the recordFact() dedup patch, multiple open proposals could exist for the
 * same (user_id, fact_key, entity_id, tax_year) tuple when a document was processed
 * more than once or two documents extracted the same field.
 *
 * This command:
 *   1. Groups all open proposals (is_current=false, source_type=document_extraction,
 *      confirmed_at IS NULL, superseded_by_id IS NULL) by (user_id, fact_key, entity_id, tax_year).
 *   2. For each group with more than one open proposal, supersedes all but the NEWEST
 *      (highest asserted_at) by setting superseded_by_id → newest.id.
 *
 * Idempotent: already-resolved proposals (superseded_by_id NOT NULL) are excluded from
 * consideration. Safe to re-run multiple times.
 */
class DedupProposals extends Command
{
    protected $signature = 'optimizer:dedup-proposals
                            {--dry-run : Preview affected rows without writing anything}
                            {--user=  : Restrict to a specific user_id}';

    protected $description = 'Fix-1: Mark older duplicate open proposals superseded, keeping only the newest per fact_key tuple';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $onlyUser = $this->option('user') ? (int) $this->option('user') : null;

        // Pull all open proposals, optionally restricted to one user.
        $query = UserTaxFact::query()
            ->where('is_current', false)
            ->where('source_type', 'document_extraction')
            ->whereNull('confirmed_at')
            ->whereNull('superseded_by_id')
            ->orderBy('asserted_at', 'asc'); // oldest first — newest will be last in each group

        if ($onlyUser !== null) {
            $query->where('user_id', $onlyUser);
        }

        // Group in PHP — the tuple key is user_id:fact_key:entity_id:tax_year.
        $groups = $query
            ->get(['id', 'user_id', 'fact_key', 'entity_id', 'tax_year', 'asserted_at', 'metadata'])
            ->groupBy(fn (UserTaxFact $f) => implode(':', [
                $f->user_id,
                $f->fact_key,
                (string) $f->entity_id,
                (string) $f->tax_year,
            ]));

        $totalGroups = 0;
        $totalSuperseded = 0;

        foreach ($groups as $tupleKey => $facts) {
            if ($facts->count() < 2) {
                continue; // no duplicate — nothing to do
            }

            $totalGroups++;

            // The last element (sorted asc above) is the newest.
            $newest = $facts->last();
            $older = $facts->filter(fn (UserTaxFact $f) => $f->id !== $newest->id);

            $this->line(sprintf(
                '  Tuple [%s]: %d duplicate(s) found — keeper id=%d (asserted %s)%s',
                $tupleKey,
                $older->count(),
                $newest->id,
                $newest->asserted_at?->toDateTimeString() ?? 'unknown',
                $dryRun ? ' [DRY RUN]' : ''
            ));

            if ($dryRun) {
                $totalSuperseded += $older->count();

                continue;
            }

            DB::transaction(function () use ($newest, $older, &$totalSuperseded) {
                foreach ($older as $old) {
                    $old->update(['superseded_by_id' => $newest->id]);
                    $totalSuperseded++;
                }
            });
        }

        if ($totalGroups === 0) {
            $this->info('No duplicate open proposals found — nothing to dedup.');

            return 0;
        }

        if ($dryRun) {
            $this->info(sprintf(
                'DRY RUN: would supersede %d proposal(s) across %d duplicate tuple(s).',
                $totalSuperseded,
                $totalGroups
            ));
        } else {
            $this->info(sprintf(
                'Deduplication complete: %d older proposal(s) superseded across %d tuple(s).',
                $totalSuperseded,
                $totalGroups
            ));
        }

        return 0;
    }
}
