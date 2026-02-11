<?php

namespace App\Providers;

use App\Http\Middleware\EnsureBankConnected;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\Enforce2FA;
use App\Http\Middleware\VerifyCaptcha;
use App\Models\AIQuestion;
use App\Models\BankAccount;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Policies\AIQuestionPolicy;
use App\Policies\BankAccountPolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\TransactionPolicy;
use Illuminate\Support\Facades\Gate;
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
        // ── Route Model Binding ──
        // Automatically resolve {transaction}, {account}, {question} from URL
        Route::model('transaction', Transaction::class);
        Route::model('account', BankAccount::class);
        Route::model('question', AIQuestion::class);
        Route::model('subscription', Subscription::class);

        // ── Middleware Aliases ──
        Route::aliasMiddleware('bank.connected', EnsureBankConnected::class);
        Route::aliasMiddleware('profile.complete', EnsureProfileComplete::class);
        Route::aliasMiddleware('2fa', Enforce2FA::class);
        Route::aliasMiddleware('captcha', VerifyCaptcha::class);

        // ── Policies ──
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(AIQuestion::class, AIQuestionPolicy::class);
        Gate::policy(Subscription::class, SubscriptionPolicy::class);

        // ── Vite Prefetch (from Breeze starter kit) ──
        Vite::prefetch(concurrency: 3);
    }
}
