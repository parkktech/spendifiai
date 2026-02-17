<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Verify email from the link sent.
     *
     * GET /email/verify/{id}/{hash}
     */
    public function verify(EmailVerificationRequest $request): JsonResponse
    {
        // EmailVerificationRequest extracts user from signed {id}/{hash} URL params
        $user = \App\Models\User::findOrFail($request->route('id'));

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
                'token' => $user->tokens()->latest()->first()?->plainTextToken,
            ]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        // Create auth token so user is logged in after verification
        $token = $user->createToken('spendifiai')->plainTextToken;

        return response()->json([
            'message' => 'Email verified successfully.',
            'token' => $token,
        ]);
    }

    /**
     * Resend verification email.
     *
     * POST /api/auth/email/resend
     */
    public function resend(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent.']);
    }
}
