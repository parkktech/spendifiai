<?php

namespace App\Providers;

use App\Http\Middleware\EnsureBankConnected;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\Enforce2FA;
use App\Http\Middleware\VerifyCaptcha;
use App\Models\AIQuestion;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\SavingsPlanAction;
use App\Models\SavingsRecommendation;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Policies\AIQuestionPolicy;
use App\Policies\BankAccountPolicy;
use App\Policies\BankConnectionPolicy;
use App\Policies\SavingsPlanActionPolicy;
use App\Policies\SavingsRecommendationPolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\TransactionPolicy;
use App\Listeners\LogMailableMessage;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Events\MailFailed;
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

        // ── Route Model Binding ──
        // Automatically resolve {transaction}, {account}, {question} from URL
        Route::model('transaction', Transaction::class);
        Route::model('account', BankAccount::class);
        Route::model('question', AIQuestion::class);
        Route::model('subscription', Subscription::class);
        Route::model('rec', SavingsRecommendation::class);
        Route::model('action', SavingsPlanAction::class);
        Route::model('connection', BankConnection::class);

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

        // ── Vite Prefetch (from Breeze starter kit) ──
        Vite::prefetch(concurrency: 3);

        // ── Slow Query Logging (development only) ──
        if ($this->app->environment('local')) {
            DB::listen(function ($query) {
                if ($query->time > 100) {
                    Log::warning('Slow query', [
                        'sql'  => $query->sql,
                        'time' => $query->time . 'ms',
                    ]);
                }
            });
        }
    }
}
