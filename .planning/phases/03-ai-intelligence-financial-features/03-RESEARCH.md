# Phase 3: AI Intelligence & Financial Features - Research

**Researched:** 2026-02-10
**Domain:** Claude API integration, AI-powered financial analysis, email parsing, tax export
**Confidence:** HIGH

## Summary

Phase 3 is primarily an integration and wiring phase. The vast majority of service logic, controllers, models, routes, form requests, and API resources already exist and are complete. The core work is: (1) fixing mismatches between service code and model schemas, (2) integrating the email parsing module from `existing-code/`, (3) wiring up scheduled tasks and jobs, (4) ensuring the Claude API calls work correctly with proper error handling and rate limiting, and (5) installing Python dependencies for tax export.

The existing services (`TransactionCategorizerService`, `SavingsAnalyzerService`, `SavingsTargetPlannerService`, `SubscriptionDetectorService`, `TaxExportService`) are fully written with complete business logic. The controllers (`AIQuestionController`, `TransactionController`, `SubscriptionController`, `SavingsController`, `TaxController`) are also complete. The `EmailConnectionController` has 501 stubs that need to be replaced with real implementations. The email parsing services (`GmailService`, `EmailParserService`, `ReconciliationService`, `ProcessOrderEmails` job) exist in `existing-code/spendifiai-existing/expense-parser-module/` but need to be copied into the main app and adapted (fixing namespace issues, model name mismatches, column name mismatches).

**Primary recommendation:** Focus on integration, bug-fixing, and gap-filling rather than writing new logic. The services are done -- wire them together, fix mismatches, install dependencies, and verify the pipeline works end-to-end.

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Anthropic Messages API | v2023-06-01 | AI categorization, savings analysis, email parsing | Direct HTTP calls via Laravel Http facade; already implemented |
| Claude Sonnet 4 | claude-sonnet-4-20250514 | AI model for all categorization/analysis | Configured in `config/services.php` and `config/spendwise.php` |
| google/apiclient | ^2.x | Gmail API OAuth + email fetching | Already in composer.json; standard Google PHP client |
| openpyxl (Python) | latest | Excel tax workbook generation | Called via shell from `TaxExportService` |
| reportlab (Python) | latest | PDF tax summary generation | Called via shell from `TaxExportService` |
| Laravel Queue (Redis) | built-in | Background job processing | predis client already configured |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| Laravel Http facade | built-in | HTTP calls to Anthropic API | All Claude API interactions |
| Carbon | built-in | Date manipulation | Subscription detection, spending analysis |
| Laravel Storage | built-in | Tax export file management | Storing/downloading tax packages |
| Laravel Mail | built-in | Tax package email delivery | `TaxPackageMail` already exists |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Direct HTTP to Anthropic | anthropic-php SDK | SDK would add type safety but all service code already uses Http facade; switching would require rewriting 4 services |
| openpyxl via shell | PhpSpreadsheet | Python scripts already written and produce professional output; no reason to rewrite |
| Structured Outputs (`output_config`) | Prompt-based JSON | Structured Outputs guarantee valid JSON schema compliance, eliminating JSON parsing errors. Consider upgrading the services to use `output_config.format` with `json_schema` type instead of relying on prompt instructions + regex cleanup |

**Installation:**
```bash
# Python dependencies (for tax export)
pip3 install openpyxl reportlab

# PHP dependencies already installed:
# google/apiclient, predis/predis are in composer.json
```

## Architecture Patterns

### Existing Project Structure (Phase 3 additions)
```
app/
├── Services/
│   ├── AI/
│   │   ├── TransactionCategorizerService.php    # EXISTS - complete
│   │   ├── SavingsAnalyzerService.php           # EXISTS - complete
│   │   ├── SavingsTargetPlannerService.php      # EXISTS - complete
│   │   └── EmailParserService.php               # COPY from expense-parser-module
│   ├── Email/
│   │   └── GmailService.php                     # COPY from expense-parser-module
│   ├── ReconciliationService.php                # COPY from expense-parser-module (fix model refs)
│   ├── SubscriptionDetectorService.php          # EXISTS - complete
│   └── TaxExportService.php                     # EXISTS - complete
├── Http/Controllers/Api/
│   ├── AIQuestionController.php                 # EXISTS - complete
│   ├── TransactionController.php                # EXISTS - complete
│   ├── SubscriptionController.php               # EXISTS - complete
│   ├── SavingsController.php                    # EXISTS - complete
│   ├── TaxController.php                        # EXISTS - complete
│   └── EmailConnectionController.php            # EXISTS - 501 stubs, needs real implementation
├── Jobs/
│   ├── CategorizePendingTransactions.php         # EXISTS - complete
│   └── ProcessOrderEmails.php                   # COPY from expense-parser-module (fix issues)
├── Http/Resources/                              # ALL EXIST - complete
├── Http/Requests/                               # ALL EXIST - complete
├── Models/                                      # ALL 18 EXIST - complete
└── Enums/                                       # ALL 7 EXIST - complete
```

### Pattern 1: Service Layer with Queue Dispatch
**What:** Controllers call services synchronously for simple operations, dispatch jobs for heavy AI operations
**When to use:** AI categorization (batched, async), email sync (long-running), subscription detection (can be slow)
**Example:**
```php
// Source: existing app/Jobs/CategorizePendingTransactions.php
// After transaction sync, dispatch categorization job
CategorizePendingTransactions::dispatch($userId);

// Job handles batching (25 transactions per Claude API call), rate limiting (500ms between batches)
$pending->chunk(25)->each(function ($batch) use ($categorizer) {
    $result = $categorizer->categorizeBatch($batch, $this->userId);
    usleep(500000); // Rate limit
});
```

### Pattern 2: Confidence-Based Routing
**What:** AI categorization results route through different paths based on confidence score
**When to use:** All transaction categorization
**Example:**
```php
// Source: existing TransactionCategorizerService.php
// >= 0.85: auto-categorize (review_status = 'auto_categorized')
// 0.60-0.84: categorize + flag (review_status = 'needs_review')
// 0.40-0.59: generate multiple-choice AIQuestion
// < 0.40: generate open-ended AIQuestion
```

### Pattern 3: Claude API Direct HTTP Pattern
**What:** All services call Claude via Laravel's Http facade with retry logic
**When to use:** Every AI interaction
**Example:**
```php
// Source: existing TransactionCategorizerService.php
$response = Http::withHeaders([
    'x-api-key'         => $this->apiKey,
    'anthropic-version'  => '2023-06-01',
    'content-type'       => 'application/json',
])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
    'model'      => $this->model,
    'max_tokens' => 4000,
    'system'     => $systemPrompt,
    'messages'   => [['role' => 'user', 'content' => $userData]],
]);
// Parse JSON from content.0.text, strip markdown fencing
```

### Anti-Patterns to Avoid
- **Calling Claude API in controllers directly:** All AI calls go through services, dispatched as jobs for heavy workloads
- **Manual encrypt()/decrypt():** Use model casts (`'encrypted'`, `'encrypted:array'`) only -- see `EmailConnection.access_token`, `ParsedEmail.raw_parsed_data`
- **Inline validation in controllers:** All validation through Form Request classes (already built)
- **Hard-coding confidence thresholds:** Use `config('spendwise.ai.confidence_thresholds')` values
- **Re-implementing what exists:** Most logic is written; focus on wiring, not rewriting

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| JSON parsing from Claude | Custom parser | Use `output_config.format` with `json_schema` type OR keep existing regex cleanup | Structured Outputs guarantees valid JSON; existing code handles edge cases acceptably |
| Excel generation | PHP spreadsheet library | Existing Python `generate_tax_excel.py` via shell | Already written with professional formatting, 5 tabs |
| PDF generation | PHP PDF library | Existing Python `generate_tax_pdf.py` via shell | Already written with reportlab, professional cover sheet |
| Gmail OAuth flow | Custom OAuth implementation | `google/apiclient` with `Google\Client` | GmailService already implements this fully |
| Subscription recurrence detection | Simple regex | Existing `SubscriptionDetectorService.analyzeRecurrence()` | Handles weekly/monthly/quarterly/annual with 20% tolerance |
| Transaction reconciliation | Exact matching | Existing `ReconciliationService.calculateMatchScore()` | Weighted scoring: amount (50%), date proximity (30%), merchant similarity (20%) |

**Key insight:** This phase is about integration, not invention. All complex algorithms are already implemented in the service layer.

## Common Pitfalls

### Pitfall 1: Model/Service Column Name Mismatches
**What goes wrong:** Services write to column names that don't match the actual model `$fillable` or database schema
**Why it happens:** The services were written before models were finalized. Several mismatches exist.
**How to avoid:** Cross-reference each service's `create()` / `update()` calls against the model's `$fillable` array and the migration schema
**Specific mismatches found:**
- `AIQuestion` model has `$fillable` with `confidence` but `TransactionCategorizerService` writes `ai_confidence`
- `AIQuestion` model has `$fillable` with `options` but `TransactionCategorizerService` writes `options` (match OK)
- `SavingsPlanAction` model `$fillable` is INCOMPLETE: missing `user_id`, `how_to`, `impact`, `priority`, `is_essential_cut`, `related_merchants`, `related_subscription_ids`, `accepted_at`, `rejected_at`, `rejection_reason`. The service and controller write all these fields but the model won't save them.
- `SavingsRecommendation` model `$fillable` includes `action_steps` and `related_merchants` but migration schema has `related_transaction_ids` and `related_subscription_ids` (not `action_steps` or `related_merchants`). The `SavingsAnalyzerService.storeRecommendations()` writes `generated_at` but doesn't write `action_steps` or `related_merchants`.
- `ReconciliationService` references `BankTransaction` model (doesn't exist) -- should be `Transaction`
- `ReconciliationService` references `matched_bank_transaction_id` on Order -- schema column is `matched_transaction_id`
- `ReconciliationService` references `$transaction->plaid_transaction_id` for order matching -- should use `$transaction->id`
- `ParsedEmail` model has `email_message_id` but `ProcessOrderEmails` job writes `gmail_message_id` -- column is `email_message_id`
- `ParsedEmail` model has `email_thread_id` but job writes `gmail_thread_id` -- column is `email_thread_id`
- `EmailConnection` schema has `sync_status` column but model doesn't have `status` cast matching this (model has `status` with `ConnectionStatus` enum, but schema column is `sync_status` not `status`)
- `ProcessOrderEmails` job references `$connection->sync_status` but model/schema may not have this -- schema has `sync_status` column in email_connections table but model lists `status` in fillable, not `sync_status`

### Pitfall 2: Missing `$fillable` Fields on Models
**What goes wrong:** `MassAssignmentException` when services try to `create()` or `update()` with fields not in `$fillable`
**Why it happens:** Models were built lean; services write more fields than models permit
**How to avoid:** Audit every model's `$fillable` against what services/controllers actually write
**Key models to fix:** `SavingsPlanAction` (most incomplete), `SavingsRecommendation`, `AIQuestion`

### Pitfall 3: Claude API JSON Parsing Failures
**What goes wrong:** Claude returns markdown-fenced JSON or slightly malformed output, causing `json_decode` to fail
**Why it happens:** Despite "Respond ONLY with a JSON array. No markdown" instructions, Claude sometimes wraps in backticks
**How to avoid:** The existing code already strips markdown fencing with `preg_replace`. Consider upgrading to Anthropic's Structured Outputs (`output_config.format` with `json_schema`) for guaranteed schema compliance.
**Warning signs:** `json_last_error_msg()` returns "Syntax error" in logs

### Pitfall 4: Python Script Dependencies Missing
**What goes wrong:** `TaxExportService` calls Python scripts that fail because `openpyxl` or `reportlab` aren't installed
**Why it happens:** Python packages are not managed by Composer
**How to avoid:** Install `pip3 install openpyxl reportlab` as part of Phase 3 setup
**Warning signs:** "Excel generation failed" or "PDF generation failed" exceptions

### Pitfall 5: Gmail API Credential Scope
**What goes wrong:** Gmail OAuth fails or returns 403 because insufficient scopes
**Why it happens:** The `GmailService` requests `Gmail::GMAIL_READONLY` scope. The Google Cloud project must have the Gmail API enabled and the OAuth consent screen configured with this scope.
**How to avoid:** Verify Google Cloud project has Gmail API enabled; ensure OAuth consent screen includes `gmail.readonly` scope; handle token refresh properly
**Warning signs:** 403 "Insufficient Permission" errors after OAuth

### Pitfall 6: Rate Limiting on Claude API
**What goes wrong:** 429 errors when categorizing large batches of transactions
**Why it happens:** Anthropic rate limits (RPM/ITPM) exceeded when processing many transactions
**How to avoid:** Existing code already has 500ms delay between batches and max 25 transactions per batch. Consider reading rate-limit headers (`anthropic-ratelimit-requests-remaining`) and implementing dynamic backoff.
**Warning signs:** "API error: 429" in categorization logs

### Pitfall 7: Email Connection Model Encryption Mismatch
**What goes wrong:** GmailService uses `encrypt()` / `decrypt()` manually but EmailConnection model uses `'encrypted'` cast
**Why it happens:** GmailService was written as standalone module with manual encryption; model uses Laravel casts
**How to avoid:** When copying GmailService into main app, remove manual `encrypt()` / `decrypt()` calls. Let the model cast handle it. Write `$connection->access_token = $token['access_token']` (not `encrypt($token['access_token'])`)
**Warning signs:** Double-encrypted values (encrypted ciphertext gets encrypted again)

### Pitfall 8: Missing `savings_recommendations` Columns
**What goes wrong:** `SavingsAnalyzerService` tries to write `action_steps` and `related_merchants` columns that may not exist in the migration
**Why it happens:** Model has these in `$fillable` with `array` casts, but the migration's `savings_recommendations` table doesn't have `action_steps` or `related_merchants` columns -- it has `related_transaction_ids` and `related_subscription_ids`
**How to avoid:** Add migration to add `action_steps` (json/text) and `related_merchants` (json/text) columns to `savings_recommendations` table, OR modify the service to not write these fields

## Code Examples

### Wiring EmailConnectionController (Replace 501 Stubs)
```php
// Source: existing-code/expense-parser-module GmailService pattern
// EmailConnectionController.php - connect method
public function connect(Request $request, string $provider): JsonResponse
{
    if ($provider !== 'gmail') {
        return response()->json(['error' => 'Unsupported provider'], 400);
    }
    $gmailService = app(GmailService::class);
    $authUrl = $gmailService->getAuthUrl();
    return response()->json(['auth_url' => $authUrl]);
}

// callback method
public function callback(Request $request, string $provider): JsonResponse
{
    $gmailService = app(GmailService::class);
    $connection = $gmailService->handleCallback(auth()->id(), $request->code);
    return response()->json(['message' => 'Gmail connected', 'email' => $connection->email_address]);
}

// sync method
public function sync(): JsonResponse
{
    $connection = EmailConnection::where('user_id', auth()->id())->firstOrFail();
    ProcessOrderEmails::dispatch($connection);
    return response()->json(['message' => 'Email sync started']);
}
```

### Fixing ReconciliationService Model References
```php
// BEFORE (existing-code version - broken references):
use App\Models\BankTransaction; // WRONG - model is Transaction
$transaction->plaid_transaction_id; // WRONG for order matching

// AFTER (corrected):
use App\Models\Transaction;
// In applyMatch():
$transaction->update([
    'matched_order_id' => $order->id,
    'is_reconciled' => true,
]);
$order->update([
    'matched_transaction_id' => $transaction->id,
    'is_reconciled' => true,
]);
```

### Anthropic Structured Outputs (Optional Upgrade)
```php
// Source: https://platform.claude.com/docs/en/build-with-claude/structured-outputs
// Upgrade callClaude() to use output_config for guaranteed JSON
$response = Http::withHeaders([
    'x-api-key'         => $this->apiKey,
    'anthropic-version'  => '2023-06-01',
    'content-type'       => 'application/json',
])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
    'model'      => $this->model,
    'max_tokens' => 4000,
    'system'     => $systemPrompt,
    'messages'   => [['role' => 'user', 'content' => $userData]],
    'output_config' => [
        'format' => [
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'transactions' => [
                        'type' => 'array',
                        'items' => [...] // schema per transaction
                    ]
                ],
                'required' => ['transactions'],
                'additionalProperties' => false,
            ],
        ],
    ],
]);
// No need for regex cleanup -- response is guaranteed valid JSON
$decoded = json_decode($response->json('content.0.text'), true);
```

### Scheduled Task Wiring (console.php)
```php
// Source: existing routes/console.php (currently commented out)
// These need to be enabled after jobs/commands are created:

// Subscription detection (daily)
Schedule::call(function () {
    $detector = app(SubscriptionDetectorService::class);
    User::whereHas('bankConnections')->each(function ($user) use ($detector) {
        $detector->detectSubscriptions($user->id);
    });
})->dailyAt('02:00')->name('detect-subscriptions');

// Email sync (every 6 hours)
Schedule::call(function () {
    EmailConnection::where('status', 'active')->each(function ($conn) {
        ProcessOrderEmails::dispatch($conn);
    });
})->everySixHours()->name('sync-email-orders');
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Prompt-only JSON | Structured Outputs (`output_config.format`) | Nov 2025 (GA for Opus/Sonnet) | Guarantees valid JSON, eliminates parsing errors |
| `output_format` parameter | `output_config.format` parameter | Late 2025 | Old parameter deprecated, new one is GA |
| Manual JSON cleanup (regex) | Structured Outputs constrained decoding | Nov 2025 | No more `preg_replace` for backtick stripping |
| `anthropic-version: 2023-06-01` | Still current | N/A | Still the correct API version header |

**Deprecated/outdated:**
- `output_format` parameter: Deprecated in favor of `output_config.format`. Old parameter still works temporarily.
- The existing services use prompt instructions + regex cleanup for JSON parsing. This works but is fragile. Structured Outputs is the modern approach.

**Recommendation on Structured Outputs upgrade:** The existing regex-based approach works and is tested. Upgrading to Structured Outputs would be a valuable improvement but is NOT required for Phase 3 success. It could be done as an enhancement within Phase 3 or deferred to a future phase. The planner should decide based on scope.

## Open Questions

1. **Should we upgrade to Structured Outputs?**
   - What we know: Anthropic's `output_config.format` with `json_schema` guarantees valid JSON. Existing code uses prompt instructions + regex cleanup which works but can fail.
   - What's unclear: Whether the schema complexity of the categorization response (array of objects with many fields) fits within Structured Outputs' limitations (no recursive schemas, `additionalProperties: false` required on all objects).
   - Recommendation: Keep existing approach for Phase 3, add Structured Outputs as a follow-up task if time permits. The schemas are straightforward and should work fine.

2. **Python dependency management**
   - What we know: `TaxExportService` shells out to Python scripts requiring `openpyxl` and `reportlab`. These aren't tracked by Composer.
   - What's unclear: Whether Python3 is installed on the target environment.
   - Recommendation: Add `pip3 install openpyxl reportlab` as a setup step. Consider adding a `requirements.txt` in `resources/scripts/`.

3. **Google Cloud OAuth Consent Screen**
   - What we know: Gmail integration requires OAuth consent screen configured with `gmail.readonly` scope.
   - What's unclear: Whether the Google Cloud project is set up for this.
   - Recommendation: Document the setup steps. In sandbox/dev, a test project with "Testing" publish status works. Production requires Google verification.

4. **ReconciliationService Integration Scope**
   - What we know: The service exists but uses wrong model names (`BankTransaction` instead of `Transaction`).
   - What's unclear: Whether reconciliation should run automatically after email sync or be triggered manually.
   - Recommendation: Run automatically at the end of `ProcessOrderEmails` job (after creating orders, attempt reconciliation). Also expose a manual trigger endpoint.

5. **Missing `action_steps` and `related_merchants` columns on `savings_recommendations`**
   - What we know: `SavingsRecommendation` model has `action_steps` and `related_merchants` in `$fillable` with `'array'` casts. But the migration schema only has `related_transaction_ids` and `related_subscription_ids` JSON columns.
   - What's unclear: Whether `SavingsAnalyzerService` actually writes `action_steps` (it doesn't currently -- it only writes title, description, monthly_savings, annual_savings, difficulty, category, impact). But the model expects the columns.
   - Recommendation: Add a migration for `action_steps` (text, nullable) and `related_merchants` (text, nullable) columns, OR remove them from model $fillable if not needed. The `SavingsAnalyzerService` currently doesn't populate them but the Claude prompt does return `action_steps` and `related_merchants` fields -- the store method just doesn't save them. Fix the store method to save all fields Claude returns.

## Critical Integration Tasks Inventory

This is the complete list of what Phase 3 must accomplish, derived from code analysis:

### Plan 03-01: AI Categorization Pipeline + Question Flow
1. **Fix `AIQuestion` model `$fillable`**: Add `ai_confidence`, `ai_best_guess` (service writes these but model uses `confidence`)
2. **Fix `TransactionCategorizerService` field mapping**: Ensure service writes match model column names
3. **Verify `CategorizePendingTransactions` job dispatching**: Confirm it triggers after transaction sync (Phase 2 PlaidController sync should dispatch this)
4. **Wire up question expiry**: Already scheduled in `console.php` (active), verify it works
5. **Verify `AIQuestionController` end-to-end**: Controller + service + model alignment

### Plan 03-02: Subscription Detection + Savings
1. **Enable subscription detection schedule**: Uncomment/activate in `console.php`
2. **Fix `SavingsPlanAction` model `$fillable`**: Add ALL missing fields (critical -- currently very incomplete)
3. **Fix `SavingsRecommendation` migration gap**: Add missing `action_steps` + `related_merchants` columns
4. **Update `SavingsAnalyzerService.storeRecommendations()`**: Save `action_steps` and `related_merchants` from Claude response
5. **Enable savings analysis schedule**: Create artisan command or uncomment schedule
6. **Verify `SavingsController` end-to-end**: All 10 endpoints

### Plan 03-03: Tax Export + Email Parsing
1. **Install Python dependencies**: `openpyxl`, `reportlab`
2. **Verify Python scripts work**: Test `generate_tax_excel.py` and `generate_tax_pdf.py` with sample data
3. **Copy email parsing services**: `GmailService`, `EmailParserService`, `ReconciliationService` from expense-parser-module
4. **Fix all model name mismatches in copied services** (BankTransaction -> Transaction, column names)
5. **Fix encryption double-wrapping in GmailService**: Remove manual encrypt/decrypt, let model casts handle it
6. **Copy `ProcessOrderEmails` job**: Fix column name mismatches (`gmail_message_id` -> `email_message_id`)
7. **Implement `EmailConnectionController`**: Replace 501 stubs with real Gmail OAuth flow
8. **Wire `ReconciliationService` into `ProcessOrderEmails`**: Auto-reconcile after email parsing
9. **Enable email sync schedule**: Uncomment in `console.php`
10. **Verify `TaxController` end-to-end**: All 4 endpoints including file download

## Sources

### Primary (HIGH confidence)
- **Codebase analysis** - Direct reading of all 7 services, 6 controllers, 18 models, all migrations, routes, jobs, form requests, resources, enums, config files
- **Anthropic Structured Outputs docs** - https://platform.claude.com/docs/en/build-with-claude/structured-outputs - GA feature for json_schema output format
- **Anthropic Rate Limits docs** - https://platform.claude.com/docs/en/api/rate-limits - Token bucket algorithm, RPM/ITPM limits

### Secondary (MEDIUM confidence)
- **Google API PHP Client** - https://github.com/googleapis/google-api-php-client - Gmail API integration patterns
- **Anthropic API best practices** - Multiple web sources confirming exponential backoff, header monitoring for rate limits

### Tertiary (LOW confidence)
- None -- all findings are based on direct code analysis or verified official documentation

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - All libraries already installed and configured in the project
- Architecture: HIGH - All patterns verified by reading existing service/controller code
- Pitfalls: HIGH - All mismatches identified by cross-referencing service code against model $fillable and migration schemas
- Integration tasks: HIGH - Complete inventory from direct code comparison

**Research date:** 2026-02-10
**Valid until:** 2026-03-10 (stable -- codebase is well-established, Anthropic API is GA)
