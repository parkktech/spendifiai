# Architecture Patterns

**Domain:** Tax Document Vault, AI Extraction Pipeline, Accountant Portal, Dual Sign-off, Audit Log, Super Admin Storage
**Researched:** 2026-03-30
**Confidence:** HIGH (analysis of existing codebase patterns, no external dependencies to verify)

## Existing Architecture Snapshot

Before describing new components, here is what exists and how new features attach.

**Current state (32 models, 20 API controllers, 14 services, 11 enums):**

| Layer | Count | Key Examples |
|-------|-------|-------------|
| Models | 32 | User (with UserType enum, `clients()`/`accountants()` BelongsToMany), AccountantClient, AccountantActivityLog, Transaction, BankAccount, BankConnection, StatementUpload |
| API Controllers | 20 | AccountantController (7 methods), AccountantTaxController (3 methods), TaxController, StatementUploadController |
| Services | 14 | AI/TransactionCategorizerService (confidence thresholds), TaxExportService, PlaidService, BankStatementParserService |
| Enums | 11 | UserType (Personal/Accountant), ReviewStatus, ExpenseType, ConnectionStatus |
| Middleware | 11 | EnsureAccountant, EnsureAdmin, EnsureBankConnected, EnsureProfileComplete |
| Policies | 9 | TransactionPolicy, BankAccountPolicy, AIQuestionPolicy |
| Jobs | 10 | CategorizePendingTransactions, ParseBankStatement, SyncBankTransactions |
| API Resources | 10 | TransactionResource, BankAccountResource, SubscriptionResource |

**Key architectural patterns already established:**
- Thin controllers calling service layer for business logic
- `auth:sanctum` middleware on all authenticated routes
- Named middleware aliases (`admin`, `accountant`) in `bootstrap/app.php`
- Accountant-client relationship via `accountant_clients` pivot table
- `verifyAccountantClientRelationship()` helper pattern for access checks
- `AccountantActivityLog` model with `$timestamps = false`, manual `created_at`
- Config-driven AI thresholds in `config/spendifiai.php`
- Local + S3 filesystem disks already configured in `config/filesystems.php`
- Form Request validation classes (20 existing)
- API Resource classes for response formatting

---

## Recommended Architecture

### High-Level Component Map

```
EXISTING (modify)                     NEW (create)
============================          ============================
User model                            TaxDocument model
  + taxDocuments()                     TaxDocumentVersion model
  + taxWorksheets()                    TaxDocumentAnnotation model
  + signOffs()                         TaxWorksheet model
                                       TaxWorksheetField model
AccountantClient model                 TaxYearSignOff model
  + (no changes)                       DocumentSharePackage model
                                       DocumentShareItem model (pivot)
AccountantController                   DocumentRequest model
  + (no changes)                       DocumentAuditLog model
                                       StorageSetting model
AccountantTaxController
  + extend with doc/worksheet routes   AccountantFirm model

config/spendifiai.php                  DocumentStorageService
  + document_vault section             TaxDocumentClassifierService
  + extraction thresholds              TaxDocumentExtractorService
                                       DocumentAuditService
config/filesystems.php                 TaxWorksheetService
  + tax-documents disk                 DocumentShareService
                                       SignOffService
bootstrap/app.php
  + (no changes needed)

routes/api.php                         TaxDocumentController
  + vault route group                  TaxWorksheetController
  + share route group                  DocumentShareController
                                       SignOffController
                                       AdminStorageController

                                       ClassifyTaxDocument (Job)
                                       ExtractTaxDocument (Job)
                                       GenerateSharePackage (Job)

                                       TaxDocumentPolicy
                                       TaxWorksheetPolicy
                                       DocumentSharePolicy
                                       SignOffPolicy

                                       DocumentStatus (Enum)
                                       DocumentType (Enum)
                                       SignOffStatus (Enum)
                                       AuditAction (Enum)
                                       SharePackageStatus (Enum)

                                       TaxDocumentResource
                                       TaxWorksheetResource
                                       DocumentShareResource
```

### Component Boundaries

| Component | Responsibility | Communicates With |
|-----------|---------------|-------------------|
| **DocumentStorageService** | File storage abstraction (local/S3 toggle), signed URL generation, file encryption at rest | TaxDocumentController, DocumentShareService, AdminStorageController |
| **TaxDocumentClassifierService** | Two-pass AI: classify document type from first page, confidence scoring | ClassifyTaxDocument job, TaxDocumentExtractorService |
| **TaxDocumentExtractorService** | Extract structured fields from classified documents (25 form types), generate extraction JSON | ExtractTaxDocument job, TaxWorksheetService |
| **TaxWorksheetService** | Auto-populate worksheet fields from extraction data, cross-document validation, anomaly flagging | TaxWorksheetController, TaxDocumentExtractorService |
| **DocumentAuditService** | Immutable audit log writes (no update/delete), query audit trail | All controllers touching documents, middleware |
| **DocumentShareService** | Generate share packages with signed URLs, time-limited access, revocation | DocumentShareController, AccountantController |
| **SignOffService** | Dual sign-off workflow state machine (draft -> taxpayer_signed -> accountant_signed -> filed) | SignOffController, TaxWorksheetController |

---

## New Models (11 tables)

### Model Relationship Map

```
User (existing)
 |-- hasMany --> TaxDocument
 |                |-- hasMany --> TaxDocumentVersion
 |                |-- hasMany --> TaxDocumentAnnotation
 |                |-- belongsToMany --> DocumentSharePackage (via document_share_items)
 |
 |-- hasMany --> TaxWorksheet
 |                |-- hasMany --> TaxWorksheetField
 |                |-- hasOne --> TaxYearSignOff
 |
 |-- hasMany --> DocumentRequest (as recipient)
 |-- hasMany --> DocumentRequest (as requester, via accountant)
 |
 |-- hasOne --> AccountantFirm (accountant users only)

AccountantFirm (new)
 |-- belongsTo --> User (accountant)

TaxDocument (new)
 |-- belongsTo --> User
 |-- hasMany --> TaxDocumentVersion
 |-- hasMany --> TaxDocumentAnnotation
 |-- hasMany --> DocumentAuditLog
 |-- belongsToMany --> DocumentSharePackage

TaxDocumentVersion (new)
 |-- belongsTo --> TaxDocument
 |-- metadata: extraction_data (JSON), ai_confidence, storage_path

TaxDocumentAnnotation (new)
 |-- belongsTo --> TaxDocument
 |-- belongsTo --> User (author)
 |-- threaded via parent_id self-reference

TaxWorksheet (new)
 |-- belongsTo --> User
 |-- hasMany --> TaxWorksheetField
 |-- hasOne --> TaxYearSignOff
 |-- scoped by tax_year

TaxWorksheetField (new)
 |-- belongsTo --> TaxWorksheet
 |-- belongsTo --> TaxDocument (source, nullable)
 |-- stores: field_name, extracted_value, user_override, confidence

TaxYearSignOff (new)
 |-- belongsTo --> TaxWorksheet
 |-- belongsTo --> User (taxpayer)
 |-- belongsTo --> User (accountant, nullable)
 |-- status: SignOffStatus enum

DocumentSharePackage (new)
 |-- belongsTo --> User (sharer)
 |-- belongsTo --> User (recipient, nullable for link-based)
 |-- belongsToMany --> TaxDocument (via document_share_items)
 |-- has: access_token (hashed), expires_at, download_count

DocumentRequest (new)
 |-- belongsTo --> User (requester, accountant)
 |-- belongsTo --> User (recipient, client)
 |-- belongsTo --> TaxDocument (fulfilled_by, nullable)

DocumentAuditLog (new, immutable)
 |-- belongsTo --> User (actor)
 |-- polymorphic: auditable (TaxDocument, TaxWorksheet, DocumentSharePackage, etc.)
 |-- NO update/delete methods (override in model)

StorageSetting (new)
 |-- singleton-like: one row per setting key
 |-- Super Admin only
```

### How New Tables Relate to Existing 32 Models

| Existing Model | New Relationship | Type |
|----------------|-----------------|------|
| **User** | `taxDocuments()` | HasMany |
| **User** | `taxWorksheets()` | HasMany |
| **User** | `signOffs()` | HasMany (via TaxYearSignOff) |
| **User** | `documentRequests()` | HasMany |
| **User** | `receivedDocumentRequests()` | HasMany |
| **User** | `accountantFirm()` | HasOne (accountant users only) |
| **AccountantClient** | No changes | Pivot remains as-is |
| **AccountantActivityLog** | No changes | Existing activity log for accountant actions stays separate from DocumentAuditLog |
| **Transaction** | No changes | Tax deduction data feeds into worksheets via TaxWorksheetService, not direct relationship |

**Design decision:** Keep `AccountantActivityLog` and `DocumentAuditLog` as separate tables. The existing activity log tracks accountant CRM actions (view client, download tax summary). The new audit log tracks document-specific compliance events (upload, view, download, share, sign). Different retention policies, different query patterns, different compliance requirements.

---

## New Controllers (5)

Following existing pattern: thin controllers, Form Request validation, Policy authorization, service delegation.

### TaxDocumentController

```
POST   /api/v1/vault/documents              upload (multipart)
GET    /api/v1/vault/documents              index (paginated, filterable by year/type/status)
GET    /api/v1/vault/documents/{doc}        show (metadata + versions)
GET    /api/v1/vault/documents/{doc}/view   view (signed URL redirect)
DELETE /api/v1/vault/documents/{doc}        destroy (soft delete, audit logged)
POST   /api/v1/vault/documents/{doc}/classify    re-classify
GET    /api/v1/vault/documents/{doc}/annotations  annotations
POST   /api/v1/vault/documents/{doc}/annotations  createAnnotation
```

Middleware: `auth:sanctum`, `throttle:120,1`
Policy: `TaxDocumentPolicy` (owner or linked accountant via AccountantClient)

### TaxWorksheetController

```
GET    /api/v1/vault/worksheets                    index (by tax year)
GET    /api/v1/vault/worksheets/{year}             show (auto-create if missing)
POST   /api/v1/vault/worksheets/{year}/populate    populate from extractions
PATCH  /api/v1/vault/worksheets/{year}/fields      updateFields (user overrides)
GET    /api/v1/vault/worksheets/{year}/anomalies   anomalies (cross-doc validation)
```

Middleware: `auth:sanctum`, `throttle:120,1`
Policy: `TaxWorksheetPolicy` (owner or linked accountant)

### DocumentShareController

```
POST   /api/v1/vault/shares                  create package
GET    /api/v1/vault/shares                  index (list my packages)
DELETE /api/v1/vault/shares/{package}        revoke
GET    /api/v1/vault/shares/{token}/access   public access (no auth, token-validated)
```

The public access endpoint uses the hashed token for lookup, validates expiry, increments download count, returns signed URL. No `auth:sanctum` on this route.

### SignOffController

```
GET    /api/v1/vault/signoff/{year}          status
POST   /api/v1/vault/signoff/{year}/sign     sign (taxpayer or accountant based on user_type)
POST   /api/v1/vault/signoff/{year}/revoke   revoke own signature
```

Middleware: `auth:sanctum`
Policy: `SignOffPolicy` (taxpayer owns worksheet, or linked accountant)

### AdminStorageController

```
GET    /api/admin/storage/settings           current config
PATCH  /api/admin/storage/settings           update (local/s3 toggle, bucket config)
POST   /api/admin/storage/test               test S3 connectivity
GET    /api/admin/storage/stats              usage statistics
```

Middleware: `auth:sanctum`, `admin`
Placed under existing `Route::prefix('admin')->middleware('admin')` group.

---

## New Services (7)

### DocumentStorageService

**Integration point:** Wraps Laravel's filesystem. Reads `StorageSetting` model to determine active disk at runtime (not `.env`). Falls back to `config/filesystems.php` defaults.

```php
class DocumentStorageService
{
    public function store(UploadedFile $file, int $userId, int $taxYear): string;
    public function generateSignedUrl(string $path, int $expiryMinutes = 15): string;
    public function delete(string $path): bool;
    public function getActiveDisk(): string; // reads StorageSetting
    public function migrateToS3(string $localPath): string; // for admin migration
}
```

**Storage path convention:** `tax-documents/{user_id}/{tax_year}/{uuid}.{ext}`

**Signed URL strategy:**
- Local disk: Use Laravel's `URL::temporarySignedRoute()` with a dedicated download route
- S3 disk: Use S3 pre-signed URLs via `Storage::temporaryUrl()`
- Both return time-limited, tamper-proof URLs
- Default expiry: 15 minutes (configurable in `config/spendifiai.php`)

### TaxDocumentClassifierService

**Integration point:** Follows same pattern as `TransactionCategorizerService` -- calls Anthropic Claude API, uses confidence thresholds from `config/spendifiai.php`.

```php
class TaxDocumentClassifierService
{
    // Mirrors existing confidence pattern
    const CONFIDENCE_AUTO = 0.85;    // Auto-classify
    const CONFIDENCE_REVIEW = 0.60;  // Classify but flag
    const CONFIDENCE_MANUAL = 0.40;  // Suggest options, ask user

    public function classify(string $filePath, string $mimeType): ClassificationResult;
}
```

**Two-pass pipeline:** Classification runs first. If confidence >= 0.60, extraction job is queued automatically. If < 0.40, document is flagged for manual classification before extraction can proceed.

### TaxDocumentExtractorService

```php
class TaxDocumentExtractorService
{
    public function extract(TaxDocument $document, DocumentType $type): ExtractionResult;
    public function getSupportedFields(DocumentType $type): array; // 25 form types
}
```

Returns structured JSON with field-level confidence scores. Each field maps to a potential `TaxWorksheetField`.

### TaxWorksheetService

```php
class TaxWorksheetService
{
    public function populateFromExtractions(User $user, int $taxYear): TaxWorksheet;
    public function detectAnomalies(TaxWorksheet $worksheet): array;
    public function detectMissingDocuments(User $user, int $taxYear): array;
}
```

**Cross-document validation:** Compares W-2 totals against pay stubs, 1099 totals against bank deposits, etc. Flags discrepancies as anomalies.

### DocumentAuditService

```php
class DocumentAuditService
{
    public function log(
        User $actor,
        Model $auditable,  // polymorphic
        AuditAction $action,
        array $metadata = [],
        ?string $ipAddress = null
    ): DocumentAuditLog;

    public function getTrail(Model $auditable): Collection;
    public function getUserActivity(User $user, ?Carbon $since = null): Collection;
}
```

**Immutable enforcement:** The `DocumentAuditLog` model overrides `update()` and `delete()` to throw exceptions. No soft deletes. The migration should omit `updated_at` column (only `created_at`).

### DocumentShareService

```php
class DocumentShareService
{
    public function createPackage(User $sharer, array $documentIds, ?User $recipient, Carbon $expiresAt): DocumentSharePackage;
    public function revokePackage(DocumentSharePackage $package): void;
    public function accessPackage(string $token): DocumentSharePackage; // validates token + expiry
}
```

### SignOffService

```php
class SignOffService
{
    // State machine: draft -> taxpayer_signed -> fully_signed -> filed
    public function sign(TaxYearSignOff $signOff, User $signer): void;
    public function revoke(TaxYearSignOff $signOff, User $signer): void;
    public function getStatus(User $user, int $taxYear): TaxYearSignOff;
    public function canSign(TaxYearSignOff $signOff, User $signer): bool;
}
```

**Sign-off rules:**
1. Taxpayer signs first (attests all documents uploaded, worksheet reviewed)
2. Accountant signs second (attests review complete)
3. Either party can revoke their own signature (resets to previous state)
4. Both signatures = status `fully_signed`
5. `filed` is a manual final status set by accountant after actual filing

---

## New Enums (5)

Following existing pattern: backed string enums with `label()` method.

```php
enum DocumentStatus: string {
    case Pending = 'pending';         // Uploaded, not yet classified
    case Classifying = 'classifying'; // AI classification in progress
    case Classified = 'classified';   // Type determined, awaiting extraction
    case Extracting = 'extracting';   // AI extraction in progress
    case Extracted = 'extracted';     // Fields extracted successfully
    case ReviewNeeded = 'review_needed'; // Low confidence, needs human review
    case Failed = 'failed';          // Processing failed
}

enum DocumentType: string {
    case W2 = 'w2';
    case Form1099Misc = '1099_misc';
    case Form1099Nec = '1099_nec';
    case Form1099Int = '1099_int';
    case Form1099Div = '1099_div';
    case Form1099B = '1099_b';
    case Form1099R = '1099_r';
    case Form1098 = '1098';
    case Form1098T = '1098_t';
    case ScheduleC = 'schedule_c';
    case ScheduleK1 = 'schedule_k1';
    case Form1040 = '1040';
    // ... up to 25 types
    case Other = 'other';
}

enum SignOffStatus: string {
    case Draft = 'draft';
    case TaxpayerSigned = 'taxpayer_signed';
    case FullySigned = 'fully_signed';
    case Filed = 'filed';
}

enum AuditAction: string {
    case Upload = 'upload';
    case View = 'view';
    case Download = 'download';
    case Classify = 'classify';
    case Extract = 'extract';
    case Annotate = 'annotate';
    case Share = 'share';
    case RevokeShare = 'revoke_share';
    case Sign = 'sign';
    case RevokeSign = 'revoke_sign';
    case Delete = 'delete';
    case RequestDocument = 'request_document';
    case FulfillRequest = 'fulfill_request';
    case OverrideField = 'override_field';
}

enum SharePackageStatus: string {
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
```

---

## New Jobs (3)

Following existing pattern: `ShouldQueue`, `tries = 3`, `timeout = 180`.

### ClassifyTaxDocument

```
Trigger:  Dispatched by TaxDocumentController::upload()
Input:    TaxDocument ID
Process:  1. Read file from storage
          2. Call TaxDocumentClassifierService::classify()
          3. Update TaxDocument status + document_type
          4. If confidence >= 0.60, dispatch ExtractTaxDocument
          5. If confidence < 0.40, set status to review_needed
          6. Log via DocumentAuditService
Queue:    'document-processing' (new queue name)
```

### ExtractTaxDocument

```
Trigger:  Dispatched by ClassifyTaxDocument (or manual re-extract)
Input:    TaxDocument ID, DocumentType
Process:  1. Call TaxDocumentExtractorService::extract()
          2. Create TaxDocumentVersion with extraction_data JSON
          3. Update TaxDocument status to 'extracted'
          4. Log via DocumentAuditService
Queue:    'document-processing'
```

### GenerateSharePackage

```
Trigger:  Dispatched by DocumentShareController::create()
Input:    DocumentSharePackage ID
Process:  1. Generate access token (hashed)
          2. Create signed URLs for each document
          3. Set package status to active
          4. Send notification email to recipient (if specified)
Queue:    'default'
```

---

## New Policies (4)

### TaxDocumentPolicy

```php
// view/update/delete: owner OR linked accountant with active relationship
public function view(User $user, TaxDocument $document): bool
{
    if ($document->user_id === $user->id) return true;

    // Accountant access via existing AccountantClient relationship
    return $user->isAccountant() && AccountantClient::where('accountant_id', $user->id)
        ->where('client_id', $document->user_id)
        ->where('status', 'active')
        ->exists();
}
```

This reuses the same access check pattern as `AccountantController::verifyAccountantClientRelationship()` but encapsulates it in policy for consistency.

### TaxWorksheetPolicy, DocumentSharePolicy, SignOffPolicy

All follow the same owner-or-linked-accountant pattern.

---

## Modified Existing Components

### User Model (modify)

Add 6 new relationships:

```php
public function taxDocuments(): HasMany { return $this->hasMany(TaxDocument::class); }
public function taxWorksheets(): HasMany { return $this->hasMany(TaxWorksheet::class); }
public function signOffs(): HasMany { return $this->hasMany(TaxYearSignOff::class, 'taxpayer_id'); }
public function documentRequests(): HasMany { return $this->hasMany(DocumentRequest::class, 'recipient_id'); }
public function sentDocumentRequests(): HasMany { return $this->hasMany(DocumentRequest::class, 'requester_id'); }
public function accountantFirm(): HasOne { return $this->hasOne(AccountantFirm::class); }
```

### config/spendifiai.php (modify)

Add new config sections:

```php
'document_vault' => [
    'max_file_size_mb' => 25,
    'allowed_types' => ['pdf', 'jpg', 'jpeg', 'png', 'tiff'],
    'signed_url_expiry_minutes' => 15,
    'share_max_expiry_days' => 30,
    'storage_disk' => env('DOCUMENT_STORAGE_DISK', 'local'), // fallback, overridden by StorageSetting
],

'extraction' => [
    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
    'confidence_thresholds' => [
        'auto_classify' => 0.85,
        'flag_review' => 0.60,
        'manual_required' => 0.40,
    ],
    'max_pages' => 50,
    'batch_size' => 5, // concurrent extraction jobs
],
```

### config/filesystems.php (modify)

Add dedicated tax documents disk:

```php
'tax-documents' => [
    'driver' => env('DOCUMENT_STORAGE_DISK', 'local'),
    'root' => storage_path('app/private/tax-documents'), // local driver
    // S3 config inherited when driver is 's3'
],
```

### routes/api.php (modify)

Add new route groups within existing authenticated block:

```php
// Inside Route::middleware(['auth:sanctum']) -> Route::prefix('v1')
Route::prefix('vault')->group(function () {
    // TaxDocumentController routes
    // TaxWorksheetController routes
    // DocumentShareController routes
    // SignOffController routes
});

// Public share access (no auth)
Route::get('/vault/shares/{token}/access', [DocumentShareController::class, 'publicAccess']);

// Inside Route::prefix('admin')->middleware('admin')
Route::prefix('storage')->group(function () {
    // AdminStorageController routes
});
```

### Accountant routes (extend existing)

Add document-related endpoints under existing accountant-only route group:

```php
// Inside existing accountant-only group
Route::get('/clients/{client}/documents', [TaxDocumentController::class, 'clientDocuments']);
Route::get('/clients/{client}/worksheets/{year}', [TaxWorksheetController::class, 'clientWorksheet']);
Route::post('/clients/{client}/document-requests', [DocumentRequestController::class, 'create']);
Route::get('/clients/{client}/document-requests', [DocumentRequestController::class, 'index']);
```

---

## Data Flow: Upload to Sign-off

### Complete Pipeline

```
1. UPLOAD
   User uploads PDF/image via TaxDocumentController::upload()
   |
   +--> DocumentStorageService::store() -- saves file to active disk
   +--> TaxDocument created (status: pending)
   +--> DocumentAuditService::log(Upload)
   +--> ClassifyTaxDocument::dispatch()

2. CLASSIFY (async, job queue)
   ClassifyTaxDocument job runs
   |
   +--> TaxDocumentClassifierService::classify()
   |     |
   |     +--> Sends first page to Claude API
   |     +--> Returns: DocumentType + confidence score
   |
   +--> confidence >= 0.60?
   |     YES --> TaxDocument.status = 'classified', dispatch ExtractTaxDocument
   |     NO  --> confidence >= 0.40?
   |             YES --> TaxDocument.status = 'review_needed' (suggest type, user confirms)
   |             NO  --> TaxDocument.status = 'review_needed' (no suggestion)
   |
   +--> DocumentAuditService::log(Classify)

3. EXTRACT (async, job queue)
   ExtractTaxDocument job runs
   |
   +--> TaxDocumentExtractorService::extract()
   |     |
   |     +--> Sends full document + type-specific extraction prompt to Claude API
   |     +--> Returns: structured fields with per-field confidence scores
   |
   +--> TaxDocumentVersion created (extraction_data JSON)
   +--> TaxDocument.status = 'extracted'
   +--> DocumentAuditService::log(Extract)

4. WORKSHEET POPULATION (on-demand or auto)
   TaxWorksheetController::populate() or auto-triggered after extraction
   |
   +--> TaxWorksheetService::populateFromExtractions()
   |     |
   |     +--> Finds all extracted TaxDocuments for user + tax year
   |     +--> Maps extraction fields to TaxWorksheetFields
   |     +--> Links each field to source TaxDocument
   |     +--> Flags conflicts (e.g., two W-2s with same employer)
   |
   +--> TaxWorksheet created/updated with TaxWorksheetFields
   +--> Anomaly detection runs automatically

5. REVIEW + ANNOTATION
   Taxpayer and/or accountant review worksheet
   |
   +--> View fields, override extracted values if needed
   +--> Add annotations on specific documents
   +--> Accountant requests missing documents (DocumentRequest)
   +--> All actions audit-logged

6. SIGN-OFF (dual)
   SignOffController::sign()
   |
   +--> Taxpayer signs first
   |     +--> TaxYearSignOff.status = 'taxpayer_signed'
   |     +--> DocumentAuditService::log(Sign, {role: 'taxpayer'})
   |
   +--> Accountant signs second
   |     +--> TaxYearSignOff.status = 'fully_signed'
   |     +--> DocumentAuditService::log(Sign, {role: 'accountant'})
   |
   +--> Either party can revoke own signature (resets state)

7. SHARE (parallel to review)
   DocumentShareController::create()
   |
   +--> DocumentShareService::createPackage()
   +--> Generates hashed access token
   +--> Sets expiry (max 30 days)
   +--> Optional email notification
   +--> Public access via token (no auth required)
```

---

## Frontend Integration

### New Inertia Pages

```
resources/js/Pages/
  Tax/
    Vault.tsx            -- Document vault listing with upload
    VaultDocument.tsx    -- Single document view + annotations
    Worksheet.tsx        -- Tax worksheet with editable fields
    SignOff.tsx          -- Sign-off status + action buttons
    Share.tsx            -- Share package management

  Accountant/
    ClientDocuments.tsx  -- View client's vault
    ClientWorksheet.tsx  -- View/annotate client's worksheet
    DocumentRequests.tsx -- Manage document requests
    Firm.tsx             -- Firm profile/settings

  Admin/
    Storage.tsx          -- Storage configuration
```

### New TypeScript Interfaces

Add to `resources/js/types/spendifiai.d.ts`:

```typescript
interface TaxDocument {
    id: number;
    user_id: number;
    tax_year: number;
    document_type: string | null;
    status: 'pending' | 'classifying' | 'classified' | 'extracting' | 'extracted' | 'review_needed' | 'failed';
    original_filename: string;
    file_size: number;
    mime_type: string;
    ai_confidence: number | null;
    created_at: string;
    updated_at: string;
    versions?: TaxDocumentVersion[];
    annotations?: TaxDocumentAnnotation[];
}

interface TaxWorksheet {
    id: number;
    user_id: number;
    tax_year: number;
    fields: TaxWorksheetField[];
    sign_off?: TaxYearSignOff;
    anomalies?: WorksheetAnomaly[];
}

interface TaxYearSignOff {
    id: number;
    status: 'draft' | 'taxpayer_signed' | 'fully_signed' | 'filed';
    taxpayer_signed_at: string | null;
    accountant_signed_at: string | null;
}

interface DocumentSharePackage {
    id: number;
    access_url: string;
    expires_at: string;
    status: 'active' | 'expired' | 'revoked';
    document_count: number;
    download_count: number;
}
```

---

## Patterns to Follow

### Pattern 1: Audit-Wrapped Controller Actions

Every controller method that touches tax documents wraps with audit logging. Use a trait rather than repeating in each method.

```php
trait AuditsDocumentActions
{
    protected function auditAction(
        Request $request,
        Model $auditable,
        AuditAction $action,
        array $metadata = []
    ): void {
        app(DocumentAuditService::class)->log(
            actor: $request->user(),
            auditable: $auditable,
            action: $action,
            metadata: $metadata,
            ipAddress: $request->ip(),
        );
    }
}
```

### Pattern 2: Accountant Access via Policy (not inline checks)

The existing `AccountantController` uses an inline `verifyAccountantClientRelationship()` method. New code should use Laravel Policies instead, which is the pattern already established for other models (9 existing policies).

```php
// In TaxDocumentPolicy
public function view(User $user, TaxDocument $document): bool
{
    return $document->user_id === $user->id
        || ($user->isAccountant() && $this->isLinkedAccountant($user, $document->user_id));
}

private function isLinkedAccountant(User $accountant, int $clientId): bool
{
    return AccountantClient::where('accountant_id', $accountant->id)
        ->where('client_id', $clientId)
        ->active()
        ->exists();
}
```

### Pattern 3: Config-Driven Thresholds

Follow existing `config/spendifiai.php` pattern. All AI thresholds readable from config, not hardcoded in services.

```php
// In TaxDocumentClassifierService constructor
$this->autoClassifyThreshold = config('spendifiai.extraction.confidence_thresholds.auto_classify', 0.85);
```

### Pattern 4: Job Chaining for Pipeline

Use Laravel job chaining for the classify-then-extract pipeline:

```php
// In TaxDocumentController::upload()
ClassifyTaxDocument::dispatch($document->id)
    ->onQueue('document-processing');

// In ClassifyTaxDocument::handle(), conditionally:
if ($confidence >= $this->reviewThreshold) {
    ExtractTaxDocument::dispatch($document->id, $classifiedType)
        ->onQueue('document-processing');
}
```

---

## Anti-Patterns to Avoid

### Anti-Pattern 1: Storing Files Without Abstraction
**What:** Calling `Storage::disk('local')` or `Storage::disk('s3')` directly in controllers.
**Why bad:** The Super Admin storage toggle means the active disk changes at runtime. Direct calls bypass the toggle.
**Instead:** Always go through `DocumentStorageService`, which reads the active disk from `StorageSetting`.

### Anti-Pattern 2: Mutable Audit Log
**What:** Using standard Eloquent `update()` or `delete()` on `DocumentAuditLog`.
**Why bad:** Compliance requirement: audit trail must be immutable.
**Instead:** Override `update()`, `delete()`, `forceDelete()` in the model to throw `\RuntimeException`.

### Anti-Pattern 3: Inline Accountant Access Checks
**What:** Repeating `AccountantClient::where(...)` checks in every controller method.
**Why bad:** Duplicated logic, easy to miss edge cases (revoked status, etc.).
**Instead:** Encapsulate in Policy classes and use `$this->authorize()` in controllers.

### Anti-Pattern 4: Exposing File Paths in API Responses
**What:** Returning `storage_path` or S3 keys in JSON responses.
**Why bad:** Leaks internal storage structure. All file access must go through signed URLs.
**Instead:** Return only signed URLs via `DocumentStorageService::generateSignedUrl()`. Add `storage_path` to model `$hidden`.

### Anti-Pattern 5: Single Audit Log for Everything
**What:** Merging `AccountantActivityLog` and `DocumentAuditLog` into one table.
**Why bad:** Different compliance requirements, different query patterns, different retention policies. The existing activity log is accountant-CRM focused; the new one is document-compliance focused.
**Instead:** Keep them separate. Both are append-only, but serve different purposes.

---

## Scalability Considerations

| Concern | At 100 users | At 10K users | At 1M users |
|---------|--------------|--------------|-------------|
| Document storage | Local disk (dev/staging) | S3 with lifecycle policies | S3 + CloudFront CDN for signed URLs |
| Audit log volume | Single table, no partitioning | Partition by month via PostgreSQL partitioning | Archive to cold storage (S3/Glacier) after 2 years |
| AI extraction queue | Single worker | 3-5 workers on `document-processing` queue | Dedicated queue workers, rate limiting to manage API costs |
| Signed URL generation | On-demand, no caching | On-demand, 15-min expiry handles load | Pre-warm for share packages |
| Search/filtering | Simple WHERE clauses | Add indexes on (user_id, tax_year, document_type, status) | Full-text search on extraction data via PostgreSQL tsvector |

---

## Migration Strategy

### Migration Order (respects foreign key dependencies)

```
1. create_accountant_firms_table
   - References: users(id)
   - No other new table depends on this

2. create_storage_settings_table
   - No foreign keys to other new tables
   - Standalone admin config table

3. create_tax_documents_table
   - References: users(id)
   - Many tables depend on this

4. create_tax_document_versions_table
   - References: tax_documents(id)

5. create_tax_document_annotations_table
   - References: tax_documents(id), users(id)
   - Self-referential parent_id

6. create_tax_worksheets_table
   - References: users(id)

7. create_tax_worksheet_fields_table
   - References: tax_worksheets(id), tax_documents(id) nullable

8. create_tax_year_sign_offs_table
   - References: tax_worksheets(id), users(id) x2

9. create_document_share_packages_table
   - References: users(id) x2

10. create_document_share_items_table (pivot)
    - References: document_share_packages(id), tax_documents(id)

11. create_document_requests_table
    - References: users(id) x2, tax_documents(id) nullable

12. create_document_audit_logs_table
    - Polymorphic (auditable_type, auditable_id)
    - References: users(id)
    - No updated_at column
```

---

## Suggested Build Order

Based on dependency analysis:

### Phase 1: Foundation (Storage + Vault + Audit)
1. `StorageSetting` model + migration + `AdminStorageController`
2. `DocumentStorageService` (abstraction over local/S3)
3. `DocumentAuditLog` model + migration + `DocumentAuditService`
4. `TaxDocument` + `TaxDocumentVersion` models + migrations
5. `TaxDocumentController` (upload, list, view, delete)
6. `TaxDocumentPolicy`
7. Frontend: `Tax/Vault.tsx`, `Admin/Storage.tsx`

**Rationale:** Everything else depends on documents existing and being storable/auditable.

### Phase 2: AI Pipeline (Classify + Extract)
1. `DocumentType` + `DocumentStatus` enums
2. `TaxDocumentClassifierService`
3. `TaxDocumentExtractorService`
4. `ClassifyTaxDocument` + `ExtractTaxDocument` jobs
5. Config additions to `spendifiai.php`
6. Frontend: classification status indicators, manual classification UI

**Rationale:** Extraction feeds worksheets. Must work before worksheets can be populated.

### Phase 3: Worksheets + Annotations
1. `TaxWorksheet` + `TaxWorksheetField` models + migrations
2. `TaxDocumentAnnotation` model + migration
3. `TaxWorksheetService` (population, anomaly detection)
4. `TaxWorksheetController`
5. Frontend: `Tax/Worksheet.tsx`, `Tax/VaultDocument.tsx` (annotations)

**Rationale:** Worksheets depend on extraction data. Annotations are review support.

### Phase 4: Accountant Portal Extensions
1. `AccountantFirm` model + migration
2. `DocumentRequest` model + migration
3. Accountant document/worksheet routes
4. Frontend: `Accountant/ClientDocuments.tsx`, `Accountant/ClientWorksheet.tsx`, `Accountant/Firm.tsx`

**Rationale:** Accountant features overlay on existing vault + worksheets.

### Phase 5: Sign-off + Sharing
1. `TaxYearSignOff` model + migration
2. `DocumentSharePackage` + pivot models + migrations
3. `SignOffService` + `DocumentShareService`
4. `SignOffController` + `DocumentShareController`
5. Frontend: `Tax/SignOff.tsx`, `Tax/Share.tsx`

**Rationale:** Sign-off and sharing are the culmination features. They require documents, worksheets, and accountant relationships to all be in place.

---

## Sources

- Direct codebase analysis of `/var/www/html/ledgeriq/` (32 models, 20 controllers, 14 services, 11 enums, 11 middleware, 9 policies, 10 jobs)
- Existing patterns from `AccountantController`, `AccountantTaxController`, `TransactionCategorizerService`
- Laravel 12 filesystem configuration at `config/filesystems.php`
- Existing route structure at `routes/api.php`
- Existing middleware registration at `bootstrap/app.php`
- Project requirements from `.planning/PROJECT.md`
