---
phase: 02-auth-bank-integration
plan: 01
subsystem: auth
tags: [sanctum, 2fa, totp, google-oauth, recaptcha, account-lockout, laravel]

# Dependency graph
requires:
  - phase: 01-scaffolding
    provides: "Laravel project with User model, auth controllers, middleware, routes"
provides:
  - "Fully functional auth system: register, login (with lockout + 2FA), Google OAuth, password reset/change, email verification"
  - "reCAPTCHA v3 middleware on register and login routes"
  - "Rate limiting on all public auth endpoints"
  - "Plaid webhook route registered (stub controller)"
affects: [02-auth-bank-integration, 04-frontend-events]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "captcha config uses !empty() for env var truthiness"
    - "PlaidWebhookController stub pattern for forward route registration"

key-files:
  created:
    - app/Http/Controllers/Api/PlaidWebhookController.php
  modified:
    - app/Models/User.php
    - routes/api.php
    - config/spendwise.php

key-decisions:
  - "Fixed captcha config to use !empty() instead of !== null for RECAPTCHA_SITE_KEY check"
  - "Created PlaidWebhookController stub to unblock route registration (full impl in Plan 03)"
  - "Left inline validation in TwoFactorController as-is (single-field checks, not worth FormRequest overhead)"

patterns-established:
  - "Auth controllers are thin: logic validated, no changes needed beyond model $fillable fix"
  - "Captcha middleware passes through when disabled, returns 422 when enabled without valid token"

# Metrics
duration: 4min
completed: 2026-02-11
---

# Phase 2 Plan 1: Auth System Bug Fix & Verification Summary

**Fixed User model $fillable for lockout/2FA fields, wired reCAPTCHA v3 middleware to auth routes, and verified all 5 auth controllers work end-to-end**

## Performance

- **Duration:** 4 min
- **Started:** 2026-02-11T05:09:16Z
- **Completed:** 2026-02-11T05:13:40Z
- **Tasks:** 3
- **Files modified:** 4

## Accomplishments
- Fixed silent data loss bug: User model $fillable was missing 5 fields that auth controllers call update() on (failed_login_attempts, locked_until, two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at)
- Added reCAPTCHA v3 middleware (captcha:register, captcha:login) to register and login routes per AUTH-15
- Verified all auth endpoints work: register returns 201 with token, login returns 200 with token, me returns user data, logout revokes token
- Added rate limiting to change-password endpoint and email verification API route

## Task Commits

Each task was committed atomically:

1. **Task 1: Fix User model $fillable, casts, and $hidden for auth fields** - `c78b7e5` (fix)
2. **Task 2: Add reCAPTCHA middleware and rate limiting to auth routes, add email verification route** - `ec132b0` (feat)
3. **Task 3: Verify auth controller logic is correct end-to-end** - `afdb560` (fix)

## Files Created/Modified
- `app/Models/User.php` - Added 5 fields to $fillable, locked_until datetime cast
- `routes/api.php` - Added captcha middleware, throttle on change-password, email verify route, webhook route
- `app/Http/Controllers/Api/PlaidWebhookController.php` - Stub controller for webhook route registration
- `config/spendwise.php` - Fixed captcha enabled check from !== null to !empty()

## Decisions Made
- Fixed captcha config to use `!empty()` instead of `!== null` -- empty string env var was incorrectly enabling captcha
- Created PlaidWebhookController as a stub (returns 200 "received") to prevent route:list errors -- full implementation in Plan 03
- Left inline validation in TwoFactorController (password, code fields) as-is -- creating FormRequest for single-field checks is unnecessary overhead

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Created PlaidWebhookController stub**
- **Found during:** Task 2 (Route registration)
- **Issue:** Route file referenced PlaidWebhookController which doesn't exist yet (Plan 03 scope), causing route:list to throw ReflectionException
- **Fix:** Created minimal stub controller with handle() method returning 200
- **Files modified:** app/Http/Controllers/Api/PlaidWebhookController.php
- **Verification:** php artisan route:list --path=api/v1/webhooks shows route
- **Committed in:** ec132b0 (Task 2 commit)

**2. [Rule 1 - Bug] Fixed captcha config empty string check**
- **Found during:** Task 3 (End-to-end verification)
- **Issue:** `env('RECAPTCHA_SITE_KEY')` returns empty string `""` when key exists but has no value in .env. The check `!== null` evaluates to true, incorrectly enabling captcha and blocking all registration/login requests
- **Fix:** Changed to `!empty(env('RECAPTCHA_SITE_KEY'))` which correctly treats empty string as disabled
- **Files modified:** config/spendwise.php
- **Verification:** php artisan tinker confirms config returns false with empty env var; register endpoint accepts requests
- **Committed in:** afdb560 (Task 3 commit)

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 bug)
**Impact on plan:** Both auto-fixes necessary for correctness. No scope creep.

## Issues Encountered
None beyond the auto-fixed deviations above.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Auth system fully functional, ready for Plaid bank integration (Plan 02)
- All auth routes have proper middleware (captcha, throttle, signed)
- PlaidWebhookController stub in place for Plan 03

## Self-Check: PASSED

All files verified present. All 3 task commits verified in git log.

---
*Phase: 02-auth-bank-integration*
*Completed: 2026-02-11*
