<?php

use App\Jobs\CategorizePendingTransactions;
use App\Jobs\ProcessOrderEmails;
use App\Jobs\RetryFailedEmails;
use App\Jobs\SyncBankTransactions;
use App\Models\AIQuestion;
use App\Models\BankConnection;
use App\Models\EmailConnection;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\UnusedSubscriptionAlert;
use App\Notifications\WeeklySavingsDigest;
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
Schedule::call(function () {
    BankConnection::where('status', 'active')->each(function ($connection) {
        SyncBankTransactions::dispatch($connection);
    });
})->everyFourHours()->name('sync-bank-transactions');

// ── AI categorize pending transactions (every 2 hours) ──
Schedule::call(function () {
    User::whereHas('transactions', fn ($q) => $q->where('review_status', 'pending_ai'))
        ->pluck('id')
        ->each(fn ($id) => CategorizePendingTransactions::dispatch($id));
})->everyTwoHours()->name('categorize-pending');

// ── Detect subscriptions (daily at 2am) + notify about unused ──
Schedule::call(function () {
    $detector = app(\App\Services\SubscriptionDetectorService::class);
    User::whereHas('bankConnections')->each(function ($user) use ($detector) {
        $detector->detectSubscriptions($user->id);

        // After detection, notify users about unused subscriptions
        $unused = Subscription::where('user_id', $user->id)
            ->where('status', 'unused')
            ->get();

        if ($unused->isNotEmpty()) {
            $totalMonthlyCost = $unused->sum('amount');
            $subscriptionNames = $unused->pluck('merchant_normalized')->toArray();

            $user->notify(new UnusedSubscriptionAlert($subscriptionNames, $totalMonthlyCost));
        }
    });
})->dailyAt('02:00')->name('detect-subscriptions');

// ── Generate savings recommendations (weekly on Mondays at 06:00) ──
Schedule::call(function () {
    $analyzer = app(\App\Services\AI\SavingsAnalyzerService::class);
    User::whereHas('bankConnections')->each(function ($user) use ($analyzer) {
        $analyzer->analyze($user);
    });
})->weeklyOn(1, '06:00')->name('generate-savings-recommendations');

// ── Weekly savings digest (Monday 07:00, after savings analysis at 06:00) ──
Schedule::call(function () {
    User::whereHas('savingsRecommendations')->each(function ($user) {
        $user->notify(new WeeklySavingsDigest($user));
    });
})->weeklyOn(1, '07:00')->name('weekly-savings-digest');

// ── Expire old AI questions (daily) ──
Schedule::call(function () {
    $expiry = config('spendifiai.sync.question_expiry_days', 7);
    AIQuestion::where('status', 'pending')
        ->where('created_at', '<', now()->subDays($expiry))
        ->update(['status' => 'expired']);
})->dailyAt('03:00')->name('expire-ai-questions');

// ── Sync email accounts for order confirmations (every 6 hours) ──
Schedule::call(function () {
    EmailConnection::where('sync_status', '!=', 'syncing')->each(function ($conn) {
        ProcessOrderEmails::dispatch($conn);
    });
})->everySixHours()->name('sync-email-orders');

// ── Retry failed email parses (daily at 4am) ──
Schedule::job(new RetryFailedEmails)->dailyAt('04:00')->name('retry-failed-emails');
