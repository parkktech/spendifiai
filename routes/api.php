<?php

use App\Http\Controllers\Admin\CancellationProviderController;
use App\Http\Controllers\Api\AIQuestionController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailConnectionController;
use App\Http\Controllers\Api\OrderItemController;
use App\Http\Controllers\Api\PlaidController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\SavingsController;
use App\Http\Controllers\Api\StatementUploadController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TaxController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — SpendifiAI
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

    // Registration + Login (with captcha + rate limiting)
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware(['throttle:5,1', 'captcha:register']);

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware(['throttle:10,1', 'captcha:login']);

    // Password reset
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:3,1'); // 3 per minute

    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:5,1');

    // Email verification (public — signed URLs are self-verifying)
    // Note: Web route handles this instead (routes/web.php)
    // Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    //     ->middleware(['signed']);

    // Google OAuth (stateless — no CSRF)
    Route::get('/google/redirect', [SocialAuthController::class, 'redirectToGoogle']);

    // reCAPTCHA config for frontend
    Route::get('/captcha-config', function () {
        return response()->json([
            'enabled' => config('spendifiai.captcha.enabled'),
            'site_key' => config('spendifiai.captcha.site_key'),
        ]);
    });
});

// ══════════════════════════════════════════════════════════
// WEBHOOKS (no auth — verified by provider signatures)
// ══════════════════════════════════════════════════════════

Route::post('/v1/webhooks/plaid', [\App\Http\Controllers\Api\PlaidWebhookController::class, 'handle']);

// ══════════════════════════════════════════════════════════
// OAUTH CALLBACKS (no auth — user identity via encrypted state)
// ══════════════════════════════════════════════════════════

Route::get('/v1/email/callback/outlook', [EmailConnectionController::class, 'outlookCallback']);

// ══════════════════════════════════════════════════════════
// AUTHENTICATED ROUTES
// ══════════════════════════════════════════════════════════

Route::middleware(['auth:sanctum'])->group(function () {

    // ─── Auth Management ───
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/change-password', [PasswordResetController::class, 'changePassword'])
            ->middleware('throttle:5,1');

        // Email verification resend (authenticated only)
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

    // ─── SpendifiAI API v1 ───
    Route::prefix('v1')->middleware('throttle:120,1')->group(function () {

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/store/{storeName}', [DashboardController::class, 'storeDetail']);
        Route::post('/dashboard/classify', [DashboardController::class, 'classify']);

        // Expense Categories (reference data)
        Route::get('/categories', function () {
            return \App\Models\ExpenseCategory::orderBy('name')
                ->select('id', 'name', 'slug')
                ->get();
        });

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

        // Statement Uploads (no bank.connected requirement)
        Route::prefix('statements')->group(function () {
            Route::post('/upload', [StatementUploadController::class, 'upload']);
            Route::post('/import', [StatementUploadController::class, 'import']);
            Route::get('/history', [StatementUploadController::class, 'history']);
        });

        // ── Routes requiring a linked bank account ──
        Route::middleware('bank.connected')->group(function () {

            // AI Questions
            Route::get('/questions', [AIQuestionController::class, 'index']);
            Route::post('/questions/{question}/answer', [AIQuestionController::class, 'answer']);
            Route::post('/questions/{question}/chat', [AIQuestionController::class, 'chat']);
            Route::post('/questions/bulk-answer', [AIQuestionController::class, 'bulkAnswer']);

            // Transactions
            Route::get('/transactions', [TransactionController::class, 'index']);
            Route::patch('/transactions/{transaction}/category', [TransactionController::class, 'updateCategory']);
            Route::post('/transactions/categorize', [TransactionController::class, 'categorize'])
                ->middleware('throttle:5,1');

            // Subscriptions
            Route::get('/subscriptions', [SubscriptionController::class, 'index']);
            Route::post('/subscriptions/detect', [SubscriptionController::class, 'detect'])
                ->middleware('throttle:5,1');
            Route::patch('/subscriptions/{subscription}', [SubscriptionController::class, 'update']);
            Route::post('/subscriptions/{subscription}/respond', [SubscriptionController::class, 'respond']);
            Route::delete('/subscriptions/{subscription}', [SubscriptionController::class, 'dismiss']);
            Route::get('/subscriptions/{subscription}/alternatives', [SubscriptionController::class, 'alternatives']);

            // Savings
            Route::prefix('savings')->group(function () {
                Route::get('/', [SavingsController::class, 'recommendations']);
                Route::post('/analyze', [SavingsController::class, 'analyze'])
                    ->middleware('throttle:5,1');
                Route::post('/{rec}/dismiss', [SavingsController::class, 'dismiss']);
                Route::post('/{rec}/apply', [SavingsController::class, 'apply']);
                Route::post('/{rec}/respond', [SavingsController::class, 'respond']);
                Route::get('/{rec}/alternatives', [SavingsController::class, 'alternatives']);
                Route::get('/projected', [SavingsController::class, 'projected']);
                Route::get('/tracking', [SavingsController::class, 'savingsHistory']);
                Route::post('/target', [SavingsController::class, 'setTarget']);
                Route::get('/target', [SavingsController::class, 'getTarget']);
                Route::post('/target/regenerate', [SavingsController::class, 'regeneratePlan'])
                    ->middleware('throttle:5,1');
                Route::post('/plan/{action}/respond', [SavingsController::class, 'respondToAction']);
                Route::get('/pulse', [SavingsController::class, 'pulseCheck'])
                    ->middleware('throttle:10,1');
            });

            // Order Items
            Route::patch('/order-items/{item}/expense-type', [OrderItemController::class, 'updateExpenseType']);

            // Tax
            Route::prefix('tax')->middleware('profile.complete')->group(function () {
                Route::get('/summary', [TaxController::class, 'summary']);
                Route::post('/export', [TaxController::class, 'export'])
                    ->middleware('throttle:10,1');
                Route::post('/send-to-accountant', [TaxController::class, 'sendToAccountant'])
                    ->middleware('throttle:5,1');
                Route::get('/download/{year}/{type}', [TaxController::class, 'download'])
                    ->name('tax.download');
            });
        });

        // Reconciliation Candidates
        Route::prefix('reconciliation')->group(function () {
            Route::get('/candidates', [ReconciliationController::class, 'candidates']);
            Route::post('/candidates/{candidate}/confirm', [ReconciliationController::class, 'confirm']);
            Route::post('/candidates/{candidate}/reject', [ReconciliationController::class, 'reject']);
        });

        // Email Connections
        Route::prefix('email')->group(function () {
            Route::get('/connections', [EmailConnectionController::class, 'index']);
            Route::post('/connect/{provider}', [EmailConnectionController::class, 'connect']);
            Route::post('/connect-imap', [EmailConnectionController::class, 'connectImap']);
            Route::post('/test', [EmailConnectionController::class, 'testConnection']);
            Route::post('/setup-instructions', [EmailConnectionController::class, 'setupInstructions']);
            Route::get('/callback/{provider}', [EmailConnectionController::class, 'callback']);
            Route::post('/sync', [EmailConnectionController::class, 'sync']);
            Route::delete('/{emailConnection}', [EmailConnectionController::class, 'disconnect']);
        });

        // User Profile
        Route::post('/profile/financial', [UserProfileController::class, 'updateFinancial']);
        Route::get('/profile/financial', [UserProfileController::class, 'showFinancial']);

        // Account Deletion (GDPR/CCPA compliance)
        Route::delete('/account', [UserProfileController::class, 'deleteAccount']);
    });

    // ─── Admin Routes ───
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/stats', [CancellationProviderController::class, 'stats']);
        Route::get('/providers', [CancellationProviderController::class, 'index']);
        Route::post('/providers', [CancellationProviderController::class, 'store']);
        Route::get('/providers/{provider}', [CancellationProviderController::class, 'show']);
        Route::patch('/providers/{provider}', [CancellationProviderController::class, 'update']);
        Route::delete('/providers/{provider}', [CancellationProviderController::class, 'destroy']);
        Route::post('/providers/bulk-import', [CancellationProviderController::class, 'bulkImport']);
        Route::post('/providers/{provider}/find-link', [CancellationProviderController::class, 'findCancellationLink']);
    });
});
