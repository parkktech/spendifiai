# Phase 7: AI Document Extraction - Research

**Researched:** 2026-03-30
**Domain:** AI-powered document classification and structured field extraction (Claude API)
**Confidence:** HIGH

## Summary

Phase 7 adds AI-powered document classification and field extraction to the existing tax document vault built in Phase 6. The core work involves: (1) a new `TaxDocumentExtractorService` in `app/Services/AI/` that calls Claude API to classify documents into 25 tax form types and extract structured fields, (2) an `ExtractTaxDocument` queued job that orchestrates the two-pass pipeline (classify then extract), (3) expanding the `TaxDocumentCategory` enum from 8 to 25 form types, (4) a Document Detail page with split-panel PDF/image viewer and extracted field review, and (5) PATCH endpoint for inline field corrections with audit logging.

The project already has two proven Claude API integration patterns: `TransactionCategorizerService` (text-based structured JSON extraction with confidence scoring) and `BankStatementParserService` (PDF base64 document processing via Claude vision). The new extractor service combines both patterns -- sending document content (PDF via base64 document type, images via base64 image type) and receiving structured JSON with per-field confidence scores.

**Primary recommendation:** Build `TaxDocumentExtractorService` following `TransactionCategorizerService` structure (class constants for thresholds, `callClaude()` with retry, JSON response parsing) but using `BankStatementParserService`'s document/image submission pattern for the API call itself.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- Two-pass pipeline: classify first, then extract only if classification confidence >= 0.70
- On upload, document status transitions: upload -> classifying -> extracting -> ready (or failed)
- Classification dispatched as queued job (`ExtractTaxDocument`) triggered after successful upload
- Job has 3 retries with exponential backoff
- Claude AI called via `Http::` facade (same pattern as `TransactionCategorizerService`)
- Expand TaxDocumentCategory enum from 8 to 25 form types
- Tier 1 extraction (W-2, 1099-NEC, 1099-INT, 1098): full structured field extraction
- Tier 2+ forms: classified but extraction deferred/simpler schemas
- Unrecognized forms classified as "Other" with low confidence
- Per-field confidence structure: `{ fields: { field_name: { value: "...", confidence: 0.92 } }, overall_confidence: 0.87 }`
- Confidence thresholds aligned with transaction categorizer: >= 0.85 green, 0.60-0.84 amber, < 0.60 red
- SSN stripped to last 4 digits during extraction (never stored full)
- EIN stored encrypted via `encrypted:array` cast
- Split-panel layout: PDF/image viewer left, extracted fields right
- Inline editing with PATCH per-field, corrections set confidence to 1.0 and mark "user-verified"
- "Accept All" button to mark all fields as reviewed
- Corrections are audit-logged
- Configurable thresholds in `config/spendifiai.php` under `ai.extraction_thresholds`

### Claude's Discretion
- Exact Claude prompt engineering for classification and extraction
- PDF text extraction approach (pdftotext vs Claude vision vs hybrid)
- Extraction job timeout and memory limits
- Error messaging for failed extractions
- Loading states during extraction processing
- Field ordering and grouping in the review panel
- How to handle multi-page documents in the viewer

### Deferred Ideas (OUT OF SCOPE)
None -- discussion stayed within phase scope
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| AIEX-01 | Auto-classify uploaded documents into 25 tax form types using Claude AI | TaxDocumentExtractorService classify() method with Claude API document/image submission |
| AIEX-02 | Two-pass pipeline: classify first, extract if confidence >= threshold | ExtractTaxDocument job orchestrates classify->extract with 0.70 threshold gate |
| AIEX-03 | Extract structured fields from W-2, 1099-NEC, 1099-INT, 1098 (Tier 1) | Per-form field schemas defined as class constants, Claude prompted with schema |
| AIEX-04 | Extract structured fields from remaining form types (Tier 2+) | Simpler/generic extraction schemas, can be added incrementally |
| AIEX-05 | SSN stored as last 4 only, EIN encrypted, extracted_data uses encrypted:array | SSN stripping in service layer + prompt instruction, existing encrypted:array cast |
| AIEX-06 | Per-field confidence scoring surfaced in review UI | JSON structure with per-field confidence, ExtractionPanel component with color-coded badges |
| AIEX-07 | Side-by-side document viewer + extracted field review/correction | Document Detail Inertia page with split-panel, inline editing, PATCH endpoint |
| AIEX-08 | Extraction runs as queued job with retries | ExtractTaxDocument job, 3 tries, exponential backoff, status transitions |
| UI-02 | Document Detail page with split-panel viewer + extracted fields + annotations thread | New Inertia page at /vault/documents/{id} with tab structure |
| UI-04b | ExtractionPanel component (Phase 7 component) | React component with confidence badges, inline edit, accept all |
| TEST-02 | Unit tests for TaxDocumentExtractorService | Pest tests with Http::fake() for Claude API mocking |
| TEST-03 | AI extraction tests mock Claude API via Http::fake() | Established pattern from existing test suite |
</phase_requirements>

## Standard Stack

### Core (Already in Project)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Laravel 12 | 12.x | Backend framework | Project stack |
| React 19 | 19.x | Frontend UI | Project stack |
| Inertia.js 2 | 2.x | SPA routing | Project stack |
| Tailwind CSS v4 | 4.x | Styling with sw-* tokens | Project stack |
| Anthropic Claude API | 2023-06-01 | Document classification + extraction | Existing AI provider |
| Pest PHP 3 | 3.x | Testing | Project test framework |

### Supporting (No New Dependencies)
| Library | Purpose | When to Use |
|---------|---------|-------------|
| `Http::` facade | Claude API calls | All AI service methods |
| `Http::fake()` | Test mocking | All extraction tests |
| lucide-react | Icons | Confidence badges, status indicators |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Claude vision for PDFs | pdftotext + text-based extraction | Claude vision handles scanned/image PDFs that pdftotext cannot; pdftotext not installed in dev env per MEMORY.md |
| Separate classify + extract API calls | Single combined API call | Two-pass is a locked decision -- allows skipping extraction for low-confidence classifications |

**Installation:** No new packages needed. All dependencies exist.

## Architecture Patterns

### Recommended Project Structure
```
app/
  Services/AI/
    TaxDocumentExtractorService.php  # NEW - classify + extract methods
  Jobs/
    ExtractTaxDocument.php           # NEW - queued pipeline orchestrator
  Enums/
    TaxDocumentCategory.php          # MODIFY - expand 8 -> 25 cases
    DocumentStatus.php               # EXISTS - no changes needed
  Http/Controllers/Api/
    TaxDocumentController.php        # MODIFY - add show detail, PATCH field, dispatch job on upload
  Http/Resources/
    TaxDocumentResource.php          # MODIFY - include extracted_data for detail view
config/
  spendifiai.php                     # MODIFY - add ai.extraction_thresholds section
resources/js/
  Pages/Vault/
    Show.tsx                         # NEW - Document Detail page (split-panel)
  Components/SpendifiAI/
    ExtractionPanel.tsx              # NEW - extracted fields list with confidence badges
    ConfidenceBadge.tsx              # NEW - green/amber/red confidence indicator
    InlineEditField.tsx              # NEW - click-to-edit field component
  types/
    spendifiai.d.ts                  # MODIFY - add ExtractedField, expand TaxDocumentCategory
tests/
  Feature/
    TaxDocumentExtractionTest.php    # NEW - extraction pipeline feature tests
  Unit/Services/
    TaxDocumentExtractorServiceTest.php  # NEW - unit tests for service
```

### Pattern 1: Two-Pass AI Pipeline (ExtractTaxDocument Job)
**What:** Queued job that runs classification first, gates extraction on confidence threshold
**When to use:** Every document upload triggers this job
**Example:**
```php
// Source: Matches CategorizePendingTransactions pattern
class ExtractTaxDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = [10, 30, 60]; // exponential

    public function __construct(protected int $documentId) {}

    public function handle(TaxDocumentExtractorService $extractor, TaxVaultAuditService $audit): void
    {
        $document = TaxDocument::findOrFail($this->documentId);

        // Pass 1: Classify
        $document->update(['status' => DocumentStatus::Classifying->value]);
        $classification = $extractor->classify($document);
        $document->update([
            'category' => $classification['category'],
            'classification_confidence' => $classification['confidence'],
        ]);

        // Gate: skip extraction if low confidence
        if ($classification['confidence'] < config('spendifiai.ai.extraction_thresholds.classification_gate', 0.70)) {
            $document->update(['status' => DocumentStatus::Failed->value]);
            return;
        }

        // Pass 2: Extract fields
        $document->update(['status' => DocumentStatus::Extracting->value]);
        $extraction = $extractor->extract($document);
        $document->update([
            'extracted_data' => $extraction,
            'status' => DocumentStatus::Ready->value,
        ]);

        $audit->log($document, $document->user, 'extraction_completed', null, [
            'category' => $classification['category'],
            'overall_confidence' => $extraction['overall_confidence'] ?? null,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $document = TaxDocument::find($this->documentId);
        $document?->update(['status' => DocumentStatus::Failed->value]);
    }
}
```

### Pattern 2: TaxDocumentExtractorService (AI Service)
**What:** Service that sends documents to Claude API for classification and extraction
**When to use:** Called by ExtractTaxDocument job
**Example:**
```php
// Source: Follows TransactionCategorizerService + BankStatementParserService patterns
class TaxDocumentExtractorService
{
    // Confidence thresholds (matches existing pattern)
    const CONFIDENCE_AUTO = 0.85;
    const CONFIDENCE_REVIEW = 0.60;
    const CLASSIFICATION_GATE = 0.70;

    // Tier 1 form field schemas
    const W2_FIELDS = [
        'employer_name', 'employer_ein', 'employee_name', 'employee_ssn_last4',
        'wages', 'federal_tax_withheld', 'social_security_wages', 'social_security_tax',
        'medicare_wages', 'medicare_tax', 'state', 'state_wages', 'state_tax_withheld',
    ];

    public function classify(TaxDocument $document): array
    {
        $content = $this->buildDocumentContent($document);
        $prompt = $this->buildClassificationPrompt();
        $response = $this->callClaude($prompt, $content);
        return [
            'category' => $response['category'] ?? 'other',
            'confidence' => (float)($response['confidence'] ?? 0),
        ];
    }

    public function extract(TaxDocument $document): array
    {
        $schema = $this->getFieldSchema($document->category);
        $content = $this->buildDocumentContent($document);
        $prompt = $this->buildExtractionPrompt($document->category, $schema);
        $response = $this->callClaude($prompt, $content);
        return $this->sanitizeExtraction($response, $document->category);
    }

    protected function buildDocumentContent(TaxDocument $document): array
    {
        $disk = Storage::disk($document->disk);
        $fileContents = $disk->get($document->stored_path);
        $base64 = base64_encode($fileContents);

        if ($document->mime_type === 'application/pdf') {
            return [['type' => 'document', 'source' => [
                'type' => 'base64', 'media_type' => 'application/pdf', 'data' => $base64,
            ]]];
        }

        // Image types (JPG, PNG)
        return [['type' => 'image', 'source' => [
            'type' => 'base64', 'media_type' => $document->mime_type, 'data' => $base64,
        ]]];
    }

    protected function sanitizeExtraction(array $data, TaxDocumentCategory $category): array
    {
        // Strip SSN to last 4 digits
        if (isset($data['fields']['employee_ssn']) || isset($data['fields']['ssn'])) {
            $ssnField = isset($data['fields']['employee_ssn']) ? 'employee_ssn' : 'ssn';
            $ssn = $data['fields'][$ssnField]['value'] ?? '';
            $data['fields']['ssn_last4'] = [
                'value' => substr(preg_replace('/\D/', '', $ssn), -4),
                'confidence' => $data['fields'][$ssnField]['confidence'] ?? 0,
            ];
            unset($data['fields'][$ssnField], $data['fields']['employee_ssn']);
        }
        return $data;
    }
}
```

### Pattern 3: Document Detail Split-Panel UI
**What:** Inertia page with PDF/image viewer left, extracted fields right
**When to use:** Document Detail page (UI-02)
**Example:**
```tsx
// Vault/Show.tsx - Split panel layout
<div className="flex gap-6 h-[calc(100vh-200px)]">
  {/* Left: Document viewer */}
  <div className="w-1/2 bg-sw-card rounded-lg overflow-hidden">
    {document.mime_type === 'application/pdf' ? (
      <iframe src={document.signed_url} className="w-full h-full" />
    ) : (
      <img src={document.signed_url} className="w-full h-full object-contain" />
    )}
  </div>
  {/* Right: Extracted fields */}
  <div className="w-1/2">
    <ExtractionPanel
      fields={document.extracted_data?.fields}
      overallConfidence={document.extracted_data?.overall_confidence}
      documentId={document.id}
      onFieldUpdate={handleFieldUpdate}
    />
  </div>
</div>
```

### Pattern 4: Inline Field Editing with PATCH
**What:** Click-to-edit individual fields, save via PATCH API
**When to use:** Field correction in ExtractionPanel
**Example:**
```tsx
// PATCH /api/v1/vault/documents/{id}/fields
const handleFieldUpdate = async (fieldName: string, newValue: string) => {
  await apiPost(`/api/v1/vault/documents/${documentId}/fields`, {
    _method: 'PATCH',
    field: fieldName,
    value: newValue,
  });
  // Field now has confidence: 1.0, verified: true
};
```

### Anti-Patterns to Avoid
- **Storing full SSN:** Never store full SSN -- strip to last 4 in service layer AND instruct Claude to only return last 4
- **Inline extraction on upload:** Always use queued job -- Claude API calls can take 30-60 seconds for PDFs
- **Single API call for classify+extract:** Two-pass pipeline is a locked decision -- allows short-circuiting for unrecognized documents
- **Manual encrypt/decrypt:** Use `encrypted:array` cast on `extracted_data` -- never call encrypt()/decrypt() directly

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| PDF rendering in browser | Custom PDF renderer | `<iframe src={signedUrl}>` for PDFs, `<img>` for images | Browser-native PDF viewer handles zoom, scroll, multi-page |
| JSON response parsing from Claude | Custom parser | Existing `parseJsonResponse()` pattern from BankStatementParserService | Handles markdown fences, control chars, JSON repair |
| Confidence badge UI | Custom color logic | Reusable ConfidenceBadge component with threshold-based colors | Consistency with future phases |
| Audit logging | Manual DB inserts | `TaxVaultAuditService::log()` | Hash chain integrity, IP/UA capture |
| File access | Direct Storage::get | `TaxVaultStorageService::getSignedUrl()` | Signed URLs for security (VAULT-06) |

**Key insight:** The project already has proven patterns for every building block -- Claude API calls, PDF processing, queued jobs, audit logging, encrypted storage. This phase composes existing patterns rather than inventing new ones.

## Common Pitfalls

### Pitfall 1: Full SSN Leakage
**What goes wrong:** Claude returns full SSN in extraction, gets stored in encrypted_data
**Why it happens:** AI may return full SSN despite prompt instructions
**How to avoid:** Defense-in-depth: (1) Prompt instructs "return only last 4 digits", (2) Service layer strips to last 4 with regex before storage, (3) Unit test verifies stripping
**Warning signs:** Any SSN field value longer than 4 digits in extracted_data

### Pitfall 2: Extraction Timeout on Large PDFs
**What goes wrong:** Job times out on multi-page tax documents
**Why it happens:** Claude API can take 30-60+ seconds for large PDFs (100-page limit, 32MB limit)
**How to avoid:** Set job timeout to 120s (not default 60s), set Http::timeout(90) for API call. Tax forms are typically 1-4 pages so this is generous.
**Warning signs:** Job failures with timeout exceptions

### Pitfall 3: Encrypted Data Schema Mismatch
**What goes wrong:** Frontend expects `fields.wage.value` but API returns different structure
**Why it happens:** Claude returns inconsistent JSON structure across calls
**How to avoid:** Validate and normalize Claude response in service layer before storing. Define explicit field schemas per form type.
**Warning signs:** TypeScript errors on extracted field access, undefined values in ExtractionPanel

### Pitfall 4: Stale Signed URLs in Split-Panel
**What goes wrong:** User opens document detail, walks away, comes back -- signed URL expired (15min default)
**Why it happens:** Signed URL generated once on page load
**How to avoid:** Include signed_url in API response, provide a refresh mechanism or generate longer expiry for detail view
**Warning signs:** Broken PDF viewer showing 403 error

### Pitfall 5: Race Condition on Status Updates
**What goes wrong:** User views document while extraction job is running, sees stale status
**Why it happens:** Inertia page loaded before job completes
**How to avoid:** Poll document status while status is 'classifying' or 'extracting' (simple setInterval on API endpoint), update UI when status transitions to 'ready' or 'failed'
**Warning signs:** Document stuck on "processing" in UI when actually ready

### Pitfall 6: TaxDocumentCategory Enum Expansion Breaks Frontend
**What goes wrong:** Adding 17 new enum cases breaks existing vault grid that hardcodes 8 categories
**Why it happens:** Vault/Index.tsx CATEGORY_DEFS array is hardcoded
**How to avoid:** Update CATEGORY_DEFS array and TaxDocumentCategory TypeScript type simultaneously. Consider fetching categories from API instead of hardcoding.
**Warning signs:** TypeScript errors on new category values, missing categories in vault grid

## Code Examples

### Claude Classification Prompt
```php
// Verified pattern from TransactionCategorizerService prompt structure
$classificationPrompt = <<<PROMPT
You are a tax document classifier. Analyze the uploaded document and determine its tax form type.

Return ONLY valid JSON with these fields:
{
  "category": "<form type from list below>",
  "confidence": <0.0-1.0>,
  "reasoning": "<brief explanation>"
}

FORM TYPES (use the exact string value):
- "w2" (W-2 Wage and Tax Statement)
- "1099_nec" (1099-NEC Nonemployee Compensation)
- "1099_int" (1099-INT Interest Income)
- "1098" (1098 Mortgage Interest Statement)
... [all 25 types]
- "other" (Unrecognized document)

CONFIDENCE SCORING:
- 0.90-1.00: Clear, standard form with visible title/form number
- 0.70-0.89: Likely this form type, minor ambiguity
- 0.40-0.69: Uncertain, could be multiple form types
- 0.00-0.39: Cannot determine form type

IMPORTANT: Return ONLY valid JSON. No markdown, no backticks.
PROMPT;
```

### Claude Extraction Prompt (W-2 Example)
```php
$extractionPrompt = <<<PROMPT
Extract all fields from this W-2 tax form. Return ONLY valid JSON.

For EACH field, provide:
- "value": the extracted text/number exactly as shown on the form
- "confidence": 0.0-1.0 how confident you are in the extraction

CRITICAL: For any SSN or Social Security Number, return ONLY THE LAST 4 DIGITS.
Never return a full SSN. If the SSN is 123-45-6789, return "6789".

Required fields:
{
  "fields": {
    "employer_name": { "value": "...", "confidence": 0.95 },
    "employer_ein": { "value": "XX-XXXXXXX", "confidence": 0.90 },
    "employee_name": { "value": "...", "confidence": 0.95 },
    "ssn_last4": { "value": "XXXX", "confidence": 0.85 },
    "wages": { "value": "50000.00", "confidence": 0.92 },
    "federal_tax_withheld": { "value": "8000.00", "confidence": 0.92 },
    "social_security_wages": { "value": "50000.00", "confidence": 0.90 },
    "social_security_tax": { "value": "3100.00", "confidence": 0.90 },
    "medicare_wages": { "value": "50000.00", "confidence": 0.90 },
    "medicare_tax": { "value": "725.00", "confidence": 0.90 },
    "state": { "value": "CA", "confidence": 0.95 },
    "state_wages": { "value": "50000.00", "confidence": 0.88 },
    "state_tax_withheld": { "value": "3000.00", "confidence": 0.88 }
  },
  "overall_confidence": 0.91
}

If a field is not visible or not applicable, set value to null and confidence to 0.
Return ONLY valid JSON. No markdown, no backticks.
PROMPT;
```

### Http::fake() Test Pattern for Extraction
```php
// Source: Established project pattern from existing test suite
Http::fake([
    'api.anthropic.com/v1/messages' => Http::sequence()
        ->push([
            'content' => [['text' => json_encode([
                'category' => 'w2',
                'confidence' => 0.95,
                'reasoning' => 'Standard W-2 form',
            ])]],
            'stop_reason' => 'end_turn',
        ], 200)
        ->push([
            'content' => [['text' => json_encode([
                'fields' => [
                    'employer_name' => ['value' => 'Acme Corp', 'confidence' => 0.95],
                    'wages' => ['value' => '50000.00', 'confidence' => 0.92],
                    'ssn_last4' => ['value' => '6789', 'confidence' => 0.85],
                ],
                'overall_confidence' => 0.91,
            ])]],
            'stop_reason' => 'end_turn',
        ], 200),
]);
```

### PATCH Field Correction Endpoint
```php
// In TaxDocumentController
public function updateField(Request $request, TaxDocument $document): JsonResponse
{
    $this->authorize('view', $document);

    $validated = $request->validate([
        'field' => 'required|string|max:100',
        'value' => 'required|string|max:1000',
    ]);

    $data = $document->extracted_data;
    $oldValue = $data['fields'][$validated['field']]['value'] ?? null;

    $data['fields'][$validated['field']] = [
        'value' => $validated['value'],
        'confidence' => 1.0,
        'verified' => true,
    ];
    $document->update(['extracted_data' => $data]);

    $this->auditService->log($document, $request->user(), 'field_corrected', $request, [
        'field' => $validated['field'],
        'old_value' => $oldValue,
        'new_value' => $validated['value'],
    ]);

    return response()->json(['success' => true]);
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| pdftotext + regex parsing | Claude vision/document API (base64) | 2024-2025 | Handles scanned PDFs, images, complex layouts |
| Single confidence per document | Per-field confidence scores | Current best practice | Enables targeted review, reduces review burden |
| Full OCR pipeline (Tesseract) | Claude multimodal API | 2024 | Eliminates OCR dependency, better accuracy |

**Note on pdftotext:** `poppler-utils` is not installed in dev env (per MEMORY.md). Claude's document API handles PDFs natively via base64, making pdftotext unnecessary. Recommend using Claude vision exclusively for both PDFs and images.

## Open Questions

1. **Multi-page document scrolling in iframe viewer**
   - What we know: Browser native PDF viewer handles multi-page via scroll
   - What's unclear: UX for very long documents alongside extracted fields
   - Recommendation: Use native iframe scrolling; no custom viewer needed for tax forms (1-4 pages typical)

2. **Tier 2+ form field schemas**
   - What we know: AIEX-04 requires extraction for remaining 21 form types
   - What's unclear: How detailed field schemas should be for less common forms
   - Recommendation: Use a generic schema for Tier 2+ (form_title, issuer_name, recipient_name, total_amount, tax_year) with category-specific fields added incrementally. The context says "simpler extraction schemas" for Tier 2+.

3. **Extraction cost per document**
   - What we know: Each document requires 2 Claude API calls (classify + extract)
   - What's unclear: Cost per document at scale
   - Recommendation: Acceptable for tax documents (users upload 5-20 per year). Not a concern at current scale.

## Sources

### Primary (HIGH confidence)
- `app/Services/AI/TransactionCategorizerService.php` -- Reference pattern for Claude API calls, confidence thresholds, prompt structure, JSON response parsing
- `app/Services/BankStatementParserService.php` -- Reference for PDF base64 document submission to Claude API, image handling
- `app/Models/TaxDocument.php` -- Existing model with encrypted:array cast, status/category fields
- `app/Enums/TaxDocumentCategory.php` -- Current 8-case enum to expand
- `app/Enums/DocumentStatus.php` -- Existing status lifecycle (upload/classifying/extracting/ready/failed)
- `app/Jobs/CategorizePendingTransactions.php` -- Reference pattern for queued AI jobs with retries
- `config/spendifiai.php` -- Existing AI config structure to extend

### Secondary (MEDIUM confidence)
- [Anthropic PDF Support Docs](https://platform.claude.com/docs/en/build-with-claude/pdf-support) -- PDF base64 API, 32MB limit, 100-page limit
- [Anthropic Vision Docs](https://platform.claude.com/docs/en/build-with-claude/vision) -- Image base64 API, supported formats

### Tertiary (LOW confidence)
- None -- all findings verified against existing codebase patterns

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH -- No new dependencies, all patterns exist in codebase
- Architecture: HIGH -- Direct composition of existing patterns (TransactionCategorizerService + BankStatementParserService + CategorizePendingTransactions job)
- Pitfalls: HIGH -- Identified from existing codebase patterns and domain knowledge
- Form field schemas: MEDIUM -- W-2 schema is well-defined, Tier 2+ schemas need iteration

**Research date:** 2026-03-30
**Valid until:** 2026-04-30 (stable -- no external dependency changes expected)
