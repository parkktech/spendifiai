# Feature Landscape

**Domain:** Tax Document Vault, AI Document Extraction, Accountant Portal & Collaboration
**Researched:** 2026-03-30
**Overall confidence:** HIGH (cross-referenced TaxDome, SmartVault, Canopy, Intuit, Microsoft Document Intelligence, IRS documentation)

## Table Stakes

Features users expect. Missing = product feels incomplete or untrustworthy.

### Document Vault Core

| Feature | Why Expected | Complexity | Dependencies | Notes |
|---------|--------------|------------|--------------|-------|
| Multi-file upload (PDF, images, CSV) | Every tax product supports drag-and-drop upload of common formats | Low | Existing `StatementUpload` pattern | PDF and JPEG/PNG minimum. HEIC nice-to-have. Max 20MB per file. |
| Document type classification | Users don't want to manually tag every W-2 vs 1099 | Medium | AI extraction pipeline | Two-pass: classify first, extract second. Use Claude to identify form type from first page. |
| Tax year organization | Tax documents are inherently year-scoped | Low | Existing tax year filter in `TaxController` | Default to current tax year. Allow viewing prior years. |
| Document status tracking | Users need to know if upload succeeded, is processing, or failed | Low | Queue system (Redis already configured) | States: `uploaded`, `classifying`, `extracting`, `ready`, `failed`, `needs_review` |
| Secure file storage with encryption-at-rest | Tax documents contain SSN, income data -- users won't trust a vault without security signals | Medium | Laravel filesystem, S3 encryption | AES-256 for local storage, S3 SSE for cloud. File paths never exposed to client. |
| Signed URL document access | Direct file paths are a security vulnerability. Every serious document platform uses time-limited access. | Low | Laravel `temporaryUrl()` for S3, custom for local | 15-minute expiry default. Force download headers to prevent hotlinking. |
| Document preview (PDF/image viewer) | Users need to verify what they uploaded without downloading | Medium | Frontend PDF.js or `<iframe>` embed | In-browser preview via signed URL. No server-side rendering needed. |
| Document deletion with soft-delete | Users must be able to remove documents, but audit trail requires retention | Low | Laravel `SoftDeletes` trait | Soft-delete only. Physical file deletion after 90-day retention period. |
| Per-document audit log entries | Regulatory requirement. Every access, view, download, share must be logged. | Medium | New `DocumentAuditLog` model (append-only) | Insert-only table. No `updated_at`. Record: who, what, when, IP, user agent. |
| Basic search and filtering | Users with 20+ documents need to find specific ones | Low | Database queries on type, year, status | Filter by: tax year, document type, status. Text search on original filename. |

### AI Document Extraction

| Feature | Why Expected | Complexity | Dependencies | Notes |
|---------|--------------|------------|--------------|-------|
| W-2 field extraction | Most common tax form. Every AI tax tool handles this. | Medium | Claude API, document classification | Key fields: employer EIN, wages (Box 1), federal tax withheld (Box 2), state wages, SS wages. |
| 1099 family extraction (1099-MISC, 1099-NEC, 1099-INT, 1099-DIV, 1099-B, 1099-R, 1099-G, 1099-K, 1099-SSA) | Freelancers and investors receive many 1099 variants | High | Claude API | Each subtype has different fields. 1099-NEC (freelance) and 1099-INT (interest) are highest priority. |
| 1098 extraction (mortgage interest, student loan interest) | Common itemized deductions | Medium | Claude API | Key fields: mortgage interest paid, points, property tax, loan origination. |
| Extraction confidence scoring | Users and accountants need to know if AI got it right | Medium | Mirrors existing AI categorization confidence system | Reuse confidence threshold pattern from `config/spendifiai.php`. High confidence = auto-accept, low = flag for review. |
| Human review workflow for low-confidence extractions | AI will make mistakes. Users must be able to correct. | Medium | Frontend form + backend update endpoint | Show extracted fields side-by-side with document preview. Editable fields with "confirm" action. |
| Extracted data summary view | Users want to see totals (total W-2 income, total 1099 income, total deductions) without opening each document | Low | Aggregation queries on extracted data | Dashboard widget or dedicated summary page. Group by form type. |

### Accountant Portal

| Feature | Why Expected | Complexity | Dependencies | Notes |
|---------|--------------|------------|--------------|-------|
| Client list with document status overview | Accountants manage multiple clients. Need at-a-glance status. | Low | Existing `AccountantController.clients()` | Extend existing endpoint with document counts and completion percentage per client. |
| View client's uploaded documents | Core reason accountants use the portal | Low | Existing `verifyAccountantClientRelationship()` | Reuse existing authorization pattern. Read-only access to client vault. |
| Document request (ask client for missing docs) | Industry standard. TaxDome, SmartVault, Canopy all have this. | Medium | New `DocumentRequest` model | Accountant creates request specifying document type needed. Client sees notification/checklist. |
| Comment/annotation on documents | Accountants need to flag issues without phone calls | Medium | New `DocumentComment` model with polymorphic thread | Threaded comments attached to specific documents. Both parties can comment. |
| Client document completeness checklist | Accountants need to track which documents are still outstanding | Medium | Document request system + extraction data | Auto-detect some missing docs (e.g., has W-2 income but no W-2 uploaded). Manual checklist items too. |

### Dual Sign-off Workflow

| Feature | Why Expected | Complexity | Dependencies | Notes |
|---------|--------------|------------|--------------|-------|
| Tax year status lifecycle | Both parties need to understand where things stand | Low | New `TaxYearStatus` model or column on user | States: `gathering`, `in_review`, `taxpayer_signed`, `accountant_signed`, `filed`, `amended` |
| Taxpayer sign-off ("I confirm these documents are complete") | Legal accountability. Client attests completeness. | Low | Status transition + audit log | Simple confirmation action. Records timestamp, IP. Not an e-signature -- just an attestation. |
| Accountant sign-off ("I have reviewed and approved") | Professional attestation. Common in TaxDome, CCH Axcess. | Low | Status transition + audit log | Only available after taxpayer has signed. Records timestamp, IP, accountant license info if available. |
| Sign-off notification to other party | Each party needs to know when the other has signed | Low | Existing notification/email system | Email notification on each sign-off event. |

### Document Sharing

| Feature | Why Expected | Complexity | Dependencies | Notes |
|---------|--------------|------------|--------------|-------|
| Share document package with accountant | Primary sharing use case. Accountant already has access via portal, but external sharing is also needed. | Medium | New `DocumentSharePackage` model | Bundle of documents as a single shareable unit. Time-limited signed URL. |
| Time-limited access links | Security requirement for tax documents | Low | Signed URL generation | Default 72-hour expiry. Configurable per package. Single-use or multi-use option. |
| Download all as ZIP | Accountants need to pull everything at once for their workflow | Medium | ZipArchive PHP extension | Generate ZIP on demand via queue job. Return signed URL to completed ZIP. |

## Differentiators

Features that set SpendifiAI apart from TaxDome/SmartVault. Not expected, but high value.

| Feature | Value Proposition | Complexity | Dependencies | Notes |
|---------|-------------------|------------|--------------|-------|
| AI missing document detection | Automatically detect what's missing based on transaction data. If user has interest income from bank transactions, flag that a 1099-INT should exist. | High | Transaction data + document type cross-reference | This is the killer feature. No competitor does this well. Cross-reference Plaid transaction categories with expected document types. |
| Auto-populate tax worksheets from extracted data | Extracted W-2 fields flow directly into tax worksheet lines. No manual data entry. | High | Extraction pipeline + worksheet models | Requires mapping extracted fields to IRS form line numbers. Schedule C, Schedule A, Form 1040 lines. |
| Cross-document validation (anomaly detection) | Flag when W-2 wages don't match bank deposit totals, or 1099 amounts seem inconsistent with transaction history | High | Extraction data + transaction aggregation | Surface discrepancies as warnings, not blockers. "Your W-2 shows $85K wages but bank deposits total $92K -- verify." |
| Transaction-to-document linking | Link a specific 1099 to the transactions it covers. Link a receipt document to a specific transaction. | Medium | Existing transaction + new document models | Useful for audit preparation. "Show me the 1099-NEC and all associated freelance deposits." |
| Accountant firm branding (logo, colors on invite emails) | Makes the accountant look professional when onboarding clients | Low | Firm profile model + email template customization | Store firm logo, primary color. Apply to invite emails and client-facing portal header. |
| Tax software export from extracted data (TurboTax TXF, H&R Block) | Users can take extracted data directly into filing software | High | Extraction data + TXF/export format specs | TXF format is documented but finicky. Would need per-software export templates. Defer unless specifically requested. |
| Bulk document upload with auto-classification | Upload 15 PDFs at once and AI sorts them all | Medium | Queue-based classification pipeline | Process in parallel via queue jobs. Show progress indicator. Group results by detected type. |
| Year-over-year document comparison | "You uploaded 3 W-2s last year but only 1 this year -- did you change jobs?" | Low | Prior year document data | Simple count comparison. Low effort, high value for completeness checking. |

## Anti-Features

Features to explicitly NOT build. These add risk, complexity, or liability without proportional value.

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| Full SSN/TIN storage | Massive PII liability. Breach would be catastrophic. | Store last 4 digits only from extracted documents. Display "***-**-1234" format. Explicitly strip full SSN from stored extraction data. |
| E-signature (legally binding) | Requires compliance with ESIGN Act, UETA. Complex legal framework. TaxDome spent years on this. | Use simple attestation ("I confirm") with audit log. Not a legal e-signature. Recommend DocuSign/HelloSign integration later if needed. |
| Direct IRS e-filing | Requires IRS e-file provider certification (EFIN). Massive compliance burden. | Export to TurboTax/TaxAct format. Let users file through certified software. |
| OCR for handwritten documents | Unreliable accuracy. Most tax documents are typed/printed. | Support digital PDFs and scanned typed documents only. Reject or warn on handwritten content. |
| Real-time collaborative document editing | Not a Google Docs competitor. Accountants don't edit tax source documents. | Comments/annotations are sufficient. Documents are immutable after upload (versioning via re-upload). |
| Custom form template builder | Accountants don't want to define extraction schemas. They want it to work. | Support the 25 most common IRS form types. Add new types via code updates, not user config. |
| Document retention/archival automation | Complex regulatory landscape varies by state and document type | Soft-delete with 90-day retention. Let users manage their own cleanup. Don't auto-delete anything. |
| Multi-tenant firm management (multiple offices, departments) | Premature complexity for v2. Current accountant model is individual. | Single accountant per firm for now. Firm = one user with company_name. Multi-user firms can come in v3. |
| Payment processing for accountant services | Billing/monetization explicitly deferred per PROJECT.md | Free for now. No Stripe, no invoicing, no payment collection. |

## Feature Dependencies

```
Upload & Storage
  -> Document Classification (requires uploaded file)
    -> Field Extraction (requires classification result)
      -> Tax Worksheet Auto-population (requires extracted fields)
      -> Cross-document Validation (requires multiple extraction results)
      -> Missing Document Detection (requires extraction + transaction data)

Accountant-Client Relationship (ALREADY BUILT)
  -> Document Request System (requires active relationship)
  -> Document Sharing Packages (requires active relationship)
  -> Dual Sign-off Workflow (requires active relationship)
  -> Document Comments/Annotations (requires active relationship + uploaded documents)

Audit Trail
  -> Runs parallel to everything. Every document action creates a log entry.
  -> Must be built FIRST or concurrently with document upload.

Super Admin Storage Config
  -> Must be built before or with document upload (determines where files go)
  -> Toggle between local filesystem and S3
```

## MVP Recommendation

### Phase 1: Document Vault Foundation
Prioritize:
1. **Document upload with secure storage** (local + S3 toggle) -- foundation for everything
2. **AI classification** (identify document type) -- gates extraction
3. **AI field extraction** (W-2, 1099-NEC, 1099-INT, 1098 as first four types) -- core value
4. **Audit trail** (append-only log) -- must exist from day one, retrofitting is painful
5. **Super Admin storage config** -- needed for document upload to work

### Phase 2: Accountant Collaboration
Prioritize:
1. **Accountant document view** (extend existing portal) -- lowest friction, high value
2. **Document comments/annotations** -- enables remote collaboration
3. **Document request system** -- industry standard workflow
4. **Client completeness checklist** -- manual checklist first, auto-detection later

### Phase 3: Sign-off and Sharing
Prioritize:
1. **Dual sign-off workflow** -- depends on documents being uploaded and reviewed
2. **Document sharing packages** with signed URLs -- external sharing
3. **ZIP download** -- accountant convenience

### Defer to Post-MVP:
- **Tax software export formats** (TurboTax TXF): Complex format specs, niche demand initially
- **Cross-document validation**: High value but high complexity, needs solid extraction first
- **Firm branding**: Nice-to-have, not blocking any workflow
- **Year-over-year comparison**: Needs two years of data to be useful
- **Remaining 21 form types** beyond the initial 4: Add incrementally based on user demand

## Supported Tax Form Types (Priority Order)

Based on IRS filing frequency and freelancer/small business relevance:

### Tier 1 -- Build First (4 forms)
| Form | Description | Why Priority |
|------|-------------|--------------|
| W-2 | Wages and Tax Statement | Most common. Everyone with a job has one. |
| 1099-NEC | Nonemployee Compensation | Core audience is freelancers. |
| 1099-INT | Interest Income | Common for anyone with a savings account. |
| 1098 | Mortgage Interest Statement | Common itemized deduction. |

### Tier 2 -- Build Next (8 forms)
| Form | Description | Why Priority |
|------|-------------|--------------|
| 1099-MISC | Miscellaneous Income | Rental income, royalties. |
| 1099-DIV | Dividends and Distributions | Investors. |
| 1099-B | Proceeds from Broker Transactions | Stock sales. |
| 1099-R | Distributions from Pensions/IRAs | Retirement distributions. |
| 1099-G | Government Payments | Unemployment, state tax refunds. |
| 1099-K | Payment Card and Third Party Transactions | Etsy, eBay, PayPal sellers. |
| 1098-E | Student Loan Interest Statement | Common deduction for younger users. |
| 1098-T | Tuition Statement | Education credits. |

### Tier 3 -- Build on Demand (13 forms)
| Form | Description |
|------|-------------|
| 1099-S | Proceeds from Real Estate Transactions |
| 1099-SA | HSA Distributions |
| 1099-Q | Payments from Qualified Education Programs |
| 1099-C | Cancellation of Debt |
| 1099-A | Acquisition or Abandonment of Secured Property |
| W-2G | Gambling Winnings |
| 1095-A | Health Insurance Marketplace Statement |
| 1095-C | Employer-Provided Health Insurance |
| SSA-1099 | Social Security Benefit Statement |
| K-1 (1065) | Partner's Share of Income |
| K-1 (1120-S) | Shareholder's Share of Income |
| 5498 | IRA Contribution Information |
| 5498-SA | HSA/MSA Contribution Information |

## Existing SpendifiAI Assets to Leverage

| Existing Asset | How It Helps New Features |
|----------------|--------------------------|
| `AccountantClient` model + controller | Relationship system is built. Document sharing/viewing authorization is ready. |
| `AccountantActivityLog` model | Pattern for audit logging exists. Extend for document-specific actions. |
| `ImpersonationController` | Accountant can already "view as client." Document vault should respect this. |
| `StatementUpload` model | Upload pattern exists (file_path, status tracking). Similar schema for tax documents. |
| `TaxController` + `TaxExportService` | Tax summary/export is built. Extracted document data feeds into this. |
| `TaxDeduction` + `UserTaxDeduction` models | Deduction tracking exists. Document extraction can auto-create deductions. |
| AI confidence thresholds in `config/spendifiai.php` | Reuse same confidence pattern for extraction quality scoring. |
| `PlaidStatement` model | Another upload pattern. Validates the file storage approach. |
| Redis queue infrastructure | Already configured for background jobs. Extraction jobs will use this. |
| User `user_type` enum with `Accountant` value | Role system exists. No new role needed. |

## Security Considerations for Tax Documents

| Concern | Approach | Notes |
|---------|----------|-------|
| Encryption at rest | AES-256 local, S3 SSE-S3 or SSE-KMS for cloud | Laravel `Storage::put()` with encrypted disk config |
| Encryption in transit | HTTPS enforced (already in place) | Signed URLs also use HTTPS |
| Access control | Policy-based authorization per document | Owner OR linked accountant with active relationship |
| PII minimization | Strip full SSN from stored extraction data | Store last 4 only. Log that stripping occurred. |
| Audit trail immutability | Append-only table, no UPDATE/DELETE permissions | Application-level enforcement. Consider DB-level `REVOKE UPDATE, DELETE` on audit table. |
| File type validation | Server-side MIME type checking, not just extension | Reject executables. Allow: PDF, JPEG, PNG, HEIC, TIFF. |
| File size limits | 20MB per file, 100MB per upload batch | Prevents abuse. Most tax documents are under 5MB. |
| Signed URL expiry | 15-minute default for viewing, 72-hour for sharing packages | Short for casual access, longer for intentional sharing. |
| Rate limiting on upload | 50 uploads per hour per user | Prevents bulk abuse. |
| Virus/malware scanning | ClamAV scan before processing | Queue-based scan on upload. Quarantine if suspicious. Optional for v2 MVP, recommended for production. |

## Sources

- [SmartVault - Document Management & Secure File Sharing](https://www.smartvault.com/)
- [TaxDome - Practice Management Software](https://taxdome.com)
- [TaxDome Workflow Management](https://taxdome.com/workflow)
- [TaxDome E-Signature](https://taxdome.com/e-signature)
- [Canopy - Accounting Client Portal](https://www.getcanopy.com/accounting-client-portal)
- [Microsoft Document Intelligence - US Tax Documents](https://learn.microsoft.com/en-us/azure/ai-services/document-intelligence/prebuilt/tax-document?view=doc-intel-4.0.0)
- [OCRTax.com - OCR for Tax Documents](https://www.ocrtax.com/)
- [Parseur - AI Tax Parsing](https://parseur.com/use-case/automate-tax-season)
- [AWS Presigned URLs for Secure File Sharing](https://aws.amazon.com/blogs/security/how-to-securely-transfer-files-with-presigned-urls/)
- [HubiFi - Immutable Audit Trails Guide](https://www.hubifi.com/blog/immutable-audit-log-basics)
- [IRS - What to Do When W-2 or 1099 Is Missing](https://www.irs.gov/newsroom/what-to-do-when-a-w-2-or-form-1099-is-missing-or-incorrect)
- [Future Firm - Client Portals for Accountants](https://futurefirm.co/client-portals-for-accountants/)
- [Assembly - Client Portals for Accountants 2025](https://assembly.com/blog/client-portal-for-accountants)
- [Wolters Kluwer - Best Tax Software for Preparers](https://www.wolterskluwer.com/en/expert-insights/best-tax-software-for-preparers-an-expert-guide-to-choosing-the-right-solution)
