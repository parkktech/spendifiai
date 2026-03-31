# Phase 9: Intelligence Layer & Final Validation - Research

**Researched:** 2026-03-31
**Domain:** AI intelligence analysis (transaction-document cross-referencing), comprehensive test coverage, build validation
**Confidence:** HIGH

## Summary

Phase 9 closes the v2.0 milestone with two distinct work streams: (1) a new TaxDocumentIntelligenceService that cross-references Plaid transaction data with uploaded tax documents to detect missing documents, flag anomalies, and link transactions to documents; and (2) comprehensive test coverage for all v2.0 API endpoints plus build validation (TypeScript + Pint).

The intelligence layer is entirely server-side logic that reuses existing data (Transaction model with plaid_category/plaid_detailed_category/merchant_name/amount and TaxDocument model with category/extracted_data). No new external API calls are needed. The existing IncomeDetectorService already classifies transactions into income types (employment, contractor, interest, transfer, other) with merchant normalization -- this is directly reusable for mapping transactions to expected document types.

The test/build validation work is a quality gate. Existing tests cover 9 extraction tests and 25 accountant tests. The gap is primarily: document upload/list/show/download/delete/purge endpoints, storage config admin endpoints, and the new intelligence endpoint itself.

**Primary recommendation:** Build TaxDocumentIntelligenceService following TaxDocumentExtractorService patterns (same namespace, same Http::fake() testability), reuse IncomeDetectorService's classification maps, and write the intelligence tests alongside the feature code.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- New TaxDocumentIntelligenceService in app/Services/AI/ namespace
- Cross-references Plaid transaction categories with expected tax form types (1099-NEC, 1099-INT, 1099-DIV, 1098, W-2)
- Detection runs on-demand when user views vault (not background job -- results cached)
- Missing document alerts include explanation text
- Alerts surfaced via existing MissingAlertBanner component (Phase 6)
- Uses existing transaction data from Plaid -- no new API calls
- Anomaly detection compares extracted document values against transaction totals
- Anomalies flagged with severity (info/warning/alert) and explanation
- Tolerance threshold configurable in config/spendifiai.php
- New pivot table/relationship linking transactions to tax documents
- Linking logic: match by merchant/employer name, tax year, and category
- Links visible in both directions (transaction->documents, document->transactions)
- Feature tests for ALL new v2.0 API endpoints across Phases 6-8
- Focus on endpoint coverage gaps -- don't duplicate existing tests
- npm run build must succeed with zero TypeScript errors
- vendor/bin/pint must report no formatting issues

### Claude's Discretion
- Exact matching algorithm for transaction-to-document linking
- Anomaly detection tolerance percentages
- Intelligence cache duration and invalidation strategy
- How to handle edge cases (partial year data, missing Plaid categories)
- Test organization and naming conventions
- Pint/TypeScript fix prioritization

### Deferred Ideas (OUT OF SCOPE)
None -- this is the final phase of v2.0
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| INTEL-01 | AI detects missing documents by cross-referencing Plaid transaction categories with expected tax form types | IncomeDetectorService already maps plaid_detailed_category to income types (employment, contractor, interest); TaxDocumentCategory enum provides all form types; new service aggregates income by type+merchant and checks for matching uploaded documents |
| INTEL-02 | Cross-document anomaly detection (e.g., W-2 wages vs bank deposit totals) | TaxDocument.extracted_data has typed field values (wages, nonemployee_compensation, interest_income, mortgage_interest); Transaction model has amount with income scope; compare sums with configurable tolerance |
| INTEL-03 | Transaction-to-document linking (1099 linked to freelance deposits) | New pivot table tax_document_transaction with linking logic matching merchant_name/employer_name + tax_year + income type to document category |
| INTEL-04 | Missing document alerts shown to user with explanation | MissingAlertBanner component already exists at line 135 of Vault/Index.tsx with empty alerts=[]; intelligence API endpoint populates this |
| TEST-01 | Feature tests for all new API endpoints | Gap analysis shows missing tests for: document upload/list/show/download/delete, admin purge, storage config CRUD, intelligence endpoint |
| TEST-05 | npm run build succeeds with zero TypeScript errors | Run npm run build, fix any errors found |
| TEST-06 | vendor/bin/pint reports no formatting issues | Run vendor/bin/pint --dirty --format agent, fix any issues |
</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Laravel 12 | 12.x | Backend framework | Project standard |
| Pest PHP 3 | 3.x | Testing framework | Project standard, existing 142+ tests |
| Laravel Cache | built-in | Intelligence result caching | On-demand caching, no new dependencies |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| Http::fake() | built-in | Mock Claude API in tests | All AI service tests |
| Carbon | built-in | Date manipulation for tax year filtering | Transaction date ranges |
| Collection | built-in | Aggregate transaction data | Sum/group/filter operations |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Cache facade | Redis directly | Cache facade is project standard, abstracts driver |
| Separate intelligence job | On-demand controller call | User decision: on-demand with caching, not background job |

## Architecture Patterns

### Recommended Project Structure
```
app/Services/AI/
  TaxDocumentIntelligenceService.php    # NEW: intelligence analysis
  TaxDocumentExtractorService.php       # EXISTING: pattern to follow

app/Http/Controllers/Api/
  TaxDocumentController.php             # ADD: intelligence endpoint

database/migrations/
  2026_03_31_200001_create_tax_document_transaction_table.php  # NEW: pivot table

config/
  spendifiai.php                        # ADD: intelligence config section

tests/Feature/
  TaxDocumentIntelligenceTest.php       # NEW: intelligence tests
  TaxVaultEndpointTest.php              # NEW: vault endpoint coverage
  AdminStorageConfigTest.php            # NEW: admin storage tests
```

### Pattern 1: Intelligence Service (follows TaxDocumentExtractorService)
**What:** Service class in app/Services/AI/ that analyzes transaction data against documents
**When to use:** On-demand when user requests intelligence data for a tax year
**Example:**
```php
// Pattern from existing TaxDocumentExtractorService
class TaxDocumentIntelligenceService
{
    public function analyze(int $userId, int $taxYear): array
    {
        $cacheKey = "tax_intelligence_{$userId}_{$taxYear}";
        return Cache::remember($cacheKey, now()->addHours(4), function () use ($userId, $taxYear) {
            return [
                'missing_documents' => $this->detectMissingDocuments($userId, $taxYear),
                'anomalies' => $this->detectAnomalies($userId, $taxYear),
                'transaction_links' => $this->linkTransactions($userId, $taxYear),
            ];
        });
    }
}
```

### Pattern 2: Transaction-to-Document Income Type Mapping
**What:** Maps transaction income types to expected document categories
**When to use:** Core of missing document detection
**Example:**
```php
// Reuse IncomeDetectorService's classification logic
protected array $incomeTypeToDocumentCategory = [
    'employment' => TaxDocumentCategory::W2,           // W-2 expected
    'contractor' => TaxDocumentCategory::NEC_1099,     // 1099-NEC expected
    'interest'   => TaxDocumentCategory::INT_1099,     // 1099-INT expected
    // Dividends need special handling -- plaid_detailed_category INCOME_DIVIDENDS
    'dividend'   => TaxDocumentCategory::DIV_1099,     // 1099-DIV expected
];

// Mortgage detection: look for LOAN_PAYMENTS or plaid_category containing MORTGAGE
protected array $expenseTypeToDocumentCategory = [
    'mortgage' => TaxDocumentCategory::Mortgage_1098,  // 1098 expected
];
```

### Pattern 3: Pivot Table with BelongsToMany
**What:** Simple pivot table for transaction-document links
**When to use:** Transaction-to-document linking
**Example:**
```php
// Migration
Schema::create('tax_document_transaction', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tax_document_id')->constrained()->cascadeOnDelete();
    $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
    $table->string('link_reason', 100)->nullable(); // e.g., "employer_match", "income_type_match"
    $table->timestamps();
    $table->unique(['tax_document_id', 'transaction_id']);
});

// On TaxDocument model
public function transactions(): BelongsToMany
{
    return $this->belongsToMany(Transaction::class, 'tax_document_transaction')
        ->withPivot('link_reason')
        ->withTimestamps();
}

// On Transaction model
public function taxDocuments(): BelongsToMany
{
    return $this->belongsToMany(TaxDocument::class, 'tax_document_transaction')
        ->withPivot('link_reason')
        ->withTimestamps();
}
```

### Anti-Patterns to Avoid
- **Calling Claude API for intelligence:** This is pure data analysis -- no AI API calls needed. Use existing transaction/document data.
- **Running intelligence as a background job:** User decision is on-demand with caching. Don't queue it.
- **Duplicating IncomeDetectorService logic:** Reuse its classification maps, don't rebuild them.
- **Overcomplicating matching:** Simple merchant name + income type matching is sufficient. Fuzzy matching is unnecessary for v2.0.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Income classification | Custom transaction classifier | IncomeDetectorService maps (plaidTypeMap, aiTypeMap) | Already maps Plaid categories to income types |
| Cache management | Custom file-based cache | Laravel Cache facade (Cache::remember) | Built-in TTL, invalidation, Redis backend |
| Merchant normalization | Custom string matching | IncomeDetectorService::normalizeMerchant() | Already handles payroll prefixes, app names |
| Decimal comparisons | Raw float comparison | Round to 2 decimals, use tolerance % | Float precision issues with money |

**Key insight:** The IncomeDetectorService already does 80% of the work needed for missing document detection. It classifies transactions into employment/contractor/interest/transfer/other types and normalizes merchant names. The intelligence service just needs to aggregate by type+merchant and check if matching documents exist.

## Common Pitfalls

### Pitfall 1: Decimal Comparison for Anomaly Detection
**What goes wrong:** Comparing transaction sum (float) to extracted document value (string from AI) fails due to type mismatches and precision
**Why it happens:** extracted_data fields store values as strings ("52000.00"), transaction amounts use decimal:2 cast (serializes as string in JSON)
**How to avoid:** Cast both to float, use Number() on frontend. Use tolerance percentage (e.g., 5%) not exact match.
**Warning signs:** Tests passing with round numbers but failing with real-world decimals

### Pitfall 2: Transaction Amount Sign Convention
**What goes wrong:** Income transactions have NEGATIVE amounts in this system (money flowing IN)
**Why it happens:** Transaction model uses Plaid convention where amount > 0 = spending, amount < 0 = income
**How to avoid:** Use `->where('amount', '<', 0)` for income, `abs()` when summing. The scopeIncome() scope already handles this.
**Warning signs:** Intelligence reporting $0 in income when transactions exist

### Pitfall 3: Missing Plaid Categories
**What goes wrong:** Manual statement upload transactions may have null plaid_category/plaid_detailed_category
**Why it happens:** BankConnection/BankAccount plaid columns are nullable to support manual uploads
**How to avoid:** Fall back to ai_category/user_category (same pattern as IncomeDetectorService). Use COALESCE in queries.
**Warning signs:** Intelligence missing income from manually uploaded statements

### Pitfall 4: Cache Invalidation on Document Upload
**What goes wrong:** User uploads a document but intelligence still shows it as "missing"
**Why it happens:** Intelligence results are cached but not invalidated on new document upload
**How to avoid:** Invalidate cache key `tax_intelligence_{userId}_{taxYear}` in TaxDocumentController::store() after upload
**Warning signs:** User confusion when freshly uploaded document still shows as missing

### Pitfall 5: Employer/Merchant Name Mismatch
**What goes wrong:** W-2 says "Acme Corporation" but transactions say "ACME CORP PAYROLL"
**Why it happens:** Plaid merchant names include suffixes like PAYROLL, DIRECT DEP, etc.
**How to avoid:** Normalize both sides: strip common suffixes from transaction merchants, use case-insensitive contains/similarity matching. IncomeDetectorService::normalizeMerchant() already strips these.
**Warning signs:** Intelligence linking 0 transactions to documents despite clear matches

### Pitfall 6: Partial Year Data
**What goes wrong:** January income shows "$5,000 freelance" but user only connected Plaid in March, so actual is $20,000
**Why it happens:** Transaction history only goes back to Plaid connection date
**How to avoid:** Include caveat in alerts: "Based on transactions since [earliest_date]". Don't assert missing documents for income types where data coverage is clearly partial.
**Warning signs:** Anomaly detection flagging every document as mismatched

## Code Examples

### Intelligence API Endpoint Pattern
```php
// In TaxDocumentController or new dedicated controller
// Route: GET /api/v1/tax-vault/intelligence?year={year}
public function intelligence(Request $request): JsonResponse
{
    $year = (int) $request->query('year', now()->year);
    $userId = $request->user()->id;

    $service = app(TaxDocumentIntelligenceService::class);
    $result = $service->analyze($userId, $year);

    return response()->json($result);
}
```

### Missing Document Detection Core Logic
```php
protected function detectMissingDocuments(int $userId, int $taxYear): array
{
    // Get income transactions for the tax year
    $incomeByType = Transaction::where('user_id', $userId)
        ->where('amount', '<', 0) // income = negative amounts
        ->whereYear('transaction_date', $taxYear)
        ->get()
        ->groupBy(fn ($tx) => $this->classifyIncomeType($tx));

    // Get uploaded documents for the year
    $documents = TaxDocument::forUser($userId)
        ->byYear($taxYear)
        ->whereNotNull('category')
        ->get()
        ->groupBy(fn ($doc) => $doc->category->value);

    $missing = [];

    // Check employment income -> W-2
    if ($incomeByType->has('employment')) {
        $employers = $this->groupByMerchant($incomeByType['employment']);
        foreach ($employers as $employer => $total) {
            if (!$documents->has('w2') || !$this->hasMatchingDocument($documents['w2'], $employer)) {
                $missing[] = [
                    'message' => "Missing W-2 from {$employer}",
                    'details' => "Based on " . number_format(abs($total), 2) . " in employment income from {$employer}, we expect a W-2.",
                    'category' => 'w2',
                    'severity' => 'warning',
                ];
            }
        }
    }

    // Similar for contractor->1099-NEC, interest->1099-INT, etc.
    return $missing;
}
```

### Anomaly Detection Pattern
```php
protected function detectAnomalies(int $userId, int $taxYear): array
{
    $tolerance = config('spendifiai.intelligence.anomaly_tolerance', 0.05); // 5%
    $anomalies = [];

    $documents = TaxDocument::forUser($userId)
        ->byYear($taxYear)
        ->where('status', DocumentStatus::Ready->value)
        ->whereNotNull('extracted_data')
        ->get();

    foreach ($documents as $doc) {
        $fields = $doc->extracted_data['fields'] ?? [];

        if ($doc->category === TaxDocumentCategory::W2 && isset($fields['wages'])) {
            $docWages = (float) ($fields['wages']['value'] ?? 0);
            $employerName = $fields['employer_name']['value'] ?? null;

            if ($employerName && $docWages > 0) {
                $depositTotal = abs((float) Transaction::where('user_id', $userId)
                    ->where('amount', '<', 0)
                    ->whereYear('transaction_date', $taxYear)
                    ->where('merchant_name', 'ILIKE', "%{$employerName}%")
                    ->sum('amount'));

                if ($depositTotal > 0) {
                    $variance = abs($docWages - $depositTotal) / $docWages;
                    if ($variance > $tolerance) {
                        $anomalies[] = [
                            'message' => "W-2 wages vs deposits mismatch for {$employerName}",
                            'details' => "Your W-2 shows $" . number_format($docWages, 2) . " in wages, but we see $" . number_format($depositTotal, 2) . " in deposits from this employer.",
                            'severity' => $variance > 0.20 ? 'alert' : 'warning',
                            'document_id' => $doc->id,
                        ];
                    }
                }
            }
        }
        // Similar for 1099-NEC, 1098, etc.
    }

    return $anomalies;
}
```

### Frontend Intelligence Integration
```tsx
// In Vault/Index.tsx -- replace empty alerts with intelligence data
const { data: intelligenceData } = useApi<IntelligenceResult>(
    `/api/v1/tax-vault/intelligence?year=${selectedYear}`,
);

const missingAlerts = (intelligenceData?.missing_documents ?? []).map(m => ({
    message: m.message,
    details: m.details,
}));

const anomalyAlerts = (intelligenceData?.anomalies ?? []).map(a => ({
    message: a.message,
    details: a.details,
}));

// Feed into MissingAlertBanner
<MissingAlertBanner alerts={[...missingAlerts, ...anomalyAlerts]} />
```

### Test Pattern (Http::fake not needed -- no AI calls)
```php
// Intelligence tests use real DB data, no HTTP mocking needed
it('detects missing W-2 when employment income exists without document', function () {
    $user = User::factory()->create();
    Transaction::factory()->create([
        'user_id' => $user->id,
        'amount' => -5000.00, // income
        'merchant_name' => 'ACME CORP PAYROLL',
        'plaid_detailed_category' => 'INCOME_WAGES',
        'transaction_date' => '2025-06-15',
    ]);
    // No W-2 document uploaded

    $response = $this->actingAs($user)
        ->getJson('/api/v1/tax-vault/intelligence?year=2025');

    $response->assertOk()
        ->assertJsonPath('missing_documents.0.category', 'w2');
});
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Manual document checklist | AI-powered detection from transaction patterns | v2.0 Phase 9 | Users get proactive alerts instead of guessing |
| Separate document and transaction views | Linked transactions and documents | v2.0 Phase 9 | Users can trace income to tax forms |

## Open Questions

1. **Dividend vs Interest income distinction**
   - What we know: IncomeDetectorService maps INCOME_DIVIDENDS to 'interest' type (same as INCOME_INTEREST_EARNED)
   - What's unclear: Should intelligence distinguish dividends (1099-DIV) from interest (1099-INT)?
   - Recommendation: Yes -- add 'dividend' as a separate type in the intelligence service's income classification. Check plaid_detailed_category specifically for INCOME_DIVIDENDS.

2. **Mortgage payment detection**
   - What we know: IncomeDetectorService only classifies income types, not expense types
   - What's unclear: How to detect mortgage payments from transaction data
   - Recommendation: Look for transactions with plaid_category containing 'LOAN_PAYMENTS' or ai_category/user_category of 'Mortgage' or merchant names containing 'MORTGAGE', 'HOME LOAN'. This is an expense (amount > 0).

3. **Transaction factory availability**
   - What we know: Phase 7 tests used direct TaxDocument::create() with helper because no factory existed
   - What's unclear: Whether Transaction factory is available or needs helper
   - Recommendation: Transaction::factory() likely exists (model has HasFactory trait). Verify during implementation; if not, create one.

4. **Recommended anomaly tolerance percentage**
   - What we know: Config-driven threshold in spendifiai.php
   - Recommendation: 5% for default tolerance. Note that net pay != gross wages (taxes withheld), so W-2 wages will ALWAYS be higher than deposit totals. The anomaly should compare document amount vs transaction total and flag when deposits are significantly HIGHER than document amount (possible unreported income) or when no deposits match at all.

## Test Coverage Gap Analysis

### Existing Tests (do NOT duplicate)
| File | Count | Covers |
|------|-------|--------|
| TaxDocumentExtractionTest | 9 | Extraction pipeline, field correction, accept-all, retry |
| AccountantFirmTest | 10 | Firm CRUD, invite link, dashboard |
| AccountantAuthorizationTest | 15 | Cross-role document/annotation/request access |

### Missing Tests (Phase 9 must add)
| Endpoint | Method | Route | Priority |
|----------|--------|-------|----------|
| Document list | GET | /api/v1/tax-vault/documents | HIGH |
| Document upload | POST | /api/v1/tax-vault/documents | HIGH |
| Document show | GET | /api/v1/tax-vault/documents/{id} | HIGH |
| Document download | GET | /api/v1/tax-vault/documents/{id}/download | HIGH |
| Document delete | DELETE | /api/v1/tax-vault/documents/{id} | HIGH |
| Admin purge | DELETE | /admin/documents/{id}/purge | MEDIUM |
| Storage config show | GET | /admin/storage | MEDIUM |
| Storage config update | PUT | /admin/storage | MEDIUM |
| Storage test connection | POST | /admin/storage/test | MEDIUM |
| Intelligence endpoint | GET | /api/v1/tax-vault/intelligence | HIGH |
| Audit log list | GET | /api/v1/tax-vault/documents/{id}/audit-log | MEDIUM |
| Audit chain verify | GET | /api/v1/tax-vault/documents/{id}/audit-log/verify | MEDIUM |

## Intelligence Config Recommendations

```php
// Add to config/spendifiai.php
'intelligence' => [
    'cache_hours' => 4,
    'anomaly_tolerance' => 0.05,    // 5% variance threshold
    'min_income_threshold' => 600,  // IRS 1099 reporting threshold
    'income_type_to_document' => [
        'employment' => 'w2',
        'contractor' => '1099_nec',
        'interest' => '1099_int',
        'dividend' => '1099_div',
    ],
],
```

The `min_income_threshold` of $600 matches the IRS 1099-NEC/MISC reporting minimum. Don't flag missing 1099s for income below this threshold.

## Sources

### Primary (HIGH confidence)
- Existing codebase: TaxDocumentExtractorService, IncomeDetectorService, Transaction model, TaxDocument model
- Existing codebase: TaxDocumentController routes (api.php lines 260-275)
- Existing codebase: MissingAlertBanner component (already has alerts interface)
- Existing codebase: Vault/Index.tsx line 135 (placeholder for intelligence data)

### Secondary (MEDIUM confidence)
- IRS $600 reporting threshold for 1099-NEC/MISC -- well-established tax law
- Plaid transaction category naming conventions from IncomeDetectorService maps

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - all components exist in project, no new dependencies
- Architecture: HIGH - follows established service patterns, reuses existing classification logic
- Pitfalls: HIGH - based on direct codebase analysis (amount sign convention, nullable Plaid fields, etc.)
- Test gaps: HIGH - based on route enumeration vs existing test file analysis

**Research date:** 2026-03-31
**Valid until:** 2026-04-30 (stable -- no external dependency changes expected)
