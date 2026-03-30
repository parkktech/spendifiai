# Project Research Summary

**Project:** SpendifiAI v2.0 — Tax Document Vault & Accountant Portal
**Domain:** Tax document management, AI extraction, accountant-client collaboration
**Researched:** 2026-03-30
**Confidence:** HIGH

## Executive Summary

SpendifiAI v2.0 adds a Tax Document Vault and Accountant Collaboration Portal on top of an already-mature finance platform (32 models, 14 services, 10 jobs). The new feature set follows a well-trodden pattern in tax practice management software (TaxDome, SmartVault, Canopy), which means the feature requirements and risks are well-understood. The existing codebase provides strong foundations: an accountant-client relationship system, a PDF-to-Claude AI pipeline (`BankStatementParserService`), Redis queue infrastructure, and a confidence-threshold pattern in `config/spendifiai.php` — all of which the new features extend rather than replace. The build is primarily additive: 11 new models, 5 new controllers, 6 new services, and 3 new packages.

The recommended approach is a three-phase build. Phase 1 establishes the secure document vault with AI classification and extraction (the foundation everything else depends on). Phase 2 adds accountant collaboration tools (document requests, comments, client completeness view). Phase 3 delivers the dual sign-off workflow and document sharing. This ordering is dictated by hard dependencies: extraction must exist before worksheets, accountant access must be proven secure before sign-off workflows can be built on top of it, and the audit trail must be in place from day one — retrofitting it is painful and audit gaps are a compliance liability.

The biggest risks are security, not engineering complexity. Tax documents contain SSN, EIN, and income data. The critical pitfalls are: authorization boundary failures (accountants accessing wrong clients), PII leakage through AI extraction results stored as plain JSON, signed URL token replay, and immutable audit log bypass via standard Eloquent. All four are preventable with established patterns but require intentional implementation from the start. The single most important architectural decision is to build `TaxDocumentPolicy` with full delegated-access logic (owner OR active-relationship accountant) before any document endpoint is exposed.

## Key Findings

### Recommended Stack

Only 3 new packages are needed. The existing stack handles everything else. `league/flysystem-aws-s3-v3` is required for S3 support via Laravel's Storage facade. `smalot/pdfparser` provides pure-PHP PDF text extraction without system binary dependencies (solving the `poppler-utils` problem documented in project memory). `react-pdf@^10` (React 19 compatible) provides in-browser PDF preview via signed URLs.

**Core technology additions:**
- `league/flysystem-aws-s3-v3 ^3.0`: S3 filesystem adapter — required by Laravel Storage for S3 disk, enables local/S3 toggle via config
- `smalot/pdfparser ^2.12`: Pure PHP PDF text extraction — no binary dependencies, preprocesses PDFs before sending to Claude (cheaper than vision API)
- `react-pdf ^10.0`: In-browser PDF rendering — React 19 compatible, passes signed URLs as file prop, requires pdf.js worker setup

All AI extraction uses the existing Claude API integration. All file upload validation uses existing Laravel Form Request patterns. All auth uses existing Sanctum + Policy patterns. `barryvdh/laravel-dompdf` and `phpoffice/phpspreadsheet` are already installed for export formats.

### Expected Features

**Must have (table stakes):**
- Secure multi-file upload (PDF, JPG, PNG) with server-side MIME validation and signed URL access
- AI document classification (identify form type from first page) and field extraction (W-2, 1099-NEC, 1099-INT, 1098 as first four)
- Extraction confidence scoring reusing the existing `config/spendifiai.php` threshold pattern
- Human review workflow for low-confidence extractions (side-by-side document + extracted fields)
- Append-only document audit log (every view, download, share, sign recorded)
- Document status tracking through upload/classifying/extracting/ready/failed states
- Accountant view of client documents via existing portal (extend `AccountantTaxController`)
- Document request system (accountant requests missing docs from client)
- Dual sign-off workflow (taxpayer attests completeness, accountant attests review)
- Document sharing packages with time-limited signed URLs

**Should have (differentiators):**
- AI missing document detection (cross-reference transaction categories with expected document types)
- Tax worksheet auto-population from extraction data (extracted W-2 fields flow into form lines)
- Cross-document anomaly detection (W-2 wages vs. bank deposit totals)
- Transaction-to-document linking (1099 linked to associated freelance deposits)
- Year-over-year document comparison (document count changes)

**Defer to post-MVP:**
- Tax software export formats (TurboTax TXF) — complex format specs, niche demand initially
- Firm branding on invite emails — nice-to-have, not blocking any workflow
- Remaining 21 form types beyond the initial 4 — add incrementally per user demand
- Multi-user firm management — single accountant per firm is sufficient for v2

**Explicit anti-features (do not build):**
- Full SSN/TIN storage — store last 4 only, strip from extracted data
- Legal e-signature (ESIGN Act compliance) — use simple attestation with audit log instead
- Direct IRS e-filing — requires EFIN certification, export to TurboTax instead
- Real-time collaborative document editing — comments/annotations are sufficient

### Architecture Approach

New features follow all existing patterns: thin controllers delegating to service classes, Form Request validation, Policy authorization, Redis-backed queue jobs, and config-driven thresholds. Six new services are added to the `app/Services/` layer. Eleven new models are introduced. The key architectural choices are: `TaxDocumentVersion` for versioning without re-upload, `DocumentAuditLog` as a separate immutable table from the existing `AccountantActivityLog` (different retention policies, different compliance requirements), and the `StorageSetting` model for runtime-configurable S3 credentials (must never be returned to the frontend).

**Major components:**
1. `DocumentStorageService` — file storage abstraction (local/S3 toggle), signed URL generation, encryption at rest
2. `TaxDocumentClassifierService` / `TaxDocumentExtractorService` — two-pass AI pipeline, Claude API with smalot preprocessing, confidence scoring per field
3. `TaxWorksheetService` — auto-populate worksheet fields from extraction data, cross-document validation, anomaly flagging
4. `DocumentAuditService` — immutable audit log writes with hash chain for tamper detection
5. `DocumentShareService` — share packages with short-expiry signed URLs, revocation, download tracking
6. `SignOffService` — dual sign-off state machine with `DB::transaction() + lockForUpdate()` to prevent race conditions

**5 new controllers:** `TaxDocumentController`, `TaxWorksheetController`, `DocumentShareController`, `SignOffController`, `AdminStorageController`

**4 new policies:** `TaxDocumentPolicy` (critical — owner OR active-relationship accountant), `TaxWorksheetPolicy`, `DocumentSharePolicy`, `SignOffPolicy`

### Critical Pitfalls

1. **Authorization boundary failure** — Never use `|| $user->isAccountant()` in policies. `TaxDocumentPolicy` must check the full chain: owner OR (accountant + active `AccountantClient` relationship with document owner). Extract `canAccessClient(User $accountant, User $client): bool` and use it everywhere. Write explicit cross-accountant access tests.

2. **PII leakage through AI extraction** — Store extracted data using `encrypted:array` cast (never plain JSON). Create a `SanitizedExtractionResource` that masks SSN to `***-**-1234` by default. Add regex-based PII redaction to log formatter. Never log raw Claude API responses.

3. **Signed URL token replay** — 5-15 minute expiry for direct downloads, 1-hour maximum for sharing packages. Bind signed URLs to the session by including `user_id` as a signed parameter. Generate S3 pre-signed URLs server-side on demand, never cache in API responses.

4. **Immutable audit log bypass** — Override `delete()`, `update()`, `save()` on `DocumentAuditLog` to throw exceptions. Add PostgreSQL rules (`CREATE RULE audit_no_update`) as database-level enforcement. Implement hash chain (each entry stores `sha256(prev_hash + entry_data)`) for tamper detection.

5. **Dual sign-off race condition** — Use `DB::transaction()` with `lockForUpdate()` on the sign-off record. Model as explicit state machine. Store sign-offs as separate rows with `UNIQUE(tax_year_id, user_role)` constraint. Final "filed" transition happens via background job, not the HTTP request.

6. **Missing tenant scoping** — Always query through relationships (`$request->user()->taxDocuments()->findOrFail($id)`), never `TaxDocument::find($id)`. Consider a global scope on `TaxDocument`. Policy is a second line of defense, not the first.

## Implications for Roadmap

Based on research, suggested phase structure:

### Phase 1: Document Vault Foundation
**Rationale:** Everything else depends on this. Classification gates extraction. Extraction gates worksheets. Audit trail must exist from day one — retrofitting creates compliance gaps. Super Admin storage config must be set before any file is stored.
**Delivers:** Secure file upload and storage (local + S3 toggle), AI document classification and field extraction (4 Tier 1 forms: W-2, 1099-NEC, 1099-INT, 1098), document status tracking, extraction review UI, append-only audit trail, Super Admin storage configuration
**Features addressed:** Document vault core (all table stakes), Tier 1 AI extraction forms
**Pitfalls to avoid:** #1 (authorization), #2 (PII leakage), #4 (audit log immutability), #6 (S3 credentials), #10 (tenant scoping), #12 (file upload validation)
**New packages needed:** `league/flysystem-aws-s3-v3`, `smalot/pdfparser`, `react-pdf`

### Phase 2: Accountant Collaboration
**Rationale:** Accountant-client relationship already exists. This phase extends it to documents. Must come after Phase 1 so accountants have documents to collaborate on. Authorization pattern established in Phase 1 is the foundation.
**Delivers:** Accountant view of client documents with document status overview, threaded document comments/annotations (accountant-only visibility by default), document request system (accountant requests missing docs), client completeness checklist
**Features addressed:** All Accountant Portal table stakes
**Pitfalls to avoid:** #1 (cross-client access), #9 (impersonation audit gaps), #11 (cross-client comment leakage)
**Architecture:** Extend existing `AccountantTaxController`; add `DocumentRequest` and `TaxDocumentAnnotation` models

### Phase 3: Sign-off, Sharing, and Worksheets
**Rationale:** Sign-off requires documents to exist and accountant collaboration to be in place. Worksheets require extraction data from Phase 1. Sharing requires documents and (optionally) sign-off status. These features complete the taxpayer → accountant → filed workflow.
**Delivers:** Dual sign-off workflow (taxpayer attestation + accountant approval with DB-locked state machine), document sharing packages with time-limited signed URLs, ZIP download for accountants, tax worksheet auto-population from extracted data
**Features addressed:** Dual sign-off workflow (all), document sharing (all), worksheet auto-population (differentiator)
**Pitfalls to avoid:** #3 (signed URL replay), #5 (sign-off race condition), #13 (timezone confusion in sign-off timestamps), #16 (sharing package expiry without notification)

### Phase 4: Intelligence Layer (Post-MVP)
**Rationale:** Requires solid extraction data from multiple documents across multiple users to be meaningful. Cross-document validation needs multiple extraction results. Missing document detection needs transaction data cross-referencing. This phase is high-value but depends on extraction accuracy being proven first.
**Delivers:** AI missing document detection (transaction → expected document cross-reference), cross-document anomaly detection (W-2 vs. bank deposits), transaction-to-document linking, year-over-year comparison, Tier 2 form support (8 additional forms)
**Features addressed:** All differentiators
**Pitfalls to avoid:** #8 (AI hallucination as ground truth)

### Phase Ordering Rationale

- Audit trail must be parallel to Phase 1, not deferred — retrofitting creates gaps in immutable log and cannot be reconstructed retroactively
- Accountant access (Phase 2) builds on the `TaxDocumentPolicy` established in Phase 1 — the policy must be correct before accountants can access anything
- Sign-off (Phase 3) has a hard dependency on both documents existing AND accountant review happening — it cannot precede Phase 2
- Worksheets (Phase 3) have a hard dependency on extraction data — extraction must be proven reliable before auto-population is trusted
- Differentiators (Phase 4) are pure enhancements — no other feature depends on them

### Research Flags

Phases likely needing deeper research during planning:
- **Phase 1 (AI Extraction):** Per-field confidence scoring is not established in the codebase. The existing pattern uses document-level confidence. Needs design for how field-level confidence is stored, surfaced, and acted on.
- **Phase 3 (ZIP generation):** `ZipArchive` with S3-backed files requires streaming from S3 into the ZIP. Pattern not established in codebase — may need composer research on memory-efficient streaming approach.
- **Phase 4 (Missing document detection):** Mapping Plaid transaction categories to expected tax form types requires a lookup table design that does not currently exist. Needs schema design work.

Phases with standard patterns (research-phase likely not needed):
- **Phase 1 (File upload + storage):** Established Laravel Storage pattern. S3 disk configuration already exists in `config/filesystems.php`. Well-documented.
- **Phase 2 (Document comments):** Polymorphic comments with `parent_id` threading is a well-documented Laravel pattern.
- **Phase 3 (Signed URL sharing):** Laravel `URL::temporarySignedRoute()` is well-documented. Pattern exists in the research.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | All 3 package recommendations verified against official sources and existing codebase. Existing stack compatibility confirmed. |
| Features | HIGH | Cross-referenced TaxDome, SmartVault, Canopy, Intuit, IRS documentation. Table stakes are well-established in the domain. |
| Architecture | HIGH | Based on direct analysis of the existing codebase (32 models, 14 services). All patterns are extensions of verified existing code. |
| Pitfalls | HIGH | Security pitfalls backed by multiple sources including NIST PII guidelines, IRS privacy protections, and codebase-specific analysis. |

**Overall confidence:** HIGH

### Gaps to Address

- **Per-field confidence storage schema:** How extraction confidence is stored per field (not per document) needs design before Phase 1 planning. One approach: `confidence` column on `TaxWorksheetField` model. Another: `confidence_scores` JSONB column on `TaxDocumentVersion`. Decide during Phase 1 planning.
- **APP_KEY rotation plan:** The project now uses `encrypted` casts on more fields (extraction data, S3 credentials). A rotation runbook should be documented before Phase 3 ships encrypted extraction data to production (Pitfall #15).
- **ClamAV availability:** File upload validation research recommends ClamAV scanning. Whether this binary is available on the production server is unknown. Treat as optional for MVP, required for production hardening.
- **Tier 2 form prioritization:** 8 Tier 2 forms are defined (1099-MISC, 1099-DIV, 1099-B, 1099-R, 1099-G, 1099-K, 1098-E, 1098-T). Prioritization within Tier 2 should be driven by user signup data once Tier 1 is live.

## Sources

### Primary (HIGH confidence)
- [Laravel 12.x Filesystem Docs](https://laravel.com/docs/12.x/filesystem) — S3 configuration, `temporaryUrl()`, disk abstraction
- [smalot/pdfparser v2.12.4 on Packagist](https://packagist.org/packages/smalot/pdfparser) — version and compatibility verification
- [react-pdf v10.4.1 on npm](https://www.npmjs.com/package/react-pdf) — React 19 peer dependency confirmed
- [Microsoft Document Intelligence - US Tax Documents](https://learn.microsoft.com/en-us/azure/ai-services/document-intelligence/prebuilt/tax-document) — form type coverage and field definitions
- [IRS Form documentation](https://www.irs.gov) — W-2, 1099 family, 1098 family field specifications
- Existing SpendifiAI codebase analysis — 32 models, 14 services, all existing patterns

### Secondary (MEDIUM confidence)
- [TaxDome feature documentation](https://taxdome.com) — table stakes validation, sign-off workflow patterns
- [SmartVault documentation](https://www.smartvault.com) — document vault baseline feature expectations
- [Canopy accounting portal](https://www.getcanopy.com) — client portal collaboration patterns
- [NIST SP 800-122 PII Protection Guidelines](https://nvlpubs.nist.gov/nistpubs/legacy/sp/nistspecialpublication800-122.pdf) — SSN/EIN handling requirements
- [HubiFi Immutable Audit Trail Guide](https://www.hubifi.com/blog/immutable-audit-log-basics) — audit log design patterns
- [AWS S3 Pre-signed URLs for PHP](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-presigned-url.html) — signed URL security patterns

### Tertiary (LOW confidence — validate during implementation)
- [AI Tax Accuracy Benchmarks](https://www.filed.com/measuring-ai-tax-accuracy-filed-vs-chatgpt-claude-gemini) — extraction accuracy expectations; validate with actual Claude Sonnet prompts during Phase 1
- ClamAV production availability — unknown, requires server environment check

---
*Research completed: 2026-03-30*
*Ready for roadmap: yes*
