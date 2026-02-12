<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redirect to Google OAuth consent screen.
     *
     * GET /auth/google/redirect
     */
    public function redirectToGoogle(): RedirectResponse|JsonResponse
    {
        // For SPA: return the redirect URL as JSON
        if (request()->wantsJson()) {
            return response()->json([
                'url' => Socialite::driver('google')
                    ->scopes(['openid', 'email', 'profile'])
                    ->stateless()
                    ->redirect()
                    ->getTargetUrl(),
            ]);
        }

        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->redirect();
    }

    /**
     * Handle Google OAuth callback.
     *
     * GET /auth/google/callback
     */
    public function handleGoogleCallback(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            return $this->errorResponse('Google authentication failed. Please try again.');
        }

        if (! $googleUser->getEmail()) {
            return $this->errorResponse('Could not retrieve email from Google.');
        }

        // Find existing user by google_id or email
        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        $isNewUser = false;

        if ($user) {
            // Existing user: link Google account if not already linked
            $user->update([
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
                // Don't overwrite name if user already has one
                'name' => $user->name ?: $googleUser->getName(),
            ]);

            // Auto-verify email if Google confirms it
            if (is_null($user->email_verified_at)) {
                $user->update(['email_verified_at' => now()]);
            }
        } else {
            // New user: create account
            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
                'password' => Hash::make(Str::random(32)), // Random pw, login via Google only
                'email_verified_at' => now(), // Google-verified email
            ]);

            event(new Registered($user));
            $isNewUser = true;
        }

        // Generate API token
        $user->tokens()->delete();
        $token = $user->createToken('ledgeriq-google')->plainTextToken;

        // For SPA: redirect to frontend with token
        $frontendUrl = config('app.frontend_url', config('app.url'));

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $isNewUser ? 'Account created via Google.' : 'Logged in via Google.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'email_verified' => true,
                    'is_google_user' => true,
                    'has_bank_connected' => $user->hasBankConnected(),
                    'has_profile_complete' => $user->hasProfileComplete(),
                    'is_new_user' => $isNewUser,
                ],
                'token' => $token,
            ]);
        }

        // Redirect to SPA frontend with token in URL fragment (NOT query param).
        // Fragments (#) are never sent to the server, so the token won't appear
        // in access logs, Referrer headers, or browser history synced to servers.
        // The frontend reads it via window.location.hash and then clears the URL.
        return redirect("{$frontendUrl}/auth/callback#token={$token}&new={$isNewUser}");
    }

    /**
     * Disconnect Google account (user keeps password-based login).
     *
     * POST /api/auth/google/disconnect
     */
    public function disconnectGoogle(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only allow disconnect if user has a password set
        if (! $user->password || $user->password === '') {
            return response()->json([
                'message' => 'Please set a password before disconnecting Google. Your account currently relies on Google for login.',
            ], 422);
        }

        $user->update([
            'google_id' => null,
            'avatar_url' => null,
        ]);

        return response()->json(['message' => 'Google account disconnected.']);
    }

    protected function errorResponse(string $message): JsonResponse|RedirectResponse
    {
        if (request()->wantsJson()) {
            return response()->json(['error' => $message], 422);
        }

        $frontendUrl = config('app.frontend_url', config('app.url'));

        return redirect("{$frontendUrl}/auth/error?message=".urlencode($message));
    }
}
