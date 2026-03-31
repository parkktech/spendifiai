---
phase: 06-document-vault-audit-foundation
plan: 02
subsystem: api
tags: [controllers, routes, policy, form-request, api-resource, queue-job, signed-url, audit-trail]

requires:
  - phase: 06-01
    provides: TaxDocument model, TaxVaultStorageService, TaxVaultAuditService, DocumentStatus enum
provides:
  - TaxDocumentController with CRUD + admin purge
  - TaxVaultAuditController with audit log and chain verification endpoints
  - StorageConfigController for admin storage management
  - TaxDocumentUploadRequest for file validation (PDF/JPG/PNG, 100MB)
  - TaxDocumentPolicy with owner, accountant, and admin authorization
  - MigrateStorageJob for background storage driver migration
  - API routes for vault (7 routes) and admin (7 routes)
  - Web routes for /vault and /admin/storage pages
affects: [06-03, 06-04, 07-ai-classification, 08-accountant-portal]

tech-stack:
  added: []
  patterns: [thin controller + service injection, policy authorization on every action, audit log on every document access, cache-based storage config]

key-files:
  created:
    - app/Http/Controllers/Api/TaxDocumentController.php
    - app/Http/Controllers/Api/TaxVaultAuditController.php
    - app/Http/Controllers/Api/StorageConfigController.php
    - app/Http/Requests/TaxDocumentUploadRequest.php
    - app/Http/Requests/Admin/StorageConfigRequest.php
    - app/Http/Resources/TaxDocumentResource.php
    - app/Http/Resources/TaxVaultAuditLogResource.php
    - app/Policies/TaxDocumentPolicy.php
    - app/Jobs/MigrateStorageJob.php
  modified:
    - routes/api.php
    - routes/web.php

key-decisions:
  - "Used isAdmin() for admin checks in controllers (consistent with 06-01 pattern, not user_type enum)"
  - "Stored S3 credentials encrypted in cache with no expiry for runtime-safe config changes"
  - "Purge endpoint accepts int param and uses withTrashed() to find soft-deleted documents"

patterns-established:
  - "Tax vault API routes under v1/tax-vault prefix, admin routes under admin prefix"
  - "Every document action (view/download/upload/delete/purge) creates audit log entry; list does not"
  - "TaxDocumentResource uses service injection via app() to generate signed URLs"

requirements-completed: [VAULT-01, VAULT-03, VAULT-04, VAULT-05, VAULT-06, VAULT-07, VAULT-08, AUDIT-05]

duration: 3min
completed: 2026-03-30
---

# Phase 6 Plan 2: Vault API Layer Summary

**Tax vault API controllers with policy authorization, signed URL downloads, admin purge, storage config management, and audit logging on every document action**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-31T01:18:52Z
- **Completed:** 2026-03-31T01:21:36Z
- **Tasks:** 2
- **Files modified:** 11

## Accomplishments
- Three API controllers (TaxDocument, TaxVaultAudit, StorageConfig) with thin controller + service pattern
- TaxDocumentPolicy with owner, linked accountant, and admin purge authorization
- MigrateStorageJob with chunked processing and cache-based progress tracking
- 14 routes registered (7 vault API + 5 admin storage API + 1 admin purge + 1 admin web page + vault web page)

## Task Commits

Each task was committed atomically:

1. **Task 1: Create form requests, resources, policy, and migration job** - `6ee4d72` (feat)
2. **Task 2: Create controllers and register routes** - `2b1cefa` (feat)

## Files Created/Modified
- `app/Http/Requests/TaxDocumentUploadRequest.php` - Validates PDF/JPG/PNG, 100MB max, tax_year 2000-next year
- `app/Http/Requests/Admin/StorageConfigRequest.php` - Validates S3 credentials required when driver is s3
- `app/Http/Resources/TaxDocumentResource.php` - Serializes document with signed URL, excludes stored_path/disk
- `app/Http/Resources/TaxVaultAuditLogResource.php` - Audit entries with conditional ip/user_agent for admins
- `app/Policies/TaxDocumentPolicy.php` - viewAny, view, create, delete, purge, viewAuditLog methods
- `app/Jobs/MigrateStorageJob.php` - Background migration between storage drivers with progress cache
- `app/Http/Controllers/Api/TaxDocumentController.php` - CRUD + download + admin purge with audit logging
- `app/Http/Controllers/Api/TaxVaultAuditController.php` - Audit log listing and chain verification
- `app/Http/Controllers/Api/StorageConfigController.php` - Admin storage show/update/test/migrate/status
- `routes/api.php` - Added tax-vault and admin storage/purge routes
- `routes/web.php` - Added /vault and /admin/storage web routes

## Decisions Made
- Used `isAdmin()` boolean for admin checks (consistent with 06-01, not user_type enum)
- S3 credentials stored encrypted in cache with no expiry for runtime-safe config (no .env mutation)
- Purge route accepts int param and uses `withTrashed()` to handle already-soft-deleted documents
- List action (index) does not create audit log entries to avoid noise

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed admin check to use isAdmin() instead of user_type**
- **Found during:** Task 1 (StorageConfigRequest) and Task 2 (controllers)
- **Issue:** Plan specified `$this->user()->user_type === 'admin'` but project uses `is_admin` boolean, not a user_type enum case for admin
- **Fix:** Used `$this->user()->isAdmin()` in StorageConfigRequest authorize() and `abort_unless($request->user()->isAdmin(), 403)` in controllers
- **Files modified:** app/Http/Requests/Admin/StorageConfigRequest.php, app/Http/Controllers/Api/StorageConfigController.php
- **Verification:** Consistent with 06-01 pattern and User model's isAdmin() method
- **Committed in:** 6ee4d72 (Task 1) and 2b1cefa (Task 2)

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Essential fix for correctness. Same deviation identified and fixed in 06-01.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- API layer complete and ready for frontend integration (Plan 06-03)
- All CRUD operations functional with proper authorization
- Audit logging active on every document access
- Storage config management ready for admin UI
- Policy handles accountant access for future accountant portal (Phase 8)

## Self-Check: PASSED

- All 9 created files verified present on disk
- Commit 6ee4d72 (Task 1) verified in git log
- Commit 2b1cefa (Task 2) verified in git log
- 7 tax-vault routes confirmed via route:list
- 7 admin routes confirmed (5 storage + 1 purge + 1 web)
- All policy methods exist: viewAny, view, create, delete, purge, viewAuditLog
- Code style passes (pint --dirty)

---
*Phase: 06-document-vault-audit-foundation*
*Completed: 2026-03-30*
