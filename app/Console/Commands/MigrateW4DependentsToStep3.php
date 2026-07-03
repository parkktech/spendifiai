<?php

namespace App\Console\Commands;

use App\Models\UserTaxFact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MigrateW4DependentsToStep3 — Fix-B data repair.
 *
 * Modern W-4 Step 3 carries an annual dollar credit amount, NOT a dependent count.
 * Before Fix-B, the paystub extractor mapped the w4_dependents_claimed extraction
 * field → w4.dependents_claimed (count fact), so a $3,200 Step 3 credit was stored
 * as "3200 dependents".
 *
 * This command:
 *   1. Finds all unconfirmed w4.dependents_claimed proposals from document_extraction
 *      whose value is implausible as a dependent count (>20).
 *   2. For each, reads the source document and creates a corrected
 *      w4.step3_annual_credits_cents proposal (money fact, cents: value × 100).
 *   3. Marks the bad proposal resolved via superseded_by_id → corrected proposal id.
 *
 * Idempotent: already-resolved proposals (superseded_by_id NOT NULL) are skipped.
 * Safe to re-run: will not create duplicate corrected proposals for already-migrated rows.
 */
class MigrateW4DependentsToStep3 extends Command
{
    protected $signature = 'optimizer:migrate-w4-step3
                            {--dry-run : Preview affected rows without writing anything}';

    protected $description = 'Fix-B: Invalidate implausible w4.dependents_claimed proposals and create corrected w4.step3_annual_credits_cents proposals';

    /**
     * A dependent count >20 is implausible — it was actually a Step 3 dollar amount.
     */
    private const IMPLAUSIBLE_COUNT_THRESHOLD = 20;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Find bad proposals: unconfirmed w4.dependents_claimed from document_extraction,
        // not yet resolved, with an implausible count value (> 20).
        $badProposals = UserTaxFact::where('fact_key', 'w4.dependents_claimed')
            ->where('source_type', 'document_extraction')
            ->whereNull('confirmed_at')
            ->whereNull('superseded_by_id')
            ->get(['id', 'user_id', 'value', 'tax_year', 'entity_id', 'source_id', 'metadata']);

        $implausible = $badProposals->filter(
            fn (UserTaxFact $f) => (int) $f->value > self::IMPLAUSIBLE_COUNT_THRESHOLD
        );

        if ($implausible->isEmpty()) {
            $this->info('No implausible w4.dependents_claimed proposals found — nothing to migrate.');

            return 0;
        }

        $this->info(sprintf('Found %d implausible w4.dependents_claimed proposal(s).', $implausible->count()));

        $migrated = 0;
        $skipped = 0;

        foreach ($implausible as $bad) {
            $docId = (int) ($bad->metadata['document_id'] ?? $bad->source_id ?? 0);

            // Dollar amount stored as raw string (e.g. "3200") → cents (320000)
            $creditsCents = (int) $bad->value * 100;

            $this->line(sprintf(
                '  Proposal #%d: user=%d, value="%s", credits=%d cents%s',
                $bad->id,
                $bad->user_id,
                $bad->value,
                $creditsCents,
                $dryRun ? ' [DRY RUN]' : ''
            ));

            if ($dryRun) {
                $skipped++;

                continue;
            }

            DB::transaction(function () use ($bad, $docId, $creditsCents, &$migrated) {
                // Check that the corrected proposal does not already exist
                $alreadyMigrated = UserTaxFact::where('user_id', $bad->user_id)
                    ->where('fact_key', 'w4.step3_annual_credits_cents')
                    ->where('source_type', 'document_extraction')
                    ->whereJsonContains('metadata->migrated_from_proposal_id', $bad->id)
                    ->whereNull('superseded_by_id')
                    ->exists();

                if ($alreadyMigrated) {
                    return; // idempotent skip
                }

                // Create the corrected w4.step3_annual_credits_cents proposal
                $corrected = UserTaxFact::create([
                    'user_id' => $bad->user_id,
                    'fact_key' => 'w4.step3_annual_credits_cents',
                    'value' => (string) $creditsCents,
                    'label' => 'W-4 Step 3 dependent credits',
                    'volatility' => 'annual',
                    'source_type' => 'document_extraction',
                    'source_id' => $bad->source_id,
                    'tax_year' => $bad->tax_year,
                    'entity_id' => $bad->entity_id,
                    'asserted_at' => now(),
                    'confirmed_at' => null,
                    'is_current' => false,
                    'metadata' => [
                        'migrated_from_proposal_id' => $bad->id,
                        'document_id' => $docId,
                        'migration_note' => 'Fix-B: w4_dependents_claimed was a Step 3 dollar amount, not a count',
                        'original_value_string' => $bad->value,
                    ],
                ]);

                // Resolve the bad proposal by linking it to the corrected fact
                $bad->update(['superseded_by_id' => $corrected->id]);

                $migrated++;
            });
        }

        if ($dryRun) {
            $this->info(sprintf('DRY RUN: would migrate %d proposal(s).', $implausible->count()));
        } else {
            $this->info(sprintf('Migrated %d proposal(s). Skipped %d (already migrated).', $migrated, $skipped));
        }

        return 0;
    }
}
