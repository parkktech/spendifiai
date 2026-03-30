# Technology Stack: Tax Document Vault & Accountant Portal

**Project:** SpendifiAI v2.0
**Researched:** 2026-03-30
**Scope:** NEW packages/libraries only. Existing stack (Laravel 12, React 19, Inertia 2, PostgreSQL, Redis, Sanctum, Fortify, Pest, Claude API) is validated and not re-evaluated.

## Recommended Stack Additions

### Document Storage (S3 + Local)

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| league/flysystem-aws-s3-v3 | ^3.0 | S3 filesystem adapter | Required by Laravel's Storage facade for S3 disk. Not currently installed. Laravel 12 already has the s3 disk configured in `config/filesystems.php` with env vars -- just needs the adapter package installed. |

**Signed URLs:** Laravel 12's `Storage::temporaryUrl()` works natively for S3 disks. For local disk, use `URL::temporarySignedRoute()` to generate time-limited download URLs via a controller route. No additional package needed.

**Super Admin disk toggle:** Store the active disk name (`local` or `s3`) in a `system_settings` table or `config/spendifiai.php`. Resolve at runtime via `Storage::disk(config('spendifiai.document_storage.disk'))`. The Flysystem abstraction makes local and S3 interchangeable -- same API, different backend.

**Installation:**
```bash
composer require league/flysystem-aws-s3-v3:"^3.0" --with-all-dependencies
```

**Confidence:** HIGH -- verified against [Laravel 12.x filesystem docs](https://laravel.com/docs/12.x/filesystem) and existing `config/filesystems.php`.

### PDF Text Extraction (Backend)

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| smalot/pdfparser | ^2.12 | Pure PHP PDF text extraction | Zero external binary dependencies. The project already has `spatie/pdf-to-text` (^1.54) but it requires `poppler-utils` / `pdftotext` binary which is NOT installed in the dev environment (per MEMORY.md). smalot/pdfparser is pure PHP -- works everywhere without system packages. |

**Role in extraction pipeline:** Text preprocessing before Claude AI. For text-layer PDFs (most digital tax forms), extracting raw text first and sending it to Claude as text is significantly cheaper and faster than sending base64 PDF images via the vision API. The existing `BankStatementParserService` sends full base64 PDFs to Claude -- that works but costs more. For 25+ form types at scale, text-first is the better default.

**Fallback strategy:** If smalot/pdfparser extracts no meaningful text (scanned/image PDF), fall back to base64 PDF via Claude vision API -- same pattern as existing `BankStatementParserService::callClaudeWithPdf()`.

**Why not rely on spatie/pdf-to-text:** Already in `composer.json` at ^1.54, but requires `poppler-utils` system binary. Can coexist -- use smalot as primary (no deps), spatie as optional enhanced extractor when `pdftotext` is available on the server.

**Installation:**
```bash
composer require smalot/pdfparser:"^2.12"
```

**Confidence:** HIGH -- [smalot/pdfparser v2.12.4](https://packagist.org/packages/smalot/pdfparser) released 2026-03-10, actively maintained, PHP 7.1+ compatible.

### PDF Viewing (Frontend)

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| react-pdf | ^10.0 | In-browser PDF rendering | Most popular React PDF viewer (1040+ npm dependents). v10 supports React 19, uses pdf.js under the hood. Renders PDF pages as canvas/SVG with text selection, zoom, and page navigation. MIT licensed. |

**Why react-pdf over alternatives:**
- `@react-pdf-viewer/core` -- abandoned since 2023, no updates in 3 years
- `@pdf-viewer/react` -- smaller community, less battle-tested
- Syncfusion / commercial options -- unnecessary license cost for this use case

**Integration with signed URLs:** Pass the signed URL directly as the `file` prop to `<Document>`. Works with both S3 presigned URLs and Laravel signed route URLs.

**Worker setup note:** pdf.js requires a web worker. Configure it in the component module where `<Document>` is rendered:
```typescript
import { pdfjs } from 'react-pdf';
pdfjs.GlobalWorkerOptions.workerSrc = `//unpkg.com/pdfjs-dist@${pdfjs.version}/build/pdf.worker.min.mjs`;
```

**Installation:**
```bash
npm install react-pdf@^10.0
```

**Confidence:** HIGH -- [react-pdf v10.4.1](https://www.npmjs.com/package/react-pdf) verified on npm, React 19 peer dep confirmed.

## No New Packages Needed For

These capabilities use existing stack or custom implementations:

| Capability | How It's Handled | Existing Asset |
|------------|-----------------|----------------|
| **AI Document Extraction** | Claude API with new prompts. Two-pass pipeline (classify then extract). Same API pattern as `BankStatementParserService::callClaudeWithPdf()`. | Anthropic Claude API (already integrated) |
| **Accountant Invite Emails** | New Mailable classes extending existing pattern. | `AccountantInviteMail`, `TaxPackageMail` (already exist), SendGrid mailer configured |
| **Immutable Audit Log** | Custom `AuditTrail` model -- insert-only, no update/delete. PostgreSQL `BEFORE UPDATE OR DELETE` trigger enforces immutability at DB level. | PostgreSQL triggers, `AccountantActivityLog` model (similar pattern exists) |
| **Queue Jobs** | New jobs follow existing `ShouldQueue` pattern on Redis. | Redis queue driver, `CategorizePendingTransactions` job pattern |
| **Dual Sign-Off Workflow** | Custom state machine via enum + model method transitions. 4 states, 3 transitions -- too simple for a package. | `UserType` enum, Policy authorization pattern |
| **Accountant Middleware** | Extend existing `EnsureAccountant` pattern. Add `EnsureAccountantOwnsClient`. | `EnsureAccountant` middleware exists |
| **User Types** | `UserType` enum already has `Personal` and `Accountant`. No changes needed. | `app/Enums/UserType.php` |
| **Activity Logging** | Extend existing `AccountantActivityLog` model for document-specific actions. | `AccountantActivityLog` model exists |
| **File Upload Validation** | Laravel's built-in `UploadedFile` + Form Request rules (`mimes:pdf,jpg,png`, `max:30720`). | Standard Laravel, 20 Form Request classes exist |
| **Signed Invite URLs** | Laravel's `URL::temporarySignedRoute()` for tamper-proof, time-limited invite links. | Core framework feature |
| **PDF Generation** | Generate tax worksheets from extracted data. | `barryvdh/laravel-dompdf` ^3.1 (installed) |
| **Excel/Spreadsheet Export** | Tax software export formats. | `phpoffice/phpspreadsheet` ^5.4 (installed) |
| **Document Comments** | Polymorphic `comments` table with `commentable_type`/`commentable_id`. Standard Eloquent pattern. | Standard Laravel polymorphic relationships |

## Complete New Dependencies

### Backend (Composer)

```bash
composer require league/flysystem-aws-s3-v3:"^3.0" --with-all-dependencies
composer require smalot/pdfparser:"^2.12"
```

### Frontend (npm)

```bash
npm install react-pdf@^10.0
```

**Total: 3 packages** (2 Composer, 1 npm). That is all.

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| S3 adapter | league/flysystem-aws-s3-v3 | Direct AWS SDK (aws/aws-sdk-php) | Flysystem integrates with Laravel Storage facade. Direct SDK bypasses the abstraction that enables the local/S3 toggle. |
| PDF extraction | smalot/pdfparser + Claude AI | spatie/pdf-to-text only | spatie requires pdftotext binary (not in dev env). smalot is pure PHP. Primary extraction is Claude AI anyway -- text extraction is preprocessing. |
| PDF extraction | smalot/pdfparser + Claude AI | AWS Textract | Adds AWS dependency, per-page cost, and latency. Claude already handles PDFs natively via vision API and is already integrated. |
| PDF viewer | react-pdf v10 | @react-pdf-viewer/core | Abandoned since 2023. No updates in 3 years. |
| PDF viewer | react-pdf v10 | @pdf-viewer/react | Smaller community, less TypeScript support, fewer battle-tested deployments. |
| Audit logging | Custom insert-only model | owen-it/laravel-auditing v14 | Model-diff tracker (tracks old/new attribute values). Document vault needs action-level logging (viewed, downloaded, shared). Wrong abstraction. |
| Audit logging | Custom insert-only model | spatie/laravel-activitylog | Closer fit (logs arbitrary activities) but carries model-change tracking overhead. Custom solution is ~50 lines, type-safe, and exactly fits the immutable insert-only requirement. |
| State machine | Custom enum + transitions | spatie/laravel-model-states | 4-state linear workflow (draft -> taxpayer_signed -> accountant_signed -> filed) does not justify a package dependency. Packages shine for branching/parallel states. |
| File upload | Laravel built-in | chunked upload libraries (Resumable.js, Filepond) | Tax documents are inherently small (<10MB). Chunked upload adds complexity for no benefit. |
| Permissions | Laravel Policies (existing) | spatie/laravel-permission | Existing Policy authorization covers user/accountant/admin role checks. Adding Spatie permissions creates a parallel auth system. |

## What NOT to Add

| Package | Why Skip |
|---------|----------|
| intervention/image | Not doing image manipulation. PDFs rendered client-side by react-pdf. |
| spatie/laravel-media-library | Over-abstraction. Direct `Storage::disk()` calls are simpler for single-purpose document storage. |
| laravel/scout | No full-text search on documents required. PostgreSQL `tsvector` handles future needs natively. |
| livewire/livewire | Project uses Inertia.js exclusively. Mixing Livewire creates two competing paradigms. |
| spatie/laravel-permission | Existing Policy pattern handles authorization. Adding a role/permission package duplicates existing patterns. |
| any WebSocket package | Real-time not required (per project constraints). Polling and page refresh are sufficient. |

## New Enums Needed

| Enum | Cases | Purpose |
|------|-------|---------|
| TaxFormType | W2, W2G, Form1099MISC, Form1099NEC, Form1099INT, Form1099DIV, Form1099B, Form1099R, Form1099G, Form1099SA, Form1099K, Form1098, Form1098E, Form1098T, ScheduleC, ScheduleD, ScheduleE, ScheduleSE, Form1040, Form1040ES, Form8829, Form4562, FormK1, CharitableReceipt, Other | 25 supported tax form types for AI classification |
| DocumentStatus | Uploaded, Classifying, Classified, Extracting, Extracted, ReviewNeeded, Verified, Error | Document processing pipeline states |
| SignOffStatus | Pending, TaxpayerSigned, AccountantSigned, BothSigned, Rejected | Dual sign-off workflow states |
| SharePackageStatus | Draft, Shared, Expired, Revoked | Document sharing package lifecycle |

## New Mail Classes Needed

| Class | Purpose | Trigger |
|-------|---------|---------|
| DocumentRequestMail | Accountant requests missing documents from client | Accountant action in portal |
| SignOffRequestMail | Notify other party that sign-off is needed | First party completes sign-off |
| SignOffCompleteMail | Both parties notified of completed dual sign-off | Second party completes sign-off |
| DocumentSharedMail | Accountant notified of new shared document package | Taxpayer creates share package |

Existing mail classes (`AccountantInviteMail`, `TaxPackageMail`, `SyncDigestMail`) provide the template pattern.

## Config Additions (config/spendifiai.php)

```php
'document_storage' => [
    'disk' => env('DOCUMENT_STORAGE_DISK', 'local'),
    'max_file_size_mb' => 30,
    'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
    'signed_url_expiry_minutes' => 30,
    'upload_url_expiry_minutes' => 5,
],

'tax_extraction' => [
    'classify_confidence_threshold' => 0.85,
    'extract_confidence_threshold' => 0.70,
    'max_extraction_retries' => 2,
    'supported_form_count' => 25,
],
```

## Integration Points with Existing Stack

| Existing Component | How New Features Integrate |
|-------------------|---------------------------|
| Claude API (Sonnet) | New `DocumentExtractionService` follows `BankStatementParserService` pattern. Same API client, new form-specific prompt templates. Two-pass: classify then extract. |
| BankStatementParserService | Reference implementation for PDF-to-Claude pipeline. `callClaudeWithPdf()` method is the pattern to follow. |
| Laravel Sanctum | API routes for document CRUD use existing `auth:sanctum` middleware. Accountant portal uses same token auth. |
| Fortify + Socialite | Accountant registration reuses existing auth flow. Firm association added as post-registration step. |
| Redis queues | `ClassifyDocumentJob` and `ExtractDocumentJob` follow `CategorizePendingTransactions` pattern. |
| PostgreSQL | New tables use JSONB for extraction data and audit metadata. Encrypted columns use TEXT type per existing convention. |
| Tailwind CSS v4 (sw-* tokens) | All new pages use existing design system. No new CSS framework or tokens needed. |
| Pest PHP 3 | Test document upload, extraction pipeline, sign-off state transitions, audit trail immutability. |
| AccountantActivityLog | Extend or reference for document-specific audit actions. |
| EnsureAccountant middleware | Pattern for new `EnsureAccountantOwnsClient` middleware. |

## Sources

- [Laravel 12.x Filesystem Documentation](https://laravel.com/docs/12.x/filesystem) -- S3 configuration, temporaryUrl(), disk abstraction
- [league/flysystem-aws-s3-v3 on Packagist](https://packagist.org/packages/league/flysystem-aws-s3-v3) -- v3.x line, Laravel 12 compatible
- [smalot/pdfparser on Packagist](https://packagist.org/packages/smalot/pdfparser) -- v2.12.4 (released 2026-03-10), pure PHP
- [smalot/pdfparser GitHub](https://github.com/smalot/pdfparser) -- release history and PHP compatibility
- [react-pdf on npm](https://www.npmjs.com/package/react-pdf) -- v10.4.1, React 19 compatible, MIT licensed
- [Best React PDF Viewer Libraries 2025](https://blog.react-pdf.dev/top-6-pdf-viewers-for-reactjs-developers-in-2025) -- comparison of options
- [spatie/pdf-to-text on Packagist](https://packagist.org/packages/spatie/pdf-to-text) -- v1.55.0, requires poppler-utils (evaluated, kept as optional)
