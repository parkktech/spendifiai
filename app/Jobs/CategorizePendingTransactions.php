<?php

namespace App\Jobs;

use App\Events\TransactionCategorized;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AI\TransactionCategorizerService;
use App\Services\SubscriptionDetectorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CategorizePendingTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        protected int $userId,
        protected bool $recategorizeAll = false
    ) {}

    public function handle(
        TransactionCategorizerService $categorizer,
        SubscriptionDetectorService $subDetector
    ): void {
        $query = Transaction::where('user_id', $this->userId);

        if ($this->recategorizeAll) {
            // Re-run AI on everything (e.g., after profile update)
            $query->whereNull('user_category'); // Don't override user choices
        } else {
            // Only categorize new/pending transactions
            $query->whereIn('review_status', ['pending_ai', 'needs_review']);
        }

        $pending = $query->orderByDesc('transaction_date')->get();

        if ($pending->isEmpty()) return;

        Log::info("Categorizing {$pending->count()} transactions for user {$this->userId}");

        $totalCategorized = 0;
        $totalQuestions = 0;

        // Process in batches of 25 to stay within token limits
        $pending->chunk(25)->each(function ($batch) use ($categorizer, &$totalCategorized, &$totalQuestions) {
            $result = $categorizer->categorizeBatch($batch, $this->userId);

            Log::info('Categorization batch complete', $result);

            $totalCategorized += ($result['auto_categorized'] ?? 0) + ($result['needs_review'] ?? 0);
            $totalQuestions += $result['questions_generated'] ?? 0;

            // Rate limit between batches
            usleep(500000);
        });

        // After categorization, detect subscriptions
        $subResult = $subDetector->detectSubscriptions($this->userId);
        Log::info('Subscription detection complete', $subResult);

        // Dispatch event so listeners can handle budget checks, notifications, etc.
        $user = User::find($this->userId);
        if ($user) {
            TransactionCategorized::dispatch($user, $totalCategorized, $totalQuestions);
        }
    }
}
