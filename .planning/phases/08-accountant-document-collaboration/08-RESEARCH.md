# Phase 8: Accountant Document Collaboration - Research

**Researched:** 2026-03-30
**Domain:** Laravel multi-tenant accountant portal with document annotations, requests, and email notifications
**Confidence:** HIGH

## Summary

Phase 8 extends the existing accountant infrastructure (AccountantClient model, AccountantController, EnsureAccountant middleware, ImpersonationContext, TaxDocumentPolicy) with firm-level organization, document annotations, missing document requests, a comprehensive dashboard, and 5 email notification classes. The codebase already has all foundational patterns in place -- this phase adds 3 new models (AccountingFirm, DocumentAnnotation, DocumentRequest), 2 new controllers, 1 new Inertia page, extends 2 existing pages, and creates 5 Mail classes following the established AccountantInviteMail pattern.

The primary technical challenge is the firm-level restructuring: moving from accountant-to-client relationships to firm-to-client relationships while maintaining backward compatibility with the existing AccountantClient pivot table. The document annotations system is straightforward (threaded comments on TaxDocument), and the missing document request system reuses MissingAlertBanner patterns from Phase 6.

**Primary recommendation:** Extend existing accountant infrastructure with firm-level scoping. Build models first (AccountingFirm, DocumentAnnotation, DocumentRequest), then API endpoints, then frontend pages, then email notifications, then authorization tests.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- New AccountingFirm model with name, address, phone, branding (logo URL, primary color)
- Accountant users belong to a firm (accountant_id -> accounting_firm_id foreign key on users or separate pivot)
- Firm registration is a dedicated flow: accountant creates firm, then invites clients from firm context
- Existing AccountantClient model extended -- clients managed at firm level, not individual accountant level (ACCT-02)
- Branded invite links generated per-firm with unique token -- client self-registers and auto-links to the firm
- Firm generates branded invite links (unique URL with firm token)
- Client clicks link -> registers (or logs in if existing) -> automatically linked to the firm
- Existing AccountantInviteMail extended for branded firm invites (firm name, logo, color in email template)
- Invite link page shows firm branding (name, logo) so client knows who invited them
- New DocumentAnnotation model: belongs to TaxDocument and User, threaded (parent_id for replies)
- Annotations displayed as a thread on the document detail page (new "Comments" tab)
- Both accountant and client can add annotations -- threaded conversation on a document
- Annotations are timestamped and show author name/role badge (Accountant vs Client)
- New annotations trigger email notification to the other party (ACCT-09)
- New DocumentRequest model: accountant creates a request with description, links to client and optional tax year/category
- Client sees requests as alerts in their vault view (extends MissingAlertBanner from Phase 6)
- Each request has status: pending -> uploaded -> dismissed
- When client uploads a document that matches a request, request auto-updates to "uploaded"
- Request creation triggers email notification to client (ACCT-09)
- New Accountant/Dashboard.tsx page replacing or extending the existing Clients.tsx
- Stats bar at top: total clients, documents pending review, missing requests open, upcoming deadlines
- Client list table with: name, document count, completeness percentage, last activity, status badge
- Deadline tracker section (tax filing deadlines per client)
- Invite link generator: button to copy firm's branded invite URL
- Click client row -> view their documents (existing impersonation or scoped view)
- 5 Mail classes: FirmInviteMail, DocumentRequestMail, AnnotationNotifyMail, DocumentUploadedMail, RequestFulfilledMail
- Cross-role authorization tested: owner access own docs, linked accountant access client docs, unlinked accountant blocked

### Claude's Discretion
- Exact dashboard layout and responsive breakpoints
- Annotation thread styling and threading depth limits
- Deadline tracker data source and display format
- Document completeness calculation algorithm
- Invite link expiration policy
- How "matching" works for auto-fulfilling document requests

### Deferred Ideas (OUT OF SCOPE)
None -- discussion stayed within phase scope
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| ACCT-01 | AccountingFirm model with firm registration flow | New model, migration, controller endpoint, registration Inertia page or modal |
| ACCT-02 | Accountant belongs to a firm; clients managed at firm level | Add `accounting_firm_id` to users table, extend AccountantClient with firm scope |
| ACCT-03 | Firm generates branded invite links for client onboarding | New `firm_invite_tokens` table or column on AccountingFirm, public invite route |
| ACCT-04 | Accountant can view client's uploaded tax documents | Existing TaxDocumentPolicy.view() already handles this -- verify and extend |
| ACCT-05 | Accountant can add annotations/comments on client documents (threaded) | New DocumentAnnotation model, API endpoints, AnnotationThread component |
| ACCT-06 | Accountant can request missing documents from client | New DocumentRequest model, API endpoints, DocumentRequestCard component |
| ACCT-07 | Client sees missing document requests as alerts with upload prompts | Extend MissingAlertBanner to show document requests alongside AI-detected gaps |
| ACCT-08 | Accountant dashboard shows client list with document completeness, deadline tracking | New Dashboard.tsx Inertia page with stats API endpoint |
| ACCT-09 | 5 new Mail classes for accountant workflows | 5 Mailable classes following AccountantInviteMail pattern + Blade templates |
| UI-03 | Accountant Dashboard page with stats bar, client list, deadline tracker, invite generator | Inertia page at /accountant/dashboard |
| UI-04b | Phase 8 shared components (AnnotationThread, DocumentRequestCard) | Two React components in Components/SpendifiAI/ |
| TEST-04 | Cross-role authorization tests (owner, accountant, wrong-accountant blocked) | Pest feature tests for TaxDocumentPolicy and new endpoints |
</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Laravel 12 | 12.x | Backend framework | Project standard |
| React 19 | 19.x | Frontend framework | Project standard |
| Inertia.js 2 | 2.x | SPA bridge | Project standard |
| TypeScript | 5.x | Type safety | Project standard |
| Tailwind CSS v4 | 4.x | Styling with sw-* tokens | Project standard |
| Pest PHP 3 | 3.x | Testing | Project standard |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| lucide-react | latest | Icons | All UI components (already used throughout) |
| axios | latest | HTTP client | API calls from React (already used) |

### No New Dependencies
This phase requires zero new npm or composer packages. Everything is built with existing stack.

## Architecture Patterns

### New Database Tables (3 migrations)

```
accounting_firms
├── id
├── name (string 255)
├── address (text, nullable)
├── phone (string 20, nullable)
├── logo_url (string 500, nullable)
├── primary_color (string 7, nullable -- hex like #0D9488)
├── invite_token (string 64, unique -- for branded invite links)
├── timestamps

document_annotations
├── id
├── tax_document_id (FK -> tax_documents)
├── user_id (FK -> users)
├── parent_id (FK -> document_annotations, nullable -- for threading)
├── body (text)
├── timestamps
├── INDEX (tax_document_id, created_at)
├── INDEX (parent_id)

document_requests
├── id
├── accounting_firm_id (FK -> accounting_firms)
├── client_id (FK -> users)
├── accountant_id (FK -> users -- who created the request)
├── description (text)
├── tax_year (integer, nullable)
├── category (string, nullable -- TaxDocumentCategory value)
├── status (string 20 -- pending/uploaded/dismissed)
├── fulfilled_document_id (FK -> tax_documents, nullable)
├── timestamps
├── INDEX (client_id, status)
├── INDEX (accounting_firm_id)
```

**Schema change to existing table:**
```
users table: ADD accounting_firm_id (FK -> accounting_firms, nullable)
```

### Recommended File Structure

```
app/
├── Models/
│   ├── AccountingFirm.php          # NEW
│   ├── DocumentAnnotation.php      # NEW
│   └── DocumentRequest.php         # NEW
├── Http/
│   ├── Controllers/Api/
│   │   ├── AccountantFirmController.php    # NEW: firm CRUD + invite links
│   │   ├── DocumentAnnotationController.php # NEW: annotation CRUD
│   │   └── DocumentRequestController.php   # NEW: request CRUD
│   └── Requests/
│       ├── StoreFirmRequest.php             # NEW
│       ├── StoreAnnotationRequest.php       # NEW
│       └── StoreDocumentRequestRequest.php  # NEW
├── Mail/
│   ├── FirmInviteMail.php          # NEW
│   ├── DocumentRequestMail.php     # NEW
│   ├── AnnotationNotifyMail.php    # NEW
│   ├── DocumentUploadedMail.php    # NEW
│   └── RequestFulfilledMail.php    # NEW
├── Policies/
│   └── TaxDocumentPolicy.php       # EXTEND: add annotate, requestDocument methods
database/migrations/
├── 2026_03_31_000001_create_accounting_firms_table.php
├── 2026_03_31_000002_add_accounting_firm_id_to_users.php
├── 2026_03_31_000003_create_document_annotations_table.php
└── 2026_03_31_000004_create_document_requests_table.php
resources/js/
├── Pages/
│   └── Accountant/
│       └── Dashboard.tsx           # NEW: replaces/extends Clients.tsx
├── Components/SpendifiAI/
│   ├── AnnotationThread.tsx        # NEW
│   └── DocumentRequestCard.tsx     # NEW
├── types/
│   └── spendifiai.d.ts             # EXTEND: add AccountingFirm, DocumentAnnotation, DocumentRequest types
resources/views/emails/
├── firm-invite.blade.php           # NEW
├── document-request.blade.php      # NEW
├── annotation-notify.blade.php     # NEW
├── document-uploaded.blade.php     # NEW
└── request-fulfilled.blade.php     # NEW
routes/
├── api.php                         # EXTEND: new accountant routes
└── web.php                         # EXTEND: /invite/{token} public route, /accountant/dashboard
tests/Feature/
└── AccountantAuthorizationTest.php # NEW: cross-role tests
```

### Pattern 1: Firm Registration Flow

**What:** Accountant creates a firm, which generates a unique invite token. Firm ID stored on user.
**When to use:** When accountant first accesses the portal without a firm.

```php
// AccountingFirm model
class AccountingFirm extends Model
{
    protected $fillable = [
        'name', 'address', 'phone', 'logo_url', 'primary_color', 'invite_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (AccountingFirm $firm) {
            $firm->invite_token = Str::random(64);
        });
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'accounting_firm_id');
    }

    public function clients(): HasManyThrough
    {
        // Firm -> members (accountants) -> accountant_clients -> clients
        // OR: firm clients directly via accountant_clients where accountant has this firm
        return $this->hasManyThrough(
            AccountantClient::class,
            User::class,
            'accounting_firm_id', // FK on users
            'accountant_id',      // FK on accountant_clients
            'id',                 // local key on accounting_firms
            'id'                  // local key on users
        );
    }
}
```

### Pattern 2: Threaded Annotations

**What:** Comments on documents with parent_id for threading.
**When to use:** Document detail Comments tab.

```php
class DocumentAnnotation extends Model
{
    protected $fillable = [
        'tax_document_id', 'user_id', 'parent_id', 'body',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(TaxDocument::class, 'tax_document_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }
}
```

### Pattern 3: Document Requests with Auto-Fulfillment

**What:** Accountant creates a request specifying what they need. When client uploads matching document, request auto-updates.
**When to use:** Missing document workflow.

```php
// In TaxDocumentController::store() or as an observer
// After document upload + classification:
$matchingRequests = DocumentRequest::where('client_id', $document->user_id)
    ->where('status', 'pending')
    ->when($document->tax_year, fn ($q) => $q->where('tax_year', $document->tax_year))
    ->when($document->category, fn ($q) => $q->where('category', $document->category->value))
    ->get();

foreach ($matchingRequests as $request) {
    $request->update([
        'status' => 'uploaded',
        'fulfilled_document_id' => $document->id,
    ]);
    // Send RequestFulfilledMail to accountant
    Mail::to($request->accountant)->queue(new RequestFulfilledMail($request, $document));
}
```

### Pattern 4: Branded Invite Link (Public Route)

**What:** Public route `/invite/{token}` that shows firm branding and lets client register/login.
**When to use:** Client onboarding from firm.

```php
// routes/web.php -- public, no auth required
Route::get('/invite/{token}', function (string $token) {
    $firm = AccountingFirm::where('invite_token', $token)->firstOrFail();
    return Inertia::render('Auth/FirmInvite', [
        'firm' => [
            'name' => $firm->name,
            'logo_url' => $firm->logo_url,
            'primary_color' => $firm->primary_color,
        ],
        'token' => $token,
    ]);
})->name('firm.invite');
```

### Pattern 5: Mail Classes (Follow AccountantInviteMail Pattern)

**What:** All 5 Mail classes follow the existing Mailable pattern with Blade templates.
**When to use:** All notification emails.

```php
class FirmInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AccountingFirm $firm,
        public User $client,
        public string $inviteUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->firm->name} invites you to {$this->configAppName()}",
        );
    }

    public function content(): Content
    {
        return new Content(html: 'emails.firm-invite');
    }

    private function configAppName(): string
    {
        return config('app.name');
    }
}
```

### Anti-Patterns to Avoid
- **Direct TaxDocument::find()** without tenant check -- always scope through policy or forUser()
- **Inline validation** in controllers -- always use FormRequest classes
- **Manual auth checks** -- use Policy methods (view, annotate, requestDocument)
- **env() calls** outside config files -- use config('spendifiai.*')
- **Manual encrypt/decrypt** -- use model casts

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Email templating | Custom HTML generation | Blade email templates + Mailable class | Consistent with existing 3 email templates |
| Authorization | Custom middleware per route | TaxDocumentPolicy + Gate + FormRequest | Already established pattern, tested |
| Threaded comments | Nested component recursion without limits | Flat list with parent_id + max depth 2 | Prevents infinite nesting UI issues |
| Token generation | Manual random strings | `Str::random(64)` in model boot | Laravel standard, collision-safe |
| Date formatting | Manual JS date math | Existing formatDate/formatRelativeTime helpers from Clients.tsx | Already built, tested in production |

## Common Pitfalls

### Pitfall 1: Firm-Level Client Scoping Mismatch
**What goes wrong:** AccountantClient table has `accountant_id` (individual), but ACCT-02 says "clients managed at firm level."
**Why it happens:** Existing schema is accountant-to-client, not firm-to-client.
**How to avoid:** Keep existing `accountant_id` on AccountantClient but add `accounting_firm_id` to users. When querying firm clients, join through accountant users who belong to the firm. Do NOT add `accounting_firm_id` to accountant_clients -- use the user's firm membership as the link.
**Warning signs:** If you find yourself adding a firm_id column to accountant_clients, reconsider.

### Pitfall 2: Annotation Visibility Leaking
**What goes wrong:** Annotations on a document visible to users who shouldn't see them.
**Why it happens:** Missing policy check on annotation listing.
**How to avoid:** Annotation API always checks TaxDocumentPolicy::view() before returning annotations. Only document owner and linked accountant see annotations.
**Warning signs:** Annotations endpoint not calling `$this->authorize('view', $document)`.

### Pitfall 3: Email Notification Loops
**What goes wrong:** Accountant posts annotation -> sends email to client -> client replies -> sends email to accountant -> infinite notifications.
**Why it happens:** Not technically a loop, but high notification volume.
**How to avoid:** This is actually correct behavior (each annotation notifies the other party). But ensure emails are queued (`Mail::to()->queue()`) not sent synchronously, and include unsubscribe/mute option in email footer.

### Pitfall 4: Invite Token Collision
**What goes wrong:** Two firms get the same invite token.
**Why it happens:** Random string collision (unlikely but possible).
**How to avoid:** Use `Str::random(64)` (collision probability negligible) and add `unique` constraint on invite_token column.

### Pitfall 5: Document Request Auto-Fulfillment False Positives
**What goes wrong:** Client uploads any document and it auto-fulfills an unrelated request.
**Why it happens:** Matching logic too broad (any upload fulfills any pending request).
**How to avoid:** Match on BOTH tax_year AND category. If request has no category, require manual fulfillment by accountant.

### Pitfall 6: Missing Audit Logging for New Actions
**What goes wrong:** Annotation and document request actions not logged in audit trail.
**Why it happens:** Forgetting to call TaxVaultAuditService for new operations.
**How to avoid:** Add audit log entries for: annotation_created, document_request_created, request_fulfilled. Use existing TaxVaultAuditService pattern.

## Code Examples

### API Route Structure for New Endpoints

```php
// routes/api.php -- add to accountant-only group
Route::prefix('v1/accountant')->middleware(['throttle:120,1', 'accountant'])->group(function () {
    // ... existing routes ...

    // Firm management
    Route::post('/firm', [AccountantFirmController::class, 'store']);
    Route::get('/firm', [AccountantFirmController::class, 'show']);
    Route::patch('/firm', [AccountantFirmController::class, 'update']);
    Route::get('/firm/invite-link', [AccountantFirmController::class, 'inviteLink']);

    // Dashboard data
    Route::get('/dashboard', [AccountantFirmController::class, 'dashboard']);

    // Document annotations (scoped to client documents)
    Route::get('/documents/{document}/annotations', [DocumentAnnotationController::class, 'index']);
    Route::post('/documents/{document}/annotations', [DocumentAnnotationController::class, 'store']);

    // Document requests
    Route::get('/clients/{client}/requests', [DocumentRequestController::class, 'index']);
    Route::post('/clients/{client}/requests', [DocumentRequestController::class, 'store']);
    Route::patch('/requests/{request}/dismiss', [DocumentRequestController::class, 'dismiss']);
});

// Client-facing routes (any auth user)
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    // Client can view their document requests
    Route::get('/document-requests', [DocumentRequestController::class, 'myRequests']);

    // Client can add annotations on their own documents
    Route::get('/tax-vault/documents/{document}/annotations', [DocumentAnnotationController::class, 'index']);
    Route::post('/tax-vault/documents/{document}/annotations', [DocumentAnnotationController::class, 'store']);
});
```

### TypeScript Interfaces

```typescript
export interface AccountingFirm {
    id: number;
    name: string;
    address?: string;
    phone?: string;
    logo_url?: string;
    primary_color?: string;
    invite_token: string;
    created_at: string;
}

export interface DocumentAnnotation {
    id: number;
    tax_document_id: number;
    user_id: number;
    parent_id: number | null;
    body: string;
    author: {
        id: number;
        name: string;
        user_type: 'personal' | 'accountant';
    };
    replies: DocumentAnnotation[];
    created_at: string;
}

export interface DocumentRequest {
    id: number;
    accounting_firm_id: number;
    client_id: number;
    accountant_id: number;
    description: string;
    tax_year?: number;
    category?: string;
    category_label?: string;
    status: 'pending' | 'uploaded' | 'dismissed';
    fulfilled_document_id?: number;
    accountant_name: string;
    created_at: string;
}
```

### AnnotationThread Component Pattern

```typescript
// AnnotationThread.tsx -- renders threaded comments with max depth 2
interface AnnotationThreadProps {
    documentId: number;
    annotations: DocumentAnnotation[];
    onAnnotationAdded: () => void;
}

// Top-level annotations (parent_id === null) rendered as cards
// Replies (parent_id !== null) rendered indented under parent
// Max nesting depth: 2 levels (top-level + replies)
// New annotation form at bottom with optional "Reply" button on each annotation
```

### Cross-Role Authorization Test Pattern

```php
// tests/Feature/AccountantAuthorizationTest.php
it('allows linked accountant to view client document', function () {
    $accountant = User::factory()->create(['user_type' => 'accountant']);
    $client = User::factory()->create(['user_type' => 'personal']);
    AccountantClient::create([
        'accountant_id' => $accountant->id,
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $document = TaxDocument::create([...documentData($client)]);

    $this->actingAs($accountant)
        ->getJson("/api/v1/tax-vault/documents/{$document->id}")
        ->assertOk();
});

it('blocks unlinked accountant from viewing document', function () {
    $accountant = User::factory()->create(['user_type' => 'accountant']);
    $otherClient = User::factory()->create(['user_type' => 'personal']);
    $document = TaxDocument::create([...documentData($otherClient)]);

    $this->actingAs($accountant)
        ->getJson("/api/v1/tax-vault/documents/{$document->id}")
        ->assertForbidden();
});
```

### Dashboard Stats API Pattern

```php
// AccountantFirmController::dashboard()
public function dashboard(Request $request): JsonResponse
{
    $accountant = $request->user();
    $clientIds = $accountant->clients()->pluck('users.id');

    return response()->json([
        'total_clients' => $clientIds->count(),
        'documents_pending_review' => TaxDocument::whereIn('user_id', $clientIds)
            ->where('status', 'ready')
            ->count(),
        'open_requests' => DocumentRequest::where('accountant_id', $accountant->id)
            ->where('status', 'pending')
            ->count(),
        'clients' => $accountant->clients()->get()->map(fn ($client) => [
            'id' => $client->id,
            'name' => $client->name,
            'email' => $client->email,
            'document_count' => $client->taxDocuments()->count(),
            'completeness' => $this->calculateCompleteness($client),
            'last_activity' => $client->last_active_at?->toIso8601String(),
            'open_requests' => DocumentRequest::where('client_id', $client->id)
                ->where('status', 'pending')->count(),
        ]),
        'firm' => $accountant->accountingFirm,
    ]);
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Individual accountant-client links | Firm-level client management | Phase 8 | AccountantClient stays, firm scoping added via user.accounting_firm_id |
| Email invite only | Branded invite link + email | Phase 8 | Public route /invite/{token} with firm branding |
| No document collaboration | Threaded annotations + requests | Phase 8 | New Comments tab on document detail |

## Open Questions

1. **Document completeness calculation algorithm**
   - What we know: Dashboard needs a completeness percentage per client
   - What's unclear: What defines "complete"? All expected form types present?
   - Recommendation: Simple approach -- count documents uploaded vs document requests (open + fulfilled). Percentage = fulfilled / (fulfilled + pending). If no requests exist, show "N/A" or calculate based on common form types for the client's employment type (from financial profile).

2. **Invite link expiration policy**
   - What we know: Firm invite tokens need to be persistent enough for client use
   - What's unclear: Should tokens expire?
   - Recommendation: No expiration for v2.0. Tokens are long (64 chars), unique, and the firm can regenerate. Expiration adds complexity without clear benefit at this stage.

3. **Deadline tracker data source**
   - What we know: Dashboard shows tax filing deadlines per client
   - What's unclear: Where do deadlines come from?
   - Recommendation: Use standard US tax deadlines (April 15 for individual, March 15 for corporate). Store in config/spendifiai.php. Phase 8 shows these as static dates -- Phase 9 intelligence layer can make them dynamic.

4. **Annotation notification recipient determination**
   - What we know: "Notify the other party" when annotation is added
   - What's unclear: If document has multiple accountants, who gets notified?
   - Recommendation: Notify document owner if annotator is accountant, notify all linked accountants if annotator is client (document owner). Keep it simple.

## Sources

### Primary (HIGH confidence)
- Codebase analysis of existing models, controllers, policies, and patterns
- `app/Models/AccountantClient.php` -- existing pivot model
- `app/Http/Controllers/Api/AccountantController.php` -- existing endpoints
- `app/Policies/TaxDocumentPolicy.php` -- existing authorization logic
- `app/Mail/AccountantInviteMail.php` -- existing email pattern
- `resources/js/Pages/Accountant/Clients.tsx` -- existing UI to extend
- `resources/js/Pages/Vault/Show.tsx` -- document detail with tabs
- `routes/api.php` -- existing route structure

### Secondary (MEDIUM confidence)
- Laravel 12 Mailable patterns -- consistent with project conventions
- Pest PHP 3 testing patterns -- consistent with existing test suite

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH -- no new dependencies, all existing patterns
- Architecture: HIGH -- extends well-understood existing patterns (models, controllers, mail, policies)
- Pitfalls: HIGH -- identified from actual codebase analysis of existing authorization and relationship patterns

**Research date:** 2026-03-30
**Valid until:** 2026-04-30 (stable -- internal application patterns don't change externally)
