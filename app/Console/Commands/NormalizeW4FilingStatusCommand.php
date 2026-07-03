<?php

namespace App\Console\Commands;

use App\Models\UserTaxFact;
use App\Services\AI\PaystubFactExtractorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * NormalizeW4FilingStatusCommand — Fix 1 (choices-repair): migrate existing
 * w4.filing_status facts that carry verbatim W-4 display strings to engine enums.
 *
 * Background: before the normalizeW4FilingStatus() fix in PaystubFactExtractorService,
 * Claude's paystub extraction stored the W-4 form's verbatim label
 * (e.g. "Married filing jointly (or Qualifying widow(er))") as the fact value.
 * TaxRulesEngineService / ScenarioSolverService expect engine enums
 * (married_joint / single_or_mfs / head_of_household).
 *
 * This command is idempotent — running it twice is safe (already-valid enums
 * pass through normalizeW4FilingStatus() unchanged).
 *
 * Usage: php artisan optimizer:normalize-w4-filing-status [--user=ID]
 */
class NormalizeW4FilingStatusCommand extends Command
{
    protected $signature = 'optimizer:normalize-w4-filing-status
                            {--user= : Limit migration to a single user ID}
                            {--dry-run : Show what would change without writing}';

    protected $description = 'Migrate w4.filing_status facts from verbatim W-4 display strings to engine enums (idempotent)';

    /** Valid engine enums — facts already holding these are skipped. */
    private const VALID_ENUMS = ['single_or_mfs', 'married_joint', 'head_of_household'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        // Fetch current w4.filing_status facts.
        // We use the underlying DB query (not currentFact()) because we need ALL
        // current rows, not just year-scoped ones.
        $query = UserTaxFact::where('fact_key', 'w4.filing_status')
            ->where('is_current', true)
            ->whereNull('superseded_by_id');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $facts = $query->get(['id', 'user_id', 'value', 'metadata']);

        $migratedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($facts as $fact) {
            $rawValue = (string) $fact->value;

            if (in_array($rawValue, self::VALID_ENUMS, true)) {
                $this->line("  skip user={$fact->user_id} id={$fact->id}: already enum '{$rawValue}'");
                $skippedCount++;

                continue;
            }

            $normalized = PaystubFactExtractorService::normalizeW4FilingStatus($rawValue);

            if ($normalized === $rawValue) {
                // Could not normalize — store as-is and log
                $this->warn("  WARN user={$fact->user_id} id={$fact->id}: unrecognized value '{$rawValue}' — skipped");
                $errorCount++;

                continue;
            }

            $this->line("  migrate user={$fact->user_id} id={$fact->id}: '{$rawValue}' → '{$normalized}'");

            if (! $dryRun) {
                DB::transaction(function () use ($fact, $rawValue, $normalized) {
                    // Preserve original display string in metadata
                    $meta = (array) ($fact->metadata ?? []);
                    $meta['original_display'] = $rawValue;
                    $meta['normalized_by'] = 'optimizer:normalize-w4-filing-status';
                    $meta['normalized_at'] = now()->toIso8601String();

                    $fact->update([
                        'value' => $normalized,
                        'metadata' => $meta,
                    ]);
                });
            }

            $migratedCount++;
        }

        $mode = $dryRun ? 'DRY-RUN: ' : '';
        $this->info("{$mode}Done. migrated={$migratedCount}, skipped={$skippedCount}, errors={$errorCount}");

        return self::SUCCESS;
    }
}
