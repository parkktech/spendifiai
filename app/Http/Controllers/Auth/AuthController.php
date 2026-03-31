<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Jobs\SyncBankTransactions;
use App\Models\BankConnection;
use App\Models\User;
use App\Services\CookieConsentService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user.
     *
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'timezone' => $request->timezone ?? 'America/New_York',
            'user_type' => $request->input('user_type', 'personal'),
            'company_name' => $request->input('company_name'),
        ]);

        event(new Registered($user));

        $token = $user->createToken('spendifiai')->plainTextToken;

        return response()->json([
            'message' => 'Account created. Please verify your email.',
            'user' => $this->userPayload($user),
            'token' => $token,
        ], 201);
    }

    /**
     * Login with email + password. Handles 2FA if enabled.
     *
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        // Check account lockout
        if ($user?->locked_until && $user->locked_until->isFuture()) {
            $minutes = now()->diffInMinutes($user->locked_until);
            throw ValidationException::withMessages([
                'email' => ["Account locked. Try again in {$minutes} minutes."],
            ]);
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Increment failed attempts
            if ($user) {
                $user->increment('failed_login_attempts');
                if ($user->failed_login_attempts >= 5) {
                    $user->update(['locked_until' => now()->addMinutes(15)]);
                }
            }
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // 2FA check: if user has 2FA enabled, require the code
        if ($user->hasTwoFactorEnabled()) {
            if (! $request->filled('two_factor_code')) {
                return response()->json([
                    'two_factor_required' => true,
                    'message' => 'Please enter your two-factor authentication code.',
                ], 200);
            }

            $valid = $this->verifyTwoFactorCode($user, $request->two_factor_code);
            if (! $valid) {
                throw ValidationException::withMessages([
                    'two_factor_code' => ['Invalid two-factor authentication code.'],
                ]);
            }
        }

        // Success — reset failed attempts
        $syncTriggered = false;
        $updateData = [
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ];

        // Trigger immediate sync for returning users
        if ($user->isReturningUser() && $user->hasBankConnected()) {
            BankConnection::where('user_id', $user->id)
                ->where('status', 'active')
                ->each(fn ($conn) => SyncBankTransactions::dispatch($conn));
            $syncTriggered = true;
        }

        $updateData['last_active_at'] = now();
        $user->update($updateData);

        // Prune old tokens — keep up to 4 recent sessions so multiple tabs/devices work
        $tokenIds = $user->tokens()->orderByDesc('created_at')->pluck('id');
        if ($tokenIds->count() >= 5) {
            $keep = $tokenIds->take(4);
            $user->tokens()->whereNotIn('id', $keep)->delete();
        }

        $token = $user->createToken('spendifiai')->plainTextToken;

        // Link anonymous consent records to this user
        $visitorId = $request->cookie(config('spendifiai.consent.visitor_cookie', 'sw_visitor_id'));
        if ($visitorId) {
            app(CookieConsentService::class)->linkVisitorToUser($visitorId, $user->id);
        }

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => $this->userPayload($user),
            'token' => $token,
            'sync_triggered' => $syncTriggered,
        ]);
    }

    /**
     * Logout — revoke current token.
     *
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Get current authenticated user.
     *
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('financialProfile');

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Update user type (for Google OAuth users who selected accountant before redirect).
     *
     * PATCH /api/auth/user-type
     */
    public function updateUserType(Request $request): JsonResponse
    {
        $request->validate([
            'user_type' => 'required|string|in:personal,accountant',
            'company_name' => 'nullable|string|max:255',
        ]);

        $request->user()->update([
            'user_type' => $request->input('user_type'),
            'company_name' => $request->input('company_name'),
        ]);

        return response()->json(['message' => 'User type updated.']);
    }

    // ─── Helpers ───

    protected function verifyTwoFactorCode(User $user, string $code): bool
    {
        // Check TOTP code
        $google2fa = app(\PragmaRX\Google2FALaravel\Google2FA::class);
        $secret = $user->two_factor_secret;  // Model cast auto-decrypts

        if ($google2fa->verifyKey($secret, $code)) {
            return true;
        }

        // Check recovery codes
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];  // encrypted:array cast returns array
        if (in_array($code, $recoveryCodes)) {
            // Remove used recovery code
            $remaining = array_values(array_diff($recoveryCodes, [$code]));
            $user->update([
                'two_factor_recovery_codes' => $remaining,  // Model cast auto-encrypts + JSON encodes
            ]);

            return true;
        }

        return false;
    }

    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'email_verified' => ! is_null($user->email_verified_at),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'is_google_user' => $user->isGoogleUser(),
            'has_bank_connected' => $user->hasBankConnected(),
            'has_profile_complete' => $user->hasProfileComplete(),
            'timezone' => $user->timezone,
            'user_type' => $user->user_type?->value ?? 'personal',
            'company_name' => $user->company_name,
            'is_accountant' => $user->isAccountant(),
            'created_at' => $user->created_at->toIso8601String(),
        ];
    }
}
