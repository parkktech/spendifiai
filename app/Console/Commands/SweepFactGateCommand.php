<?php

namespace App\Console\Commands;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AIQuestion;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\InterviewOrchestratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SweepFactGateCommand — backlog hygiene for fact-aware question suppression.
 *
 * Sweeps PENDING optimization interview questions against the confirmed fact
 * store and auto-resolves (expires) those whose target facts are now confirmed.
 * This handles the backlog of questions created before document facts existed.
 *
 * SCOPE: optimization questions only (question_type = 'optimization').
 *        Transaction-categorization questions are OUT OF SCOPE.
 *
 * IDEMPOTENT: safe to run multiple times. Already-expired questions are skipped.
 *
 * Usage:
 *   php artisan interview:sweep-fact-gate                # all users
 *   php artisan interview:sweep-fact-gate --user=1       # single user
 *   php artisan interview:sweep-fact-gate --dry-run      # preview only
 */
class SweepFactGateCommand extends Command
{
    protected $signature = 'interview:sweep-fact-gate
        {--user= : Limit sweep to a single user ID}
        {--dry-run : Preview without making changes}';

    protected $description = 'Sweep pending optimization questions against confirmed facts and auto-resolve stale ones.';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $limitUserId = $this->option('user') ? (int) $this->option('user') : null;

        if ($isDryRun) {
            $this->info('[DRY RUN] No changes will be made.');
        }

        // Build base query — optimization questions only, pending status only
        $query = AIQuestion::where('question_type', QuestionType::Optimization->value)
            ->where('status', QuestionStatus::Pending->value)
            ->whereIn('ai_best_guess', array_keys(InterviewOrchestratorService::TARGET_FACTS_MAP));

        if ($limitUserId !== null) {
            $query->where('user_id', $limitUserId);
        }

        $totalScanned = 0;
        $totalExpired = 0;
        $perUserCounts = [];

        // Process in batches of 100 to avoid memory pressure
        $query->orderBy('user_id')->orderBy('id')
            ->chunk(100, function ($questions) use ($isDryRun, &$totalScanned, &$totalExpired, &$perUserCounts) {
                foreach ($questions as $question) {
                    $totalScanned++;
                    $userId = $question->user_id;
                    $factKey = $question->ai_best_guess;

                    $targets = InterviewOrchestratorService::TARGET_FACTS_MAP[$factKey] ?? [];
                    $confirmed = false;

                    foreach ($targets as $targetFactKey) {
                        if (UserTaxFact::currentFact($userId, $targetFactKey) !== null) {
                            $confirmed = true;
                            break;
                        }
                    }

                    if (! $confirmed) {
                        continue; // target facts not yet confirmed — skip
                    }

                    $totalExpired++;
                    $perUserCounts[$userId] = ($perUserCounts[$userId] ?? 0) + 1;

                    $this->line(sprintf(
                        '  %s user=%d id=%d key=%s',
                        $isDryRun ? '[WOULD EXPIRE]' : '[EXPIRED]',
                        $userId,
                        $question->id,
                        $factKey,
                    ));

                    if (! $isDryRun) {
                        $question->update(['status' => QuestionStatus::Expired->value]);

                        Log::info('SweepFactGateCommand: question auto-resolved by confirmed facts', [
                            'question_id' => $question->id,
                            'user_id' => $userId,
                            'fact_key' => $factKey,
                            'target_facts' => $targets,
                        ]);
                    }
                }
            });

        $this->newLine();
        $this->info("Scanned: {$totalScanned} pending optimization questions");
        $this->info(($isDryRun ? 'Would expire: ' : 'Expired: ').$totalExpired);

        if (! empty($perUserCounts)) {
            $this->newLine();
            $this->info('Per-user breakdown:');
            foreach ($perUserCounts as $userId => $count) {
                $this->line("  user_id={$userId}: {$count} question(s) suppressed");
            }
        }

        // If run for a single user, report what remains pending for them
        if ($limitUserId !== null) {
            $remaining = AIQuestion::where('user_id', $limitUserId)
                ->where('question_type', QuestionType::Optimization->value)
                ->where('status', QuestionStatus::Pending->value)
                ->count();

            $this->newLine();
            $this->info("User {$limitUserId} remaining pending optimization questions: {$remaining}");
        }

        return self::SUCCESS;
    }
}
