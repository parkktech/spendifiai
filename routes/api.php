<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\PlaidController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SavingsController;
use App\Http\Controllers\Api\TaxController;
use App\Http\Controllers\Api\AIQuestionController;
use App\Http\Controllers\Api\EmailConnectionController;
use App\Http\Controllers\Api\UserProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — SpendWise
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically.
| Auth routes: /api/auth/...
| App routes:  /api/v1/...
|
*/

// ══════════════════════════════════════════════════════════
// PUBLIC AUTH ROUTES (no auth required)
// ══════════════════════════════════════════════════════════

Route::prefix('auth')->group(function () {

    // Registration + Login (with captcha)
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1'); // 5 attempts per minute

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1'); // 10 attempts per minute

    // Password reset
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:3,1'); // 3 per minute

    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:5,1');

    // Google OAuth (stateless — no CSRF)
    Route::get('/google/redirect', [SocialAuthController::class, 'redirectToGoogle']);

    // reCAPTCHA config for frontend
    Route::get('/captcha-config', function () {
        return response()->json([
            'enabled'  => config('spendwise.captcha.enabled'),
            'site_key' => config('spendwise.captcha.site_key'),
        ]);
    });
});


// ══════════════════════════════════════════════════════════
// AUTHENTICATED ROUTES
// ══════════════════════════════════════════════════════════

Route::middleware(['auth:sanctum'])->group(function () {

    // ─── Auth Management ───
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/change-password', [PasswordResetController::class, 'changePassword']);

        // Email verification
        Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:3,1');

        // Google account management
        Route::post('/google/disconnect', [SocialAuthController::class, 'disconnectGoogle']);

        // Two-Factor Authentication
        Route::prefix('two-factor')->group(function () {
            Route::get('/status', [TwoFactorController::class, 'status']);
            Route::post('/enable', [TwoFactorController::class, 'enable']);
            Route::post('/confirm', [TwoFactorController::class, 'confirm']);
            Route::post('/disable', [TwoFactorController::class, 'disable']);
            Route::post('/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        });
    });

    // ─── SpendWise API v1 ───
    Route::prefix('v1')->group(function () {

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Plaid Bank Linking
        Route::prefix('plaid')->group(function () {
            Route::post('/link-token', [PlaidController::class, 'createLinkToken']);
            Route::post('/exchange', [PlaidController::class, 'exchangeToken']);
            Route::post('/sync', [PlaidController::class, 'sync']);
            Route::delete('/{connection}', [PlaidController::class, 'disconnect']);  // Revokes Plaid token + deletes connection
        });

        // Bank Accounts
        Route::get('/accounts', [BankAccountController::class, 'index']);
        Route::patch('/accounts/{account}/purpose', [BankAccountController::class, 'updatePurpose']);

        // ── Routes requiring a linked bank account ──
        Route::middleware('bank.connected')->group(function () {

            // AI Questions
            Route::get('/questions', [AIQuestionController::class, 'index']);
            Route::post('/questions/{question}/answer', [AIQuestionController::class, 'answer']);
            Route::post('/questions/bulk-answer', [AIQuestionController::class, 'bulkAnswer']);

            // Transactions
            Route::get('/transactions', [TransactionController::class, 'index']);
            Route::patch('/transactions/{transaction}/category', [TransactionController::class, 'updateCategory']);

            // Subscriptions
            Route::get('/subscriptions', [SubscriptionController::class, 'index']);
            Route::post('/subscriptions/detect', [SubscriptionController::class, 'detect']);

            // Savings
            Route::prefix('savings')->group(function () {
                Route::get('/', [SavingsController::class, 'recommendations']);
                Route::post('/analyze', [SavingsController::class, 'analyze']);
                Route::post('/{rec}/dismiss', [SavingsController::class, 'dismiss']);
                Route::post('/{rec}/apply', [SavingsController::class, 'apply']);
                Route::post('/target', [SavingsController::class, 'setTarget']);
                Route::get('/target', [SavingsController::class, 'getTarget']);
                Route::post('/target/regenerate', [SavingsController::class, 'regeneratePlan']);
                Route::post('/plan/{action}/respond', [SavingsController::class, 'respondToAction']);
                Route::get('/pulse', [SavingsController::class, 'pulseCheck']);
            });

            // Tax
            Route::prefix('tax')->middleware('profile.complete')->group(function () {
                Route::get('/summary', [TaxController::class, 'summary']);
                Route::post('/export', [TaxController::class, 'export']);
                Route::post('/send-to-accountant', [TaxController::class, 'sendToAccountant']);
                Route::get('/download/{year}/{type}', [TaxController::class, 'download'])
                    ->name('tax.download');
            });
        });

        // Email Connections
        Route::prefix('email')->group(function () {
            Route::post('/connect/{provider}', [EmailConnectionController::class, 'connect']);
            Route::get('/callback/{provider}', [EmailConnectionController::class, 'callback']);
            Route::post('/sync', [EmailConnectionController::class, 'sync']);
            Route::delete('/{connection}', [EmailConnectionController::class, 'disconnect']);
        });

        // User Profile
        Route::post('/profile/financial', [UserProfileController::class, 'updateFinancial']);
        Route::get('/profile/financial', [UserProfileController::class, 'showFinancial']);

        // Account Deletion (GDPR/CCPA compliance)
        Route::delete('/account', [UserProfileController::class, 'deleteAccount']);
    });
});
