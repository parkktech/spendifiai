<?php

namespace App\Listeners;

use App\Enums\QuestionType;
use App\Events\UserAnsweredQuestion;
use App\Models\UserTaxFact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Write optimization question answers through to UserTaxFact (FEED-03 / D7).
 *
 * When a user answers an AIQuestion with question_type=Optimization, this listener:
 *   1. Reads the fact_key from the question's options JSON
 *   2. Records the answer as a UserTaxFact (source_type=interview_answer)
 *   3. Appends to the active InterviewSession transcript (if a session exists — INT-05)
 *
 * SAFETY CONSTRAINTS (D12 / SAFE-03):
 *   - NEVER writes estimated_value_cents or any dollar figure
 *   - The recorded value is the user's raw answer string, NOT a computed amount
 *   - NEVER silently supersedes document_extraction facts (D3 confirmation gate)
 *
 * Non-optimization questions are silently ignored (early return).
 *
 * SECURITY (T-11-04-02): this listener is the only path for optimization answers to
 * persist — it cannot trigger transaction categorization or subscription detection.
 */
class UpdateOptimizationFromAnswer implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 60;

    public function handle(UserAnsweredQuestion $event): void
    {
        $question = $event->question;
        $user = $event->user;

        // Only handle optimization questions — all other types are ignored.
        // (UpdateTransactionCategory handles the categorization listeners.)
        if ($question->question_type !== QuestionType::Optimization) {
            return;
        }

        // Resolve the fact_key from the question's options JSON.
        // SurfaceHighPriorityRedFlags encodes: options->fact_key = "finding.{finding_key}"
        $options = is_array($question->options) ? $question->options : [];
        $factKey = $options['fact_key'] ?? null;

        if ($factKey === null) {
            Log::warning('UpdateOptimizationFromAnswer: missing fact_key in options', [
                'question_id' => $question->id,
                'user_id' => $user->id,
            ]);

            return;
        }

        // The user's answer — stored as-is. NEVER compute a dollar amount here.
        // (SAFE-03: estimated_value_cents is written ONLY by TaxRulesEngineService)
        $answerValue = $question->user_answer;

        if (! $answerValue) {
            return;  // No answer to record (e.g., question was skipped)
        }

        // Write the durable fact (append-only — recordFact() handles supersession
        // atomically via SELECT FOR UPDATE + DB transaction).
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: $factKey,
            value: $answerValue,
            sourceType: 'interview_answer',
            label: "Answer to optimization question: {$question->question}",
            volatility: 'stable',
            sourceId: (string) $question->id,
        );

        Log::info('UpdateOptimizationFromAnswer: wrote UserTaxFact', [
            'user_id' => $user->id,
            'question_id' => $question->id,
            'fact_key' => $factKey,
        ]);

        // Append to active InterviewSession transcript if a session exists.
        // This is optional — if no session exists (e.g., question surfaced via
        // the feed before a session was started), we still persist the UserTaxFact.
        $this->appendToSessionTranscript($user->id, $question, $factKey, $answerValue);
    }

    /**
     * Append the Q/A to the active InterviewSession transcript (INT-05 / D3).
     *
     * The transcript is stored as an encrypted JSON array in InterviewSession.assertions.
     * This method is safe to call even if InterviewSession does not exist (e.g., the
     * session table has not been created yet in early bootstrap).
     */
    private function appendToSessionTranscript(
        int $userId,
        \App\Models\AIQuestion $question,
        string $factKey,
        string $answer
    ): void {
        // Guard: InterviewSession model/table may not exist yet (created in 11-04 Task 2).
        // If the class does not exist or the table is missing, silently skip the transcript
        // update — the UserTaxFact write above is the authoritative durability record.
        if (! class_exists(\App\Models\InterviewSession::class)) {
            return;
        }

        try {
            $session = \App\Models\InterviewSession::forUser($userId)
                ->whereIn('status', ['in_progress', 'paused'])
                ->latest()
                ->first();

            if ($session === null) {
                return;
            }

            // Decode existing transcript (encrypted JSON array)
            $transcript = $session->assertions ? json_decode($session->assertions, true) : [];
            if (! is_array($transcript)) {
                $transcript = [];
            }

            // Append this Q/A pair with timestamp
            $transcript[] = [
                'fact_key' => $factKey,
                'question' => $question->question,
                'answer' => $answer,
                'answered_at' => now()->toIso8601String(),
                'question_id' => $question->id,
            ];

            $session->update(['assertions' => json_encode($transcript)]);

        } catch (\Throwable $e) {
            // Non-fatal: transcript update failure should not break the fact write.
            Log::warning('UpdateOptimizationFromAnswer: could not update session transcript', [
                'user_id' => $userId,
                'question_id' => $question->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
