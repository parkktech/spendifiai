<?php

use App\Jobs\CategorizePendingTransactions;
// use App\Jobs\SyncBankTransactions; // TODO: Create in Phase 6
use App\Models\AIQuestion;
use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes — Scheduled Tasks
|--------------------------------------------------------------------------
|
| Laravel 12 style: define schedules directly in routes/console.php
|
*/

// ── Sync bank transactions (every 4 hours) ──
// TODO: Enable after SyncBankTransactions job is created in Phase 6
// Schedule::call(function () {
//     BankConnection::where('status', 'active')->each(function ($connection) {
//         SyncBankTransactions::dispatch($connection);
//     });
// })->everyFourHours()->name('sync-bank-transactions');

// ── AI categorize pending transactions (every 2 hours) ──
Schedule::call(function () {
    User::whereHas('transactions', fn($q) => $q->where('review_status', 'pending_ai'))
        ->pluck('id')
        ->each(fn($id) => CategorizePendingTransactions::dispatch($id));
})->everyTwoHours()->name('categorize-pending');

// ── Detect subscriptions (daily at 2am) ──
Schedule::call(function () {
    $detector = app(\App\Services\SubscriptionDetectorService::class);
    User::whereHas('bankConnections')->each(function ($user) use ($detector) {
        $detector->detectSubscriptions($user->id);
    });
})->dailyAt('02:00')->name('detect-subscriptions');

// ── Generate savings recommendations (weekly on Mondays) ──
Schedule::call(function () {
    $analyzer = app(\App\Services\AI\SavingsAnalyzerService::class);
    User::whereHas('bankConnections')->each(function ($user) use ($analyzer) {
        $analyzer->analyze($user);
    });
})->weeklyOn(1, '06:00')->name('generate-savings-recommendations');

// ── Expire old AI questions (daily) ──
Schedule::call(function () {
    $expiry = config('spendwise.sync.question_expiry_days', 7);
    AIQuestion::where('status', 'pending')
        ->where('created_at', '<', now()->subDays($expiry))
        ->update(['status' => 'expired']);
})->dailyAt('03:00')->name('expire-ai-questions');

// ── Sync email accounts for order confirmations (every 6 hours) ──
// Schedule::command('spendwise:sync-emails')->everySixHours();
