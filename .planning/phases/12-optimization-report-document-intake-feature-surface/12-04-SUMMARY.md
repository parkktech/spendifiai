---
phase: 12-optimization-report-document-intake-feature-surface
plan: "04"
subsystem: staleness-wiring-report-api-pro-review-badge
tags: [staleness, report-api, pro-review-export, nav-badge, RPT-02, RPT-05, UI-01]
status: complete
requirements: [RPT-05]

dependency_graph:
  requires:
    - phases/12-02 (TaxDocumentExtracted event)
    - phases/12-03 (OptimizationReport model + GenerateOptimizationReport ShouldBeUnique job + OptimizationReportExportService)
    - phases/11 (OptimizationProfileBuilt + UserAnsweredQuestion events, OptimizationFinding model)
  provides:
    - MarkOptimizationReportStale listener (flag-flip on TaxDocumentExtracted/OptimizationProfileBuilt/UserAnsweredQuestion)
    - DispatchReportGeneration listener (30s debounced ShouldBeUnique job on doc extraction + profile rebuild)
    - OptimizationReportController (GET/regenerate/download + RPT-05 pro-review export)
    - OptimizationProReviewExportService (RPT-05 packet: static legal_basis + defensibility + persistent disclaimer)
    - pendingOptimizationCount Inertia shared prop (guarded by hasBankConnected)
  affects:
    - 12-05-PLAN (UI-01 nav badge consumes pendingOptimizationCount; report pages consume GET/download endpoints)
    - AppServiceProvider (Phase 12-04 event listener block registered)
    - HandleInertiaRequests (additive pendingOptimizationCount in auth)

tech_stack:
  added:
    - optimization-pro-review.blade.php (Blade + inline CSS for dompdf; RPT-05 packet layout)
    - config/optimization-report.php: defensibility_ratings map + pro_review_disclaimer (RPT-05 static)
  patterns:
    - Flag-flip-only staleness (no thundering-herd): MarkOptimizationReportStale = DB UPDATE only, never dispatches
    - Debounced ShouldBeUnique job dispatch: DispatchReportGeneration adds 30s delay; GenerateOptimizationReport uniqueId coalesces bursts
    - fetchOrInit Pitfall-8 null guard: GET show() always returns a report model (never null/500)
    - RPT-05 export gate: assertExportReady() blocks when docs_missing non-empty or pro_export_ready=false (422)
    - Static defensibility rating: band → config map (never Claude output, never user data)
    - scopeForUser() on every controller action (T-12-04-01 cross-user isolation)
    - Encrypted user_assertions decrypted only in pro-review service (T-12-04-02)
    - HandleInertiaRequests additive prop guarded by hasBankConnected (T-12-04-04)

key_files:
  created:
    - app/Listeners/MarkOptimizationReportStale.php
    - app/Listeners/DispatchReportGeneration.php
    - app/Http/Controllers/Api/OptimizationReportController.php
    - app/Services/OptimizationProReviewExportService.php
    - resources/views/pdf/optimization-pro-review.blade.php
    - tests/Feature/ReportStalenessTest.php
    - tests/Feature/OptimizationReportControllerTest.php
    - tests/Feature/HandleInertiaRequestsTest.php
  modified:
    - app/Providers/AppServiceProvider.php (Phase 12-04 event listener block + finding route model binding)
    - app/Http/Controllers/Api/UserProfileController.php (BuildIncomeOptimizationProfile dispatch on profile save)
    - app/Http/Middleware/HandleInertiaRequests.php (pendingOptimizationCount additive prop)
    - routes/api.php (optimizer/report prefix group — 4 routes)
    - config/optimization-report.php (defensibility_ratings + pro_review_disclaimer)

decisions:
  - "MarkOptimizationReportStale performs only a DB UPDATE (flag flip) — never dispatches GenerateOptimizationReport; DispatchReportGeneration is the separate listener that adds the 30s delay"
  - "UserAnsweredQuestion guard: only QuestionType::Optimization triggers stale flag; mirrors UpdateOptimizationFromAnswer FEED-04 guard pattern exactly"
  - "Show endpoint uses fetchOrInit (Pitfall 8) + dispatches generation on stale/empty; always returns a model instance with status=generating or ready"
  - "pro_review_disclaimer in config — static string, not Claude output; applies to every PDF page via Blade template footer"
  - "Defensibility rating mapped from finding.band via config/optimization-report.php defensibility_ratings — static, never computed from user data"
  - "user_assertions decrypted only inside OptimizationProReviewExportService::decryptUserAssertions(); $hidden on model prevents API leakage"
  - "pendingOptimizationCount: open + whereNotNull(description) — un-narrated findings (null description, not yet processed by Phase 11 listener) are excluded from the badge count"
  - "BinaryFileResponse::getContent() returns false in Laravel tests — PDF content checks use the service directly; HTTP tests check status/content-type header only"

metrics:
  duration: "~14 minutes"
  completed_date: "2026-07-02"
  tasks_completed: 3
  tasks_total: 3
  files_created: 8
  files_modified: 5
  tests_added: 24
  assertions_added: 61
  commits: 3
---

# Phase 12 Plan 04: Staleness Wiring, Report API, Pro-Review Export, Badge Prop Summary

**One-liner:** Staleness listeners (flag-flip + debounced unique regen) + owner-scoped report GET/regenerate/download + RPT-05 pro-review export service (static legal basis, config defensibility rating, persistent disclaimer, 422 gate) + pendingOptimizationCount Inertia prop.

## What Was Built

### Task 1 — Staleness Listeners + Event Wiring + Profile-Change Staleness (RPT-02)

**`app/Listeners/MarkOptimizationReportStale.php`** — Queued listener handling three events:
- `TaxDocumentExtracted` → `handleTaxDocumentExtracted()`: scopes to document.user_id + document.tax_year, DB UPDATE `is_stale=true, stale_since=now()`.
- `OptimizationProfileBuilt` → `handleOptimizationProfileBuilt()`: same flag-flip pattern.
- `UserAnsweredQuestion` → `handleUserAnsweredQuestion()`: early-returns for non-Optimization question types (QuestionType::Optimization guard — mirrors FEED-04 UpdateOptimizationFromAnswer pattern).

CRITICAL: Flag flip ONLY — this listener never dispatches `GenerateOptimizationReport`.

**`app/Listeners/DispatchReportGeneration.php`** — Queued listener handling TaxDocumentExtracted and OptimizationProfileBuilt: `GenerateOptimizationReport::dispatch($userId, $year)->delay(now()->addSeconds(30))`. The 30s delay + `ShouldBeUnique(user:year)` coalesce bursts (Pitfall 4 — a 20-page paystub upload fires 20 events but produces one report job).

**`app/Providers/AppServiceProvider.php`** — Phase 12-04 block registered: 5 listener bindings (TaxDocumentExtracted → MarkStale + DispatchRegen; OptimizationProfileBuilt → MarkStale + DispatchRegen; UserAnsweredQuestion → MarkStale). Manual style, no auto-discovery. Route model binding for `finding` → `OptimizationFinding`.

**`app/Http/Controllers/Api/UserProfileController.php`** — Additive: after existing `CategorizePendingTransactions::dispatch()`, also dispatches `BuildIncomeOptimizationProfile::dispatch(auth()->id(), now()->year)`. This fires `OptimizationProfileBuilt` → chains through the staleness listeners. Existing profile save + categorization dispatches unchanged.

**Test coverage:** 7 tests, 17 assertions (ReportStalenessTest):
- Flag flip on TaxDocumentExtracted
- No inline GenerateOptimizationReport (Queue::fake — MarkOptimizationReportStale pushes nothing)
- DispatchReportGeneration queues a delayed job
- OptimizationProfileBuilt marks stale
- 5 optimization answers each flip the flag, no job dispatched
- Non-optimization answer does NOT mark stale
- Cross-user isolation: user1's event does not touch user2's report

### Task 2 — Report API (GET/regenerate/download) + RPT-05 Pro-Review Export

**`app/Http/Controllers/Api/OptimizationReportController.php`** — 4 endpoints, all owner-scoped:
- `show(year)` → `OptimizationReport::fetchOrInit()` (Pitfall 8 null guard). If stale or empty sections, dispatches `GenerateOptimizationReport` with 5s delay. Returns `status: generating|ready`.
- `regenerate(year)` → dispatches `GenerateOptimizationReport`, returns `status: generating`.
- `download(year)` → `OptimizationReportExportService::generatePdf()` → `response()->download()`.
- `proReviewExport(Request, OptimizationFinding)` → calls `assertExportReady()` (returns 422 if docs_missing non-empty or pro_export_ready=false) → `generatePacket()` → `response()->download()`.

**`app/Services/OptimizationProReviewExportService.php`** — RPT-05 packet generation:
- `assertExportReady()`: blocks export when `docs_missing` is non-empty or `pro_export_ready === false`.
- `generatePacket()`: assembles Blade view data (no estimated_value_cents — SAFE-03), calls dompdf (ini_set 512M memory guard), returns absolute path.
- `resolveDefensibilityRating($band)`: config lookup only — `auto → solid`, `conditional → fact-dependent`, `specialist → frequently-abused`.
- `decryptUserAssertions()`: decrypts encrypted column safely; try/catch logs and returns [] on failure.
- `buildProfessionalQuestion()`: checks `details.professional_question` first; falls back to `defaultQuestionForType()` with 6 type-matched patterns.

**`resources/views/pdf/optimization-pro-review.blade.php`** — Inline CSS only; 6 sections: fact-pattern, timestamped user assertions table, docs captured, legal basis + assumptions + citations, defensibility context, professional question. Persistent disclaimer banner at top + footer (RPT-05).

**`config/optimization-report.php`** — Added `defensibility_ratings` map + `pro_review_disclaimer` static string.

**`routes/api.php`** — `optimizer/report` prefix group, `throttle:5,1`: GET/{year}, POST/{year}/regenerate, GET/{year}/download, POST/finding/{finding}/pro-review-export.

**Test coverage:** 11 tests, 26 assertions (OptimizationReportControllerTest):
- GET returns generating status + auto-init for new user (never 500)
- GET returns ready when report has sections
- GET dispatches generation on stale
- GET never 500 for past years with no history
- Cross-user GET does not expose other users' report
- POST regenerate queues job + returns generating
- Pro-review 422 when docs_missing non-empty
- Pro-review 422 when pro_export_ready=false
- Pro-review HTTP 200 + application/pdf content-type when export-ready
- Pro-review service: PDF file starts with %PDF- and is > 50KB
- Cross-user pro-review 403

### Task 3 — Nav Badge Shared Prop (UI-01 backend support)

**`app/Http/Middleware/HandleInertiaRequests.php`** — Additive `pendingOptimizationCount` in the `auth` array:
```php
'pendingOptimizationCount' => ($request->user()?->hasBankConnected())
    ? OptimizationFinding::forUser($request->user()->id)
        ->where('status', 'open')
        ->whereNotNull('description')
        ->count()
    : 0,
```
- Guarded by `hasBankConnected` (0 for unauthenticated/bank-less users).
- `status = 'open'` confirmed by `SurfaceHighPriorityRedFlags` grep (T-12-04-04 scope guard).
- `whereNotNull('description')`: excludes un-narrated findings (Phase 11 narrator has not yet run on them).
- No existing shared props modified.

**Test coverage:** 6 tests, 18 assertions (HandleInertiaRequestsTest):
- Integer type assertion
- 0 when no bank connected
- Reflects count of open narrated findings for bank-connected user
- Does not count resolved findings
- Does not count un-narrated (null description) findings
- Existing shared props (hasBankConnected, isAdmin, etc.) still present

## Deviations from Plan

None — plan executed exactly as written.

Open Questions from plan confirmed in-place:
- **A1 (OptimizationFinding 'open' status):** Confirmed via `SurfaceHighPriorityRedFlags.php:56` — `.where('status', 'open')`.
- **A3 (UserProfileController existing dispatches):** Confirmed `CategorizePendingTransactions::dispatch()` is the only existing dispatch; `BuildIncomeOptimizationProfile::dispatch()` added additively.

## Known Stubs

None. All endpoints return real data:
- Report GET: `OptimizationReport::fetchOrInit()` returns real model; sections from `OptimizationReportGeneratorService`.
- Pro-review export: `user_assertions`, `legal_basis`, `assumptions`, `docs_captured` from real model fields; defensibility from config.
- Badge count: live DB query via `OptimizationFinding::forUser()`.

## Threat Surface Scan

No new network endpoints or auth paths beyond the planned `optimizer/report/*` routes. STRIDE register mitigations confirmed implemented:

| Threat | Mitigation Applied |
|--------|-------------------|
| T-12-04-01: Cross-user report/finding access | `scopeForUser()` on all controller queries + explicit `user_id !== auth()->id()` owner-check on proReviewExport |
| T-12-04-02: Unverified facts as advice | `assertExportReady()` 422 gate; `pro_review_disclaimer` on every PDF page; `legal_basis`/`defensibility_rating` are static config, never Claude output |
| T-12-04-03: Thundering-herd rebuilds | `MarkOptimizationReportStale` = flag only (never dispatches); `DispatchReportGeneration` + 30s delay + `ShouldBeUnique` coalesce bursts |
| T-12-04-04: Badge count leaking cross-user findings | `forUser()` scope on `pendingOptimizationCount` + guarded by `hasBankConnected` |
| T-12-04-SC: npm/composer installs | None — no packages installed this plan |

## Verification Results

| Check | Result |
|-------|--------|
| `php artisan test --filter=ReportStalenessTest` | 7 passed, 17 assertions |
| `php artisan test --filter=OptimizationReportControllerTest` | 11 passed, 26 assertions |
| `php artisan test --filter=HandleInertiaRequestsTest` | 6 passed, 18 assertions |
| `php artisan test --compact` (full suite) | 790 passed, 1 known pre-existing failure (DashboardFinancialBlocksTest), 0 new failures |
| `vendor/bin/pint --dirty` | pass |
| SAFE-03 grep (estimated_value_cents in new files) | Comments only — no writes or reads in service logic |
| No inline report generation on events | Queue::fake assertion in ReportStalenessTest confirms (MarkOptimizationReportStale pushes nothing) |

## Self-Check: PASSED

- `app/Listeners/MarkOptimizationReportStale.php` — FOUND
- `app/Listeners/DispatchReportGeneration.php` — FOUND
- `app/Http/Controllers/Api/OptimizationReportController.php` — FOUND
- `app/Services/OptimizationProReviewExportService.php` — FOUND
- `resources/views/pdf/optimization-pro-review.blade.php` — FOUND
- `tests/Feature/ReportStalenessTest.php` — FOUND, 7 tests pass
- `tests/Feature/OptimizationReportControllerTest.php` — FOUND, 11 tests pass
- `tests/Feature/HandleInertiaRequestsTest.php` — FOUND, 6 tests pass
- Commits a4cc332, 3260bea, 108a0d8 — verified in git log
