# Phase 2: Auth & Bank Integration - Research

**Researched:** 2026-02-10
**Domain:** Laravel Authentication (Sanctum/Fortify/Socialite/2FA), Plaid Bank Integration, Plaid Webhooks
**Confidence:** HIGH

## Summary

Phase 2 wires up the authentication system, Plaid bank integration, webhook handling, and user profile management. The good news is that approximately 90% of the code already exists from Phase 1's integration of existing code. All 5 auth controllers, the PlaidController, BankAccountController, UserProfileController, PlaidService, CaptchaService, middleware, models, enums, policies, form requests, and routes are already in place and appear functionally complete.

The primary work in this phase is **verification, gap-filling, and building the one missing piece: the Plaid webhook handler**. The auth system needs testing and several bug fixes (User model `$fillable` gaps, BankConnection model naming mismatch). The Plaid integration needs the webhook controller built from scratch, including JWT signature verification using `firebase/php-jwt`. The user profile management needs the `deleteAccount` method enhanced with proper cascading and Plaid token revocation.

**Primary recommendation:** Focus effort on (1) fixing identified bugs in existing code, (2) building the PlaidWebhookController with proper JWT verification, and (3) end-to-end manual verification of all auth and Plaid flows. Most code exists -- this phase is about making it production-correct.

## Standard Stack

### Core (Already Installed)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/sanctum | ^4.0 | API token auth + SPA cookie auth | Official Laravel auth for SPAs |
| laravel/fortify | ^1.24 | 2FA backend, password features | Official headless auth backend |
| laravel/socialite | ^5.16 | Google OAuth | Official social auth |
| pragmarx/google2fa-laravel | ^2.2 | TOTP code generation/verification | Most popular PHP TOTP library |
| bacon/bacon-qr-code | ^3.0 | QR code generation for 2FA setup | Standard QR generator for PHP |

### Needed for Webhooks (Not Yet Installed)
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| firebase/php-jwt | ^6.10 | JWT decode + ES256 verification | Plaid webhook signature verification |

### Already Available (No Additional Install)
| Library | Purpose | Notes |
|---------|---------|-------|
| guzzlehttp/guzzle | HTTP client for Plaid API | Already a Laravel dependency |
| predis/predis | Redis client for queues | Already installed in Phase 1 |

**Installation (webhook support only):**
```bash
composer require firebase/php-jwt
```

## Architecture Patterns

### Existing Controller Structure (from Phase 1)
```
app/Http/Controllers/
├── Auth/
│   ├── AuthController.php           # register, login, logout, me (EXISTS)
│   ├── SocialAuthController.php     # Google OAuth redirect + callback (EXISTS)
│   ├── TwoFactorController.php      # 2FA enable/confirm/disable/recovery (EXISTS)
│   ├── PasswordResetController.php  # forgot/reset/change password (EXISTS)
│   └── EmailVerificationController.php  # verify + resend (EXISTS)
├── Api/
│   ├── PlaidController.php          # link-token, exchange, sync, disconnect (EXISTS)
│   ├── BankAccountController.php    # index, updatePurpose (EXISTS)
│   ├── UserProfileController.php    # financial profile + delete account (EXISTS)
│   └── PlaidWebhookController.php   # MUST BE CREATED - webhook handler
├── ProfileController.php            # Breeze profile controller (EXISTS)
```

### Webhook Handler Pattern
```
PlaidWebhookController
├── handle()           # Main entry: verify JWT, route by webhook_type/webhook_code
├── verifySignature()  # JWT verification using firebase/php-jwt
├── handleTransactionSync()        # SYNC_UPDATES_AVAILABLE
├── handleTransactionsRemoved()    # TRANSACTIONS_REMOVED (handled by sync)
├── handleItemError()              # ERROR (ITEM_LOGIN_REQUIRED)
├── handlePendingExpiration()      # PENDING_EXPIRATION / PENDING_DISCONNECT
├── handleUserPermissionRevoked()  # USER_PERMISSION_REVOKED
└── logWebhook()                   # Idempotency tracking + audit log
```

### Authentication Flow Pattern (Already Implemented)
```
Register → Email Verification → Login (+ optional 2FA) → Sanctum Token → API Access
Google OAuth → Callback → Token via URL Fragment (#token=xxx) → Frontend stores token
```

### Plaid Integration Flow (Already Implemented)
```
createLinkToken() → Frontend Plaid Link → exchangeToken() → syncTransactions() →
  (webhook: SYNC_UPDATES_AVAILABLE → automatic re-sync)
```

### Anti-Patterns to Avoid
- **Do NOT create a SyncBankTransactions job yet** -- that is Phase 4/6 work. For now, webhook-triggered syncs can call PlaidService directly or dispatch the existing CategorizePendingTransactions job.
- **Do NOT build frontend pages** -- this phase is backend API only. Frontend is Phase 4.
- **Do NOT create Events/Listeners** -- that is Phase 4. Webhook handlers should directly call services.
- **Do NOT refactor the auth system to remove Breeze/Fortify routes** -- the dual route system (Breeze web routes + custom API routes) is intentional and coexists fine.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| JWT ES256 verification | Custom JWT parser | `firebase/php-jwt` with `JWK::parseKey()` | ES256 cryptography is error-prone |
| TOTP codes | Custom TOTP implementation | `pragmarx/google2fa-laravel` | Already integrated, handles timing attacks |
| QR code generation | Custom QR rendering | `bacon/bacon-qr-code` SvgImageBackEnd | Already works in TwoFactorController |
| OAuth flow | Custom OAuth HTTP calls | `laravel/socialite` | Handles state, CSRF, token refresh |
| Password hashing | Manual bcrypt calls | Laravel `'hashed'` cast on User model | Already configured |
| Encryption | Manual `encrypt()` calls | Model `'encrypted'` cast | Already configured on all sensitive fields |
| CSRF for webhooks | Manual CSRF handling | `bootstrap/app.php` already excludes `api/*` and `webhooks/*` | Already configured |

**Key insight:** Almost everything for auth and Plaid integration is already built. The risk is in missed bugs and the one new piece (webhooks), not in missing features.

## Common Pitfalls

### Pitfall 1: User Model $fillable Missing Lockout Fields
**What goes wrong:** The AuthController calls `$user->increment('failed_login_attempts')` and `$user->update(['locked_until' => ...])`, but the User model's `$fillable` does not include `failed_login_attempts` or `locked_until`. Eloquent's `increment()` works without `$fillable`, but `update(['locked_until' => ...])` will silently fail to save.
**Why it happens:** These fields were added in migration 000004 but never added to the model's `$fillable`.
**How to avoid:** Add `'failed_login_attempts'` and `'locked_until'` to User model's `$fillable` array. Add `'locked_until' => 'datetime'` to the `casts()` method.
**Warning signs:** Account lockout never triggers even after 5 failed attempts.

### Pitfall 2: BankConnection Model sync_cursor vs plaid_cursor Naming Mismatch
**What goes wrong:** The database migration creates a column called `sync_cursor`. The PlaidService correctly uses `$connection->sync_cursor`. But the BankConnection model's `$fillable` and `$hidden` arrays reference `plaid_cursor` instead of `sync_cursor`. This means the cursor won't be saved properly after sync operations.
**Why it happens:** Naming inconsistency between the migration and the model code.
**How to avoid:** Change `plaid_cursor` to `sync_cursor` in BankConnection's `$fillable` and `$hidden` arrays.
**Warning signs:** Every sync fetches ALL transactions instead of just new ones; performance degrades over time.

### Pitfall 3: Fortify Route Conflicts
**What goes wrong:** Even though `Fortify::$registersRoutes = false` is set in FortifyServiceProvider, Fortify 2FA routes still appear (the `user/two-factor-*` routes). This is because Fortify's `TwoFactorAuthenticationProvider` registers these routes separately.
**Why it happens:** Fortify's 2FA feature has its own route registration that is independent of `$registersRoutes`. The custom TwoFactorController (at `api/auth/two-factor/*`) handles the same functionality.
**How to avoid:** This is a known coexistence situation. The custom API routes work correctly for the SPA. The Fortify `user/*` routes are web middleware routes and will not conflict with the API routes. Both can coexist safely. Do NOT try to remove Fortify -- its `TwoFactorAuthenticatable` trait is used by the User model.
**Warning signs:** None expected -- both route sets coexist.

### Pitfall 4: Google OAuth Callback URL Configuration
**What goes wrong:** The Google OAuth callback is at `/auth/google/callback` (web route, not API route). This is correct because Google redirects the browser. But the `GOOGLE_REDIRECT_URI` in `.env.example` uses `${APP_URL}/auth/google/callback`. If `APP_URL` is wrong (e.g., `http://localhost` when running on port 8000), the OAuth flow breaks.
**Why it happens:** The callback must match exactly between Google Console, `.env`, and `config/services.php`.
**How to avoid:** Verify the `GOOGLE_REDIRECT_URI` matches the exact URL registered in Google Cloud Console. In development: `http://localhost:8000/auth/google/callback`.
**Warning signs:** "redirect_uri_mismatch" error from Google.

### Pitfall 5: Plaid Webhook Signature Verification Complexity
**What goes wrong:** The Plaid webhook JWT uses ES256 (ECDSA) with JWK key format. Most PHP JWT libraries support this, but the JWK must be fetched from Plaid's `/webhook_verification_key/get` endpoint using the `kid` from the JWT header. The key should be cached to avoid API calls on every webhook.
**Why it happens:** Plaid's verification is more complex than simple HMAC signing.
**How to avoid:** Use `firebase/php-jwt` which has built-in JWK support. Cache the verification key (keyed by `kid`) for 24 hours. Verify the `iat` is within 5 minutes. Verify the `request_body_sha256` matches the SHA-256 of the raw request body.
**Warning signs:** Webhook verification fails intermittently (key rotation), or always passes (verification skipped).

### Pitfall 6: Account Deletion Not Revoking Plaid Access Tokens
**What goes wrong:** The `UserProfileController::deleteAccount()` method only revokes Sanctum tokens and deletes the user. It does not call `PlaidService::removeItem()` for each bank connection, leaving Plaid access tokens active even after the user's account is deleted.
**Why it happens:** The existing code relies on database cascade deletes, which remove the records but don't call the Plaid API.
**How to avoid:** Before deleting the user, iterate over all bank connections and call `PlaidService::disconnect()` for each. Also handle email connections if applicable.
**Warning signs:** Plaid dashboard shows active items for deleted users.

### Pitfall 7: Webhook Idempotency
**What goes wrong:** Plaid may send the same webhook multiple times. Without idempotency tracking, the system may process duplicate syncs or send duplicate notifications.
**Why it happens:** Webhooks are inherently at-least-once delivery.
**How to avoid:** Track processed webhook IDs (e.g., in a `plaid_webhooks` table or Redis set). Check before processing. The `item_id` + `webhook_code` combination can serve as an idempotency key for most webhook types.
**Warning signs:** Duplicate notification emails, double-counted transactions.

### Pitfall 8: Raw Request Body for SHA-256 Verification
**What goes wrong:** Laravel middleware may parse/modify the request body before it reaches the webhook controller. The SHA-256 hash must match the exact raw body that Plaid sent.
**Why it happens:** JSON parsing and re-encoding may change whitespace.
**How to avoid:** Use `$request->getContent()` to get the raw body for SHA-256 computation, NOT `json_encode($request->all())`. Plaid's body hash uses tab-spacing of 2.
**Warning signs:** Webhook signature verification fails even with correct JWT key.

## Code Examples

### Plaid Webhook JWT Verification (firebase/php-jwt)
```php
// Source: firebase/php-jwt docs + Plaid webhook verification docs
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

public function verifyWebhookSignature(Request $request): bool
{
    $signedJwt = $request->header('Plaid-Verification');
    if (!$signedJwt) {
        return false;
    }

    // 1. Decode header without verification to get kid
    $headerEncoded = explode('.', $signedJwt)[0];
    $header = json_decode(base64_decode(strtr($headerEncoded, '-_', '+/')), true);

    // 2. Verify algorithm is ES256
    if (($header['alg'] ?? '') !== 'ES256') {
        return false;
    }

    $kid = $header['kid'] ?? null;
    if (!$kid) {
        return false;
    }

    // 3. Fetch verification key (cached)
    $jwk = Cache::remember("plaid_webhook_key:{$kid}", 86400, function () use ($kid) {
        $response = Http::post(config('spendwise.plaid.base_url') . '/webhook_verification_key/get', [
            'client_id' => config('services.plaid.client_id'),
            'secret'    => config('services.plaid.secret'),
            'key_id'    => $kid,
        ]);
        return $response->json('key');
    });

    // 4. Verify JWT signature
    try {
        $key = JWK::parseKey($jwk, 'ES256');
        $decoded = JWT::decode($signedJwt, $key);
    } catch (\Exception $e) {
        Log::warning('Plaid webhook JWT verification failed', ['error' => $e->getMessage()]);
        return false;
    }

    // 5. Check timestamp (not older than 5 minutes)
    if (isset($decoded->iat) && (time() - $decoded->iat) > 300) {
        return false;
    }

    // 6. Verify body hash
    $bodyHash = hash('sha256', $request->getContent());
    if (!hash_equals($decoded->request_body_sha256 ?? '', $bodyHash)) {
        return false;
    }

    return true;
}
```

### Webhook Route Registration (exempt from auth + CSRF)
```php
// In routes/api.php - outside the auth:sanctum middleware group
Route::post('/v1/webhooks/plaid', [PlaidWebhookController::class, 'handle']);
```

### Webhook Handler Dispatch Pattern
```php
public function handle(Request $request): JsonResponse
{
    // Verify signature (optional in sandbox, required in production)
    if (config('services.plaid.env') !== 'sandbox') {
        if (!$this->verifyWebhookSignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }
    }

    $webhookType = $request->input('webhook_type');
    $webhookCode = $request->input('webhook_code');
    $itemId      = $request->input('item_id');

    Log::info('Plaid webhook received', compact('webhookType', 'webhookCode', 'itemId'));

    $connection = BankConnection::where('plaid_item_id', $itemId)->first();
    if (!$connection) {
        Log::warning('Plaid webhook for unknown item', ['item_id' => $itemId]);
        return response()->json(['status' => 'ignored']);
    }

    return match ($webhookType) {
        'TRANSACTIONS' => $this->handleTransactionWebhook($webhookCode, $connection),
        'ITEM'         => $this->handleItemWebhook($webhookCode, $connection, $request),
        default        => response()->json(['status' => 'unhandled']),
    };
}
```

### Account Deletion with Plaid Cleanup
```php
public function deleteAccount(Request $request): JsonResponse
{
    $request->validate(['password' => 'required|current_password']);

    $user = auth()->user();

    // 1. Disconnect all bank connections (revokes Plaid tokens)
    $user->bankConnections->each(function ($connection) {
        app(PlaidService::class)->disconnect($connection);
    });

    // 2. Revoke all API tokens
    $user->tokens()->delete();

    // 3. Delete user (cascade handles related data)
    $user->delete();

    return response()->json(null, 204);
}
```

### User Model Fix: Add Missing $fillable and Casts
```php
// These fields MUST be added to User model
protected $fillable = [
    'name', 'email', 'password',
    'google_id', 'avatar_url', 'email_verified_at',
    'failed_login_attempts', 'locked_until',       // ADD THESE
    'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',  // ADD THESE
];

protected function casts(): array
{
    return [
        'email_verified_at'         => 'datetime',
        'password'                  => 'hashed',
        'two_factor_confirmed_at'   => 'datetime',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_secret'         => 'encrypted',
        'locked_until'              => 'datetime',  // ADD THIS
    ];
}
```

### BankConnection Model Fix: Rename plaid_cursor to sync_cursor
```php
protected $fillable = [
    'user_id', 'plaid_item_id', 'plaid_access_token', 'institution_name',
    'institution_id', 'status', 'last_synced_at', 'sync_cursor',  // FIX: was plaid_cursor
    'error_code', 'error_message',
];

protected $hidden = [
    'plaid_access_token',
    'plaid_item_id',
    'sync_cursor',      // FIX: was plaid_cursor
    'error_code',
    'error_message',
];
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Plaid `/transactions/get` (date-based) | `/transactions/sync` (cursor-based) | 2022 | Already using sync -- correct |
| Session-based auth for SPAs | Sanctum token auth + SPA stateful cookies | Laravel 12 | Already using Sanctum -- correct |
| Manual 2FA implementation | Fortify TwoFactorAuthenticatable trait | Laravel Fortify | Already using Fortify trait -- correct |
| Plaid Legacy Webhooks (DEFAULT_UPDATE, etc.) | SYNC_UPDATES_AVAILABLE | 2022+ | Must handle SYNC_UPDATES_AVAILABLE as primary |

**Important webhook note:** Plaid still sends legacy webhook types (DEFAULT_UPDATE, INITIAL_UPDATE, HISTORICAL_UPDATE) alongside SYNC_UPDATES_AVAILABLE if `/transactions/sync` has been called. The webhook handler should handle both patterns gracefully but primarily respond to SYNC_UPDATES_AVAILABLE.

## Identified Bugs and Gaps

### Must Fix (blocking functionality)
1. **User model `$fillable` missing `failed_login_attempts`, `locked_until`** -- Account lockout will silently not work
2. **BankConnection model `plaid_cursor` should be `sync_cursor`** -- Sync cursor won't persist, causing full re-sync every time
3. **User model `$fillable` missing 2FA fields** -- `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` are not in `$fillable`, so `$user->update([...])` calls in TwoFactorController may fail
4. **`deleteAccount()` doesn't revoke Plaid tokens** -- Leaves active API access after account deletion
5. **`deleteAccount()` doesn't require password confirmation** -- GDPR/security best practice requires password confirmation for destructive actions
6. **No webhook route exists** -- Must create `PlaidWebhookController` and register route
7. **No `firebase/php-jwt` installed** -- Needed for webhook JWT verification

### Should Fix (correctness/completeness)
8. **Email verification route missing from API** -- The `EmailVerificationController::verify()` method exists but is not registered in `routes/api.php`. The verification link in emails needs a route to hit. Currently only the Breeze web route exists at `verify-email/{id}/{hash}`.
9. **No webhook idempotency tracking** -- Need a mechanism to prevent duplicate webhook processing
10. **PlaidService::syncTransactions doesn't handle initial sync date range** -- PLAID-03 requires "up to 12 months or beginning of prior year" but the PlaidService doesn't set a `start_date` parameter. The `/transactions/sync` endpoint handles this automatically by returning all available history, but it should be verified.
11. **Webhook should trigger SyncBankTransactions job** -- But this job doesn't exist yet. For now, can call PlaidService::syncTransactions() directly from the webhook handler.

### Nice to Have (polish)
12. **Webhook logging table** -- For HOOK-07 compliance, webhooks should be logged to a table for audit purposes
13. **Sandbox skip for webhook verification** -- In sandbox mode, webhook verification can be skipped since sandbox doesn't sign webhooks consistently

## Webhook Implementation Details

### Plaid Webhook Types to Handle

| webhook_type | webhook_code | Action | Requirement |
|-------------|-------------|--------|-------------|
| TRANSACTIONS | SYNC_UPDATES_AVAILABLE | Trigger transaction sync for the Item | HOOK-02 |
| TRANSACTIONS | TRANSACTIONS_REMOVED | (Handled automatically by /transactions/sync) | HOOK-05 |
| ITEM | ERROR | Check error_code; if ITEM_LOGIN_REQUIRED, mark connection as error | HOOK-03 |
| ITEM | PENDING_EXPIRATION | Notify user to re-authenticate | HOOK-04 |
| ITEM | PENDING_DISCONNECT | Notify user to re-authenticate (US/CA variant) | HOOK-04 |
| ITEM | USER_PERMISSION_REVOKED | Disconnect the bank connection | HOOK-06 |

### Plaid Webhook Payload Structure
```json
{
  "webhook_type": "TRANSACTIONS",
  "webhook_code": "SYNC_UPDATES_AVAILABLE",
  "item_id": "abc123",
  "initial_update_complete": true,
  "historical_update_complete": true,
  "environment": "sandbox"
}
```

```json
{
  "webhook_type": "ITEM",
  "webhook_code": "ERROR",
  "item_id": "abc123",
  "error": {
    "error_type": "ITEM_ERROR",
    "error_code": "ITEM_LOGIN_REQUIRED",
    "error_message": "the login details of this item have changed",
    "display_message": "The credentials were not correct.",
    "status": 400
  },
  "environment": "sandbox"
}
```

### Webhook Verification Steps (Plaid-Specific)
1. Extract `Plaid-Verification` header (contains JWT)
2. Decode JWT header without verification to extract `kid` and verify `alg` is `ES256`
3. Fetch JWK from Plaid API: `POST /webhook_verification_key/get` with the `kid`
4. Cache the JWK keyed by `kid` (24-hour TTL)
5. Verify JWT signature using the JWK public key
6. Check `iat` timestamp is within 5 minutes
7. Compute SHA-256 of raw request body, compare with `request_body_sha256` from JWT payload
8. All checks pass = authentic webhook

### Sandbox Testing
Use Plaid's sandbox endpoint to fire test webhooks:
```bash
curl -X POST https://sandbox.plaid.com/sandbox/item/fire_webhook \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": "your_client_id",
    "secret": "your_secret",
    "access_token": "access-sandbox-xxx",
    "webhook_type": "TRANSACTIONS",
    "webhook_code": "SYNC_UPDATES_AVAILABLE"
  }'
```

## Open Questions

1. **Webhook database table for idempotency**
   - What we know: HOOK-07 requires idempotent handling and logging
   - What's unclear: Should we create a `plaid_webhook_logs` migration in this phase, or use Redis for idempotency tracking?
   - Recommendation: Create a simple migration for a `plaid_webhook_logs` table (id, webhook_type, webhook_code, item_id, payload, processed_at, timestamps). Use the combination of `item_id` + `webhook_code` + timestamp bucketing for idempotency checks. This is a small migration and directly supports HOOK-07.

2. **Notification for webhook-triggered events (HOOK-03, HOOK-04)**
   - What we know: HOOK-03 and HOOK-04 require notifying users when items need re-auth
   - What's unclear: The Notification system is Phase 4. How should we notify in Phase 2?
   - Recommendation: Log a warning and update the BankConnection status/error fields. The frontend can check connection status on page load. Defer formal notifications to Phase 4 but add a TODO comment.

3. **Email verification route for SPA**
   - What we know: The Breeze web route handles verification via signed URL. The custom EmailVerificationController exists.
   - What's unclear: How should the email verification link work for the SPA? The link goes to a web route, not an API route.
   - Recommendation: The Breeze verification route at `verify-email/{id}/{hash}` is a web route that works correctly -- it verifies the email and can redirect to the SPA. The custom `EmailVerificationController` may be redundant or could serve as an API endpoint. Leave both and ensure the Breeze route handles the verification link from emails.

## Sources

### Primary (HIGH confidence)
- Existing codebase files (all controllers, models, services, routes, configs reviewed in full)
- [Plaid Webhook Verification Docs](https://plaid.com/docs/api/webhooks/webhook-verification/) - JWT verification steps
- [Plaid Transaction Webhooks](https://plaid.com/docs/transactions/webhooks/) - SYNC_UPDATES_AVAILABLE details
- [Plaid Items API](https://plaid.com/docs/api/items/) - Item webhook types and payloads
- [Laravel Sanctum Docs](https://laravel.com/docs/12.x/sanctum) - SPA authentication patterns

### Secondary (MEDIUM confidence)
- [firebase/php-jwt GitHub](https://github.com/firebase/php-jwt) - ES256 + JWK support confirmed
- [Plaid Sandbox API](https://plaid.com/docs/api/sandbox/) - `/sandbox/item/fire_webhook` for testing
- [Plaid Changelog](https://plaid.com/docs/changelog/) - Recent API changes

### Tertiary (LOW confidence)
- Account deletion GDPR patterns - based on general Laravel community practices, not official guidance specific to this version

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - all libraries already installed and configured, only firebase/php-jwt to add
- Architecture: HIGH - all controllers, routes, models, and services already exist and reviewed
- Auth system: HIGH - complete code reviewed, bugs identified with clear fixes
- Plaid integration: HIGH - PlaidService fully implemented, webhook verification well-documented by Plaid
- Pitfalls: HIGH - identified through direct code comparison (migration vs model, $fillable vs usage)
- Webhook implementation: MEDIUM - JWT verification pattern verified against docs but not yet tested in this codebase

**Research date:** 2026-02-10
**Valid until:** 2026-03-10 (stable domain, libraries are mature)
