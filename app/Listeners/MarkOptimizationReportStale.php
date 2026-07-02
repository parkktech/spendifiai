<?php

namespace App\Listeners;

use App\Enums\QuestionType;
use App\Events\OptimizationProfileBuilt;
use App\Events\TaxDocumentExtracted;
use App\Events\UserAnsweredQuestion;
use App\Models\OptimizationReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Mark the user's OptimizationReport as stale (flag flip only — no inline generation).
 *
 * This listener handles three staleness triggers (RPT-02):
 *   - TaxDocumentExtracted:     a new document was processed → profile data may have changed
 *   - UserAnsweredQuestion:     an optimization interview answer → facts changed (non-optimization ignored)
 *   - OptimizationProfileBuilt: profile rebuilt → findings may have changed
 *
 * CRITICAL: This listener performs a single DB UPDATE (flag flip) only.
 * It NEVER dispatches GenerateOptimizationReport — that is the responsibility
 * of DispatchReportGeneration (separate listener, handles TaxDocumentExtracted +
 * OptimizationProfileBuilt with a 30s debounce).
 *
 * No thundering-herd risk: a flag flip is O(1) regardless of event volume.
 *
 * SECURITY (T-12-04-04): scopeForUser() ensures cross-user isolation.
 */
class MarkOptimizationReportStale implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * Handle TaxDocumentExtracted: mark the report for the document's tax year stale.
     */
    public function handleTaxDocumentExtracted(TaxDocumentExtracted $event): void
    {
        $document = $event->document;

        $this->markStale($document->user_id, $document->tax_year, 'TaxDocumentExtracted');
    }

    /**
     * Handle OptimizationProfileBuilt: mark the report for the profile's tax year stale.
     */
    public function handleOptimizationProfileBuilt(OptimizationProfileBuilt $event): void
    {
        $this->markStale($event->userId, $event->taxYear, 'OptimizationProfileBuilt');
    }

    /**
     * Handle UserAnsweredQuestion: mark stale ONLY for optimization questions.
     *
     * Non-optimization question answers (transaction categorization, etc.) are
     * silently ignored — they do not affect the optimization report.
     */
    public function handleUserAnsweredQuestion(UserAnsweredQuestion $event): void
    {
        $question = $event->question;

        // Only optimization questions affect the optimization report.
        // Mirror the FEED-04 guard pattern from UpdateOptimizationFromAnswer.
        if ($question->question_type !== QuestionType::Optimization) {
            return;
        }

        $user = $event->user;
        $taxYear = now()->year;

        $this->markStale($user->id, $taxYear, 'UserAnsweredQuestion');
    }

    /**
     * Flip is_stale=true for the given user + tax year (flag flip only).
     *
     * Uses OptimizationReport::forUser() scope (T-12-04-04 cross-user isolation).
     * Only updates rows that exist — no insert (fetchOrInit is the API layer's job).
     */
    private function markStale(int $userId, int $taxYear, string $trigger): void
    {
        $updated = OptimizationReport::forUser($userId)
            ->where('tax_year', $taxYear)
            ->update([
                'is_stale' => true,
                'stale_since' => now(),
            ]);

        Log::info('MarkOptimizationReportStale: flagged', [
            'user_id' => $userId,
            'tax_year' => $taxYear,
            'trigger' => $trigger,
            'rows_updated' => $updated,
        ]);
    }
}
