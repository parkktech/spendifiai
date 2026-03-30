# Domain Pitfalls

**Domain:** Tax Document Vault, Accountant Portal, Dual Sign-Off Workflow (added to existing Laravel/React finance app)
**Researched:** 2026-03-30

## Critical Pitfalls

Mistakes that cause security breaches, compliance violations, or rewrites.

---

### Pitfall 1: Accountant Accessing Wrong Client's Documents (Authorization Boundary Failure)

**What goes wrong:** Existing policies (e.g., `TransactionPolicy`) use simple `$user->id === $tx->user_id` ownership checks. When you add accountant access to documents, the temptation is to bolt on `|| $user->isAccountant()` -- this grants ALL accountants access to ALL client documents. Alternatively, checking the `accountant_clients` relationship but forgetting to verify `status = 'active'` lets revoked accountants retain access.

**Why it happens:** The existing codebase has no concept of "delegated access." Every policy is single-owner. The accountant-client relationship in `AccountantClient` is a many-to-many pivot, but existing policies don't reference it. When adding document policies, developers default to the simplest check rather than scoping to the active relationship.

**Consequences:** An accountant can view, download, or annotate tax documents (containing SSN, income, EIN) belonging to clients they don't manage. This is both a data breach and a compliance violation.

**Prevention:**
- Create a dedicated `TaxDocumentPolicy` that checks the full chain: `user owns document OR (user is accountant AND active accountant_clients relationship exists with document owner AND document is in a shared package)`
- Extract a reusable `canAccessClient(User $accountant, User $client): bool` method that checks `AccountantClient::where('accountant_id', $accountant->id)->where('client_id', $client->id)->where('status', 'active')->exists()`
- Use this helper in every policy, controller, and route that touches client data
- Add a global scope or middleware that enforces client-scoping on all document queries when the current user is an accountant
- Write explicit tests: accountant A should NOT see client of accountant B

**Detection:** Test with two accountants, each with one client. Verify cross-access returns 403. Automated test: `$this->actingAs($accountantA)->getJson("/api/v1/documents/{$clientOfAccountantB->document->id}")->assertForbidden()`

---

### Pitfall 2: PII Leakage Through AI Extraction Results

**What goes wrong:** AI extraction from W-2s, 1099s, and other tax forms returns full SSN, full EIN, income figures, and addresses. This extracted data gets stored in a JSON column, returned in API responses, logged in Laravel logs, or exposed in error messages. The existing `$hidden` pattern on models doesn't help because extracted fields are inside a JSON blob, not separate columns.

**Why it happens:** The two-pass classify-then-extract pipeline returns structured JSON with all form fields. Developers store the raw extraction result for debugging/accuracy tracking. The `$hidden` attribute on models only hides top-level columns, not nested JSON keys. Laravel's exception handler may dump request/response data containing PII to log files.

**Consequences:** SSN, EIN, and income data exposed in: API responses to the frontend, Laravel log files, error tracking services (Sentry, Bugsnag), database backups (if extracted_data is not encrypted), browser dev tools network tab.

**Prevention:**
- Store extracted data using Laravel's `encrypted:array` cast -- never as plain JSON
- Create a `SanitizedExtractionResource` API Resource that masks SSN to `***-**-1234`, masks EIN to `**-***4567`, and only returns full values when explicitly requested with a `?reveal=true` parameter (gated by policy)
- Add SSN/EIN to `config('logging.replace')` or use a custom log formatter that redacts 9-digit patterns matching `\d{3}-\d{2}-\d{4}`
- Never log raw AI API responses -- log only classification results and confidence scores
- In TypeScript, never store full PII in React state; fetch masked by default, reveal on demand with a separate API call

**Detection:** Grep codebase for `extracted_data` in any `->toArray()`, `response()->json()`, or `Log::` call. Search log files for 9-digit number patterns.

---

### Pitfall 3: Signed URL Token Replay and Leakage

**What goes wrong:** Document sharing packages use time-limited signed URLs. If the expiry is too long (hours instead of minutes), URLs get shared via email, cached by browsers, indexed by corporate proxies, or forwarded to unauthorized parties. If the expiry is too short, legitimate users can't download. If URLs are generated client-side or cached in React state, they persist in browser history.

**Why it happens:** Developers pick a "reasonable" expiry (24 hours) without considering the threat model. Laravel's `temporarySignedRoute()` creates valid URLs that work for anyone who has the link -- there's no session binding. The existing app doesn't use signed URLs for anything, so there's no established pattern.

**Consequences:** Tax documents containing PII accessible to anyone with the URL. Browser history, proxy logs, and email archives become vectors for unauthorized access.

**Prevention:**
- Use short expiry: 5-15 minutes for direct downloads, 1 hour maximum for sharing packages
- Bind signed URLs to the session: add the user ID as a parameter in the signed route and verify `$request->user()->id === $request->route('userId')` in the controller
- For S3 pre-signed URLs, generate them server-side on demand -- never cache them in API responses or React state
- Add `Cache-Control: no-store, no-cache` and `Content-Disposition: attachment` headers to document download responses
- Implement one-time-use tokens for high-sensitivity documents: store token in Redis with TTL, delete on first use
- Log every signed URL generation and every download in the audit trail

**Detection:** Check if any signed URL has expiry > 15 minutes. Verify that download endpoints check user identity, not just URL validity.

---

### Pitfall 4: Immutable Audit Log Bypass via Eloquent

**What goes wrong:** The audit log is designed as append-only, but Laravel Eloquent allows `update()` and `delete()` on any model by default. A developer (or a future bug) calls `AuditLog::where('id', $id)->delete()` and the "immutable" log is quietly mutated. Raw SQL can also bypass model-level protections.

**Why it happens:** Eloquent has no built-in concept of immutable models. Overriding `delete()` and `update()` on the model only prevents ORM-level mutations. The existing codebase uses no SoftDeletes on any model, so there's no established pattern for preventing hard deletes. Database-level protections are rarely considered.

**Consequences:** Audit trail integrity compromised. In a compliance audit or legal dispute, the log cannot be trusted as evidence.

**Prevention:**
- Override `delete()`, `update()`, `save()` (when not new), `forceDelete()` on the AuditLog model to throw exceptions
- Create a database trigger that prevents UPDATE and DELETE on the audit_logs table: `CREATE RULE audit_no_update AS ON UPDATE TO audit_logs DO INSTEAD NOTHING; CREATE RULE audit_no_delete AS ON DELETE TO audit_logs DO INSTEAD NOTHING;`
- Better yet: create a dedicated PostgreSQL role for the audit log writer that only has INSERT and SELECT privileges on the audit_logs table
- Include a hash chain: each entry stores `hash = sha256(previous_entry_hash + current_entry_data)` so tampering is detectable even if database-level protections are circumvented
- Add a scheduled job that verifies hash chain integrity daily

**Detection:** Run `SELECT count(*) FROM audit_logs WHERE updated_at != created_at` -- should always be 0. Verify hash chain integrity on demand.

---

### Pitfall 5: Dual Sign-Off Race Condition

**What goes wrong:** Taxpayer and accountant both approve simultaneously. Without proper locking, both transactions read `status = 'pending'`, both write their approval, and the workflow completes without proper sequencing. Worse: one approves while the other is revoking, creating an inconsistent state where documents appear both approved and revoked.

**Why it happens:** The sign-off is two separate API calls from two different users. Standard Eloquent `save()` has no locking. The existing codebase doesn't use database transactions with locking (`lockForUpdate()`).

**Consequences:** Tax year marked as "filed" without both parties genuinely approving. Revocation ignored. Audit log shows conflicting timestamps.

**Prevention:**
- Use `DB::transaction()` with `lockForUpdate()` on the sign-off record: read current state, validate transition is legal, write new state -- all within a single locked transaction
- Model the workflow as a state machine with explicit states: `draft -> taxpayer_approved -> both_approved -> filed` (and `draft -> accountant_approved -> both_approved -> filed`). Only allow valid transitions.
- Store each sign-off as a separate row (not two columns on one row): `sign_offs` table with `user_id, role, tax_year, signed_at, revoked_at`. This naturally handles the two independent actions.
- Add a unique constraint: `UNIQUE(tax_year_id, user_role)` to prevent double sign-off by the same role
- After both sign-offs exist, a background job (not the HTTP request) transitions the tax year to "filed" -- this serializes the final check

**Detection:** Write a test that fires both approvals concurrently using `async` HTTP calls. Verify the final state is consistent. Check for duplicate sign-off records.

---

### Pitfall 6: S3 Credentials Exposed in Super Admin Config UI

**What goes wrong:** The Super Admin storage configuration page lets admins toggle between local and S3 storage and enter AWS credentials. If these credentials are stored in the database (even encrypted) and returned to the frontend, they're exposed in API responses, React state, and browser dev tools.

**Why it happens:** The natural pattern is: admin enters credentials in a form, API stores them, API returns them for the "edit" view. The existing config pattern (`config/spendifiai.php`) reads from `.env`, but runtime-configurable S3 credentials don't fit this pattern.

**Consequences:** AWS credentials leaked via: API response interception, browser dev tools, XSS attack reading React state, database dump without encryption.

**Prevention:**
- Never return AWS secret keys to the frontend. Show `AWS_SECRET_ACCESS_KEY: ••••••••••` (stored but masked). Only accept new values, never echo back.
- Store credentials using Laravel's `encrypted` cast in a `system_settings` table
- Use IAM roles (instance profiles) in production instead of access keys when possible -- this eliminates stored credentials entirely
- If access keys are required, validate them server-side before saving (attempt S3 `ListBuckets` call)
- Add `$hidden = ['aws_secret_access_key']` on any model that stores credentials
- Log credential changes in the audit trail (log the event, never the credential value)

**Detection:** Inspect API responses from the storage config endpoint. Verify no secret key values appear in JSON responses.

---

## Moderate Pitfalls

### Pitfall 7: Document Deletion vs. Soft-Delete Compliance Conflict

**What goes wrong:** Tax documents have conflicting requirements: users expect to delete their documents (GDPR/CCPA right to deletion -- the app already has `DELETE /api/v1/account` for account deletion), but IRS retention requirements mandate keeping tax records for 3-7 years. Hard-deleting a document destroys evidence; soft-deleting but keeping the file violates deletion requests.

**Prevention:**
- Implement a `RetentionPolicy` enum: `user_managed` (can delete anytime), `tax_retention` (locked for 7 years from tax year), `legal_hold` (locked indefinitely)
- For user deletion requests during retention period: delete the user record and PII, but retain anonymized document metadata and the document itself in a "tombstoned" state with no identifying information
- Use SoftDeletes on TaxDocument model specifically (even though no existing models use it), with a `permanently_deletable_after` timestamp
- For GDPR Article 17 requests: separate "identifying data" from "document data" -- delete the former, retain the latter anonymized
- Document this in the privacy policy and terms of service

### Pitfall 8: AI Extraction Hallucination Treated as Ground Truth

**What goes wrong:** The AI extraction pipeline returns structured data from tax forms (employer name, wages, SSN last 4, etc.). Developers store this directly and use it to auto-populate tax worksheets without human validation. The AI hallucinates a wrong dollar amount, wrong form type, or invents a field that doesn't exist on the document.

**Prevention:**
- Never auto-populate worksheets without a "Review Extraction" step where the user confirms each field
- Store extraction results with per-field confidence scores, not just document-level confidence
- Flag any extracted dollar amount that differs from the user's bank transaction totals by more than a configurable threshold (e.g., 5%)
- For critical fields (SSN, EIN, total income), require the user to manually confirm or correct -- never silently accept
- Implement cross-document validation: if W-2 wages don't match 1040 reported wages, flag the discrepancy
- Store both the raw extraction and the user-confirmed version separately -- audit trail should show what AI extracted vs. what the user accepted
- Use the existing confidence threshold pattern from `config/spendifiai.php` (`auto_accept: 0.85`, `flag_review: 0.60`, etc.) -- apply per-field, not per-document

### Pitfall 9: Impersonation Token Grants Document Access Without Audit Trail

**What goes wrong:** The existing impersonation system creates a full Sanctum token for the client (`$client->createToken($tokenName)`). When document vault endpoints are added, an impersonating accountant gets full document access through the client's token. The audit log records the action as the client (since `$request->user()` returns the client), making it impossible to determine who actually accessed the document.

**Prevention:**
- Every document endpoint must check if the current token is an impersonation token (starts with `impersonate:`). If so, log both the accountant ID (from token name) and the client ID.
- Better: create middleware that automatically enriches audit log entries with `acting_as` and `actual_user` fields when impersonation is detected
- Consider: should impersonation tokens even grant document access? For highly sensitive tax documents, require the accountant to access through the accountant portal (with explicit audit trail) rather than through impersonation
- At minimum, restrict impersonation tokens from document deletion, sign-off actions, and sharing package creation

### Pitfall 10: Missing Tenant Scoping on Document Queries

**What goes wrong:** A new `TaxDocumentController` is created with queries like `TaxDocument::find($id)`. This has no user scoping -- any authenticated user who guesses or enumerates document IDs can access any document. The existing app's controllers (e.g., `TransactionController`) likely scope queries to `$request->user()->transactions()`, but a new developer working on the document vault might not follow this pattern.

**Prevention:**
- Use route model binding with a custom resolution that scopes to the current user: `Route::bind('taxDocument', fn ($id) => TaxDocument::where('user_id', auth()->id())->findOrFail($id))`
- Better: always query through the relationship: `$request->user()->taxDocuments()->findOrFail($id)` -- never use `TaxDocument::find()`
- Add a global scope on TaxDocument that automatically filters by `user_id` (with an escape hatch for admin/accountant contexts)
- Use UUIDs instead of auto-incrementing IDs for document primary keys to prevent enumeration
- The policy check is a second line of defense, not the first -- query scoping prevents data loading, policy prevents rendering

### Pitfall 11: Annotation/Comment Threads Leaking Cross-Client

**What goes wrong:** An accountant adds a comment on Client A's document. The comment references Client B's information ("Same deduction pattern as your other client John Smith"). Now Client A can see information about Client B in the comment thread. This is a data handling issue, not a technical authorization issue.

**Prevention:**
- Comments by accountants should be flagged as `visibility: 'accountant_only'` or `visibility: 'shared'` -- default to accountant-only
- Add a UI confirmation when an accountant switches a comment to "shared" visibility
- Never auto-include client names or document data from other clients in comment suggestions
- If AI-assisted comments are added later, ensure the AI prompt is scoped to only the current client's data

### Pitfall 12: File Upload Validation Gaps (Malware, Size, Type)

**What goes wrong:** Tax documents are PDFs, images, and sometimes CSVs. Without proper validation: malware-laden PDFs are stored and served to other users, oversized files consume storage/memory during AI processing, non-document files (executables renamed to .pdf) are accepted, path traversal in filenames allows writing outside the upload directory.

**Prevention:**
- Validate MIME type server-side using `finfo_file()`, not just the file extension: `'file' => 'required|file|mimes:pdf,jpg,png,csv|max:20480'`
- Scan uploaded files with ClamAV or similar before storage (can run as a queue job)
- Generate random filenames on storage (`Str::uuid() . '.' . $extension`) -- never use the original filename for storage paths
- Store originals in a quarantine directory until AI processing completes, then move to the permanent directory
- Set per-user storage quotas to prevent abuse

---

## Minor Pitfalls

### Pitfall 13: Timezone Mismatch in Sign-Off Timestamps

**What goes wrong:** The existing app supports per-user timezones (`timezone` column on User, `PATCH /profile/timezone` endpoint). Sign-off timestamps stored in UTC are displayed in different timezones for the taxpayer and accountant, causing confusion about "who signed first" or whether a deadline was met.

**Prevention:**
- Always store sign-off timestamps in UTC (default Laravel behavior)
- Display in the viewer's timezone using `Carbon::parse($timestamp)->timezone($user->timezone)`
- Include both UTC and local time in the audit log entry
- For deadline enforcement, use a single canonical timezone (UTC or US Eastern) and document this in the UI

### Pitfall 14: Queue Job Failures Silently Dropping Document Processing

**What goes wrong:** AI extraction runs as a queue job. The job fails (API timeout, malformed PDF, rate limit) and after max retries, moves to the `failed_jobs` table. The user never learns their document wasn't processed -- it shows "processing" forever.

**Prevention:**
- Implement a `processing_started_at` column on the document model. If `processing_started_at` was more than 30 minutes ago and status is still "processing," show "Processing failed -- please retry"
- Use Laravel job events (`Queue::failing()`) to update document status to "failed" and notify the user
- Add a dead letter notification: if a document has been "processing" for > 1 hour, email the user
- Existing pattern: check how `StatementUpload` handles status -- follow the same pattern for consistency

### Pitfall 15: APP_KEY Rotation Invalidating All Signed URLs and Encrypted Data

**What goes wrong:** The app uses `encrypted` model casts extensively (Plaid tokens, 2FA secrets, etc.). Rotating `APP_KEY` invalidates all encrypted data AND all signed URLs simultaneously. Adding more encrypted fields (extraction results, S3 credentials) increases the blast radius.

**Prevention:**
- Never rotate APP_KEY without a migration plan
- Document all encrypted fields in a single reference (model + field name)
- Before rotating, write a migration that re-encrypts all data: decrypt with old key, encrypt with new key
- Consider using a separate encryption key for document vault data so it can be rotated independently

### Pitfall 16: Document Sharing Package Expiry Without Notification

**What goes wrong:** An accountant creates a sharing package with a 7-day expiry. The client doesn't download within 7 days. The links expire silently. The accountant thinks the client has the documents; the client never received them.

**Prevention:**
- Send reminder notifications at 24 hours before expiry
- Show expiry status prominently in both the accountant and client dashboards
- Allow accountants to extend or regenerate sharing packages
- Log download events so the accountant can see whether the client actually downloaded

---

## Phase-Specific Warnings

| Phase Topic | Likely Pitfall | Mitigation |
|-------------|---------------|------------|
| Document Vault & Storage | File upload validation gaps (#12), S3 credential exposure (#6), APP_KEY rotation risk (#15) | Validate MIME types server-side, never return secrets to frontend, document encrypted fields |
| AI Extraction Pipeline | Hallucination as ground truth (#8), PII in extraction results (#2), queue failures (#14) | Per-field confidence + human review, encrypt extracted data, implement failure notifications |
| Accountant Portal & Auth | Wrong client access (#1), impersonation audit gaps (#9), cross-client comment leakage (#11) | Scoped policies with relationship checks, enrich audit logs with actual_user, default comments to accountant-only |
| Dual Sign-Off Workflow | Race condition (#5), timezone confusion (#13) | Database locking with state machine, canonical timezone for deadlines |
| Document Sharing | Signed URL replay (#3), expiry without notification (#16) | Short expiry + session binding, reminder notifications |
| Immutable Audit Log | Eloquent bypass (#4), log integrity verification | Database-level rules + hash chains, scheduled integrity checks |
| Multi-Role Authorization | Missing tenant scoping (#10), document deletion compliance (#7) | Always query through relationships, implement retention policies |

---

## Integration Risks with Existing Codebase

These pitfalls are specific to adding these features to the existing SpendifiAI codebase:

1. **Existing policies are owner-only.** `TransactionPolicy`, `BankAccountPolicy`, etc. all use `$user->id === $model->user_id`. The document vault needs a fundamentally different pattern (owner OR delegated accountant). Don't retrofit existing policies -- create new ones with the delegated access pattern, then consider migrating existing policies later.

2. **No SoftDeletes anywhere.** The existing codebase uses zero SoftDeletes on any model. Adding SoftDeletes only to `TaxDocument` creates inconsistency. Be explicit about why this model is different (compliance retention) and document it.

3. **Impersonation creates full-privilege tokens.** The current `ImpersonationController` creates a Sanctum token with no ability restrictions. When document vault endpoints are added, impersonation tokens automatically grant full document access. Consider using Sanctum's token abilities (`$client->createToken($tokenName, ['read-only'])`) to restrict what impersonation tokens can do.

4. **Existing `$hidden` pattern doesn't protect JSON columns.** Models like `User` properly hide `password`, `google_id`, etc. But extracted tax data stored as `encrypted:array` in a JSON column needs field-level masking in API Resources, not just `$hidden`.

5. **Config reads from `.env` only.** The existing `config/spendifiai.php` uses `env()` for all values. Runtime-configurable S3 credentials (set by Super Admin) need database-backed config with a fallback to `.env`. Use a `SystemSetting` model with `config()` integration, not a parallel config system.

## Sources

- [Laravel Signed URL Best Practices](https://salihanmridha.com/signed-url-laravel/)
- [AWS S3 Pre-signed URLs for PHP](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-presigned-url.html)
- [AuditChain Immutability Documentation](https://laravel-auditchain.com/en/docs/security/immutability)
- [Immutable Audit Trails Guide](https://www.hubifi.com/blog/immutable-audit-log-basics)
- [Auditing Sensitive Data Changes in Laravel](https://dev.to/azmy/auditing-sensitive-data-changes-in-laravel-securing-high-risk-operations-9n3)
- [Multi-Tenant RBAC Authorization](https://www.aserto.com/blog/authorization-101-multi-tenant-rbac)
- [Multi-Tenant SaaS in Laravel](https://blog.greeden.me/en/2025/12/24/field-ready-complete-guide-designing-a-multi-tenant-saas-in-laravel-tenant-isolation-db-schema-row-domain-url-strategy-billing-authorization-auditing-performance-and-an-access/)
- [Database Locking for Race Conditions](https://sqlfordevs.com/transaction-locking-prevent-race-condition)
- [AI Tax Accuracy Benchmarks](https://www.filed.com/measuring-ai-tax-accuracy-filed-vs-chatgpt-claude-gemini)
- [PII Data Breach in Tax Consultancy](https://www.vpnmentor.com/news/report-rockerbox-breach/)
- [IRS Taxpayer Privacy Protections](https://www.irs.gov/privacy-disclosure/what-are-we-doing-to-protect-taxpayer-privacy)
- [NIST PII Protection Guidelines](https://nvlpubs.nist.gov/nistpubs/legacy/sp/nistspecialpublication800-122.pdf)
