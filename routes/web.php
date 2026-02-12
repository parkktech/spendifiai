<?php

use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\ProfileController;
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

// ── Inertia SPA Pages ──
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
    Route::get('/transactions', fn () => Inertia::render('Transactions/Index'))->name('transactions');
    Route::get('/subscriptions', fn () => Inertia::render('Subscriptions/Index'))->name('subscriptions');
    Route::get('/savings', fn () => Inertia::render('Savings/Index'))->name('savings');
    Route::get('/tax', fn () => Inertia::render('Tax/Index'))->name('tax');
    Route::get('/connect', fn () => Inertia::render('Connect/Index'))->name('connect');
    Route::get('/settings', fn () => Inertia::render('Settings/Index'))->name('settings');
    Route::get('/questions', fn () => Inertia::render('Questions/Index'))->name('questions');
});

// ── Profile Management (Breeze) ──
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ── Google OAuth Callback (browser redirect from Google) ──
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);

// ── Health Check ──
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'ledgeriq',
    'time' => now()->toIso8601String(),
]));

// ── Breeze Auth Routes (login, register, forgot-password, etc.) ──
require __DIR__.'/auth.php';
