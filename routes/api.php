<?php

use App\Http\Controllers\Admin\CancellationProviderController;
use App\Http\Controllers\Admin\CharitableOrganizationController;
use App\Http\Controllers\Admin\ConsentAdminController;
use App\Http\Controllers\Api\AccountantController;
use App\Http\Controllers\Api\AccountantTaxController;
use App\Http\Controllers\Api\AIQuestionController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\CookieConsentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailConnectionController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\OrderItemController;
use App\Http\Controllers\Api\PlaidController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\SavingsController;
use App\Http\Controllers\Api\StatementUploadController;
use App\Http\Controllers\Api\StorageConfigController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TaxController;
use App\Http\Controllers\Api\TaxDocumentController;
use App\Http\Controllers\Api\TaxVaultAuditController;
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
// COOKIE CONSENT (public, no auth required)
// ══════════════════════════════════════════════════════════

Route::prefix('v1/consent')->group(function () {
    Route::get('/config', [CookieConsentController::class, 'config'])
        ->middleware('throttle:60,1');
    Route::post('/', [CookieConsentController::class, 'store'])
        ->middleware('throttle:30,1');
});

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
        Route::patch('/user-type', [AuthController::class, 'updateUserType']);

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

        // Onboarding
        Route::post('/onboarding/start', [OnboardingController::class, 'start']);

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
            Route::delete('/{connection}', [PlaidController::class, 'disconnect']);

            // Plaid Statements
            Route::post('/{connection}/statements/link-token', [PlaidController::class, 'statementsLinkToken']);
            Route::post('/{connection}/statements/refresh', [PlaidController::class, 'refreshStatements'])
                ->middleware('throttle:5,1');
            Route::get('/{connection}/statements', [PlaidController::class, 'listStatements']);
        });

        // Bank Accounts
        Route::get('/accounts', [BankAccountController::class, 'index']);
        Route::patch('/accounts/{account}/purpose', [BankAccountController::class, 'updatePurpose']);

        // Statement Uploads (no bank.connected requirement)
        Route::prefix('statements')->group(function () {
            Route::post('/upload', [StatementUploadController::class, 'upload']);
            Route::get('/pending', [StatementUploadController::class, 'pending']);
            Route::get('/{upload}/status', [StatementUploadController::class, 'status']);
            Route::post('/batch-status', [StatementUploadController::class, 'batchStatus']);
            Route::post('/batch-transactions', [StatementUploadController::class, 'batchTransactions']);
            Route::post('/import', [StatementUploadController::class, 'import']);
            Route::get('/history', [StatementUploadController::class, 'history']);
            Route::get('/gaps', [StatementUploadController::class, 'gaps']);
            Route::post('/gaps/dismiss', [StatementUploadController::class, 'dismissGap']);
        });

        // ── Routes requiring a linked bank account ──
        Route::middleware('bank.connected')->group(function () {

            // AI Questions
            Route::get('/questions', [AIQuestionController::class, 'index']);
            Route::post('/questions/{question}/answer', [AIQuestionController::class, 'answer']);
            Route::post('/questions/{question}/chat', [AIQuestionController::class, 'chat']);
            Route::post('/questions/{question}/search-emails', [AIQuestionController::class, 'searchEmails']);
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

            // Tax Deduction Finder (no profile.complete requirement)
            Route::prefix('tax/deductions')->group(function () {
                Route::get('/', [TaxController::class, 'deductions']);
                Route::post('/scan', [TaxController::class, 'scanDeductions'])
                    ->middleware('throttle:5,1');
                Route::post('/{deduction}/answer', [TaxController::class, 'answerDeductionQuestion']);
                Route::post('/{deduction}/claim', [TaxController::class, 'claimDeduction']);
                Route::get('/questionnaire', [TaxController::class, 'questionnaireGet']);
                Route::post('/questionnaire', [TaxController::class, 'questionnaireSubmit']);
            });
        });

        // Tax Vault
        Route::prefix('tax-vault')->group(function () {
            Route::get('/documents', [TaxDocumentController::class, 'index']);
            Route::post('/documents', [TaxDocumentController::class, 'store']);
            Route::get('/documents/{document}', [TaxDocumentController::class, 'show']);
            Route::delete('/documents/{document}', [TaxDocumentController::class, 'destroy']);
            Route::get('/documents/{document}/download', [TaxDocumentController::class, 'download'])->name('tax-vault.download');
            Route::patch('/documents/{document}/fields', [TaxDocumentController::class, 'updateField']);
            Route::post('/documents/{document}/accept-all', [TaxDocumentController::class, 'acceptAll']);
            Route::post('/documents/{document}/retry-extraction', [TaxDocumentController::class, 'retryExtraction']);
            Route::get('/documents/{document}/audit-log', [TaxVaultAuditController::class, 'index']);
            Route::get('/documents/{document}/audit-log/verify', [TaxVaultAuditController::class, 'verifyChain']);
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
            Route::post('/connect/{emailProvider}', [EmailConnectionController::class, 'connect']);
            Route::post('/connect-imap', [EmailConnectionController::class, 'connectImap']);
            Route::post('/test', [EmailConnectionController::class, 'testConnection']);
            Route::post('/setup-instructions', [EmailConnectionController::class, 'setupInstructions']);
            Route::get('/callback/{emailProvider}', [EmailConnectionController::class, 'callback']);
            Route::post('/sync', [EmailConnectionController::class, 'sync']);
            Route::delete('/{emailConnection}', [EmailConnectionController::class, 'disconnect']);
        });

        // User Profile
        Route::post('/profile/financial', [UserProfileController::class, 'updateFinancial']);
        Route::get('/profile/financial', [UserProfileController::class, 'showFinancial']);
        Route::patch('/profile/timezone', [UserProfileController::class, 'updateTimezone']);

        // Account Deletion (GDPR/CCPA compliance)
        Route::delete('/account', [UserProfileController::class, 'deleteAccount']);

        // Cookie Consent Preferences (authenticated)
        Route::prefix('consent')->group(function () {
            Route::get('/preferences', [CookieConsentController::class, 'preferences']);
            Route::put('/preferences', [CookieConsentController::class, 'updatePreferences']);
            Route::delete('/preferences', [CookieConsentController::class, 'revokeConsent']);
        });
    });

    // ─── Accountant Routes (any authenticated user) ───
    Route::prefix('v1/accountant')->middleware('throttle:120,1')->group(function () {
        Route::get('/search', [AccountantController::class, 'searchAccountants']);
        Route::post('/add', [AccountantController::class, 'addAccountant']);
        Route::delete('/{accountant}', [AccountantController::class, 'removeAccountant']);
        Route::get('/my-accountants', [AccountantController::class, 'myAccountants']);
        Route::get('/invites', [AccountantController::class, 'pendingInvites']);
        Route::post('/invites/{invite}/respond', [AccountantController::class, 'respondToInvite']);
    });

    // ─── Accountant-Only Routes ───
    Route::prefix('v1/accountant')->middleware(['throttle:120,1', 'accountant'])->group(function () {
        Route::get('/clients', [AccountantController::class, 'clients']);
        Route::get('/clients/{client}/summary', [AccountantController::class, 'clientSummary']);
        Route::post('/clients/invite', [AccountantController::class, 'inviteClient']);
        Route::delete('/clients/{client}', [AccountantController::class, 'removeClient']);
        Route::post('/clients/{client}/resend', [AccountantController::class, 'resendInvite']);
        Route::get('/clients/{client}/tax/{year}', [AccountantTaxController::class, 'clientTaxSummary']);
        Route::get('/clients/{client}/tax/{year}/download/{type}', [AccountantTaxController::class, 'downloadClientTax']);
        Route::post('/clients/{client}/refresh', [AccountantTaxController::class, 'refreshClientData']);
        Route::post('/impersonate/stop', [ImpersonationController::class, 'stop']);
        Route::get('/impersonate/status', [ImpersonationController::class, 'status']);
        Route::post('/impersonate/{client}', [ImpersonationController::class, 'start']);
        Route::get('/activity', [AccountantController::class, 'activityLog']);
    });

    // ─── Admin Routes ───
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/stats', [CancellationProviderController::class, 'stats']);

        // User stats
        Route::get('/user-stats', function () {
            $totalUsers = \App\Models\User::count();
            $verifiedUsers = \App\Models\User::whereNotNull('email_verified_at')->count();
            $withBank = \App\Models\User::whereHas('bankConnections')->count();
            $recentlyActive = \App\Models\User::whereNotNull('last_active_at')
                ->where('last_active_at', '>=', now()->subDays(7))
                ->count();

            $mostActive = \App\Models\User::whereNotNull('last_active_at')
                ->orderByDesc('last_active_at')
                ->limit(10)
                ->get(['id', 'name', 'email', 'last_active_at', 'created_at'])
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'last_active_at' => $u->last_active_at?->toIso8601String(),
                    'created_at' => $u->created_at->toIso8601String(),
                ]);

            return response()->json([
                'total_users' => $totalUsers,
                'verified_users' => $verifiedUsers,
                'with_bank' => $withBank,
                'recently_active' => $recentlyActive,
                'most_active' => $mostActive,
            ]);
        });
        Route::get('/providers', [CancellationProviderController::class, 'index']);
        Route::post('/providers', [CancellationProviderController::class, 'store']);
        Route::get('/providers/{provider}', [CancellationProviderController::class, 'show']);
        Route::patch('/providers/{provider}', [CancellationProviderController::class, 'update']);
        Route::delete('/providers/{provider}', [CancellationProviderController::class, 'destroy']);
        Route::post('/providers/bulk-import', [CancellationProviderController::class, 'bulkImport']);
        Route::post('/providers/{provider}/find-link', [CancellationProviderController::class, 'findCancellationLink']);

        // Consent Management
        Route::prefix('consent')->group(function () {
            Route::get('/stats', [ConsentAdminController::class, 'stats']);
            Route::get('/search', [ConsentAdminController::class, 'search']);
            Route::get('/user/{user}/history', [ConsentAdminController::class, 'userHistory']);
            Route::post('/user/{user}/revoke', [ConsentAdminController::class, 'revokeUserConsent']);
            Route::delete('/user/{user}/cookies', [ConsentAdminController::class, 'deleteCookieData']);
        });

        // Storage Config
        Route::get('/storage', [StorageConfigController::class, 'show']);
        Route::put('/storage', [StorageConfigController::class, 'update']);
        Route::post('/storage/test', [StorageConfigController::class, 'testConnection']);
        Route::post('/storage/migrate', [StorageConfigController::class, 'migrate']);
        Route::get('/storage/migration-status', [StorageConfigController::class, 'migrationStatus']);

        // Document Purge (admin hard delete)
        Route::delete('/documents/{document}/purge', [TaxDocumentController::class, 'purge'])->name('admin.documents.purge');

        // Charitable Organizations
        Route::get('/charities/stats', [CharitableOrganizationController::class, 'stats']);
        Route::get('/charities', [CharitableOrganizationController::class, 'index']);
        Route::post('/charities', [CharitableOrganizationController::class, 'store']);
        Route::get('/charities/{charity}', [CharitableOrganizationController::class, 'show']);
        Route::patch('/charities/{charity}', [CharitableOrganizationController::class, 'update']);
        Route::delete('/charities/{charity}', [CharitableOrganizationController::class, 'destroy']);
    });
});
