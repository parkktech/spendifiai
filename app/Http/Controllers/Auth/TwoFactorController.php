<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function __construct(protected Google2FA $google2fa) {}

    /**
     * Get 2FA status for current user.
     *
     * GET /api/auth/two-factor/status
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => $user->hasTwoFactorEnabled(),
            'confirmed' => ! is_null($user->two_factor_confirmed_at),
            'recovery_codes_remaining' => $user->two_factor_recovery_codes
                ? count($user->two_factor_recovery_codes)  // encrypted:array cast returns array directly
                : 0,
        ]);
    }

    /**
     * Enable 2FA: generate secret + QR code.
     * User must confirm with a valid TOTP code before it activates.
     *
     * POST /api/auth/two-factor/enable
     */
    public function enable(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|current_password']);

        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is already enabled.'], 422);
        }

        // Generate secret
        $secret = $this->google2fa->generateSecretKey(32);

        // Store encrypted (not yet confirmed)
        $user->update([
            'two_factor_secret' => $secret,  // Model cast auto-encrypts
            'two_factor_confirmed_at' => null, // Not active until confirmed
        ]);

        // Generate QR code
        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('spendifiai.two_factor.issuer', 'SpendifiAI'),
            $user->email,
            $secret
        );

        $renderer = new ImageRenderer(
            new RendererStyle(300),
            new SvgImageBackEnd
        );
        $writer = new Writer($renderer);
        $qrCodeSvg = $writer->writeString($qrCodeUrl);

        return response()->json([
            'message' => 'Scan the QR code with your authenticator app, then confirm with a code.',
            'secret' => $secret, // Show for manual entry
            'qr_code' => 'data:image/svg+xml;base64,'.base64_encode($qrCodeSvg),
            'setup_url' => $qrCodeUrl,
        ]);
    }

    /**
     * Confirm 2FA setup with a valid TOTP code from the authenticator app.
     *
     * POST /api/auth/two-factor/confirm
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|size:6']);

        $user = $request->user();
        $secret = $user->two_factor_secret;  // Model cast auto-decrypts

        if (! $this->google2fa->verifyKey($secret, $request->code)) {
            return response()->json(['message' => 'Invalid code. Please try again.'], 422);
        }

        // Generate recovery codes
        $recoveryCodes = collect(range(1, config('spendifiai.two_factor.recovery_codes', 8)))
            ->map(fn () => Str::random(10).'-'.Str::random(10))
            ->toArray();

        $user->update([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,  // Model encrypted:array cast handles it
        ]);

        return response()->json([
            'message' => 'Two-factor authentication enabled successfully.',
            'recovery_codes' => $recoveryCodes,
            'warning' => 'Save these recovery codes in a safe place. Each can only be used once.',
        ]);
    }

    /**
     * Disable 2FA.
     *
     * POST /api/auth/two-factor/disable
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|current_password']);

        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is not enabled.'], 422);
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    /**
     * Regenerate recovery codes.
     *
     * POST /api/auth/two-factor/recovery-codes
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|current_password']);

        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is not enabled.'], 422);
        }

        $recoveryCodes = collect(range(1, config('spendifiai.two_factor.recovery_codes', 8)))
            ->map(fn () => Str::random(10).'-'.Str::random(10))
            ->toArray();

        $user->update([
            'two_factor_recovery_codes' => $recoveryCodes,  // Model encrypted:array cast handles it
        ]);

        return response()->json([
            'message' => 'Recovery codes regenerated. Previous codes are now invalid.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }
}
