---
phase: 05-testing-deployment
plan: 01
subsystem: testing
tags: [pest, postgresql, factories, hasFactory, test-helpers]

# Dependency graph
requires:
  - phase: 01-scaffolding
    provides: "Laravel project with all 18 Eloquent models"
  - phase: 02-auth-bank-integration
    provides: "Auth system, bank connections, Plaid integration"
provides:
  - "18 model factories with enum-correct states and FK chains"
  - "PostgreSQL test database configuration"
  - "Pest test helpers: createAuthenticatedUser, createUserWithBank, createUserWithBankAndProfile"
  - "HasFactory trait on all 18 models"
affects: [05-02, 05-03]

# Tech tracking
tech-stack:
  added: []
  patterns: [factory-states-for-enums, fk-chain-factories, pest-global-helpers]

key-files:
  created:
    - ".env.testing"
    - "database/factories/BankConnectionFactory.php"
    - "database/factories/BankAccountFactory.php"
    - "database/factories/TransactionFactory.php"
    - "database/factories/SubscriptionFactory.php"
    - "database/factories/AIQuestionFactory.php"
    - "database/factories/EmailConnectionFactory.php"
    - "database/factories/ParsedEmailFactory.php"
    - "database/factories/OrderFactory.php"
    - "database/factories/OrderItemFactory.php"
    - "database/factories/ExpenseCategoryFactory.php"
    - "database/factories/SavingsRecommendationFactory.php"
    - "database/factories/SavingsTargetFactory.php"
    - "database/factories/SavingsPlanActionFactory.php"
    - "database/factories/SavingsProgressFactory.php"
    - "database/factories/BudgetGoalFactory.php"
    - "database/factories/UserFinancialProfileFactory.php"
    - "database/factories/PlaidWebhookLogFactory.php"
  modified:
    - "phpunit.xml"
    - "database/factories/UserFactory.php"
    - "tests/Pest.php"
    - "app/Models/*.php (17 models - HasFactory trait added)"

key-decisions:
  - "Used PostgreSQL for test database (SQLite cannot run migration 000005 column changes)"
  - "EmailConnectionFactory uses sync_status instead of status (DB column mismatch with model)"
  - "BudgetGoalFactory uses category_slug to match actual DB schema"
  - "Factory states match PHP backed enum values (not raw strings)"

patterns-established:
  - "Factory FK chains: Transaction -> BankAccount -> BankConnection -> User"
  - "Encrypted fields get plain values in factories (model casts handle encryption)"
  - "Pest helpers: createAuthenticatedUser() wraps User::factory + Sanctum::actingAs"
  - "Factory states for enum-based model variations (categorized, business, error, etc.)"

# Metrics
duration: 6min
completed: 2026-02-11
---

# Phase 5 Plan 1: Test Infrastructure & Model Factories Summary

**PostgreSQL test database, 18 model factories with enum states and FK chains, plus Pest test helpers for authenticated user setup**

## Performance

- **Duration:** 6 min
- **Started:** 2026-02-11T21:59:03Z
- **Completed:** 2026-02-11T22:05:04Z
- **Tasks:** 2
- **Files modified:** 38

## Accomplishments
- Configured PostgreSQL test database (spendwise_test) replacing SQLite :memory: to support migration column changes
- Created 17 new factory files and enhanced UserFactory with 2FA and Google OAuth states
- Added HasFactory trait to all 17 non-User models
- Added Pest.php test helpers (createAuthenticatedUser, createUserWithBank, createUserWithBankAndProfile)
- All 18 factories verified to produce valid records with correct FK constraints and enum values

## Task Commits

Each task was committed atomically:

1. **Task 1: Configure PostgreSQL test database and fix phpunit.xml** - `da9539b` (chore)
2. **Task 2: Add HasFactory trait to 17 models and create all 18 factories** - `fd68d21` (feat)

## Files Created/Modified
- `.env.testing` - PostgreSQL test database configuration (DB_CONNECTION=pgsql, DB_DATABASE=spendwise_test)
- `phpunit.xml` - Removed SQLite :memory: overrides, relies on .env.testing for DB config
- `tests/Pest.php` - Added createAuthenticatedUser(), createUserWithBank(), createUserWithBankAndProfile() helpers
- `database/factories/UserFactory.php` - Enhanced with withTwoFactor() and withGoogle() states
- `database/factories/BankConnectionFactory.php` - States: active, error, disconnected
- `database/factories/BankAccountFactory.php` - States: business, personal
- `database/factories/TransactionFactory.php` - States: categorized, needsReview, business, deductible, subscription
- `database/factories/SubscriptionFactory.php` - States: unused, essential
- `database/factories/AIQuestionFactory.php` - States: answered, category
- `database/factories/EmailConnectionFactory.php` - Uses sync_status to match DB schema
- `database/factories/ParsedEmailFactory.php` - Encrypted array raw_parsed_data
- `database/factories/OrderFactory.php` - Computed tax/shipping/total
- `database/factories/OrderItemFactory.php` - Computed total_price from qty * unit_price
- `database/factories/ExpenseCategoryFactory.php` - Auto-generates slug from name
- `database/factories/SavingsRecommendationFactory.php` - Includes action_steps and related_merchants arrays
- `database/factories/SavingsTargetFactory.php` - Computed goal_total from monthly_target
- `database/factories/SavingsPlanActionFactory.php` - States: accepted, rejected
- `database/factories/SavingsProgressFactory.php` - Computed actual_savings and target_met
- `database/factories/BudgetGoalFactory.php` - Uses category_slug to match DB schema
- `database/factories/UserFinancialProfileFactory.php` - Plain string monthly_income (model encrypts)
- `database/factories/PlaidWebhookLogFactory.php` - JSON payload with webhook_type/code
- `app/Models/*.php` (17 models) - Added HasFactory trait import and use

## Decisions Made
- Used PostgreSQL for test database instead of SQLite because migration 000005 uses ->change() calls that SQLite cannot execute
- EmailConnectionFactory uses `sync_status` field (not `status`) because the email_connections table has `sync_status` as its column name, not `status`
- BudgetGoalFactory uses `category_slug` to match actual DB column (model $fillable has `category` but DB has `category_slug`)
- Factory states use PHP enum instances (e.g., `ConnectionStatus::Error`) rather than raw strings for type safety

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] EmailConnectionFactory used non-existent `status` column**
- **Found during:** Task 2 (factory creation)
- **Issue:** The `email_connections` table has `sync_status` column, not `status`. The model's $fillable includes both `status` and `sync_status` but the DB only has `sync_status`.
- **Fix:** Changed EmailConnectionFactory to use `sync_status => 'pending'` instead of `status => ConnectionStatus::Active`
- **Files modified:** database/factories/EmailConnectionFactory.php
- **Verification:** Factory creates records successfully
- **Committed in:** fd68d21 (Task 2 commit)

**2. [Rule 1 - Bug] BudgetGoalFactory adapted to actual DB schema**
- **Found during:** Task 2 (factory creation)
- **Issue:** Plan specified `category` and `alert_threshold` fields but DB has `category_slug`, `notify_at_80_pct`, `notify_at_100_pct` instead
- **Fix:** Used `category_slug` in factory definition, omitted alert_threshold (DB has boolean notification flags with defaults)
- **Files modified:** database/factories/BudgetGoalFactory.php
- **Verification:** Factory creates records successfully
- **Committed in:** fd68d21 (Task 2 commit)

---

**Total deviations:** 2 auto-fixed (2 bug fixes for DB schema mismatches)
**Impact on plan:** Both fixes were necessary for factories to produce valid records. No scope creep.

## Issues Encountered
- 3 pre-existing test failures in AuthenticationTest and ProfileTest (missing ProfileUpdateRequest class, auth config issue). These failures existed before this plan and are unrelated to test infrastructure setup. 22 of 25 tests pass.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- All 18 model factories are available for feature and unit test authoring
- PostgreSQL test database is configured and migrations verified
- Test helpers ready for creating authenticated users with bank connections and profiles
- Ready for 05-02 (Feature Tests) and 05-03 (Unit Tests)

---
*Phase: 05-testing-deployment*
*Completed: 2026-02-11*
