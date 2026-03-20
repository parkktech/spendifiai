<?php

namespace App\Jobs;

use App\Events\OnboardingComplete;
use App\Models\BankConnection;
use App\Models\EmailConnection;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserTaxDeduction;
use App\Services\PlaidService;
use App\Services\SubscriptionDetectorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessOnboardingPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        protected int $userId,
    ) {}

    public function handle(
        PlaidService $plaidService,
        SubscriptionDetectorService $subDetector,
    ): void {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        Log::info('ProcessOnboardingPipeline started', ['user_id' => $this->userId]);

        $stats = [
            'transactions_imported' => 0,
            'subscriptions_detected' => 0,
            'monthly_subscription_cost' => 0,
            'deductions_found' => 0,
            'estimated_tax_savings' => 0,
            'emails_matched' => 0,
        ];

        // Step 1: Sync bank transactions
        $connections = BankConnection::where('user_id', $this->userId)
            ->where('status', 'active')
            ->get();

        foreach ($connections as $connection) {
            try {
                $result = $plaidService->syncTransactions($connection);
                $stats['transactions_imported'] += $result['added'] ?? 0;
            } catch (\Throwable $e) {
                Log::warning('Onboarding: bank sync failed', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Step 2: Trigger statements refresh (webhook will handle download)
            try {
                $startDate = now()->subYear()->startOfYear()->format('Y-m-d');
                $endDate = now()->format('Y-m-d');
                $plaidService->refreshStatements($connection, $startDate, $endDate);
                $connection->update(['statements_refresh_status' => 'refreshing']);
            } catch (\Throwable $e) {
                // Statements may not be supported
            }
        }

        // Step 3: Process email orders
        $emailConnections = EmailConnection::where('user_id', $this->userId)
            ->where('status', 'active')
            ->get();

        foreach ($emailConnections as $emailConn) {
            try {
                ProcessOrderEmails::dispatch($emailConn);
            } catch (\Throwable $e) {
                Log::warning('Onboarding: email sync failed', ['error' => $e->getMessage()]);
            }
        }

        // Step 4: Categorize all pending transactions
        try {
            CategorizePendingTransactions::dispatchSync($this->userId);
        } catch (\Throwable $e) {
            Log::warning('Onboarding: categorization failed', ['error' => $e->getMessage()]);
        }

        // Step 5: Detect subscriptions
        try {
            $subDetector->detectSubscriptions($this->userId);
        } catch (\Throwable $e) {
            Log::warning('Onboarding: subscription detection failed', ['error' => $e->getMessage()]);
        }

        // Step 6: Reconcile orders
        try {
            ReconcileOrders::dispatchSync($user);
        } catch (\Throwable $e) {
            Log::warning('Onboarding: reconciliation failed', ['error' => $e->getMessage()]);
        }

        // Gather final stats
        $stats['transactions_imported'] = Transaction::where('user_id', $this->userId)
            ->where('review_status', '!=', 'pending_ai')
            ->count();

        $activeSubscriptions = Subscription::where('user_id', $this->userId)
            ->where('status', 'active')
            ->get();
        $stats['subscriptions_detected'] = $activeSubscriptions->count();
        $stats['monthly_subscription_cost'] = (float) $activeSubscriptions->sum('amount');

        $stats['emails_matched'] = Order::where('user_id', $this->userId)
            ->where('is_reconciled', true)
            ->count();

        $stats['deductions_found'] = UserTaxDeduction::where('user_id', $this->userId)
            ->whereIn('status', ['eligible', 'claimed'])
            ->count();

        $stats['estimated_tax_savings'] = (float) UserTaxDeduction::where('user_id', $this->userId)
            ->whereIn('status', ['eligible', 'claimed'])
            ->sum('estimated_amount');

        // Mark onboarding as complete
        $user->update(['onboarding_completed_at' => now()]);

        // Fire event (triggers notification)
        OnboardingComplete::dispatch($user, $stats);

        Log::info('ProcessOnboardingPipeline complete', [
            'user_id' => $this->userId,
            'stats' => $stats,
        ]);
    }
}
