# Phase 11: Red-Flag Detection, Guided Interview & AI Feed Integration — Research

**Researched:** 2026-07-01
**Domain:** Implementation architecture — detector framework, interview state machine, durable-facts store, AI-feed bridge, Pest validation strategy
**Confidence:** HIGH (all claims derived from direct codebase inspection or locked decisions in CONTEXT.md)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D1.** Versioned rule schema (TAX-09) adopted day one: `rule_id, authority, effective_start, effective_end, phaseouts, inflation_adjusted, source_url, last_verified, status, band` — TaxRulesEngineService gains a validator that suppresses expired rules, surfaces stale `last_verified`, enforces `band`. Retrofit is expensive — ship in wave 11a.
- **D2.** Materiality gates and band cutpoints live in `config/tax-rules.php` (or sibling `config/tax-detection.php`), never in service code. $100 single-txn floor, $500/yr recurring, $1,000 interrogate, address-matched/loan-servicer always.
- **D3.** Durable-facts store anchored to Enhanced Tax Profile (LOCKED owner Decision 1): three new tables (`user_tax_facts`, `tax_profile_entities`, `interview_sessions`), append-only, encrypted sensitive values, partial unique index, carry-forward tiers (permanent/stable/annual), GDPR cascadeOnDelete.
- **D4.** Method-conflict guards (FLAG-09) run before any finding is emitted inside RedFlagDetectorService.
- **D5.** Question-engine rules: plain language / one legal test per question / static `legal_basis` metadata, never Claude output. Leading-not-assuming. Batch by merchant pattern (INT-06). High-confidence findings pre-fill "suggested — confirm" (INT-07, liability screen). Every "no" feeds STORE-02.
- **D6.** OptimizationFinding additive extension (FLAG-13): `transaction_ids`, `treatment`, `legal_basis`, `assumptions`, `band`, `user_assertions` (encrypted, $hidden), `docs_captured`, `docs_missing`, `estimated_value_cents`, `pro_export_ready`, plus forward-compat year-end fields.
- **D7.** AI-feed bridge: `QuestionType::Optimization` additive enum case; `SurfaceHighPriorityRedFlags` listener creates AIQuestion rows; `UpdateOptimizationFromAnswer` listener writes through to UserTaxFact; `UpdateTransactionCategory` guarded to ignore optimization questions.
- **D8.** Build order follows value÷effort roadmap: items 1–3 in P11 (retroactive scanners + basis ledger; penalty sweeps; transaction hypothesis engine), items 4 and 9 in P12, items 5–10 post-v2.1.
- **D9.** Monthly recurring-payee sweep + continuous penalty sweep in `routes/console.php`, activity-gated per 28-day precedent.
- **D10.** Liability reframes are binding on every plan. FLAG-18 = "safe-harbor benchmark" never "estimated taxes". FLAG-22 = cliff awareness never computed subsidy. FLAG-15 = neutral framing never audit-probability. All per-requirement reframe text applies.
- **D11.** QBI above-threshold W-2/UBIA test = professional-review sentinel only, no implementation.
- **D12.** Encryption/PII conventions non-negotiable: every sensitive column = TEXT + `'encrypted'` cast + `$hidden`. Money = integer-cents-as-string. Never put PII in fact_key or label.
- **D13.** FLAG-28 profile-vs-reality conformance detectors (wave 11b): stated facts vs paystub/transfer/transaction evidence — every mismatch = OptimizationFinding + educational question.
- **D14.** Frontend skill mandate: every P11 plan touching interview UI MUST name `/frontend-design:frontend-design` + `ui-ux-pro-max` in task instructions. Harmonize with `sw-*` tokens — elevate, don't replace. Apply taste-skill/redesign-skill/soft-skill per Decision 7 procedure.

### Claude's Discretion
- Exact implementation shape of `RedFlagDetectorService` (single service with detector methods vs. separate Detector classes — both acceptable; research recommends single service with per-detector methods in Wave 11a, extract to classes in 11b when category content grows).
- Whether materiality gates extend `config/tax-rules.php` directly or live in a sibling `config/tax-detection.php` — both are valid per D2.
- Test file organization for new Phase 11 tests (mirrors existing `tests/Unit/Services/` and `tests/Feature/` patterns).

### Deferred Ideas (OUT OF SCOPE)
- Phase 12: optimization report, pro-export, doc intake, HSA shoebox (STORE-03), Optimize My Income page/nav, Settings reshape
- Phase 13: prompt pinning, prompt-injection pen test, hard-block enforcement, SSN masking audit
- Future: FLAG-19 crypto, STORE-04 live logs, NOTIF-01 push, DOC-08 carryforward tracker, persona packs (PERS-01..13), STATE-01 anything state-touching, full life-event engine (LIFE-01), year-end Q4 engine (YEAR-01), GUARD-01 interrupt class, NOTICE-01, CTRL-01
- Blocked: all RIA strategy/detector items, AMT crossover modeling, PR Act 60, MFS optimizer, auto-classified tax treatments, silent document-extracted fact writes, abusive-scheme list
</user_constraints>

---

## Summary

Phase 11 builds four tightly coupled subsystems on top of Phase 10's shipped foundation (`TaxRulesEngineService`, `OptimizationFinding`, `CrossSourceReviewService`, `BuildIncomeOptimizationProfile` job, `OptimizationProfileBuilt` event). The four subsystems are: (1) a versioned-rule detector framework with a materiality-gated `RedFlagDetectorService`; (2) a persisted `InterviewSession` state machine with an `InterviewOrchestratorService` that emits one question at a time; (3) a durable-facts store (`UserTaxFact` / `TaxProfileEntity`) anchored to the existing Enhanced Tax Profile; and (4) an AI-feed bridge that adds `QuestionType::Optimization` to the existing AIQuestion feed with zero regression risk.

The critical insight from codebase review: Phase 10 **deliberately left hooks for Phase 11**. `OptimizationFinding.description` is null by design (Phase 11 fills via Claude narration). `OptimizationProfileBuilt` event fires with zero listeners currently. `IncomeOptimizationProfile::answerableFields()` carries a Phase 11 extension note. `UpdateTransactionCategory` listener is straightforward to guard. The Phase 11 plan should lean heavily on these pre-wired attachment points.

One confirmed FACT-CHECK FIX (from 11-CONTEXT D3): `IncomeOptimizerDataAssemblerService::readProfileFlags()` currently omits `business_type` and `housing_status` from its return array — these fields exist on `UserFinancialProfile` and are needed for entity/housing gates in detectors. This is an additive extension to the assembler, required in wave 11a.

**Primary recommendation:** Build wave 11a as strict infrastructure with no detector content beyond the core framework, then load wave 11b detector content against a tested, stable harness.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Versioned-rule validation | API / Backend (TaxRulesEngineService extension) | config/tax-detection.php | All math stays server-side; no rule constants touch frontend |
| Detector execution | API / Backend (RedFlagDetectorService) | Queue (BuildIncomeOptimizationProfile job) | CPU-bound; runs async after profile build |
| Interview orchestration | API / Backend (InterviewOrchestratorService) | — | Creates AIQuestion rows; frontend consumes via existing /api/v1/questions |
| Durable-facts persistence | Database (user_tax_facts, tax_profile_entities, interview_sessions) | API / Backend (UserTaxFact model) | Append-only ledger; no in-memory state |
| AI-feed surfacing | API / Backend (SurfaceHighPriorityRedFlags listener) | Frontend (existing Questions/Index.tsx) | Bridge pattern: backend creates AIQuestion rows, existing frontend consumes |
| Finding description narration | API / Backend (Claude via HTTP, in a dedicated NarrationService) | — | Isolated Claude calls; never write money columns |
| Interview UI (INT-02) | Frontend (React/Inertia, extending Questions/Index.tsx) | — | Reuse existing QuestionCard; add SuggestedConfirmCard variant |
| Settings learned-facts display (STORE-01 anchor) | Frontend (additive section in Settings/Index.tsx) | — | Additive UI hook alongside EnhancedProfileSection |
| Scheduled sweeps | Queue / Scheduler (routes/console.php) | Redis queue | Activity-gated like existing 28-day pattern |

---

## Standard Stack

### No new external packages required

All Phase 11 code uses the existing stack. Confirmed by direct inspection:

| Capability | Existing Package / Class | Version |
|------------|--------------------------|---------|
| Encryption | Laravel `'encrypted'` model cast | Laravel 12 [VERIFIED: codebase] |
| JSON storage | PostgreSQL JSONB + `'array'` cast | PG 15+ [VERIFIED: codebase] |
| Queue | Redis-backed Laravel queues | existing [VERIFIED: codebase] |
| Events/Listeners | Laravel `Event::listen()` in AppServiceProvider | existing [VERIFIED: codebase] |
| AI calls | `Illuminate\Support\Facades\Http` to Anthropic | existing [VERIFIED: codebase] |
| Tests | Pest PHP 3, `phpunit.xml` | existing [VERIFIED: codebase] |

**No new npm or Composer packages are introduced by Phase 11.** The frontend reuses existing `QuestionCard.tsx`, `useApi`/`useApiPost` hooks, and `sw-*` design tokens.

---

## Package Legitimacy Audit

> Phase 11 installs zero new external packages. This section is intentionally empty.

**Packages removed due to SLOP verdict:** none
**Packages flagged as suspicious (SUS):** none

---

## Architecture Patterns

### System Architecture Diagram

```
OptimizationProfileBuilt event (fired by BuildIncomeOptimizationProfile job)
  │
  ├──► SurfaceHighPriorityRedFlags listener
  │      │
  │      ├── queries OptimizationFinding (high-band findings)
  │      └── creates AIQuestion rows (question_type=Optimization)
  │                   │
  │                   └──► /api/v1/questions feed ──► Questions/Index.tsx
  │                                                     (existing UI, new variant)
  │
  ├──► RunRedFlagDetectors listener
  │      │
  │      └── RedFlagDetectorService::detectAll(userId, taxYear)
  │             │
  │             ├── applyMaterialityGate() ← config/tax-detection.php
  │             ├── checkMethodConflictGuards() ← UserTaxFact facts
  │             ├── runDetectors() ← per-category detector methods
  │             │     (vehicle, solar, medical, travel, etc.)
  │             └── OptimizationFinding::updateOrCreate() [upsert]
  │
  └──► NarrateOptimizationFindings listener
         └── calls Claude (description narration only)
                └── OptimizationFinding::update(['description' => ...])

UserAnsweredQuestion event (fired by AIQuestionController)
  │
  ├──► UpdateTransactionCategory listener
  │      └── GUARD: if question_type === Optimization → return early
  │              (zero regression — existing tests stay green)
  │
  └──► UpdateOptimizationFromAnswer listener (NEW)
         └── writes UserTaxFact record
               └── updates InterviewSession.asked[] + assertions
```

### Recommended Project Structure

```
app/
├── Services/
│   ├── RedFlagDetectorService.php          # Wave 11a: core + guards; 11b: detector content
│   ├── InterviewOrchestratorService.php    # Wave 11a: session state machine
│   └── NarrationService.php                # Wave 11a: Claude calls (description only)
├── Models/
│   ├── UserTaxFact.php                     # Wave 11a: durable facts store
│   ├── TaxProfileEntity.php                # Wave 11a: vehicles / properties / entities
│   └── InterviewSession.php               # Wave 11a: persisted state machine
├── Listeners/
│   ├── SurfaceHighPriorityRedFlags.php     # Wave 11a: creates AIQuestion rows
│   ├── RunRedFlagDetectors.php             # Wave 11a: triggers detectors
│   ├── NarrateOptimizationFindings.php     # Wave 11a: Claude description narration
│   └── UpdateOptimizationFromAnswer.php    # Wave 11a: writes through to UserTaxFact
├── Policies/
│   ├── UserTaxFactPolicy.php               # Wave 11a
│   ├── TaxProfileEntityPolicy.php          # Wave 11a
│   └── InterviewSessionPolicy.php          # Wave 11a
└── Http/Controllers/Api/
    └── InterviewController.php             # Wave 11a: interview session API
config/
└── tax-detection.php                       # Wave 11a: materiality gates, rule metadata
database/migrations/
├── XXXXXX_add_optimization_finding_columns.php    # Wave 11a: FLAG-13
├── XXXXXX_create_user_tax_facts.php               # Wave 11a: STORE-01
├── XXXXXX_create_tax_profile_entities.php          # Wave 11a: STORE-01
└── XXXXXX_create_interview_sessions.php            # Wave 11a: INT-01
resources/js/
├── Pages/Questions/Index.tsx               # Extend: add optimization question routing
└── Components/SpendifiAI/
    └── SuggestedConfirmCard.tsx            # Wave 11a: INT-07 "suggested — confirm" variant
tests/
├── Unit/
│   └── TaxRuleExpirationTest.php           # Wave 11a
└── Feature/
    ├── RedFlagDetectorTest.php             # Wave 11a + 11b
    ├── InterviewSessionTest.php            # Wave 11a
    ├── UserTaxFactTest.php                 # Wave 11a
    └── OptimizationFeedGuardTest.php       # Wave 11a: regression test
```

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Encrypted values | Custom encryption | Laravel `'encrypted'` model cast (already in use for 14+ columns across 9 models) | App key rotation, AEAD, no raw-crypto bugs |
| Query-time rule lookups | Parsing PHP arrays at runtime | Config facade: `config('tax-detection.rules.tips_deduction')` | Cached by config:cache in production |
| Idempotent upserts | Custom duplicate-check logic | `OptimizationFinding::updateOrCreate(['user_id', 'tax_year', 'finding_key'], [...])` | Already used by BuildIncomeOptimizationProfile job |
| Tax dollar arithmetic | Inline arithmetic in detectors | `TaxRulesEngineService` methods only | SAFE-03 enforcement: estimated_value_cents must trace to service, never detector |
| Merchant pattern matching | Re-implementing string normalization | `MerchantAlias` table + existing lowercase/trim/suffix-strip pattern from `SubscriptionDetectorService` | Already battle-tested with 300+ transactions |
| Event-to-listener wiring | Custom observer pattern | `Event::listen()` in `AppServiceProvider::boot()` | Consistent with all existing event wiring |
| Skip-gate logic | Duplicate answerableFields checks | Extend `IncomeOptimizationProfile::answerableFields()` to also consult `UserTaxFact::currentFact()` | Single point of truth for CTX-04 |
| Session transcript storage | Encrypted JSON column on the session | `InterviewSession.assertions` TEXT + `'encrypted'` cast | Consistent with ParsedEmail.raw_parsed_data pattern |

**Key insight:** The Phase 10 codebase is a mature harness. Every new Phase 11 class should follow the exact patterns already established (encrypted TEXT columns, scopeForUser(), Policy, cascadeOnDelete, Http::preventStrayRequests() test guard).

---

## Phase 11 Implementation Patterns

### Pattern 1: Versioned Rule Schema in Config

Every detector/sweep/probe rule lives in `config/tax-detection.php` as an associative array matching the TD-v2Δ §9 canonical schema:

```php
// Source: TD-v2Δ §9 (D1 decision) — verbatim schema
'rules' => [
    'tips_deduction' => [
        'rule_id'            => 'tips_deduction',
        'authority'          => 'IRC §224 (OBBBA)',
        'effective_start'    => '2025-01-01',
        'effective_end'      => '2028-12-31',
        'phaseouts'          => ['magi_single' => 150_000, 'magi_mfj' => 300_000],
        'inflation_adjusted' => false,
        'source_url'         => 'https://www.irs.gov/...',
        'last_verified'      => '2026-07-01',
        'status'             => 'verified',   // verified|needs_review|expired|expired_pending_extension
        'band'               => 'auto',       // auto|conditional|specialist|suppress|hard_block
    ],
    // ... more rules
],
```

The TaxRulesEngineService gains a `validateRule(string $ruleId): array` method:
```php
// [VERIFIED: codebase — TaxRulesEngineService has zero HTTP calls; add validator here]
public function validateRule(string $ruleId): array
{
    $rule = config("tax-detection.rules.{$ruleId}");
    if ($rule === null) {
        throw new \InvalidArgumentException("Unknown rule: {$ruleId}");
    }
    $today = now()->toDateString();
    $expired = $rule['effective_end'] !== null && $today > $rule['effective_end'];
    $stale = now()->diffInDays($rule['last_verified']) > config('tax-detection.staleness_days', 90);
    return [
        'suppressed'  => $expired || in_array($rule['band'], ['suppress', 'hard_block']),
        'band'        => $rule['band'],
        'status'      => $expired ? 'expired' : $rule['status'],
        'stale'       => $stale,
    ];
}
```

### Pattern 2: Materiality Gate (config-driven)

```php
// config/tax-detection.php
'materiality' => [
    'single_txn_auto_floor_cents'       => 100_00,    // $100
    'recurring_pattern_annual_cents'    => 500_00,    // $500/yr
    'single_txn_interrogate_cents'      => 1_000_00,  // $1,000
    'address_match_always_interrogate'  => true,
    'loan_servicer_always_interrogate'  => true,
],

// In RedFlagDetectorService — never hardcode the numbers:
private function passesGate(Transaction $tx, bool $isRecurring, float $annualTotal): bool
{
    $cfg = config('tax-detection.materiality');
    if ($isRecurring && $annualTotal * 100 >= $cfg['recurring_pattern_annual_cents']) {
        return true;
    }
    return (int) ($tx->amount * 100) >= $cfg['single_txn_interrogate_cents'];
}
```

### Pattern 3: OptimizationFinding Additive Extension (FLAG-13)

Migration adds columns only — no drops, no type changes:

```php
// Migration stub — additive only per CLAUDE.md rule
Schema::table('optimization_findings', function (Blueprint $table) {
    $table->jsonb('transaction_ids')->nullable()->after('status');
    $table->text('treatment')->nullable();
    $table->text('legal_basis')->nullable();        // static config citation, NEVER Claude output
    $table->jsonb('assumptions')->nullable();       // static config citations
    $table->string('band', 20)->nullable();         // mirrors rule band
    $table->text('user_assertions')->nullable();    // encrypted TEXT, $hidden
    $table->jsonb('docs_captured')->nullable();
    $table->jsonb('docs_missing')->nullable();
    $table->bigInteger('estimated_value_cents')->nullable(); // written ONLY by TaxRulesEngineService
    $table->boolean('pro_export_ready')->default(false);
    // year-end forward-compat (cheap now, expensive later — D6)
    $table->date('deadline')->nullable();
    $table->integer('lead_time_days')->nullable();
    $table->bigInteger('net_cash_cost')->nullable();
    $table->bigInteger('tax_saved')->nullable();
    $table->bigInteger('cliff_bonus_value')->nullable();
    $table->boolean('reversible')->nullable();
});
```

The model update adds `user_assertions` to `$hidden` and `'encrypted'` to casts.

### Pattern 4: UserTaxFact Append-Only Store

```php
// Schema: user_tax_facts
// fact_key  = namespaced string, e.g. 'ira.pretax_balance_range', 'method.mileage'
// value     = encrypted TEXT (money = integer-cents-as-string)
// volatility = 'permanent' | 'stable' | 'annual'
// source_type = 'interview_answer' | 'document_extraction' | 'detector' | 'profile_field' | 'user_edit'
// is_current  = boolean (partial unique index enforces one current per key per entity+year)
// superseded_by_id = FK to user_tax_facts (null for current row)

// Partial unique index (Postgres):
// CREATE UNIQUE INDEX idx_tax_facts_current
//   ON user_tax_facts (user_id, fact_key, COALESCE(entity_id, 0), COALESCE(tax_year, 0))
//   WHERE is_current = true;

// NEVER update a row's value — instead:
// 1. Set old row is_current = false, superseded_by_id = new_row_id
// 2. Insert new row with is_current = true
// 3. confirmed_at must be set for source_type = 'document_extraction' before is_current goes true (D3 gate)
```

### Pattern 5: InterviewSession State Machine

Statuses and transitions:

```
created ──► in_progress ──► paused ──► in_progress (resume)
                │
                └──► completed
```

```php
// InterviewOrchestratorService::nextQuestion(InterviewSession $session): ?AIQuestion
// 1. If session->status = 'paused' → set in_progress
// 2. Pop first key from session->queue (JSONB array)
// 3. Check UserTaxFact::currentFact($userId, $factKey) → if exists and confirmed, skip
// 4. Check IncomeOptimizationProfile::answerableFields() → if already answered, skip
// 5. Create AIQuestion row (question_type = Optimization)
// 6. Update session->asked[] to add the fact_key
// 7. Return the AIQuestion

// Re-answer:
// 1. User answers a question already in session->asked[]
// 2. Create new UserTaxFact row (superseding old)
// 3. Remove fact_key from session->asked[], re-queue if needed
```

### Pattern 6: AI-Feed Bridge — Guard in UpdateTransactionCategory

Current listener at `app/Listeners/UpdateTransactionCategory.php` must be guarded at the top of `handle()`:

```php
// [VERIFIED: codebase — UpdateTransactionCategory line 18-33]
public function handle(UserAnsweredQuestion $event): void
{
    // Guard: optimization questions must not trigger subscription detection
    // or any categorization side effects — their answers flow to UserTaxFact
    // via UpdateOptimizationFromAnswer listener instead. (FEED-04, D7)
    if ($event->question->question_type === \App\Enums\QuestionType::Optimization) {
        return;
    }

    // existing subscription re-detection code unchanged below this point
    $detector = app(SubscriptionDetectorService::class);
    $detector->detectSubscriptions($event->user->id);
    // ...
}
```

This is the zero-regression guard. All 131 existing tests (which never create Optimization questions) remain unaffected.

### Pattern 7: QuestionType Additive Enum Extension

```php
// [VERIFIED: codebase — app/Enums/QuestionType.php]
// Current: Category, BusinessPersonal, Split, Confirm
// Add (additive only):
enum QuestionType: string
{
    case Category = 'category';
    case BusinessPersonal = 'business_personal';
    case Split = 'split';
    case Confirm = 'confirm';
    case Optimization = 'optimization';   // NEW — FEED-01
}
```

No database migration needed — `question_type` is a VARCHAR column, and the existing `QuestionType::class` cast handles the new value transparently.

### Pattern 8: Event Registration (AppServiceProvider)

```php
// [VERIFIED: codebase — AppServiceProvider::boot() uses Event::listen() pattern]
// Add to AppServiceProvider::boot():

// Phase 11 — Red-Flag Detection
Event::listen(
    \App\Events\OptimizationProfileBuilt::class,
    \App\Listeners\RunRedFlagDetectors::class,
);
Event::listen(
    \App\Events\OptimizationProfileBuilt::class,
    \App\Listeners\NarrateOptimizationFindings::class,
);
Event::listen(
    \App\Events\OptimizationProfileBuilt::class,
    \App\Listeners\SurfaceHighPriorityRedFlags::class,
);
Event::listen(
    \App\Events\UserAnsweredQuestion::class,
    \App\Listeners\UpdateOptimizationFromAnswer::class,
);
```

Also add Gate::policy registrations for `UserTaxFact`, `TaxProfileEntity`, `InterviewSession`.

### Pattern 9: Claude Call Pattern (NarrationService — description only)

```php
// NarrationService::narrateFinding(OptimizationFinding $finding): string
// ONLY generates: human-readable description from pre-computed data
// NEVER generates: estimated_value_cents, legal_basis, assumptions, band

// Input to Claude (safe — no PII in prompt, merchant names JSON-encoded):
$systemPrompt = <<<'SYS'
You are an educational financial assistant. Given a pre-computed tax finding,
write a 1-3 sentence plain-English description for the user. Use "may", "could",
and "consider" language. Never state dollar amounts — use the placeholders provided.
Never make deduction assertions. Never use first person from the IRS perspective.
SYS;

$userMessage = json_encode([
    'finding_type'   => $finding->finding_type,
    'severity'       => $finding->severity,
    'treatment'      => $finding->treatment,
    'legal_basis'    => $finding->legal_basis,
    // estimated_value_cents is deliberately EXCLUDED from Claude context
    // Claude must never see the number to prevent it from echoing back
]);

// [VERIFIED: codebase — TransactionCategorizerService uses Http::post() to Anthropic directly]
// Same Http::post() pattern, same model from config('services.anthropic.model')
```

**Prompt-injection safety:** All user-supplied fields (merchant names, answers, entity labels) must be passed inside a JSON payload with `json_encode()` — never interpolated into the system prompt or as raw strings.

### Pattern 10: IncomeOptimizerDataAssemblerService FACT-CHECK FIX

The `readProfileFlags()` method currently returns 7 keys and omits `business_type` and `housing_status`. [VERIFIED: codebase — line 119-127 of IncomeOptimizerDataAssemblerService.php]

```php
// Additive extension — add two keys to the existing return array:
return [
    'filing_status'        => $filingStatus,
    'has_hsa_eligible_plan'=> (bool) ($profile->has_hsa ?? false),
    'has_ira'              => (bool) ($profile->has_ira ?? false),
    'ira_type'             => $profile->ira_type ?? null,
    'has_home_office'      => (bool) ($profile->has_home_office ?? false),
    'has_self_employment'  => $hasSelfEmployment,
    'employment_type'      => $profile->employment_type ?? null,
    // FACT-CHECK FIX — required for entity/housing gates (11-CONTEXT D3):
    'business_type'        => $profile->business_type ?? null,
    'housing_status'       => $profile->housing_status ?? null,
];
```

Also add the two new keys to `IncomeOptimizationProfile::$fillable` and an `'array'`-compatible storage path (or read them directly from the UserFinancialProfile join in the assembler without storing on the snapshot — either is acceptable).

### Pattern 11: answerableFields() Extension

```php
// IncomeOptimizationProfile::answerableFields() — additive extension
// Add: consult UserTaxFact so durable facts count as "already answered"
// [VERIFIED: codebase — existing method at line 150-163]

public function answerableFields(?\App\Models\UserTaxFact $factsProxy = null): array
{
    $base = [
        'filing_status'         => $this->filing_status !== null,
        'has_hsa_eligible_plan' => $this->has_hsa_eligible_plan === true,
        'has_ira'               => $this->has_ira === true,
        'ira_type'              => $this->ira_type !== null,
        'has_home_office'       => $this->has_home_office === true,
        'has_self_employment'   => $this->has_self_employment === true,
        'has_401k_contributions'=> $this->traditional_401k_ytd !== null && (int) $this->traditional_401k_ytd > 0,
        'has_hsa_contributions' => $this->hsa_ytd !== null && (int) $this->hsa_ytd > 0,
        'employment_type'       => $this->employment_type !== null,
    ];

    // Merge durable facts — a confirmed UserTaxFact counts as answered
    if ($factsProxy !== null) {
        foreach ($factsProxy->currentFactKeys($this->user_id) as $factKey) {
            $base[$factKey] = true;
        }
    }
    return $base;
}
```

### Pattern 12: Multi-Account IRA Representation

The existing `ira_type` column stays untouched (backwards-compat). New retirement facts live in `UserTaxFact` with namespaced keys:

```
ira.roth_ytd_cents       → Roth IRA YTD contributions (integer-cents-as-string, encrypted)
ira.traditional_ytd_cents → Traditional IRA YTD contributions
ira.roth_balance_cents   → Roth balance range (not PII-indexed)
ira.traditional_balance_cents → Traditional balance range
```

`TaxRulesEngineService::remainingIraRoomCents()` must consume COMBINED Roth+Traditional contributions:

```php
// D3 correctness requirement: IRA limit is SHARED across Roth+Traditional
// Combined = (int) $rothFact->value + (int) $traditionalFact->value
// Then: remainingIraRoomCents($combinedCents, $age, $year)
```

The existing `remainingIraRoomCents()` method signature is unchanged — callers are responsible for passing combined contributions.

### Pattern 13: Confirmation Gate for Document-Extracted Facts (D3)

Facts with `source_type = 'document_extraction'` are PROPOSALS. Proposal schema:

```
is_current = false
confirmed_at = null
extraction_confidence (jsonb field in `assumptions`): per-field confidence float
```

They do NOT contribute to:
- answerableFields() skip-gate (only confirmed facts qualify)
- estimated_value_cents calculations
- pro_export_ready flag

The user confirms via a proposed-fact review UI in Settings (additive section alongside EnhancedProfileSection.tsx). On confirm:
1. Set `is_current = true`, `confirmed_at = now()`
2. Supersede any older fact for the same key
3. Dispatch a re-evaluation of pending findings that were blocked by this fact being unconfirmed

---

## Common Pitfalls

### Pitfall 1: Enum Case Regression
**What goes wrong:** Adding `QuestionType::Optimization` breaks existing code that exhaustively matches on the enum without a `default` case.
**Why it happens:** PHP backed enums throw `UnhandledMatchError` on unmatched cases in `match()` expressions.
**How to avoid:** Grep the entire codebase for `match($question->question_type)` and `match($question->question_type->value)` before shipping the new case. Add `default => null` or explicit handling for `Optimization`.
**Warning signs:** Test suite throws `UnhandledMatchError` in any listener or resource class.

### Pitfall 2: encrypted Cast on Non-TEXT Column
**What goes wrong:** Storing an `'encrypted'` cast on a VARCHAR or INT column causes truncation/corruption on longer encrypted payloads.
**Why it happens:** Laravel encryption produces base64-encoded serialized payloads significantly longer than the original value.
**How to avoid:** All new encrypted columns MUST be declared as `$table->text('column_name')` in migrations. [VERIFIED: codebase — all 14 existing encrypted money columns in IncomeOptimizationProfile are TEXT]

### Pitfall 3: estimated_value_cents Written Outside TaxRulesEngineService
**What goes wrong:** A detector or listener computes estimated_value_cents directly (e.g., `$tx->amount * 0.22`), bypassing the SAFE-03 guard.
**Why it happens:** Temptation to compute "simple" tax savings inline without calling the service.
**How to avoid:** Enforce via a Pest test that grep-gates the `estimated_value_cents` identifier in all files outside `TaxRulesEngineService.php` and `TaxRulesEngineServiceTest.php`. If any non-test, non-engine file assigns it, the test fails.

### Pitfall 4: UserTaxFact Unique Index Violation on Concurrent Upserts
**What goes wrong:** Two concurrent listeners both try to write the same `(user_id, fact_key, entity_id, tax_year)` as `is_current = true`, causing a partial-unique index violation.
**Why it happens:** Multiple events dispatched close together (e.g., profile build + interview answer) can race.
**How to avoid:** Wrap fact-write operations in a DB transaction with a `SELECT ... FOR UPDATE` on the existing current row before inserting the new one. Use Postgres advisory locks keyed on `(user_id, fact_key)` for high-contention paths.

### Pitfall 5: OptimizationFinding updateOrCreate Key Collision Across Tax Years
**What goes wrong:** A detector emits `finding_key = 'vehicle_mileage_deduction'` for both 2025 and 2026, but the key is not year-scoped, so 2025 findings are overwritten.
**Why it happens:** `updateOrCreate` keys on `(user_id, tax_year, finding_key)` — if `tax_year` is not passed correctly, cross-year collision occurs.
**How to avoid:** Always pass all three key fields explicitly. Never use `finding_key` alone as the uniqueness key.

### Pitfall 6: Pending AIQuestion Cleanup in index() for Optimization Questions
**What goes wrong:** The existing `AIQuestionController::index()` auto-resolves questions whose transactions are `user_confirmed` or `auto_categorized`. Optimization questions have no transaction or have a null `transaction_id`, so the cleanup query either errors or incorrectly resolves them.
**Why it happens:** The cleanup uses `whereHas('transaction', ...)` which excludes null-transaction questions, but the WHERE clause may unexpectedly affect them.
**How to avoid:** Scope the auto-cleanup query to `question_type != 'optimization'` explicitly. [VERIFIED: codebase — AIQuestionController::index() line 33-44 runs a bulk update that could affect null-transaction optimization questions if not guarded]

### Pitfall 7: Claude Seeing estimated_value_cents in Narration Prompts
**What goes wrong:** Passing the full finding details array (including `estimated_value_cents`) to Claude for narration causes Claude to echo back the number as a claimed amount rather than a range.
**Why it happens:** Claude will use any number in context as a factual anchor in its output.
**How to avoid:** The `NarrationService` must explicitly exclude `estimated_value_cents` from the Claude input payload. Claude receives `finding_type`, `severity`, `treatment`, `legal_basis`, and a placeholder like `"potential_range": "use a professional estimate range, not a specific dollar amount"`.

### Pitfall 8: InterviewSession Multiple In-Progress Sessions
**What goes wrong:** A user triggers two parallel events that both create `in_progress` sessions for the same (user_id, tax_year), violating the partial unique index.
**Why it happens:** `OptimizationProfileBuilt` might fire multiple times (job retry), or a manual interview trigger and an auto-trigger race.
**How to avoid:** Use `InterviewSession::firstOrCreate(['user_id' => ..., 'tax_year' => ..., 'status' => 'in_progress'], [...])` to create idempotently. The partial unique index provides the DB-level guarantee. Let job retries look up existing session rather than create new.

---

## Runtime State Inventory

> This is a greenfield feature on existing infrastructure. No rename/migration of existing state.

| Category | Items Found | Action Required |
|----------|-------------|-----------------|
| Stored data | `optimization_findings` table: existing rows have `description = null` (by design, Phase 10 left hook) | None — Phase 11 fills via narration listener |
| Stored data | `ai_questions` table: all existing rows have `question_type` in {category, business_personal, split, confirm} | None — new Optimization case is additive |
| Live service config | No external services carry Phase 11 state | None |
| OS-registered state | No OS-level registrations for Phase 11 | None |
| Secrets/env vars | No new env vars — uses existing ANTHROPIC_API_KEY | None |
| Build artifacts | No pre-built artifacts affected | None |

---

## Config Extension Reference

`config/tax-detection.php` (new file, wave 11a):

```php
return [
    // Materiality gates (D2 — never in service code)
    'materiality' => [
        'single_txn_auto_floor_cents'    => 10_000,   // $100
        'recurring_pattern_annual_cents' => 50_000,   // $500/yr
        'single_txn_interrogate_cents'   => 100_000,  // $1,000
        'address_match_always'           => true,
        'loan_servicer_always'           => true,
    ],

    // Confidence bands for interview question mode (INT-07)
    'confidence' => [
        'suggested_confirm_threshold' => 0.85,  // pre-fill + one-tap confirm
        'conditional_threshold'       => 0.60,  // standard multiple-choice
        'specialist_threshold'        => 0.40,  // route to pro-review
    ],

    // Fact carry-forward timing (D3 STORE-01)
    'facts' => [
        'reconfirm_months' => 12,   // stable-volatility facts reconfirm after N months
    ],

    // Staleness threshold for last_verified dates
    'staleness_days' => 90,

    // Rule registry (versioned schema — D1/TAX-09)
    'rules' => [
        'tips_deduction' => [
            'rule_id'         => 'tips_deduction',
            'authority'       => 'IRC §224 (OBBBA)',
            'effective_start' => '2025-01-01',
            'effective_end'   => '2028-12-31',
            'phaseouts'       => ['magi_single' => 150_000, 'magi_mfj' => 300_000],
            'inflation_adjusted' => false,
            'source_url'      => 'https://www.congress.gov/bill/119th-congress/house-bill/1',
            'last_verified'   => '2026-07-01',
            'status'          => 'verified',
            'band'            => 'auto',
        ],
        'ot_deduction' => [
            'rule_id'         => 'ot_deduction',
            'authority'       => 'IRC §225 (OBBBA)',
            'effective_start' => '2025-01-01',
            'effective_end'   => '2028-12-31',
            'phaseouts'       => ['magi_single' => 150_000, 'magi_mfj' => 300_000],
            'inflation_adjusted' => false,
            'source_url'      => 'https://www.congress.gov/bill/119th-congress/house-bill/1',
            'last_verified'   => '2026-07-01',
            'status'          => 'verified',
            'band'            => 'conditional',  // W-2 box code required before surfacing
        ],
        'auto_loan_interest' => [
            'rule_id'         => 'auto_loan_interest',
            'authority'       => 'IRC §163(h) (OBBBA)',
            'effective_start' => '2025-01-01',
            'effective_end'   => '2028-12-31',
            'phaseouts'       => [],
            'cap_cents'       => 1_000_000,  // $10,000 in cents
            'inflation_adjusted' => false,
            'source_url'      => 'https://www.congress.gov/bill/119th-congress/house-bill/1',
            'last_verified'   => '2026-07-01',
            'status'          => 'verified',
            'band'            => 'conditional',  // US-assembled + 2025+ gate
        ],
        'residential_energy_credit_25d' => [
            'rule_id'         => 'residential_energy_credit_25d',
            'authority'       => 'IRC §25D',
            'effective_start' => '2022-01-01',
            'effective_end'   => '2025-12-31',
            'phaseouts'       => [],
            'inflation_adjusted' => false,
            'source_url'      => 'https://www.irs.gov/credits-deductions/residential-clean-energy-credit',
            'last_verified'   => '2026-07-01',
            'status'          => 'expired',           // Amendment scanner only for pre-2026 installs
            'band'            => 'conditional',
        ],
        'ev_credit_30d' => [
            'rule_id'         => 'ev_credit_30d',
            'authority'       => 'IRC §30D',
            'effective_start' => '2023-01-01',
            'effective_end'   => '2025-09-30',       // pre-Oct-2025 for retro scanner
            'phaseouts'       => [],
            'inflation_adjusted' => false,
            'source_url'      => 'https://www.irs.gov/credits-deductions/credits-for-new-electric-vehicles',
            'last_verified'   => '2026-07-01',
            'status'          => 'expired',
            'band'            => 'conditional',       // date-gated past-window only
        ],
        'salt_deduction_cap' => [
            'rule_id'         => 'salt_deduction_cap',
            'authority'       => 'IRC §164(b)(6) (OBBBA — $40K cap)',
            'effective_start' => '2025-01-01',
            'effective_end'   => '2029-12-31',
            'phaseouts'       => [],
            'cap_cents'       => 4_000_000,          // $40,000
            'inflation_adjusted' => false,
            'source_url'      => 'https://www.congress.gov/bill/119th-congress/house-bill/1',
            'last_verified'   => '2026-07-01',
            'status'          => 'verified',
            'band'            => 'auto',
        ],
    ],

    // Materiality: document/upload request list (feeds docs_missing jsonb)
    'doc_request_labels' => [
        'sponsorship_agreement' => 'Written sponsorship agreement',
        'market_rate_memo'      => 'Market-rate comparable memo for sponsorship',
        'mileage_log'           => 'Mileage log (contemporaneous)',
        'gallons_log'           => 'Off-road fuel gallons log (required for fuel credit)',
        'rx_letter'             => 'Physician prescription/recommendation letter',
        'contractor_invoices'   => 'Contractor invoices / improvement receipts',
        // ... more
    ],
];
```

Additional constants for `config/tax-rules.php` extension (TAX-08 wave 11a seed — add under existing 2026 key):

```php
// Detection constants (new keys under 2026 — additive, does not touch existing keys)
'detection' => [
    '457b_employee_limit'        => 24_500,    // same as 401k for 2026 [VERIFIED: CONTEXT.md D1]
    'ira_shared_limit'           => 7_500,     // Roth+Traditional COMBINED [VERIFIED: D3]
    'ira_catchup_50_plus'        => 1_100,     // [VERIFIED: existing config]
    'auto_loan_interest_cap'     => 10_000,    // 2025-2028 OBBBA cap [VERIFIED: TD-v1 §1]
    'augusta_rule_day_cap'       => 14,        // §280A [VERIFIED: TD-v1 §3.6]
    'amended_return_lookback_yr' => 3,         // §25D retro window [VERIFIED: TD-v1 §11.1]
    'medical_agi_floor'          => 0.075,     // §213(a) 7.5% [VERIFIED: TD-v1 §13]
    'charitable_acknowledgment'  => 250,       // $250 acknowledgment letter floor [VERIFIED: TD-v1 §13]
    'gambling_loss_pct'          => 0.90,      // 2026+ phantom income (TAX-08 with §9 dating) [VERIFIED: TD-v2Δ §13]
    'onboarding_history_months'  => 36,        // retroactive scan depth [VERIFIED: TD-v1 §11]
],
```

---

## Validation Architecture

> `workflow.nyquist_validation` key absent from `.planning/config.json` — treated as enabled.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest PHP 3 |
| Config file | `phpunit.xml` (root) |
| Quick run command | `php artisan test --compact --filter=RedFlag` |
| Full suite command | `php artisan test --compact` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| FLAG-01 | RedFlagDetectorService emits OptimizationFinding | Feature | `php artisan test --filter=RedFlagDetectorTest` | ❌ Wave 0 |
| FLAG-06 | Severity model maps band to severity levels | Unit | `php artisan test --filter=TaxRuleExpirationTest` | ❌ Wave 0 |
| FLAG-08 | Materiality gate: txn < $100 auto-classified, no finding | Feature | `php artisan test --filter=RedFlagDetectorTest::materiality_gate` | ❌ Wave 0 |
| FLAG-09 | Method-conflict guard suppresses conflicting finding | Unit | `php artisan test --filter=RedFlagDetectorTest::method_conflict_guard` | ❌ Wave 0 |
| FLAG-13 | OptimizationFinding migration columns exist | Feature | `php artisan test --filter=OptimizationFindingExtensionTest` | ❌ Wave 0 |
| TAX-08 | New config constants readable from tax-rules.php | Unit | `php artisan test --filter=TaxConfigExtensionTest` | ❌ Wave 0 |
| TAX-09 | Expired rule returns suppressed=true from validator | Unit | `php artisan test --filter=TaxRuleExpirationTest::expired_rule_suppressed` | ❌ Wave 0 |
| STORE-01 | UserTaxFact append-only (no update of value column) | Feature | `php artisan test --filter=UserTaxFactTest::append_only_no_update` | ❌ Wave 0 |
| STORE-01 | Confirmed document fact feeds answerableFields | Feature | `php artisan test --filter=UserTaxFactTest::confirmed_fact_answerable` | ❌ Wave 0 |
| STORE-01 | Proposal fact does NOT feed answerableFields | Feature | `php artisan test --filter=UserTaxFactTest::proposal_not_answerable` | ❌ Wave 0 |
| STORE-02 | Basis ledger entry on TaxProfileEntity upserts | Feature | `php artisan test --filter=BasisLedgerTest` | ❌ Wave 0 |
| INT-01 | InterviewSession created with pending status | Feature | `php artisan test --filter=InterviewSessionTest::creates_pending` | ❌ Wave 0 |
| INT-02 | nextQuestion() skips already-known facts | Feature | `php artisan test --filter=InterviewSessionTest::skips_known_facts` | ❌ Wave 0 |
| INT-03 | answerableFields() extended: durable fact counts | Unit | `php artisan test --filter=IncomeOptimizationProfileTest::answerable_includes_facts` | ❌ Wave 0 |
| INT-05 | Re-answer creates new UserTaxFact, supersedes old | Feature | `php artisan test --filter=InterviewSessionTest::reanswer_supersedes` | ❌ Wave 0 |
| INT-06 | Batch by merchant pattern — one AIQuestion per merchant group | Feature | `php artisan test --filter=InterviewSessionTest::batches_by_merchant` | ❌ Wave 0 |
| INT-07 | High-confidence finding creates suggested-confirm question | Feature | `php artisan test --filter=InterviewSessionTest::high_confidence_suggested_confirm` | ❌ Wave 0 |
| FEED-01 | QuestionType::Optimization enum case exists | Unit | `php artisan test --filter=QuestionTypeEnumTest` | ❌ Wave 0 |
| FEED-02 | SurfaceHighPriorityRedFlags creates AIQuestion row | Feature | `php artisan test --filter=OptimizationFeedTest::creates_ai_question` | ❌ Wave 0 |
| FEED-03 | UpdateOptimizationFromAnswer writes UserTaxFact | Feature | `php artisan test --filter=OptimizationFeedTest::answer_writes_tax_fact` | ❌ Wave 0 |
| FEED-04 | UpdateTransactionCategory guard skips Optimization questions | Feature | `php artisan test --filter=OptimizationFeedGuardTest::guard_skips_optimization` | ❌ Wave 0 |
| SAFE-03 | estimated_value_cents never written outside TaxRulesEngineService | Unit (grep gate) | `php artisan test --filter=EstimatedValueGuardTest` | ❌ Wave 0 |
| SAFE-03 | NarrationService makes zero outbound calls to non-Anthropic hosts | Unit | `php artisan test --filter=NarrationServiceTest::no_stray_requests` | ❌ Wave 0 |
| SAFE-01 | No Claude call computes estimated_value_cents (no-number guard) | Unit | `php artisan test --filter=NarrationServiceTest::claude_never_receives_value_cents` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --compact --filter=OptimizationFeedGuardTest` (regression guard, <5s)
- **Per wave merge:** `php artisan test --compact` (full suite, all 131 + new tests)
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps

All test files for Phase 11 are new — none exist yet:

- [ ] `tests/Unit/TaxRuleExpirationTest.php` — covers TAX-09 / FLAG-06
- [ ] `tests/Unit/TaxConfigExtensionTest.php` — covers TAX-08
- [ ] `tests/Unit/EstimatedValueGuardTest.php` — SAFE-03 grep-gate
- [ ] `tests/Unit/QuestionTypeEnumTest.php` — FEED-01
- [ ] `tests/Feature/RedFlagDetectorTest.php` — covers FLAG-01/06/08/09
- [ ] `tests/Feature/InterviewSessionTest.php` — covers INT-01..07
- [ ] `tests/Feature/UserTaxFactTest.php` — covers STORE-01
- [ ] `tests/Feature/BasisLedgerTest.php` — covers STORE-02
- [ ] `tests/Feature/OptimizationFeedTest.php` — covers FEED-02/03
- [ ] `tests/Feature/OptimizationFeedGuardTest.php` — covers FEED-04 (zero-regression)
- [ ] `tests/Feature/OptimizationFindingExtensionTest.php` — covers FLAG-13
- [ ] `tests/Feature/NarrationServiceTest.php` — covers SAFE-01/SAFE-03 narration boundary

**Test infrastructure that already exists and can be reused:**
- `tests/Unit/Services/TaxRulesEngineServiceTest.php` — `Http::preventStrayRequests()` pattern to copy
- `tests/Feature/CrossSourceReviewServiceTest.php` — `createUserWithBankAndProfile()` helper pattern
- `tests/Feature/IncomeOptimizerDataAssemblerTest.php` — snapshot build fixture pattern

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | n/a — existing Sanctum auth unchanged |
| V3 Session Management | No | InterviewSession is a data record, not an HTTP session |
| V4 Access Control | Yes | scopeForUser() + Policy (user_id === resource->user_id) on all 3 new models |
| V5 Input Validation | Yes | user_answer via existing AnswerQuestionRequest; fact_key validated against known enum; user_assertions stored encrypted |
| V6 Cryptography | Yes | Laravel `'encrypted'` cast (AES-256-GCM) on all sensitive columns; never hand-roll |

### Known Threat Patterns for Phase 11 Stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Prompt injection via merchant names | Tampering | json_encode() all user-derived data passed to Claude; never string interpolation in system prompt |
| Cross-user fact disclosure | Information Disclosure | scopeForUser() on UserTaxFact, TaxProfileEntity, InterviewSession — same pattern as all existing models |
| Auto-write of document-extracted facts | Repudiation | D3 confirmation gate: `source_type=document_extraction` rows set `is_current=false` until user confirms; `confirmed_at` timestamp is the audit log |
| estimated_value_cents bypass | Tampering | SAFE-03 grep-gate test; only TaxRulesEngineService has a write path |
| GDPR account deletion missing new models | Information Disclosure | All 3 new models must have `cascadeOnDelete()` on FK to users table |
| Race condition on partial-unique index | Tampering | DB transaction + SELECT FOR UPDATE before inserting new current fact |

---

## Frontend Implementation Notes (D14 / D6 / D7)

### Interview UI Surface (INT-02)

**Guiding principle:** elevate-don't-replace. The existing `Questions/Index.tsx` is the base — optimization questions appear in the same feed, routed by `question_type === 'optimization'`.

New component needed: `SuggestedConfirmCard.tsx` — handles INT-07 pre-filled confirm flow:
- Renders the pre-suggested treatment as highlighted text
- Shows single-tap "Confirm" + "Not quite" (undo) affordances
- Until confirmed: treatment excluded from aggregations, `pro_export_ready` remains false
- Design: use `sw-accent` + `sw-accent-light` for the suggested state; `sw-success` for confirmed
- Apply soft-skill spacing rhythm; use existing `Badge` component for band indicator

**Design skills to invoke (D14 mandate):**
- `/frontend-design:frontend-design` — for component implementation
- `ui-ux-pro-max` search: `python3 .claude/skills/ui-ux-pro-max/scripts/search.py "interview wizard one question at a time"`
- Apply redesign-skill audit-first approach if touching Questions/Index.tsx beyond the new routing

**Preservation audit checklist (D7 procedure):**
- URL `/questions` unchanged
- Nav label "AI Questions" unchanged
- Existing QuestionCard behavior unchanged (transaction questions render identically)
- `sw-*` tokens used throughout; no new palette additions

### Settings Learned-Facts UI Hook (STORE-01 anchor)

Additive section in `Settings/Index.tsx` alongside the existing `EnhancedProfileSection`:

```tsx
// Additive import + render — no changes to existing EnhancedProfileSection
<EnhancedProfileSection {...existingProps} />

{/* NEW: additive learned-facts review list */}
<LearnedTaxFactsSection userId={auth.user.id} />
```

`LearnedTaxFactsSection.tsx` shows confirmed + proposed facts, allows re-answer, and flags proposal-pending items for confirmation. Proposed items show the per-field extraction confidence.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `question_type` on `ai_questions` is a VARCHAR column, not a MySQL ENUM | Pattern 7 (QuestionType extension) | If MySQL ENUM: requires migration to ALTER COLUMN — but project uses PostgreSQL 15+, which has no ENUM storage constraint here |
| A2 | `AppServiceProvider::boot()` is the correct registration point for all new listeners | Pattern 8 | If Laravel 12 uses a different discovery mechanism, listeners might be registered twice — but the existing codebase confirms Event::listen() in AppServiceProvider is the pattern |
| A3 | `InterviewSession.queue` and `.asked` as plain JSONB arrays of fact_key strings is sufficient for the resume/re-answer use case | Pattern 5 | If question ordering requires richer metadata, the schema may need a `queued_items` array of objects instead of simple strings — low risk, easily extended |
| A4 | `NarrationService` can use the same Anthropic HTTP endpoint and model as `TransactionCategorizerService` | Pattern 9 | Different rate-limit bucket or model-version constraint could require separate configuration — low risk |
| A5 | `IncomeOptimizationProfile` additive columns for `business_type` and `housing_status` are the right place to extend readProfileFlags() return value | Pattern 10 | Alternative: read UserFinancialProfile directly in each detector without going through the assembler — both are valid but the assembler path maintains separation of concerns |

---

## Open Questions

1. **AIQuestionController::index() cleanup scope for optimization questions**
   - What we know: the cleanup query at line 33-44 auto-resolves questions whose transactions are `user_confirmed`. Optimization questions have null or special `transaction_id`.
   - What's unclear: should optimization questions ever be auto-expired by the existing `ai:expire-questions` command (daily 3am), or should they persist until explicitly answered/dismissed?
   - Recommendation: Explicitly scope auto-expire to `question_type != 'optimization'`. Optimization questions should only expire when their associated `InterviewSession` completes or the finding is dismissed by the user.

2. **InterviewOrchestratorService triggering point**
   - What we know: the interview is triggered on onboarding (alongside BuildIncomeOptimizationProfile). What triggers mid-flow questions when new transactions arrive?
   - What's unclear: should new transactions queue interview questions immediately (on CategorizePendingTransactions completion), or only on the next profile rebuild?
   - Recommendation: On `OptimizationProfileBuilt` (existing hook). New transactions trigger a profile rebuild which triggers the detector which queues interview questions. This avoids a new event/listener pair and keeps the data flow linear.

3. **`docs_missing` affordance wording for DOC-07 deferred stub**
   - What we know: P11 ships `docs_missing` array + "you'll be asked to upload X" affordance. P12 wires the vault.
   - What's unclear: what exact UX the affordance should render (tooltip? inline card? alert strip?).
   - Recommendation: Render as a non-blocking inline note on the finding card: "To complete this analysis, you'll be asked to upload [document type]." No vault upload button yet — that's P12.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.3 | All new services | ✓ | 8.3 (per CLAUDE.md) | — |
| PostgreSQL 15+ | JSONB + partial unique index | ✓ | 15+ (per CLAUDE.md) | — |
| Redis | Queue (optimization queue) | ✓ | 7+ (per CLAUDE.md) | Sync driver in dev |
| Anthropic API key | NarrationService | ✓ | existing (ANTHROPIC_API_KEY) | — |
| Pest PHP 3 | All new tests | ✓ | 3 (per CLAUDE.md) | — |

**Missing dependencies with no fallback:** none
**Missing dependencies with fallback:** none

---

## Sources

### Primary (HIGH confidence)
- Direct codebase inspection — all patterns verified against actual PHP/TypeScript files listed in `<files_to_read_for_codebase>`
- `11-CONTEXT.md` — locked decisions D1–D14 (authoritative)
- `enhanced-profile-integration-notes.md` — six locked owner decisions
- `INTEGRATION-MAP.md` — master source→disposition map

### Secondary (MEDIUM confidence)
- `transaction-detection-distilled.md` §§0–14 — implementation-ready detector rules and question trees
- `transaction-detection-v2-delta-distilled.md` §§6–16 — rule schema, paystub plane, penalty sweeps, build order
- `tax-strategy-playbook-distilled.md` + expansion + v2-delta — strategy content referenced for detector question text

### Tertiary (LOW confidence)
- None — all claims grounded in codebase or locked decisions

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — verified by direct file inspection
- Architecture (event wiring, model design, migration patterns): HIGH — verified against existing Phase 10 patterns
- Detector content (question trees, merchant lists): MEDIUM — sourced from distillations which are authoritative but themselves derived from planning docs, not from live codebase
- Config values (dollar thresholds, dates): MEDIUM — aligned with INTEGRATION-MAP.md consolidated config table and existing tax-rules.php

**Research date:** 2026-07-01
**Valid until:** 2026-08-01 (config constants stable; no new IRS guidance expected before then)
