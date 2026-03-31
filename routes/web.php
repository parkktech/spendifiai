<?php

use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SeoPageController;
use App\Http\Controllers\SitemapController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Inertia routes for the SPA + browser redirects (OAuth, email verification).
|
*/

// ── Public Marketing Pages ──
Route::get('/', fn () => Inertia::render('Welcome'))->name('home');
Route::get('/features', fn () => Inertia::render('Features'))->name('features');
Route::get('/how-it-works', fn () => Inertia::render('HowItWorks'))->name('how-it-works');
Route::get('/about', fn () => Inertia::render('About'))->name('about');
Route::get('/faq', fn () => Inertia::render('FAQ'))->name('faq');
Route::get('/contact', fn () => Inertia::render('Contact'))->name('contact');

// ── Legal Pages (Plaid Required) ──
Route::get('/privacy', fn () => Inertia::render('Legal/PrivacyPolicy'))->name('privacy');
Route::get('/terms', fn () => Inertia::render('Legal/TermsOfService'))->name('terms');
Route::get('/data-retention', fn () => Inertia::render('Legal/DataRetention'))->name('data-retention');
Route::get('/security-policy', fn () => Inertia::render('Legal/Security'))->name('security-policy');

// ── Google OAuth Frontend Callback ──
Route::get('/auth/callback', fn () => Inertia::render('Auth/GoogleCallback'))->name('auth.callback');

// ── Firm Invite (public branded page) ──
Route::get('/invite/{token}', function (string $token) {
    $firm = \App\Models\AccountingFirm::where('invite_token', $token)->firstOrFail();

    return Inertia::render('Auth/FirmInvite', [
        'firm' => [
            'name' => $firm->name,
            'logo_url' => $firm->logo_url,
            'primary_color' => $firm->primary_color ?? '#0D9488',
        ],
        'token' => $token,
    ]);
})->name('firm.invite');

// ── Household Join Invitation ──
Route::get('/household/join/{token}', fn (string $token) => Inertia::render('Household/Join', ['token' => $token]))->name('household.join');

// ── Inertia SPA Pages (with Sanctum token auth for SPA) ──
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/onboarding', fn () => Inertia::render('Onboarding/Index'))->name('onboarding');
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
    Route::get('/transactions', fn () => Inertia::render('Transactions/Index'))->name('transactions');
    Route::get('/subscriptions', fn () => Inertia::render('Subscriptions/Index'))->name('subscriptions');
    Route::get('/savings', fn () => Inertia::render('Savings/Index'))->name('savings');
    Route::get('/tax', fn () => Inertia::render('Tax/Index'))->name('tax');
    Route::get('/connect', fn () => Inertia::render('Connect/Index'))->name('connect');
    Route::get('/settings', fn () => Inertia::render('Settings/Index'))->name('settings');
    Route::get('/questions', fn () => Inertia::render('Questions/Index'))->name('questions');

    // Tax Vault
    Route::get('/vault', fn () => Inertia::render('Vault/Index'))->name('vault');
    Route::get('/vault/documents/{document}', fn (\App\Models\TaxDocument $document) => Inertia::render('Vault/Show', [
        'documentId' => $document->id,
    ]))->name('vault.document');

    // Accountant pages
    Route::get('/accountant/clients', fn () => Inertia::render('Accountant/Clients'))->name('accountant.clients');
    Route::get('/accountant/dashboard', fn () => Inertia::render('Accountant/Dashboard'))->name('accountant.dashboard');

    // Admin pages
    Route::middleware('admin')->group(function () {
        Route::get('/admin', fn () => Inertia::render('Admin/Dashboard'))->name('admin.dashboard');
        Route::get('/admin/providers', fn () => Inertia::render('Admin/Providers/Index'))->name('admin.providers');
        Route::get('/admin/providers/create', fn () => Inertia::render('Admin/Providers/Create'))->name('admin.providers.create');
        Route::get('/admin/providers/{provider}/edit', fn ($provider) => Inertia::render('Admin/Providers/Edit', ['provider' => $provider]))->name('admin.providers.edit');
        Route::get('/admin/consent', fn () => Inertia::render('Admin/Consent'))->name('admin.consent');
        Route::get('/admin/charities', fn () => Inertia::render('Admin/Charities/Index'))->name('admin.charities');
        Route::get('/admin/charities/create', fn () => Inertia::render('Admin/Charities/Create'))->name('admin.charities.create');
        Route::get('/admin/charities/{charity}/edit', fn ($charity) => Inertia::render('Admin/Charities/Edit', ['charity' => $charity]))->name('admin.charities.edit');
        Route::get('/admin/storage', fn () => Inertia::render('Admin/Storage'))->name('admin.storage');
    });
});

// ── Profile Management (Breeze) ──
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ── Email Verification (from email link) ──
Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
    $user = \App\Models\User::find($id);

    if (! $user) {
        return redirect('/login')->with('error', 'Invalid verification link');
    }

    // Verify the hash signature
    if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
        return redirect('/login')->with('error', 'Invalid verification link');
    }

    // Mark email as verified
    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
    }

    // Check if user is already logged in
    if ($request->user()) {
        // User is already authenticated — refresh their session
        Auth::login($user, true);

        return redirect('/dashboard');
    }

    // User is not logged in — create token and store it as a cookie
    $token = $user->createToken('spendifiai')->plainTextToken;

    // Redirect to dashboard with token stored in cookie
    return redirect('/dashboard')
        ->cookie('auth_token', $token, 43200, '/', null, true, false); // 30 days, secure (HTTPS), not httpOnly so JS can read it
})->name('verification.verify');

// ── Google OAuth Callback (browser redirect from Google) ──
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);

// ── Blog / SEO Content Pages ──
Route::get('/blog', [SeoPageController::class, 'index'])->name('blog.index');
Route::get('/blog/{category}', [SeoPageController::class, 'category'])
    ->where('category', 'comparison|alternative|guide|tax|industry|feature')
    ->name('blog.category');
Route::get('/blog/{slug}', [SeoPageController::class, 'show'])
    ->where('slug', '[a-z0-9\-]+')
    ->name('blog.show');

// ── Sitemap ──
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// ── Health Check ──
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'spendifiai',
    'time' => now()->toIso8601String(),
]));

// ── Breeze Auth Routes (login, register, forgot-password, etc.) ──
require __DIR__.'/auth.php';
