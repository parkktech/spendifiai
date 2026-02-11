<?php

// ══════════════════════════════════════════════════════════
// app/Jobs/CategorizePendingTransactions.php
// ══════════════════════════════════════════════════════════

namespace App\Jobs;

use App\Models\Transaction;
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

        // Process in batches of 25 to stay within token limits
        $pending->chunk(25)->each(function ($batch) use ($categorizer) {
            $result = $categorizer->categorizeBatch($batch, $this->userId);

            Log::info('Categorization batch complete', $result);

            // Rate limit between batches
            usleep(500000);
        });

        // After categorization, detect subscriptions
        $subResult = $subDetector->detectSubscriptions($this->userId);
        Log::info('Subscription detection complete', $subResult);
    }
}

// ══════════════════════════════════════════════════════════
// routes/api.php
// ══════════════════════════════════════════════════════════

/*
use App\Http\Controllers\Api\SpendWiseController;

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {

    // Dashboard
    Route::get('/dashboard', [SpendWiseController::class, 'dashboard']);

    // Bank connections (Plaid)
    Route::post('/plaid/link-token', [SpendWiseController::class, 'createPlaidLinkToken']);
    Route::post('/plaid/exchange', [SpendWiseController::class, 'exchangePlaidToken']);
    Route::post('/plaid/sync', [SpendWiseController::class, 'syncBank']);

    // Bank accounts (purpose management)
    Route::get('/accounts', [SpendWiseController::class, 'accounts']);
    Route::patch('/accounts/{account}/purpose', [SpendWiseController::class, 'updateAccountPurpose']);

    // Email connections
    Route::get('/email/connect/{provider}', [SpendWiseController::class, 'connectEmail']);
    Route::get('/email/callback/{provider}', [SpendWiseController::class, 'emailCallback']);
    Route::post('/email/sync', [SpendWiseController::class, 'syncEmails']);

    // AI Questions (user interaction loop)
    Route::get('/questions', [SpendWiseController::class, 'getQuestions']);
    Route::post('/questions/{question}/answer', [SpendWiseController::class, 'answerQuestion']);
    Route::post('/questions/bulk-answer', [SpendWiseController::class, 'bulkAnswerQuestions']);

    // Transactions
    Route::get('/transactions', [SpendWiseController::class, 'transactions']);
    Route::patch('/transactions/{transaction}/category', [SpendWiseController::class, 'updateTransactionCategory']);

    // Subscriptions
    Route::get('/subscriptions', [SpendWiseController::class, 'subscriptions']);
    Route::post('/subscriptions/detect', [SpendWiseController::class, 'detectSubscriptions']);

    // Savings recommendations
    Route::get('/savings', [SpendWiseController::class, 'savingsRecommendations']);
    Route::post('/savings/analyze', [SpendWiseController::class, 'generateSavingsAnalysis']);
    Route::post('/savings/{rec}/dismiss', [SpendWiseController::class, 'dismissRecommendation']);
    Route::post('/savings/{rec}/apply', [SpendWiseController::class, 'applyRecommendation']);

    // Savings targets & plans
    Route::post('/savings/target', [SpendWiseController::class, 'setSavingsTarget']);
    Route::get('/savings/target', [SpendWiseController::class, 'getSavingsTarget']);
    Route::post('/savings/target/regenerate', [SpendWiseController::class, 'regeneratePlan']);
    Route::post('/savings/plan/{action}/respond', [SpendWiseController::class, 'respondToPlanAction']);
    Route::get('/savings/pulse', [SpendWiseController::class, 'savingsPulseCheck']);

    // Tax
    Route::get('/tax-summary', [SpendWiseController::class, 'taxSummary']);
    Route::post('/tax/export', [SpendWiseController::class, 'exportTaxPackage']);
    Route::post('/tax/send-to-accountant', [SpendWiseController::class, 'sendToAccountant']);
    Route::get('/tax/download/{year}/{type}', [SpendWiseController::class, 'downloadTaxFile'])->name('tax.download');

    // User profile
    Route::post('/profile/financial', [SpendWiseController::class, 'updateFinancialProfile']);
});

// Plaid webhooks (no auth — verified by Plaid)
Route::post('/webhooks/plaid', [SpendWiseController::class, 'handlePlaidWebhook']);
*/

// ══════════════════════════════════════════════════════════
// app/Console/Kernel.php — Scheduled Tasks
// ══════════════════════════════════════════════════════════

/*
protected function schedule(Schedule $schedule): void
{
    // Sync all bank accounts every 4 hours
    $schedule->call(function () {
        $plaid = app(PlaidService::class);
        BankConnection::where('status', 'active')->each(function ($conn) use ($plaid) {
            try {
                $result = $plaid->syncTransactions($conn);
                if ($result['added'] > 0) {
                    CategorizePendingTransactions::dispatch($conn->user_id);
                }
            } catch (\Exception $e) {
                Log::error("Bank sync failed for connection {$conn->id}", ['error' => $e->getMessage()]);
            }
        });
    })->everyFourHours()->name('sync-bank-transactions');

    // Sync email accounts every 6 hours
    $schedule->call(function () {
        EmailConnection::where('sync_status', '!=', 'syncing')->each(function ($conn) {
            ProcessOrderEmails::dispatch($conn);
        });
    })->everySixHours()->name('sync-email-orders');

    // Run savings analysis weekly for all users
    $schedule->call(function () {
        $analyzer = app(SavingsAnalyzerService::class);
        User::whereHas('bankConnections')->each(function ($user) use ($analyzer) {
            $analyzer->analyze($user);
        });
    })->weekly()->name('generate-savings-recommendations');

    // Detect subscriptions daily
    $schedule->call(function () {
        $detector = app(SubscriptionDetectorService::class);
        User::whereHas('bankConnections')->each(function ($user) use ($detector) {
            $detector->detectSubscriptions($user->id);
        });
    })->daily()->name('detect-subscriptions');

    // Expire old unanswered AI questions after 7 days
    $schedule->call(function () {
        AIQuestion::where('status', 'pending')
            ->where('created_at', '<', now()->subDays(7))
            ->update(['status' => 'expired']);
    })->daily()->name('expire-old-questions');
}
*/

// ══════════════════════════════════════════════════════════
// config/services.php additions
// ══════════════════════════════════════════════════════════

/*
'plaid' => [
    'client_id' => env('PLAID_CLIENT_ID'),
    'secret'    => env('PLAID_SECRET'),
    'env'       => env('PLAID_ENV', 'sandbox'),
],

'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model'   => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
],

'google' => [
    'client_id'     => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri'  => env('GOOGLE_REDIRECT_URI'),
],
*/

// ══════════════════════════════════════════════════════════
// .env template
// ══════════════════════════════════════════════════════════

/*
# Plaid
PLAID_CLIENT_ID=
PLAID_SECRET=
PLAID_ENV=sandbox

# Anthropic (Claude API)
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-4-20250514

# Google (Gmail OAuth)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8000/api/v1/email/callback/gmail

# Queue (Redis recommended for this workload)
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=spendwise
DB_USERNAME=postgres
DB_PASSWORD=
*/

// ══════════════════════════════════════════════════════════
// Composer Requirements
// ══════════════════════════════════════════════════════════

/*
composer require laravel/sanctum
composer require google/apiclient --with-all-dependencies
composer require webklex/laravel-imap
composer require predis/predis
*/
