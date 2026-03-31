# Phase 6: Document Vault & Audit Foundation - Context

**Gathered:** 2026-03-30
**Status:** Ready for planning

<domain>
## Phase Boundary

Users can securely upload, view, and manage tax documents in a vault organized by year and category, with every action recorded in a tamper-proof audit trail and Super Admin control over storage backend. AI classification/extraction (Phase 7), accountant collaboration (Phase 8), and intelligence layer (Phase 9) are separate phases.

</domain>

<decisions>
## Implementation Decisions

### Vault UI Layout
- Year tabs + category grid layout — horizontal year tabs at top, grid of category cards below (W-2s, 1099s, 1098s, Receipts, Other)
- Each category card shows document count and status
- Clicking a category card expands inline (accordion-style) to show documents list — no separate page navigation
- Color-coded status badges on cards: green check (all ready), yellow spinner (processing), red alert (failed)
- Missing document alerts displayed as a persistent top banner above the category grid: "2 expected documents missing for 2025" with expandable details

### Upload & File Handling
- Inline drop zone embedded on the vault page (as a card in the grid or below it) — extends existing FileDropZone component
- Drag-and-drop and click-to-browse supported (existing FileDropZone pattern)
- Auto-detect tax year — defaults to currently selected year tab; user can override before upload
- No mandatory category selection — AI classification (Phase 7) handles categorization later
- 100 MB per file limit — generous to accommodate high-res scans and large PDFs
- Accepted formats: PDF, JPG, PNG (per VAULT-01)
- Per-file upload progress bar with percentage for large files
- Multi-file upload supported (existing FileDropZone multi-file capability)

### Audit Log Presentation
- Audit trail displayed as a tab on the document detail page (Overview | Extracted Fields | Audit Log)
- Entries show: action + who + when — e.g., "John Smith viewed this document — Mar 15, 2026 at 2:30 PM"
- IP addresses hidden from regular users — visible to Super Admin only
- Hash chain integrity verification visible to Super Admin only (not shown to regular users or accountants)
- Accountants see the same full audit trail as the document owner for documents they have access to

### Super Admin Storage Config
- Dedicated /admin/storage settings page (separate from existing admin dashboard)
- Local/S3 toggle with S3 credential form (bucket, region, access key, secret key)
- Inline "Test Connection" button — credentials only saveable after successful test
- Document migration (local ↔ S3) shows progress bar with live polling updates ("Migrating 45/120 documents...")
- Storage toggle disabled during active migration
- Summary stats at top of page: total documents, total storage used, active storage driver

### Claude's Discretion
- Exact card grid responsive breakpoints and spacing
- Loading skeleton design for vault page
- Error state design for failed uploads
- Document detail page layout (beyond the audit tab decision)
- Exact category groupings for the grid cards
- Upload validation error messaging

</decisions>

<specifics>
## Specific Ideas

- Category grid should feel clean and scannable — not cluttered. Think dashboard stat cards but for document categories
- Accordion expand on card click matches the existing Tax page's expandable category rows — keep the UX consistent
- FileDropZone component already supports multi-file and drag-and-drop — extend it for JPG/PNG MIME types rather than building new
- 100 MB limit is intentionally generous — no premature optimization on file size

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- `FileDropZone` component (`resources/js/Components/SpendifiAI/FileDropZone.tsx`): Supports multi-file, drag-and-drop, configurable maxSizeMb. Currently accepts PDF/CSV — needs JPG/PNG extension
- `StatCard` component: Reusable for vault summary stats and category cards
- `useApi` hook (`resources/js/hooks/useApi.ts`): Data fetching pattern for vault page API calls
- `FilterBar` component: Could inform year tab design
- `StatementUploadWizard` component: Reference for multi-step upload patterns
- `UploadHistory` component: Reference for showing upload status/history

### Established Patterns
- Year selector: Tax page (`Pages/Tax/Index.tsx`) uses year state with dropdown — vault uses tabs instead but same data pattern
- Expandable rows: Tax page has accordion-style category expansion — vault cards follow same interaction pattern
- Admin pages: `Pages/Admin/Dashboard.tsx` uses axios + useState for data fetching — new admin page follows same pattern
- Status badges: `Badge` component exists in SpendifiAI components
- Service layer: All business logic in `app/Services/` — new TaxVaultService, TaxVaultAuditService follow same pattern

### Integration Points
- Routes: New routes in `routes/web.php` (Inertia pages) and `routes/api.php` (vault API endpoints)
- Navigation: Vault page added to authenticated layout sidebar
- Admin navigation: Storage config page linked from admin dashboard sidebar
- Models: New TaxDocument, TaxVaultAuditLog models in `app/Models/`
- Storage: Laravel filesystem with configurable disk (local vs s3)

</code_context>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 06-document-vault-audit-foundation*
*Context gathered: 2026-03-30*
