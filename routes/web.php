<?php

use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
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

// ── Inertia Landing Page ──
Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// ── Inertia Dashboard ──
Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// ── Profile Management (Breeze) ──
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ── Google OAuth Callback (browser redirect from Google) ──
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);

// ── Health Check ──
Route::get('/health', fn() => response()->json([
    'status'  => 'ok',
    'service' => 'spendwise',
    'time'    => now()->toIso8601String(),
]));

// ── Breeze Auth Routes (login, register, forgot-password, etc.) ──
require __DIR__.'/auth.php';
