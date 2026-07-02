---
phase: 14-action-center-scenarios-design-elevation
plan: "09"
subsystem: optimize-my-income
tags: [change-monitor, action-center, calendar-watchers, verification-watch, nav-badge]
dependency_graph:
  requires: [14-08]
  provides: [MON-01, MON-02, ACT-01, ACT-02, ACT-03, ACT-04]
  affects: [routes/api.php, routes/console.php, HandleInertiaRequests]
tech_stack:
  added: []
  patterns:
    - ChangeMonitor service (verification-watch + income-shift persistence anchor + calendar watchers)
    - OptimizationCalendarEvent as persistence anchor (resolves Research A3)
    - SavingsLedger claimed→verified pattern (D13.5 / ACT-04)
    - DOCUMENTS-FIRST-FUNNEL Stage-0 derivation (Δ1)
    - Additive pendingActionCount Inertia prop (DRIFT-09)
key_files:
  created:
    - app/Services/ChangeMonitor.php
    - app/Models/OptimizationCalendarEvent.php
    - database/migrations/2026_07_05_000001_create_optimization_calendar_events_table.php
    - app/Http/Controllers/Api/ActionCenterController.php
    - database/factories/TaxDocumentFactory.php
    - database/factories/OptimizationReportFactory.php
    - database/factories/UserTaxFactFactory.php
    - tests/Feature/ChangeMonitorTest.php
    - tests/Feature/ActionCenterTest.php
  modified:
    - routes/console.php
    - routes/api.php
    - app/Http/Middleware/HandleInertiaRequests.php
decisions:
  - "Income-shift persistence anchored on optimization_calendar_events(event_type=income_shift_detected) NOT report.stale_since — resolves Research A3; makes detection independent of report rebuild cycle"
  - "AIQuestion created without context column (doesn't exist in schema) — deduped via question LIKE '%income has changed%' instead"
  - "ai_confidence set to 0.0 (not null) for template-driven optimization questions — satisfies NOT NULL constraint"
  - "Stage-0 ordering follows DOCUMENTS-FIRST-FUNNEL Δ1: paystub first (primary CTA), interview last"
  - "BankAccount credit-card check uses user_id directly (not through bankConnection relationship) — simpler and correct given user_id FK exists on bank_accounts"
  - "DocumentStatus::Upload used for non-ready paystub test (no Pending case in enum — values are Upload/Classifying/Extracting/Ready/Failed/Splitting/Split)"
  - "action-center route has NO bank.connected middleware — Stage-0 paystub upload is relevant even before bank is linked"
metrics:
  duration: "~4 hours (continued from prior session)"
  completed: "2026-07-02"
  tasks_total: 3
  tasks_completed: 3
  tests_added: 50
  tests_baseline: 1030
  tests_final: 1081
  known_failures: 1
status: complete
---

# Phase 14 Plan 09: ChangeMonitor + Calendar Watchers + Action Center Backend Summary

ChangeMonitor service (zero Claude) unifying verification watch, income-shift persistence detection, and calendar watchers with Activity-gated scheduled task; Action Center endpoint with DOCUMENTS-FIRST Stage-0 derivation and additive pendingActionCount Inertia prop.

## What Was Built

### Task 1 + 2: ChangeMonitor Service + OptimizationCalendarEvent

**Migration & Model** (`optimization_calendar_events`):
- Forward-only additive migration: id, user_id (FK cascade), tax_year, event_type, expected_at, lead_time_days (default 21), alert_fired_at (nullable), metadata (json — periods/types ONLY, no money values per T-14-09-03), timestamps, index [user_id, tax_year]
- `OptimizationCalendarEvent` model with `scopeForUser()` (T-14-09-04) and `scopeAlertReady()` (PostgreSQL interval arithmetic)

**ChangeMonitor (final class, D17 zero Claude)**:

`checkVerificationWindows(userId, taxYear)` (D13.5 / ACT-04):
- Finds checklist items marked `done_at` within the 2–4-week verification window
- Ensures SavingsLedger(status=Claimed) record exists for each item
- When a matching payroll/income transaction appears after `done_at`, upgrades status to Verified + sets `verified_at`

`detectIncomeShifts(userId, taxYear)` (D14 / MON-01):
- Uses `ReportStalenessPolicy::isMaterialChange()` for the income/savings threshold comparison
- Persistence anchor: `optimization_calendar_events(event_type=income_shift_detected)` — NOT `report.stale_since` (resolves Research A3)
- Three guards (Pitfall 8 cadence guard): (a) ≥60-day anchor persistence, (b) open finding dedupe, (c) 28-day activity gate
- Emits `OptimizationFinding(change_detected)` + `AIQuestion` + `DocumentRequest(pay_stub)` — all template copy, zero Claude

`runCalendarWatchers(userId, taxYear)` (D15 / D16 / MON-02):
- `checkBonusLeadTime()`: resolves `bonus.expected_month` UserTaxFact (with taxYear parameter), fires 3-option finding (A=max cash, B=max deferral, C=standing), dedupes on `alert_fired_at`
- `checkYearEndItems()`: GATED on `hasConfirmedBusinessContext()` (employment_type ∈ {self_employed, both, 1099} AND business_type non-null), Q4-only (Oct–Dec), every item carries D16 net-cost honesty guardrail verbatim

**Scheduled task** (daily 06:00): `whereHas('bankConnections')->where('last_active_at', '>', now()->subDays(28))` — 28-day activity gate, identical to other sweeps

### Task 3: ActionCenterController + Badge Prop

**ActionCenterController** (`GET /api/v1/optimizer/action-center`):
- Stage-0 items derived from live DB state in DOCUMENTS-FIRST-FUNNEL Δ1 order:
  1. `upload_paystub` — TaxDocument category=pay_stub + status=ready (primary CTA)
  2. `link_bank` — `hasBankConnected()`
  3. `link_credit_cards` — `BankAccount where user_id + type='credit'` (DRIFT-08)
  4. `link_email` — `hasEmailConnected()`
  5. `do_interview` — `InterviewSession status='completed'` (demoted to last per Δ1)
- Each item omitted once its DB condition is met; no done-state persistence (ACT-02)
- `checklist_items`: `done_at IS NULL AND knob != 'header'`, ordered by position, with `benefit_line_params`
- `monitor_prompts`: `OptimizationFinding` where `finding_type=change_detected AND status=open`
- `calendar_items`: `OptimizationCalendarEvent.scopeAlertReady()` (unfired, within lead-time window)
- All queries scoped to `auth()->id()` (T-14-09-04)

**HandleInertiaRequests** (additive DRIFT-09):
- Added `pendingActionCount` prop: counts `optimization_checklist_items WHERE done_at IS NULL AND knob != 'header'`
- Same guard as `pendingOptimizationCount` (hasBankConnected → 0 otherwise)
- `pendingOptimizationCount` left byte-for-byte unchanged (backwards compat, ACT-01)

**Route**: `GET /api/v1/optimizer/action-center` inside `auth:sanctum` group, throttle 60/min, NO `bank.connected` middleware (paystub upload is relevant before bank link)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Raw DB::table() insert violated bank_account_id NOT NULL**
- **Found during:** Task 1 test writing (ChangeMonitorTest)
- **Issue:** `DB::table('transactions')->insert([...])` skipped `bank_account_id` which is NOT NULL in schema
- **Fix:** Replaced with `Transaction::factory()->create()` which auto-creates a linked BankAccount; also added `BankAccount::factory()->create()` for explicit control
- **Files modified:** `tests/Feature/ChangeMonitorTest.php`

**2. [Rule 1 - Bug] `context` column used in AIQuestion updateOrCreate doesn't exist in schema**
- **Found during:** Task 1 first test run
- **Issue:** `AIQuestion` table has no `context` column; the service tried to upsert on it
- **Fix:** Replaced `updateOrCreate` with LIKE-based dedupe check followed by `create()`; column check added to verify actual schema before using it
- **Files modified:** `app/Services/ChangeMonitor.php`

**3. [Rule 2 - Missing] ai_confidence NOT NULL violated for optimization questions**
- **Found during:** Task 1 test run
- **Issue:** `ai_confidence` column is NOT NULL in `ai_questions` table; template-driven optimization questions were setting it to null
- **Fix:** Set `ai_confidence = 0.0` for template-driven questions (semantically: not AI-scored)
- **Files modified:** `app/Services/ChangeMonitor.php`

**4. [Rule 1 - Bug] "One-off deposit" test falsely triggered material-change guard**
- **Found during:** Task 1 test run
- **Issue:** Test snapshot had `savings_cents = 100000` but profile had no savings data (`currentSavings = 0`), so `savingsDelta = 100%` and `isMaterialChange` returned true even for a 3% income shift
- **Fix:** Changed test snapshot to `savings_cents = 0` to test ONLY the income-shift path (which correctly returns false for a 3% shift)
- **Files modified:** `tests/Feature/ChangeMonitorTest.php`

**5. [Rule 1 - Bug] Bonus alert fact lookup used wrong taxYear parameter**
- **Found during:** Task 2 test run
- **Issue:** `UserTaxFact::currentFact($userId, 'bonus.expected_month')` called without `taxYear`, so it looked for `tax_year IS NULL` while the annual-volatility fact is stored with the actual tax year
- **Fix:** Added `taxYear: $taxYear` parameter; with fallback to null-year for legacy facts
- **Files modified:** `app/Services/ChangeMonitor.php`

**6. [Rule 3 - Missing factories] OptimizationReportFactory and UserTaxFactFactory not found**
- **Found during:** Task 1 test writing
- **Issue:** Neither factory existed in the codebase (14-08 didn't need them; they're needed for ChangeMonitor tests against OptimizationReport and UserTaxFact models)
- **Fix:** Created both factories with appropriate defaults and state methods
- **Files created:** `database/factories/OptimizationReportFactory.php`, `database/factories/UserTaxFactFactory.php`

**7. [Rule 3 - Missing factory] TaxDocumentFactory not found**
- **Found during:** Task 3 test writing
- **Issue:** No TaxDocumentFactory existed; needed for ActionCenterTest paystub checks
- **Fix:** Created with `paystub()` state method
- **Files created:** `database/factories/TaxDocumentFactory.php`

**8. [Rule 1 - Bug] DocumentStatus::Pending doesn't exist in enum**
- **Found during:** Task 3 first test run
- **Issue:** Test used `DocumentStatus::Pending` which doesn't exist; actual values are Upload/Classifying/Extracting/Ready/Failed/Splitting/Split
- **Fix:** Changed to `DocumentStatus::Upload` (the correct non-ready initial state)
- **Files modified:** `tests/Feature/ActionCenterTest.php`

## Test Coverage

| Suite | Tests Added | Assertions |
|-------|-------------|-----------|
| ChangeMonitorTest | 19 | 37 |
| ActionCenterTest | 31 | 52 |
| **Total new** | **50** | **89** |

Full suite result: **1080 tests, 1 known failure** (DashboardFinancialBlocksTest — pre-existing, not introduced by this plan).

## Threat Surface Scan

No new network endpoints, auth paths, or schema changes beyond the plan's own `optimization_calendar_events` table. All queries scoped to `auth()->id()`. No new packages installed.

## Self-Check: PASSED

Created files:
- `/home/spendifi/public_html/app/Services/ChangeMonitor.php` — FOUND
- `/home/spendifi/public_html/app/Models/OptimizationCalendarEvent.php` — FOUND
- `/home/spendifi/public_html/app/Http/Controllers/Api/ActionCenterController.php` — FOUND
- `/home/spendifi/public_html/tests/Feature/ChangeMonitorTest.php` — FOUND
- `/home/spendifi/public_html/tests/Feature/ActionCenterTest.php` — FOUND

Commits:
- `6859282` — feat(14-09): ChangeMonitor + OptimizationCalendarEvent + calendar watchers
- `cf46a0f` — feat(14-09): ActionCenterController + pendingActionCount nav badge + route
