<?php

namespace App\Providers;

use App\Http\Middleware\Enforce2FA;
use App\Http\Middleware\EnsureBankConnected;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\VerifyCaptcha;
use App\Listeners\LogMailableMessage;
use App\Models\AIQuestion;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\CancellationProvider;
use App\Models\Dependent;
use App\Models\Household;
use App\Models\InterviewSession;
use App\Models\OptimizationChecklistItem;
use App\Models\OrderItem;
use App\Models\SavingsPlanAction;
use App\Models\SavingsRecommendation;
use App\Models\Subscription;
use App\Models\TaxProfileEntity;
use App\Models\Transaction;
use App\Models\UserTaxFact;
use App\Policies\AIQuestionPolicy;
use App\Policies\BankAccountPolicy;
use App\Policies\BankConnectionPolicy;
use App\Policies\CancellationProviderPolicy;
use App\Policies\DependentPolicy;
use App\Policies\HouseholdPolicy;
use App\Policies\InterviewSessionPolicy;
use App\Policies\OptimizationChecklistItemPolicy;
use App\Policies\OrderItemPolicy;
use App\Policies\SavingsPlanActionPolicy;
use App\Policies\SavingsRecommendationPolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\TaxProfileEntityPolicy;
use App\Policies\TransactionPolicy;
use App\Policies\UserTaxFactPolicy;
use Illuminate\Mail\Events\MailFailed;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ── Mail Event Logging ──
        Event::listen(MessageSending::class, [LogMailableMessage::class, 'handleSending']);
        Event::listen(MessageSent::class, [LogMailableMessage::class, 'handleSent']);
        Event::listen(MailFailed::class, [LogMailableMessage::class, 'handleFailed']);

        // ── Onboarding Complete ──
        Event::listen(
            \App\Events\OnboardingComplete::class,
            \App\Listeners\SendOnboardingCompleteNotification::class,
        );

        // ── Route Model Binding ──
        // Automatically resolve {transaction}, {account}, {question} from URL
        Route::model('transaction', Transaction::class);
        Route::model('account', BankAccount::class);
        Route::model('question', AIQuestion::class);
        Route::model('subscription', Subscription::class);
        Route::model('rec', SavingsRecommendation::class);
        Route::model('action', SavingsPlanAction::class);
        Route::model('connection', BankConnection::class);
        Route::model('item', OrderItem::class);
        Route::model('provider', CancellationProvider::class);
        Route::model('dependent', Dependent::class);
        Route::model('deduction', \App\Models\TaxDeduction::class);
        // Phase 11-02: durable-facts store bindings
        Route::model('tax-fact', UserTaxFact::class);
        Route::model('tax-entity', TaxProfileEntity::class);
        // Phase 11-04: interview session binding
        Route::model('interview', InterviewSession::class);
        // Phase 12-04: optimization finding binding (pro-review export)
        Route::model('finding', \App\Models\OptimizationFinding::class);
        // Phase 14-08: checklist item binding (PATCH done-toggle) — named 'checklistItem' to
        // avoid collision with 'item' → OrderItem binding registered above.
        Route::model('checklistItem', OptimizationChecklistItem::class);

        // ── Middleware Aliases ──
        Route::aliasMiddleware('bank.connected', EnsureBankConnected::class);
        Route::aliasMiddleware('profile.complete', EnsureProfileComplete::class);
        Route::aliasMiddleware('2fa', Enforce2FA::class);
        Route::aliasMiddleware('captcha', VerifyCaptcha::class);

        // ── Policies ──
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(BankConnection::class, BankConnectionPolicy::class);
        Gate::policy(AIQuestion::class, AIQuestionPolicy::class);
        Gate::policy(Subscription::class, SubscriptionPolicy::class);
        Gate::policy(SavingsRecommendation::class, SavingsRecommendationPolicy::class);
        Gate::policy(SavingsPlanAction::class, SavingsPlanActionPolicy::class);
        Gate::policy(OrderItem::class, OrderItemPolicy::class);
        Gate::policy(CancellationProvider::class, CancellationProviderPolicy::class);
        Gate::policy(Dependent::class, DependentPolicy::class);
        Gate::policy(Household::class, HouseholdPolicy::class);
        // Phase 11-02: durable-facts store policies
        Gate::policy(UserTaxFact::class, UserTaxFactPolicy::class);
        Gate::policy(TaxProfileEntity::class, TaxProfileEntityPolicy::class);
        // Phase 11-04: interview session policy
        Gate::policy(InterviewSession::class, InterviewSessionPolicy::class);
        // Phase 14-08: optimization checklist item policy
        Gate::policy(OptimizationChecklistItem::class, OptimizationChecklistItemPolicy::class);

        // ── Phase 11: Red-Flag Detection ──
        Event::listen(
            \App\Events\OptimizationProfileBuilt::class,
            \App\Listeners\RunRedFlagDetectors::class,
        );
        Event::listen(
            \App\Events\OptimizationProfileBuilt::class,
            \App\Listeners\NarrateOptimizationFindings::class,
        );
        // Phase 11-04: AI-feed bridge (FEED-02 / D7)
        // Surfaces high-band findings as AIQuestion(Optimization) rows in /api/v1/questions.
        Event::listen(
            \App\Events\OptimizationProfileBuilt::class,
            \App\Listeners\SurfaceHighPriorityRedFlags::class,
        );
        // Phase 11-04: Answer through-write (FEED-03 / D7)
        // Writes optimization answers to UserTaxFact; separate from UpdateTransactionCategory.
        Event::listen(
            \App\Events\UserAnsweredQuestion::class,
            \App\Listeners\UpdateOptimizationFromAnswer::class,
        );

        // ── Phase 12-04: Report Staleness + Debounced Regeneration (RPT-02) ──
        // TaxDocumentExtracted → flag flip (stale) + debounced unique job
        Event::listen(
            \App\Events\TaxDocumentExtracted::class,
            [\App\Listeners\MarkOptimizationReportStale::class, 'handleTaxDocumentExtracted'],
        );
        Event::listen(
            \App\Events\TaxDocumentExtracted::class,
            [\App\Listeners\DispatchReportGeneration::class, 'handleTaxDocumentExtracted'],
        );
        // OptimizationProfileBuilt → flag flip + debounced unique job
        Event::listen(
            \App\Events\OptimizationProfileBuilt::class,
            [\App\Listeners\MarkOptimizationReportStale::class, 'handleOptimizationProfileBuilt'],
        );
        Event::listen(
            \App\Events\OptimizationProfileBuilt::class,
            [\App\Listeners\DispatchReportGeneration::class, 'handleOptimizationProfileBuilt'],
        );
        // UserAnsweredQuestion → flag flip + debounced regen (Fix 1: closes D13 wiring gap)
        // An active user confirming facts / answering optimization questions is definitionally
        // active — the 28-day activity gate in GenerateOptimizationReport handles truly
        // inactive users (D13 §2: USER_ACTION triggers always stale + always dispatch).
        Event::listen(
            \App\Events\UserAnsweredQuestion::class,
            [\App\Listeners\MarkOptimizationReportStale::class, 'handleUserAnsweredQuestion'],
        );
        Event::listen(
            \App\Events\UserAnsweredQuestion::class,
            [\App\Listeners\DispatchReportGeneration::class, 'handleUserAnsweredQuestion'],
        );

        // ── Vite Prefetch (from Breeze starter kit) ──
        Vite::prefetch(concurrency: 3);

        // ── Slow Query Logging (development only) ──
        if ($this->app->environment('local')) {
            DB::listen(function ($query) {
                if ($query->time > 100) {
                    Log::warning('Slow query', [
                        'sql' => $query->sql,
                        'time' => $query->time.'ms',
                    ]);
                }
            });
        }
    }
}
