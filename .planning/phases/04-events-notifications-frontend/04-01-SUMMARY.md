---
phase: 04-events-notifications-frontend
plan: 01
subsystem: events, notifications, jobs
tags: [laravel-events, listeners, queued-jobs, notifications, plaid-sync, event-driven]

# Dependency graph
requires:
  - phase: 02-auth-bank-integration
    provides: PlaidController, AIQuestionController, CategorizePendingTransactions job
  - phase: 03-ai-intelligence-financial-features
    provides: TransactionCategorizerService, SubscriptionDetectorService, SavingsAnalyzerService
provides:
  - 4 Laravel event classes (BankConnected, TransactionsImported, TransactionCategorized, UserAnsweredQuestion)
  - 6 queued listener classes wiring the event chain
  - SyncBankTransactions job with retry backoff
  - 4 notification classes (AIQuestionsReady, UnusedSubscriptionAlert, BudgetThresholdReached, WeeklySavingsDigest)
  - Scheduled bank sync every 4 hours, weekly savings digest Monday 07:00
  - Notifications table migration
affects: [04-02, 04-03, frontend, testing]

# Tech tracking
tech-stack:
  added: []
  patterns: [event-driven-architecture, queued-listeners, database-mail-notifications, scheduled-job-dispatch]

key-files:
  created:
    - app/Events/BankConnected.php
    - app/Events/TransactionsImported.php
    - app/Events/TransactionCategorized.php
    - app/Events/UserAnsweredQuestion.php
    - app/Listeners/TriggerInitialSync.php
    - app/Listeners/DispatchCategorizationJob.php
    - app/Listeners/UpdateSubscriptionDetection.php
    - app/Listeners/UpdateTransactionCategory.php
    - app/Listeners/CheckBudgetThresholds.php
    - app/Listeners/NotifyQuestionsReady.php
    - app/Jobs/SyncBankTransactions.php
    - app/Notifications/AIQuestionsReady.php
    - app/Notifications/UnusedSubscriptionAlert.php
    - app/Notifications/BudgetThresholdReached.php
    - app/Notifications/WeeklySavingsDigest.php
    - database/migrations/2026_02_11_203522_create_notifications_table.php
  modified:
    - app/Http/Controllers/Api/PlaidController.php
    - app/Http/Controllers/Api/AIQuestionController.php
    - app/Jobs/CategorizePendingTransactions.php
    - routes/console.php

key-decisions:
  - "Notification classes created alongside listeners in Task 1 since listeners reference them directly"
  - "PlaidController exchangeToken now dispatches BankConnected event instead of inline sync+categorize calls"
  - "Unused subscription notifications dispatched inline after daily detection schedule (not via separate listener)"
  - "SyncBankTransactions job re-throws exceptions after logging to allow queue retry mechanism"

patterns-established:
  - "Event chain: BankConnected -> sync -> TransactionsImported -> categorize -> TransactionCategorized -> [detect subs, check budgets, notify questions]"
  - "All listeners implement ShouldQueue for async processing"
  - "Notifications use dual ['database', 'mail'] channels"
  - "Laravel 12 auto-discovery for event-listener mapping (no EventServiceProvider needed)"

# Metrics
duration: 4min
completed: 2026-02-11
---

# Phase 4 Plan 1: Events & Notifications Summary

**Event-driven architecture with 4 events, 6 queued listeners, SyncBankTransactions job, and 4 database+email notification classes**

## Performance

- **Duration:** 4 min
- **Started:** 2026-02-11T21:06:43Z
- **Completed:** 2026-02-11T21:10:55Z
- **Tasks:** 2
- **Files modified:** 20

## Accomplishments
- Built full event-driven chain: BankConnected -> TriggerInitialSync -> SyncBankTransactions -> TransactionsImported -> DispatchCategorizationJob -> TransactionCategorized -> [UpdateSubscriptionDetection, CheckBudgetThresholds, NotifyQuestionsReady]
- Created SyncBankTransactions job with escalating retry backoff (60s, 5min, 15min) and error status tracking
- Built 4 notification classes with database+email dual channels: AI questions ready, unused subscriptions, budget thresholds, weekly savings digest
- Wired event dispatches into PlaidController, AIQuestionController, and CategorizePendingTransactions job
- Configured scheduled tasks: bank sync every 4 hours, weekly savings digest Monday 07:00, unused subscription alerts after daily detection

## Task Commits

Each task was committed atomically:

1. **Task 1: Create events, listeners, SyncBankTransactions job, and wire event chain** - `50542d7` (feat)
2. **Task 2: Run notifications migration and verify notification classes** - `10a1de1` (feat)

## Files Created/Modified
- `app/Events/BankConnected.php` - Event dispatched after Plaid token exchange
- `app/Events/TransactionsImported.php` - Event dispatched after transaction sync
- `app/Events/TransactionCategorized.php` - Event dispatched after AI categorization
- `app/Events/UserAnsweredQuestion.php` - Event dispatched after user answers question
- `app/Listeners/TriggerInitialSync.php` - Dispatches SyncBankTransactions on BankConnected
- `app/Listeners/DispatchCategorizationJob.php` - Dispatches CategorizePendingTransactions on TransactionsImported
- `app/Listeners/UpdateSubscriptionDetection.php` - Runs subscription detection after categorization
- `app/Listeners/UpdateTransactionCategory.php` - Post-answer side effects (subscription re-detection, logging)
- `app/Listeners/CheckBudgetThresholds.php` - Checks budget goals after categorization, notifies on threshold
- `app/Listeners/NotifyQuestionsReady.php` - Sends AIQuestionsReady notification if questions were created
- `app/Jobs/SyncBankTransactions.php` - Per-connection Plaid sync with retry backoff and error handling
- `app/Notifications/AIQuestionsReady.php` - Database+email notification for new AI questions
- `app/Notifications/UnusedSubscriptionAlert.php` - Database+email notification for unused subscriptions
- `app/Notifications/BudgetThresholdReached.php` - Database+email notification for budget threshold/exceeded
- `app/Notifications/WeeklySavingsDigest.php` - Weekly savings email digest with recommendations and target progress
- `database/migrations/2026_02_11_203522_create_notifications_table.php` - Notifications table for database channel
- `app/Http/Controllers/Api/PlaidController.php` - Added BankConnected::dispatch, removed inline sync
- `app/Http/Controllers/Api/AIQuestionController.php` - Added UserAnsweredQuestion::dispatch to answer/bulkAnswer
- `app/Jobs/CategorizePendingTransactions.php` - Added TransactionCategorized::dispatch with counts
- `routes/console.php` - Uncommented SyncBankTransactions schedule, added weekly digest and unused sub alerts

## Decisions Made
- **Notification classes created in Task 1**: Since listeners like CheckBudgetThresholds and NotifyQuestionsReady reference notification classes, all 4 were created in Task 1 alongside the listeners to avoid syntax errors. Task 2 focused on migration and verification.
- **PlaidController event dispatch replaces inline sync**: Instead of calling sync+categorize inline in exchangeToken, we now dispatch BankConnected which triggers the full event chain asynchronously via queued listeners.
- **Unused subscription alerts inline in schedule**: Rather than a separate listener, unused subscription notifications are dispatched inline after the daily subscription detection schedule, since detection and notification are tightly coupled.
- **SyncBankTransactions re-throws after logging**: The job catches exceptions to update connection status to error, then re-throws so the queue retry mechanism (3 tries with 60s/5min/15min backoff) can handle transient failures.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Event-driven architecture fully wired, ready for frontend to consume notifications via API
- All backend features now connected via events: bank connections trigger syncs, syncs trigger categorization, categorization triggers subscription detection and budget checks
- Notifications table ready for frontend notification center
- Ready for Plan 02 (Plaid webhook handler) and Plan 03 (frontend)

## Self-Check: PASSED

All 16 created files verified present. Both task commits (50542d7, 10a1de1) verified in git log.

---
*Phase: 04-events-notifications-frontend*
*Plan: 01*
*Completed: 2026-02-11*
