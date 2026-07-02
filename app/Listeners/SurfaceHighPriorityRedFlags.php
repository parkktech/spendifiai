<?php

namespace App\Listeners;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Events\OptimizationProfileBuilt;
use App\Models\AIQuestion;
use App\Models\OptimizationFinding;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Create AIQuestion(Optimization) rows for high-band (auto) OptimizationFindings
 * so they appear in the existing /api/v1/questions feed (FEED-02 / D7).
 *
 * HIGH-BAND = band='auto' — these findings have the highest confidence and are
 * presented to the user as "suggested — confirm" questions (INT-07 pre-fill).
 *
 * IDEMPOTENCY: Uses a firstOrCreate keyed on (user_id, question_type, ai_best_guess)
 * where ai_best_guess = the finding_key. Safe to run on job retry.
 *
 * SECURITY (T-11-04-03): scopeForUser() is called on the finding query — never
 * queries across users. The AIQuestion rows carry user_id and are owned by the
 * individual user.
 *
 * THREAD-SAFETY (T-11-04-05): firstOrCreate prevents duplicate feed rows from
 * concurrent dispatches of OptimizationProfileBuilt.
 */
class SurfaceHighPriorityRedFlags implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Handle the event.
     *
     * Queries all open, high-band OptimizationFindings for the user and creates
     * a corresponding pending AIQuestion for each one that does not already have
     * an optimization question in the feed.
     */
    public function handle(OptimizationProfileBuilt $event): void
    {
        $userId = $event->userId;
        $taxYear = $event->taxYear;

        // Query open, high-band (auto) findings for this user and tax year.
        // 'auto' band = highest-confidence = INT-07 suggested-confirm mode.
        $findings = OptimizationFinding::forUser($userId)
            ->where('tax_year', $taxYear)
            ->where('band', 'auto')
            ->where('status', 'open')
            ->get();

        $created = 0;

        foreach ($findings as $finding) {
            // IDEMPOTENCY: use ai_best_guess = finding_key as the unique marker.
            // If a pending optimization question for this finding already exists, skip.
            // firstOrCreate on (user_id, question_type, ai_best_guess) ensures one row per finding.
            [$question, $wasCreated] = $this->firstOrCreateQuestion($userId, $finding);

            if ($wasCreated) {
                $created++;
                Log::info('SurfaceHighPriorityRedFlags: created AIQuestion', [
                    'user_id' => $userId,
                    'finding_key' => $finding->finding_key,
                    'question_id' => $question->id,
                ]);
            }
        }

        Log::info('SurfaceHighPriorityRedFlags: complete', [
            'user_id' => $userId,
            'tax_year' => $taxYear,
            'findings_queried' => $findings->count(),
            'questions_created' => $created,
        ]);
    }

    /**
     * Create or retrieve the AIQuestion for a given OptimizationFinding.
     *
     * INT-07 pre-fill: ai_best_guess = suggested treatment; options encodes
     * the finding metadata (finding_id, fact_key, band, transaction_ids) for
     * UpdateOptimizationFromAnswer to read when the user answers.
     *
     * @return array{AIQuestion, bool} [question, wasCreated]
     */
    private function firstOrCreateQuestion(int $userId, OptimizationFinding $finding): array
    {
        $factKey = "finding.{$finding->finding_key}";

        // Options JSON encodes everything UpdateOptimizationFromAnswer needs:
        //   fact_key        → UserTaxFact key to write on answer
        //   finding_id      → link back to the OptimizationFinding
        //   band            → 'auto' for this method (suggested-confirm)
        //   transaction_ids → INT-06 batch; applied retroactively on confirm
        $options = [
            'fact_key' => $factKey,
            'finding_id' => $finding->id,
            'band' => $finding->band ?? 'auto',
            'suggested_treatment' => $finding->treatment,
            'transaction_ids' => $finding->transaction_ids ?? [],
        ];

        $question = AIQuestion::firstOrCreate(
            [
                'user_id' => $userId,
                'question_type' => QuestionType::Optimization->value,
                'ai_best_guess' => $finding->finding_key,  // idempotency marker
                'status' => QuestionStatus::Pending->value,
            ],
            [
                'transaction_id' => null,  // optimization questions have no transaction
                'question' => $this->humanizedQuestionText($finding),
                'options' => $options,
                'ai_confidence' => 1.0,   // auto-band = highest confidence
                // ai_best_guess is already in the search key above
            ]
        );

        $wasCreated = $question->wasRecentlyCreated;

        return [$question, $wasCreated];
    }

    /**
     * D18 rule 2 (owner decision, binding): NEVER leak internal finding keys
     * into user copy. Fallback order when narration has not run yet:
     *   1. description (narrated copy);
     *   2. treatment prose (detector-authored, merchant-named data) + confirm ask;
     *   3. a generic-but-clean review prompt — the treatment still renders in the
     *      suggested-confirm card, so the data is shown there.
     *
     * The pre-D18 output ("...related to: {finding_key}...") is permanently dead.
     */
    private function humanizedQuestionText(OptimizationFinding $finding): string
    {
        $description = trim((string) $finding->description);
        if ($description !== '') {
            return $description;
        }

        $treatment = trim((string) $finding->treatment);
        if ($treatment !== '' && str_contains($treatment, ' ')) {
            return str_ends_with($treatment, '?')
                ? $treatment
                : rtrim($treatment, '.').'. Does this reflect your situation?';
        }

        return 'We spotted a potential tax opportunity in your account activity. Would you like to review it?';
    }
}
