# Phase 9: Intelligence Layer & Final Validation - Context

**Gathered:** 2026-03-31
**Status:** Ready for planning

<domain>
## Phase Boundary

AI detects missing documents from transaction patterns, flags cross-document anomalies, links transactions to documents, and the full milestone passes comprehensive testing and build validation. This is the final phase of v2.0 — it closes the milestone.

</domain>

<decisions>
## Implementation Decisions

### Missing Document Detection (INTEL-01, INTEL-04)
- New TaxDocumentIntelligenceService in app/Services/AI/ namespace
- Cross-references Plaid transaction categories with expected tax form types:
  - Freelance/contractor income (1099-NEC expected)
  - Interest income (1099-INT expected)
  - Dividend income (1099-DIV expected)
  - Mortgage payments (1098 expected)
  - Employment income (W-2 expected)
- Detection runs on-demand when user views vault (not background job — results cached)
- Missing document alerts include explanation: "Based on $X,XXX in freelance income from [merchant], we expect a 1099-NEC"
- Alerts surfaced via the existing MissingAlertBanner component (Phase 6) — already has expandable details
- Uses existing transaction data from Plaid — no new API calls needed

### Cross-Document Anomaly Detection (INTEL-02)
- Compares extracted document values against transaction totals:
  - W-2 wages vs bank deposit totals from employer
  - 1099-NEC amounts vs freelance income deposits
  - 1098 mortgage interest vs mortgage payment totals
- Anomalies flagged with severity (info/warning/alert) and explanation
- Displayed in vault view as a separate "Anomalies" section or integrated into MissingAlertBanner
- Tolerance threshold configurable in config/spendifiai.php (e.g., 5% variance before flagging)

### Transaction-to-Document Linking (INTEL-03)
- New pivot table/relationship linking transactions to tax documents
- Linking logic: match transactions to documents by merchant/employer name, tax year, and category
- Links visible in both directions:
  - Transaction detail shows linked document(s)
  - Document detail shows linked transaction(s)
- Linking runs as part of the intelligence analysis (same service)

### Comprehensive Test Coverage (TEST-01)
- Feature tests for ALL new v2.0 API endpoints across Phases 6-8:
  - Document upload, list, show, download, delete, purge
  - Extraction field correction, accept-all, retry
  - Accountant firm registration, invite, dashboard
  - Annotations CRUD, document requests CRUD
  - Audit log viewing, chain verification
  - Storage config (admin)
- Tests supplement existing Phase 7 (18 tests) and Phase 8 (25 tests)
- Focus on endpoint coverage gaps — don't duplicate existing tests

### Build Validation (TEST-05, TEST-06)
- `npm run build` must succeed with zero TypeScript errors
- `vendor/bin/pint` must report no formatting issues
- Fix any issues found — this is a quality gate, not just a check

### Claude's Discretion
- Exact matching algorithm for transaction-to-document linking
- Anomaly detection tolerance percentages
- Intelligence cache duration and invalidation strategy
- How to handle edge cases (partial year data, missing Plaid categories)
- Test organization and naming conventions
- Pint/TypeScript fix prioritization

</decisions>

<specifics>
## Specific Ideas

- TaxDocumentIntelligenceService follows the same pattern as TaxDocumentExtractorService — service in AI/ namespace, called from controller
- Missing document detection should be smart but not noisy — only flag when there's strong transaction evidence
- Anomaly explanations should be human-readable: "Your W-2 shows $65,000 in wages, but we only see $58,000 in deposits from this employer"
- Transaction-to-document links should be lightweight — don't over-engineer, simple pivot table
- The test/build validation tasks are the milestone quality gate — everything must be green before v2.0 ships

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- `TaxDocumentExtractorService` (Phase 7): Pattern for AI service in app/Services/AI/
- `TransactionCategorizerService`: Uses Plaid transaction categories — same data source for intelligence
- `MissingAlertBanner` component (Phase 6): Already displays missing doc alerts with expandable details
- `TaxDocument` model: Has extracted_data with field values for anomaly comparison
- `Transaction` model: Has plaid_category, merchant_name, amount — source for intelligence
- `DocumentRequest` model (Phase 8): Auto-fulfillment pattern can inform linking logic
- Existing tests: Phase 7 (18 tests), Phase 8 (25 tests) — supplement, don't duplicate

### Established Patterns
- AI services in app/Services/AI/ with Http:: facade for Claude calls
- Config-driven thresholds in config/spendifiai.php
- useApi hook for frontend data fetching
- sw-* Tailwind tokens for UI consistency
- Pest PHP for tests with Http::fake() for AI mocking

### Integration Points
- Intelligence API endpoint: GET /api/v1/tax-vault/intelligence?year={year}
- Transaction links: new pivot table tax_document_transaction
- Vault page: intelligence data feeds MissingAlertBanner + new anomaly display
- Transaction page: show linked documents
- Document detail: show linked transactions
- Test runner: php artisan test --compact
- Build: npm run build, vendor/bin/pint

</code_context>

<deferred>
## Deferred Ideas

None — this is the final phase of v2.0

</deferred>

---

*Phase: 09-intelligence-layer-final-validation*
*Context gathered: 2026-03-31*
