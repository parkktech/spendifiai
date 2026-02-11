---
phase: 05-testing-deployment
plan: 02
subsystem: testing
tags: [pest-php, feature-tests, unit-tests, http-fake, sanctum, plaid, anthropic, recaptcha]

# Dependency graph
requires:
  - phase: 05-01
    provides: "Model factories, Pest.php helpers, PostgreSQL test DB config"
provides:
  - "8 feature test files covering all critical user flows (auth, Plaid, transactions, AI, subscriptions, savings, tax, account deletion)"
  - "4 unit test files covering service business logic (categorizer, subscription detector, tax export, captcha)"
  - "Bug fixes for 7 code issues discovered during test execution"
affects: [05-03, deployment, ci]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Http::fake for all external API calls (Plaid sandbox, Anthropic, reCAPTCHA)"
    - "Queue::fake to prevent job dispatch during feature tests"
    - "Event::fake to isolate test concerns from listener side effects"
    - "ReflectionMethod for testing protected service methods in unit tests"
    - "Pest.php TestCase binding for Unit/Services directory"

key-files:
  created:
    - tests/Feature/Auth/ApiAuthTest.php
    - tests/Feature/Plaid/PlaidFlowTest.php
    - tests/Feature/Transaction/TransactionTest.php
    - tests/Feature/AIQuestion/AIQuestionTest.php
    - tests/Feature/Subscription/SubscriptionTest.php
    - tests/Feature/Savings/SavingsTest.php
    - tests/Feature/Tax/TaxTest.php
    - tests/Feature/Account/AccountDeletionTest.php
    - tests/Unit/Services/TransactionCategorizerServiceTest.php
    - tests/Unit/Services/SubscriptionDetectorServiceTest.php
    - tests/Unit/Services/TaxExportServiceTest.php
    - tests/Unit/Services/CaptchaServiceTest.php
  modified:
    - tests/Pest.php
    - app/Http/Controllers/Controller.php
    - app/Http/Controllers/Api/PlaidController.php
    - app/Policies/AIQuestionPolicy.php
    - app/Services/AI/TransactionCategorizerService.php
    - app/Services/AI/SavingsAnalyzerService.php
    - app/Services/AI/SavingsTargetPlannerService.php
    - app/Services/PlaidService.php
    - app/Services/TaxExportService.php

key-decisions:
  - "Unit/Services tests bound to Laravel TestCase in Pest.php for model, config, and Http::fake access"
  - "Fixed enum switch comparison in TransactionCategorizerService by extracting ->value before switch"
  - "Fixed PostgreSQL GROUP BY in TaxExportService to include full COALESCE expression"
  - "Service constructors use nullable types with ?? '' fallback for null config values in test environment"
  - "Added AuthorizesRequests trait to base Controller for Laravel 12 compatibility"

patterns-established:
  - "Feature tests: Sanctum::actingAs + Http::fake + createUserWithBank/Profile helpers"
  - "Unit service tests: RefreshDatabase + Http::fake + ReflectionMethod for protected methods"
  - "Config override in tests: config([...]) in beforeEach for service dependencies"

# Metrics
duration: 11min
completed: 2026-02-11
---

# Phase 5 Plan 2: Feature & Unit Tests Summary

**53 new Pest PHP tests across 12 files covering auth flow, Plaid integration, AI categorization, subscriptions, savings, tax export, and account deletion with 7 auto-fixed bugs discovered during execution**

## Performance

- **Duration:** ~11 min
- **Started:** 2026-02-11T22:08:28Z
- **Completed:** 2026-02-11T22:19:35Z
- **Tasks:** 2/2
- **Files modified:** 25

## Accomplishments

- Created 8 feature test files with 30 tests covering all critical API flows (register/login/logout/2FA, Plaid link/exchange/sync/disconnect, transactions CRUD, AI questions, subscriptions, savings, tax, account deletion)
- Created 4 unit test files with 23 tests covering service business logic at all confidence thresholds, recurrence detection, Schedule C mapping, and captcha verification
- Fixed 7 bugs in production code discovered through test execution (enum comparison, PostgreSQL GROUP BY, null safety, missing imports, missing traits, missing policy methods)
- Full test suite: 75 pass, 3 fail (pre-existing Breeze ProfileUpdateRequest failures from before this plan)

## Task Commits

Each task was committed atomically:

1. **Task 1: Write all 8 feature test files** - `535cf8e` (feat)
2. **Task 2: Write all 4 unit test files** - `c4f1944` (test)

## Files Created/Modified

### Feature Tests (Task 1)
- `tests/Feature/Auth/ApiAuthTest.php` - 7 tests: register, login, login fail, logout, current user, 2FA prompt, unauthenticated 401
- `tests/Feature/Plaid/PlaidFlowTest.php` - 4 tests: create link token, exchange token, sync transactions, disconnect
- `tests/Feature/Transaction/TransactionTest.php` - 5 tests: list, filter by purpose, filter by date, update category, cross-user 403
- `tests/Feature/AIQuestion/AIQuestionTest.php` - 4 tests: list pending, answer, bulk answer, skip
- `tests/Feature/Subscription/SubscriptionTest.php` - 2 tests: list subscriptions, detect from patterns
- `tests/Feature/Savings/SavingsTest.php` - 3 tests: list recommendations, set target, respond to action
- `tests/Feature/Tax/TaxTest.php` - 2 tests: tax summary with deductible totals, profile-required 403
- `tests/Feature/Account/AccountDeletionTest.php` - 3 tests: delete cascade, password validation, token revocation

### Unit Tests (Task 2)
- `tests/Unit/Services/TransactionCategorizerServiceTest.php` - 6 tests: confidence >= 0.85 auto-categorize, 0.60-0.84 review, 0.40-0.59 multiple choice, < 0.40 open-ended, handleUserAnswer business_personal, handleUserAnswer Skip
- `tests/Unit/Services/SubscriptionDetectorServiceTest.php` - 5 tests: monthly detection, weekly detection, inconsistent charges rejected, single charge rejected, unused marking
- `tests/Unit/Services/TaxExportServiceTest.php` - 6 tests: Line 8/9/18/27a mapping, aggregation, gatherTaxData totals
- `tests/Unit/Services/CaptchaServiceTest.php` - 6 tests: disabled true, score above threshold, score below, action mismatch, API failure, exception handling

### Bug Fixes (discovered during test execution)
- `app/Http/Controllers/Controller.php` - Added AuthorizesRequests trait (Laravel 12 omits it)
- `app/Http/Controllers/Api/PlaidController.php` - Added missing CategorizePendingTransactions import
- `app/Policies/AIQuestionPolicy.php` - Added update() method for AnswerQuestionRequest authorization
- `app/Services/AI/TransactionCategorizerService.php` - Fixed enum switch comparison, nullable $apiKey
- `app/Services/AI/SavingsAnalyzerService.php` - Nullable $apiKey for test environment
- `app/Services/AI/SavingsTargetPlannerService.php` - Nullable $apiKey for test environment
- `app/Services/PlaidService.php` - Nullable $clientId and $secret for test environment
- `app/Services/TaxExportService.php` - Fixed PostgreSQL GROUP BY with COALESCE
- `tests/Pest.php` - Added TestCase binding for Unit/Services directory

## Decisions Made

- **Unit/Services TestCase binding:** Unit tests under tests/Unit/Services/ are bound to Laravel TestCase in Pest.php because they need the app container for models, config, Http::fake, and database access
- **Enum value extraction before switch:** PHP 8.1+ backed enums don't loose-compare with strings in switch statements; must extract `->value` first
- **Nullable service constructor properties:** All AI service constructors and PlaidService use `?string` with `?? ''` fallback to prevent TypeError when config values are null in test environment
- **AuthorizesRequests trait:** Laravel 12's base Controller does not include this trait by default; added it since all API controllers use `$this->authorize()`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed enum switch comparison in TransactionCategorizerService**
- **Found during:** Task 1 (AIQuestionTest) and Task 2 (TransactionCategorizerServiceTest)
- **Issue:** `handleUserAnswer()` uses a switch on `$question->question_type`, but PHP 8.1+ enum instances don't match string cases. `QuestionType::BusinessPersonal` !== `'business_personal'` in switch
- **Fix:** Extract `->value` from the enum before the switch statement
- **Files modified:** `app/Services/AI/TransactionCategorizerService.php`
- **Verification:** handleUserAnswer unit tests pass, transaction gets updated correctly
- **Committed in:** `c4f1944` (Task 2 commit)

**2. [Rule 1 - Bug] Fixed PostgreSQL GROUP BY in TaxExportService**
- **Found during:** Task 2 (TaxExportServiceTest gatherTaxData)
- **Issue:** `gatherTaxData()` uses `COALESCE(tax_category, user_category, ai_category, 'Uncategorized')` in SELECT but only `tax_category` in GROUP BY. PostgreSQL strict mode rejects this.
- **Fix:** Changed groupBy to use the full `DB::raw("COALESCE(tax_category, user_category, ai_category, 'Uncategorized')")` expression
- **Files modified:** `app/Services/TaxExportService.php`
- **Verification:** gatherTaxData test passes with correct deductible totals
- **Committed in:** `c4f1944` (Task 2 commit)

**3. [Rule 3 - Blocking] Added AuthorizesRequests trait to base Controller**
- **Found during:** Task 1 (PlaidFlowTest)
- **Issue:** Laravel 12 base Controller doesn't include `AuthorizesRequests` trait, causing `$this->authorize()` calls in all API controllers to fail
- **Fix:** Added `use AuthorizesRequests;` to `app/Http/Controllers/Controller.php`
- **Files modified:** `app/Http/Controllers/Controller.php`
- **Verification:** All feature tests using authorize() pass
- **Committed in:** `535cf8e` (Task 1 commit)

**4. [Rule 3 - Blocking] Added missing CategorizePendingTransactions import**
- **Found during:** Task 1 (PlaidFlowTest sync test)
- **Issue:** PlaidController's sync() method dispatches CategorizePendingTransactions but the import was missing
- **Fix:** Added `use App\Jobs\CategorizePendingTransactions;` import
- **Files modified:** `app/Http/Controllers/Api/PlaidController.php`
- **Verification:** Plaid sync test passes
- **Committed in:** `535cf8e` (Task 1 commit)

**5. [Rule 3 - Blocking] Added update() method to AIQuestionPolicy**
- **Found during:** Task 1 (AIQuestionTest answer test)
- **Issue:** AnswerQuestionRequest's authorize() calls `can('update', $question)` but AIQuestionPolicy only had view/viewAny methods, no update()
- **Fix:** Added `update()` method checking `$user->id === $q->user_id`
- **Files modified:** `app/Policies/AIQuestionPolicy.php`
- **Verification:** AI question answer and bulk answer tests pass
- **Committed in:** `535cf8e` (Task 1 commit)

**6. [Rule 3 - Blocking] Fixed nullable service constructor properties**
- **Found during:** Task 1 (PlaidFlowTest, SavingsTest)
- **Issue:** Service constructors typed `$apiKey` as `string` but config() returns null in test environment, causing TypeError
- **Fix:** Changed to `?string` with `?? ''` fallback in TransactionCategorizerService, SavingsAnalyzerService, SavingsTargetPlannerService, and PlaidService
- **Files modified:** 4 service files
- **Verification:** All tests using these services pass
- **Committed in:** `535cf8e` (Task 1 commit)

**7. [Rule 3 - Blocking] Added Unit/Services TestCase binding in Pest.php**
- **Found during:** Task 2 (first unit test execution)
- **Issue:** Unit tests under tests/Unit/Services/ need Laravel app context for models, config, Http::fake, but weren't bound to TestCase
- **Fix:** Added `pest()->extend(Tests\TestCase::class)->in('Unit/Services');` to Pest.php
- **Files modified:** `tests/Pest.php`
- **Verification:** All unit service tests pass
- **Committed in:** `c4f1944` (Task 2 commit)

---

**Total deviations:** 7 auto-fixed (2 bugs via Rule 1, 5 blocking issues via Rule 3)
**Impact on plan:** All auto-fixes were necessary for tests to execute correctly. Bug fixes also improve production code quality. No scope creep.

## Issues Encountered

- **Pre-existing Breeze test failures (3):** AuthenticationTest ("users can authenticate") and ProfileTest (2 tests) fail due to missing `ProfileUpdateRequest` class. These were documented in 05-01-SUMMARY.md and are NOT caused by this plan. They originate from the Breeze starter kit expecting a class that was never generated.
- **Logout test design:** Sanctum::actingAs() uses transient tokens not stored in DB, so deleting currentAccessToken and re-requesting still works. Fixed assertion to check token count instead of making a follow-up request.
- **SavingsTest Http::fake format:** SavingsTargetPlannerService expects a flat JSON array of actions from Claude, not wrapped in `{actions: [...]}`. Fixed the Http::fake response format.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- All critical user flows and service business logic are now covered by automated tests
- 75 tests pass (53 new + 22 existing), 3 pre-existing failures (Breeze ProfileUpdateRequest)
- CI pipeline (05-03) can run this full test suite
- No external API calls in any test -- all use Http::fake

## Self-Check: PASSED

- All 12 test files: FOUND
- All 1 summary file: FOUND
- Commit 535cf8e (Task 1): FOUND
- Commit c4f1944 (Task 2): FOUND
- Test suite: 75 pass, 3 fail (pre-existing)

---
*Phase: 05-testing-deployment*
*Completed: 2026-02-11*
