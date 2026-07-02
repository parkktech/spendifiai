# SCENARIOS-SPEC — Part 1: Objective-Driven Data Acquisition & Readiness

> Design for Decision 10.1 (objective-driven data acquisition, per-objective readiness) and the
> data substrate Decisions 9.2 / 10.2 / 10.4 depend on (fact-gated directives, deterministic
> scenario computation, versioned fact citation). Implements against SHIPPED v2.1 machinery —
> every field/signature referenced below was verified in code on 2026-07-02.
>
> Companion parts: Part 2 (scenario computation + conflict surfacing), Part 3 (choice→checklist).
> Educational-only frame (D10.6) and SAFE-01/SAFE-03 (no dollars to Claude) apply throughout.

---

## 0. Verified grounding (shipped code this design builds on)

| Machinery | File | What Part 1 uses |
|---|---|---|
| `IncomeOptimizationProfile` | `app/Models/IncomeOptimizationProfile.php` | 14 encrypted cent columns (`w2_wages`, `traditional_401k_ytd`, `roth_401k_ytd`, `ira_ytd`, `hsa_ytd`, `bank_deposit_total`, …), flags (`filing_status`, `has_hsa_eligible_plan`, `has_ira`, `ira_type`, `has_self_employment`, `employment_type`, `estimated_age` — **column exists, never populated**), `answerableFields(?UserTaxFact)` merge of confirmed fact keys |
| `IncomeOptimizerDataAssemblerService` | `app/Services/IncomeOptimizerDataAssemblerService.php` | `buildProfile(User,$taxYear)` upsert; sources: profile flags → Ready TaxDocuments (incl. PayStub nested-`fields` arm summing `gross_pay`, `traditional_401k_deduction`, `roth_401k_deduction`, `hsa_deduction`) → calendar-year bank deposits; `profile_hash` staleness pattern; `dollarsToCents()` convention |
| `UserTaxFact` | `app/Models/UserTaxFact.php` | `recordFact()` append-only + supersession, `confirmProposal()` D4 gate, `currentFact($userId,$key,$entityId,$taxYear)`, `currentFactKeys()`, volatility + `reconfirm_after`, encrypted `value` (cents-as-string for money) |
| `InterviewOrchestratorService` | `app/Services/InterviewOrchestratorService.php` | `startOrResume()`, `buildInitialQueue()` (auto → conditional → battery; conditional-band queue fix present), `nextQuestion()` (skip-logic via `isAlreadyAnswered`, `GATED_PROBES` INT-04), `createOptimizationQuestion()` (Claude wording only, `BAND_CONFIDENCE`), `recordAnswer()` (fact + encrypted transcript) |
| `InterviewSession` | `app/Models/InterviewSession.php` | `queue`/`asked` JSONB arrays of fact_key strings; `markAsked`, `dequeueKey`, `appendTranscript`; one in_progress per (user, tax_year) partial unique index |
| `InterviewController` | `app/Http/Controllers/Api/InterviewController.php` | routes `optimizer/interview/*`; `next()` response shape; `answer()` writes fact via orchestrator; `AnswerOptimizationQuestionRequest` (`answer: required|string|max:500`) |
| `TaxDocumentExtractorService` | `app/Services/AI/TaxDocumentExtractorService.php` | `PAY_STUB_FIELDS` (21 incl. `pay_period_start/end`, `pay_date`, `gross_pay`, `federal_tax_withheld`, `ytd_gross`, `ytd_federal_tax`, pretax deduction fields), `BENEFITS_GUIDE_FIELDS` (17 incl. `employer_match_formula`, `hdhp_hsa_available`), `RETIREMENT_STATEMENT_FIELDS` (`account_balance`, `ytd_contributions`, `ytd_employer_contributions`) |
| `PaystubFactExtractorService` | `app/Services/AI/PaystubFactExtractorService.php` | `PAYSTUB_FACT_MAP` (4 money facts → `retirement.traditional_401k_ytd_cents`, `retirement.roth_401k_ytd_cents`, `retirement.hsa_ytd_cents`, `benefits.fsa_ytd_cents`), `BENEFITS_FACT_MAP` (15 `employer.*` facts), proposals via `source_type='document_extraction'` (confirm-gated) |
| `UserFinancialProfile` | `app/Models/UserFinancialProfile.php` | `tax_filing_status`, `employment_type`, `has_hsa/fsa/529/ira`, `ira_type`, `spouse_income` (encrypted), `has_childcare_expenses`, `monthly_income` (encrypted) |
| `TaxRulesEngineService` | `app/Services/TaxRulesEngineService.php` | `computeTax`, `marginalRate`, `remaining401kRoomCents`, `remainingIraRoomCents` (shared trad+Roth limit), `remainingHsaRoomCents`, `rothIraEligibility`, `traditionalIraDeductibility`, `selfEmploymentTax` — all cents-in/cents-out from `config/tax-rules.php` |
| `DurableFactsController` | `app/Http/Controllers/Api/DurableFactsController.php` | `index/confirm/supersede`; `value` is `$hidden` — API responses never carry the encrypted value |
| Config | `config/tax-detection.php`, `config/optimization-report.php` | precedent: thresholds/templates live in config, never in service code (D2) |

Existing fact keys already written/read by detectors (verified by grep): `w4.filing_status`,
`profile.filing_status`, `employer.federal_withholding` (annualized cents, per-tax-year),
`employer.match_pct`, `employer.match_threshold_pct`, `employer.contribution_pct`,
`employer.match_formula`, `employer.has_401k`, `employer.hdhp_hsa_available`,
`employer.fsa_available`, `employer.dependent_care_fsa_available`, `employer.after_tax_401k_available`,
`employer.hsa_deduction_ytd`, `hsa.ytd_contribution_cents`, `health.hsa_eligible`,
`ira.traditional_ytd_contribution_cents`, `ira.traditional_contribution_ytd` (legacy variant),
`ira.roth_ytd_contribution_cents`, `ira.balance_range`, `retirement.k401_contribution_ytd_cents`,
`retirement.has_ira_balance`, `family.dependents_count`, `family.qualifying_children_under_17`,
`prior_year.agi_cents`, `prior_year.federal_liability_cents`, `profile.estimated_magi_cents`,
`finance.is_cash_constrained`, `life_event.*` battery keys.

---

## 1. Canonical fact registry (new keys + aliases + value conventions)

### 1.1 Value conventions (unchanged from shipped code)

- **Money**: integer-cents-as-string in `UserTaxFact.value` (e.g. `'150000'` = $1,500.00). Interview
  answers typed in dollars are converted server-side: `(string)(int) round((float)$dollars * 100)`.
- **Booleans**: `'yes'` / `'no'` (PaystubFactExtractorService Pitfall-7 convention).
- **Enums**: snake_case strings matching `config/tax-rules.php` keys (`married_joint`, not `married_jointly` —
  assembler `normaliseFilingStatus()` is the reference normalizer).
- **Volatility**: `permanent` (never re-asked; e.g. birth year), `stable` (12-month reconfirm via
  `isDueForReconfirmation()`), `annual` (per-tax-year; YTD money facts always carry `tax_year`).

### 1.2 NEW fact keys introduced by this spec

| Fact key | Type | Volatility | tax_year? | Meaning |
|---|---|---|---|---|
| `pay.frequency` | enum: `weekly\|biweekly\|semimonthly\|monthly` | stable | null | Paycheck cadence. Periods/yr map in config: 52/26/24/12 |
| `pay.gross_per_period_cents` | money | annual | yes | Gross pay per paycheck |
| `pay.federal_withholding_per_period_cents` | money | annual | yes | Federal income tax withheld per paycheck |
| `w4.dependents_claimed` | integer string | stable | null | Dependents currently claimed on the W-4 at work (NOT the same as `family.dependents_count` — the delta between these two drives the owner's "update your dependents from 0 to 3" directive) |
| `w4.extra_withholding_per_period_cents` | money | stable | null | W-4 Step 4(c) extra withholding |
| `person.birth_year` | year string (`'1985'`) | permanent | null | Drives age math: catch-up limits, years-to-target. Resolver derives `age = tax_year - birth_year` and backfills the never-populated `estimated_age` snapshot column at next assembler run (additive write, see §5.4) |
| `retirement.target_age` | integer string | stable | null | User's stated target retirement age |
| `retirement.statement_balance_cents` | money | annual | yes | Total retirement balance from RETIREMENT_STATEMENT doc extraction (new `PAYSTUB_FACT_MAP`-style mapping, §3.4) |
| `hsa.coverage_type` | enum: `self_only\|family` | stable | null | Selects HSA limit row in `config/tax-rules.php` `hsa.self_only` vs `hsa.family` |
| `income.annual_gross_cents` | money | annual | yes | User-stated expected gross for the year (interview fallback when no W-2/paystub/bank signal) |
| `ira.traditional_ytd_cents_confirmed` | — | — | — | **NOT a new key.** Use existing `ira.traditional_ytd_contribution_cents`; listed here only to flag the alias problem (§1.3) |
| `spouse.annual_income_cents` | money | annual | yes | Spouse gross income (MFJ dual-earner withholding accuracy). Seeded from `UserFinancialProfile.spouse_income` (monthly, encrypted) × 12 as a `derived` source; interview ask only if absent |

All new money facts: encrypted `value` column already handles them; no schema change to `user_tax_facts`.

### 1.3 Alias map (resolver-level — detectors are NOT rewritten; scope discipline rule 8)

The shipped detector fleet uses divergent keys for the same physical fact. The resolver treats the
first column as canonical and falls back through aliases (most recently asserted current row wins):

| Canonical | Aliases (read-only fallback) |
|---|---|
| `retirement.traditional_401k_ytd_cents` | `retirement.k401_contribution_ytd_cents` |
| `hsa.ytd_contribution_cents` | `retirement.hsa_ytd_cents`, `employer.hsa_deduction_ytd` |
| `ira.traditional_ytd_contribution_cents` | `ira.traditional_contribution_ytd` |
| `w4.filing_status` | `profile.filing_status` |

New writes always use the canonical key. Aliases live in `config/optimization-objectives.php`
(`'fact_aliases' => [...]`) so future consolidation is config-only.

---

## 2. FACT-REQUIREMENTS MAP (Decision 10.1)

Three objectives: `take_home`, `tax_burden`, `retirement`. Each required fact carries a
**source-priority chain** — the resolver walks it in order and stops at the first hit:

1. **assembler-known** — `IncomeOptimizationProfile` snapshot column (already aggregates W-2/1099/paystub docs + calendar-year bank deposits)
2. **doc-extraction fact** — confirmed `UserTaxFact` from `PaystubFactExtractorService` proposals (D4 confirm gate already enforced by `currentFact()`)
3. **profile** — `UserFinancialProfile` field
4. **interview ask** — gap question template (§3)

Rule: **every blocking fact has an interview template** — documents/bank data are accelerators,
never the only path. `blocking` = scenario math is impossible or misleading without it.
`optional` = improves precision; the documented default applies when absent (defaults are config
constants read by Part 2's engine methods, never invented by narration).

### 2.1 Shared core (required by all three objectives)

| # | Fact | Blocking | Source chain | Default if optional-missing |
|---|---|---|---|---|
| C1 | `filing_status` | BLOCKING | snapshot `filing_status` → fact `w4.filing_status` (alias `profile.filing_status`) → profile `tax_filing_status` (normalized) → ask | — |
| C2 | Annual gross income (cents) | BLOCKING | snapshot `w2_wages` + `self_employment_income` → derived: paystub `ytd_gross` annualized (§4.3) → snapshot `bank_deposit_total` (flagged `low_precision`) → fact `income.annual_gross_cents` → ask | — |
| C3 | `pay.frequency` | BLOCKING (take_home, retirement); optional (tax_burden) | derived: paystub `pay_period_start/end` span (§4.3) → fact → ask | tax_burden default: `biweekly` |
| C4 | `person.birth_year` | BLOCKING (retirement); optional (take_home, tax_burden) | fact → ask | catch-ups assumed unavailable (conservative: no catch-up headroom shown) |

### 2.2 Objective `take_home` — "more money in your paycheck now"

Levers this data feeds (Part 2): W-4 alignment (filing status + dependents), withholding right-sizing
with safe-harbor guardrail, pre-tax election trims where over-funded, per-paycheck surplus routing.

| # | Fact | Blocking | Source chain |
|---|---|---|---|
| T1 | C1–C3 above | BLOCKING | — |
| T2 | `pay.gross_per_period_cents` | BLOCKING | derived: paystub `gross_pay` (per-stub field) → derived: C2 ÷ periods/yr → ask |
| T3 | Federal withholding, annualized (`employer.federal_withholding`, per-tax-year, cents) | BLOCKING | fact (already written by paystub/interview flows; read by `WithholdingGapDetector`) → derived: paystub `ytd_federal_tax` annualized → derived: `pay.federal_withholding_per_period_cents` × periods/yr → ask (per-paycheck phrasing, §3.2) |
| T4 | `w4.dependents_claimed` | BLOCKING | fact → ask |
| T5 | `family.dependents_count` | BLOCKING | fact (existing key, `RefundableCreditScanner` writes/reads) → ask |
| T6 | Current pre-tax elections YTD: `retirement.traditional_401k_ytd_cents`, `retirement.roth_401k_ytd_cents`, `hsa.ytd_contribution_cents`, `benefits.fsa_ytd_cents` | BLOCKING (confirmed-zero allowed: `'0'`) | snapshot `traditional_401k_ytd`/`roth_401k_ytd`/`hsa_ytd` → fact (confirmed paystub proposals) → ask ("none" option stores `'0'`) |
| T7 | `w4.extra_withholding_per_period_cents` | optional (default `'0'`) | fact → ask |
| T8 | `prior_year.federal_liability_cents` | optional — **safe-harbor guardrail**: without it Part 2 must not recommend reducing withholding below current-year computed tax (conservative floor) | fact (existing, `SafeHarborBenchmark`) → ask |
| T9 | `spouse.annual_income_cents` | optional; only surfaced when C1 = `married_joint` | derived: profile `spouse_income` × 12 → fact → ask |

### 2.3 Objective `tax_burden` — "lower this year's tax bill"

Levers: traditional 401(k)/IRA headroom, HSA headroom, FSA/dependent-care elections, SE deductions,
itemize-vs-standard, credit eligibility data.

| # | Fact | Blocking | Source chain |
|---|---|---|---|
| B1 | C1, C2 | BLOCKING | — |
| B2 | `has_self_employment` | BLOCKING (boolean; both values valid) | snapshot flag → profile `employment_type` derivation (already in assembler) → ask |
| B3 | T6 set (pre-tax YTD, confirmed-zero allowed) | BLOCKING | as T6 |
| B4 | `ira.traditional_ytd_contribution_cents` + `ira.roth_ytd_contribution_cents` | BLOCKING (shared-limit math per D2 — `remainingIraRoomCents` needs the COMBINED total) | snapshot `ira_ytd` (undifferentiated 5498 sum; counts as `known`, not type-split — triggers a split-confirm question when `has_ira` and `ira_type` ∈ {null, both-types-suspected}) → facts → ask |
| B5 | `health.hsa_eligible` | conditionally BLOCKING — required iff HSA lever in play (`employer.hdhp_hsa_available`=yes OR profile `has_hsa` OR `hsa.ytd_contribution_cents`>0) | fact (existing) → profile `has_hsa` (proxy, `known` not `confirmed`) → ask |
| B6 | `hsa.coverage_type` | conditionally BLOCKING — iff B5 = yes | fact → ask (gated, §3.3) |
| B7 | `person.birth_year` | optional (catch-up precision, senior std-deduction) | as C4 |
| B8 | Itemization signals: snapshot `mortgage_interest`, `property_tax_paid`, `charitable_contributions`, `student_loan_interest` | optional (default: standard deduction assumed; `compareStandardVsItemized` runs only when present) | snapshot only — never interview-asked (doc-upload affordance instead, `doc_request_labels` already covers these) |
| B9 | `profile.estimated_magi_cents` | optional (IRA deductibility/Roth phaseout precision; default: MAGI ≈ C2) | fact (existing) → derived: C2 |
| B10 | `family.dependents_count`, `family.qualifying_children_under_17` | optional (credit framing) | facts (existing) → profile `has_childcare_expenses` signal → ask |
| B11 | `prior_year.agi_cents` | optional | fact (existing) → ask |

### 2.4 Objective `retirement` — "more toward retirement"

Levers: match capture (never leave match on the table), trad/Roth split, IRA funding,
catch-ups, mega-backdoor availability, auto-transfer cadence.

| # | Fact | Blocking | Source chain |
|---|---|---|---|
| R1 | C1–C4 (all four; birth year is BLOCKING here) | BLOCKING | — |
| R2 | `retirement.target_age` | BLOCKING | fact → ask |
| R3 | T6 401(k) pair (trad + Roth YTD, confirmed-zero allowed) | BLOCKING | as T6 |
| R4 | B4 IRA pair | BLOCKING | as B4 |
| R5 | `employer.has_401k` | BLOCKING (boolean; `'no'` short-circuits R6) | fact (benefits-guide proposal → confirmed) → ask |
| R6 | Match formula: `employer.match_pct` + `employer.match_threshold_pct` (structured pair) OR `employer.match_formula` (free-text from benefits guide — counts as `known`; structured pair required for `confirmed` directive math) | BLOCKING iff R5 = yes | facts (existing, `EmployerMatchGapDetector` keys) → benefits-guide `employer.match_formula` → ask (two-part battery, §3.2) |
| R7 | `employer.contribution_pct` | BLOCKING iff R5 = yes ("not sure" answer → paystub-upload affordance + falls back to derived: T6 ÷ YTD gross) | fact (existing) → derived → ask |
| R8 | `retirement.statement_balance_cents` | optional — without it, projections are contributions-only illustrations (assumption stated per D9.7) | fact (new doc mapping §3.4) → fact `ira.balance_range` (coarse; `known`) → ask (range choices) |
| R9 | `employer.after_tax_401k_available`, `employer.in_plan_roth_conversion_available` | optional (mega-backdoor lever gate) | facts (benefits-guide) → ask only in specialist follow-up (stays out of gap queue) |
| R10 | `finance.is_cash_constrained` | optional (scenario tie-break input, Part 2) | fact (existing) → ask |

**Out of scope for v2.1 scenarios (explicit):** state income tax (config is federal-only), Social
Security projection, pension/DB plans, 457(b)/403(b) coordination (surface as specialist-band note
when `employer.has_457b` exists), spousal IRA scenarios. Listed so Part 2 states assumptions honestly.

---

## 3. GAP → QUESTION logic (extends the shipped interview, additively)

### 3.1 Where the map lives: `config/optimization-objectives.php` (NEW)

Follows the D2 precedent (config, not service code). Shape:

```php
return [
    'objectives' => [
        'take_home' => [
            'label' => 'Take-home income',
            'facts' => [
                // requirement_id => spec
                'filing_status' => [
                    'canonical_key' => 'w4.filing_status',
                    'blocking' => true,
                    'chain' => ['snapshot:filing_status', 'fact', 'profile:tax_filing_status', 'ask'],
                ],
                'federal_withholding_annual' => [
                    'canonical_key' => 'employer.federal_withholding',
                    'blocking' => true,
                    'chain' => ['fact', 'derive:annualize_ytd_federal_tax',
                                'derive:per_period_times_frequency', 'ask:pay.federal_withholding_per_period_cents'],
                ],
                // ...
            ],
        ],
        'tax_burden' => [ /* ... */ ],
        'retirement' => [ /* ... */ ],
    ],

    'fact_aliases' => [
        'retirement.traditional_401k_ytd_cents' => ['retirement.k401_contribution_ytd_cents'],
        'hsa.ytd_contribution_cents' => ['retirement.hsa_ytd_cents', 'employer.hsa_deduction_ytd'],
        'ira.traditional_ytd_contribution_cents' => ['ira.traditional_contribution_ytd'],
        'w4.filing_status' => ['profile.filing_status'],
    ],

    'pay_periods_per_year' => ['weekly' => 52, 'biweekly' => 26, 'semimonthly' => 24, 'monthly' => 12],

    'question_templates' => [
        'pay.frequency' => [
            'question' => 'How often are you paid?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'weekly', 'label' => 'Every week'],
                ['value' => 'biweekly', 'label' => 'Every 2 weeks'],
                ['value' => 'semimonthly', 'label' => 'Twice a month (e.g. 1st & 15th)'],
                ['value' => 'monthly', 'label' => 'Once a month'],
            ],
            'volatility' => 'stable',
            'label' => 'Pay frequency',
        ],
        'w4.dependents_claimed' => [
            'question' => 'How many dependents do you currently claim on the W-4 form you filed with your employer? (This is what payroll uses — it may differ from your actual dependents.)',
            'answer_type' => 'integer', 'min' => 0, 'max' => 15,
            'volatility' => 'stable',
            'label' => 'Dependents claimed on W-4',
        ],
        'person.birth_year' => [
            'question' => 'What year were you born? We use this only to compute contribution limits and retirement timelines.',
            'answer_type' => 'year', 'min' => 1920,
            'volatility' => 'permanent',
            'label' => 'Birth year',
        ],
        'retirement.target_age' => [
            'question' => 'At what age would you like to be able to retire?',
            'answer_type' => 'integer', 'min' => 40, 'max' => 80,
            'volatility' => 'stable',
            'label' => 'Target retirement age',
        ],
        'pay.federal_withholding_per_period_cents' => [
            'question' => 'How much federal income tax is withheld from each paycheck? (Look for "Federal Income Tax" or "Fed W/H" on your pay stub — or upload a pay stub and we\'ll read it for you.)',
            'answer_type' => 'money_dollars',
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Federal withholding per paycheck',
            'doc_affordance' => 'pay_stub',   // renders "upload instead" CTA
        ],
        'hsa.coverage_type' => [
            'question' => 'Is your health plan coverage for just you, or does it cover your family?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'self_only', 'label' => 'Just me'],
                ['value' => 'family', 'label' => 'My family'],
            ],
            'volatility' => 'stable',
            'label' => 'HSA coverage type',
            'prerequisite' => 'health.hsa_eligible',   // merged into GATED_PROBES at runtime (§3.3)
        ],
        'retirement.traditional_401k_ytd_cents' => [
            'question' => 'So far this year, roughly how much have you put into your Traditional (pre-tax) 401(k)? Enter 0 if none.',
            'answer_type' => 'money_dollars', 'allow_zero' => true,
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Traditional 401(k) YTD (stated)',
            'doc_affordance' => 'pay_stub',
        ],
        // ... one template per askable fact in §2 (full list in implementation task) ...
    ],
];
```

Key properties:

- **Deterministic templates — NO Claude call for gap questions.** These are profile-data asks with
  fixed phrasing and enumerated/typed answers. `createOptimizationQuestion()` gets an additive
  early branch: if `$factKey` exists in `config('optimization-objectives.question_templates')`,
  build the `AIQuestion` from the template (question text, options carry
  `choices`/`answer_type`/`objective_tags`) and skip `wordQuestion()` entirely. Cheaper, safe by
  construction, and immune to SAFE-03 drift. Finding-driven questions keep the existing Claude path.
- **Objective tagging** lives in `AIQuestion.options.objective_tags` (e.g. `['retirement','take_home']`,
  computed as: all objectives whose requirement map contains the fact) and in the config map.
  `InterviewSession.queue` stays a plain string array — **no schema change**; back-compat total.
- **`answer_type` drives server-side conversion** in `recordAnswer()` (additive param or template
  lookup by fact key): `money_dollars` → cents-string; `integer`/`year` → digit-string validation;
  `choice` → must match a template choice value. Validation added in
  `AnswerOptimizationQuestionRequest::rules()` stays `answer: required|string|max:500` (unchanged);
  the orchestrator does typed validation against the template and 422s on mismatch.

### 3.2 Enqueue mechanics (`ObjectiveReadinessService::enqueueGaps()`)

```
enqueueGaps(int $userId, int $taxYear, string $objective): array
  1. $session = orchestrator->startOrResume($userId, $taxYear)          // shipped, idempotent
  2. $missing = readiness($userId, $taxYear)[$objective]['blocking_missing']
                (+ 'confirm_needed' suggested-confirms, §3.5)
  3. $keys = fact keys with templates, minus ($session->queue ∪ $session->asked ∪ answered facts)
  4. FRONT-INSERT: $session->update(['queue' => array_values(array_unique(
         array_merge($keys, $session->queue ?? [])))])
     — gap keys go BEFORE pending finding keys so "answer 2 more questions" is immediate.
     Battery questions remain last by construction (they were already at the tail).
  5. return ['enqueued' => $keys, 'queue_size' => count($session->fresh()->queue)]
```

- Gap enqueues are **not** subject to `interview.initial_cap` (that cap governs the auto-finding
  seed in `buildInitialQueue()` only; user explicitly requested these questions).
- Multi-part asks (match formula = `employer.match_pct` + `employer.match_threshold_pct`) enqueue
  as consecutive keys; the second is prerequisite-gated on the first so ordering survives skips.
- Re-entrancy safe: keys already answered are filtered by the shipped `isAlreadyAnswered()` at pop
  time even if a race sneaks one into the queue.

### 3.3 Prerequisite gating (extends INT-04)

`GATED_PROBES` is a private const today. Additive change: orchestrator merges the const with
`config('optimization-objectives.question_templates.*.prerequisite')` pairs at construction
(`array_merge` — const entries win on collision). New gates introduced here:

| Gated key | Prerequisite |
|---|---|
| `hsa.coverage_type` | `health.hsa_eligible` |
| `employer.match_pct` | `employer.has_401k` |
| `employer.match_threshold_pct` | `employer.match_pct` |
| `employer.contribution_pct` | `employer.has_401k` |

Semantics note: shipped `isPrerequisiteUnsatisfied()` skips (drops) the key when the prerequisite is
unmet. For gap flows that's wrong for `employer.has_401k`='no' (dependent keys should be *resolved
as not-applicable*, not re-asked forever). Additive rule in the readiness computation: when a
prerequisite fact is answered `'no'`, dependent blocking facts flip to state `not_applicable` and
count as resolved. The orchestrator's skip behavior is untouched.

### 3.4 Doc-extraction accelerators (additive `PaystubFactExtractorService` maps)

- Add `RETIREMENT_STATEMENT_FACT_MAP` (new const, same shape as `PAYSTUB_FACT_MAP`):
  `account_balance` → `retirement.statement_balance_cents` (money, annual),
  `ytd_contributions` → `retirement.k401_contribution_ytd_cents`-compatible? **No** — statement
  contributions are ambiguous across account types; map to `retirement.statement_ytd_contributions_cents`
  (metadata carries `account_type`) and let the resolver use it only as a `known` cross-check, never
  as the canonical 401(k) YTD.
- Extend `PAYSTUB_FACT_MAP` (additive entries): `federal_tax_withheld` →
  `pay.federal_withholding_per_period_cents`, `gross_pay` → `pay.gross_per_period_cents`
  (both money, annual, tax_year-scoped). Pay-frequency derivation stays in the resolver (§4.3),
  not the extractor (it needs cross-field date math, not a 1:1 field map).
- All of these remain **proposals** (`source_type='document_extraction'`, D4 confirm gate). Nothing
  about the proposal/confirm pipeline changes.

### 3.5 Suggested-confirm for known-but-unconfirmed values (auto band reuse)

When the resolver finds a value from the **snapshot** or a **profile field** (math-capable but not
user-confirmed — see §5.1 two-tier model), the gap queue can include a one-tap confirm question:

- Created via the template branch with `band='auto'`, `ai_confidence=1.0` (shipped `BAND_CONFIDENCE`
  pattern) and `options.prefill_source = 'snapshot:w2_wages'` (a POINTER, never the value —
  `AIQuestion.options` is unencrypted JSONB, so dollar values must not be persisted there).
- `InterviewController::next()` resolves the pointer at read time and adds transient response fields
  `prefill_display` (formatted, e.g. `"$72,500"`) and `prefill_value` (raw string) — computed
  per-request over the authed API, never stored.
- Answering `confirm` makes the orchestrator resolve the pointer server-side at answer time and
  `recordFact()` the resolved value with `source_type='interview_answer'` (user-confirmed provenance).
  Answering with a replacement value records that instead. Either way the fact graduates to `confirmed`.

---

## 4. Resolution engine — `App\Services\ScenarioFactResolverService` (NEW)

### 4.1 Placement decision: new service, not an assembler extension

The assembler is a **write-side snapshot builder** ("ZERO Claude / pure DB aggregation" building
`IncomeOptimizationProfile` rows). Resolution/readiness is a **read-side, query-time join** across
snapshot + facts + profile that must never mutate the snapshot and must reflect a fact confirmed
seconds ago (no rebuild latency). Mixing them would also put readiness behind the
`BuildIncomeOptimizationProfile` job cadence. So: new service; it *consumes* the assembler's product
and mirrors its conventions (cents-as-string, `normaliseFilingStatus`, `dollarsToCents`).

### 4.2 Public API

```php
final class ScenarioFactResolverService
{
    /** Resolve every fact in the union of all objective requirement maps. */
    public function resolveAll(User $user, int $taxYear): array;          // fact_key => ResolvedFact|null

    /** Resolve one canonical fact through its source chain (incl. aliases + derivations). */
    public function resolve(User $user, int $taxYear, string $canonicalKey): ?array;

    /** Freeze the current resolution into a versioned, citable ScenarioFactSet row (§6.2). */
    public function snapshotFactSet(User $user, int $taxYear): ScenarioFactSet;

    /** Staleness check mirroring IncomeOptimizerDataAssemblerService::isStale(). */
    public function isStale(ScenarioFactSet $set): bool;
}
```

`ResolvedFact` shape (plain array; encrypted only when persisted in a fact set):

```php
[
    'fact_key'   => 'employer.federal_withholding',
    'value'      => '842000',                    // cents-string / enum / yes-no / digit-string
    'value_type' => 'money_cents',
    'source'     => 'derived',                   // snapshot | fact | profile | derived
    'source_ref' => 'derived:annualize_ytd_federal_tax(doc:512)',  // provenance pointer
    'confirmed'  => false,                       // §5.1 two-tier flag
    'resolved_at'=> '2026-07-02T14:03:11Z',
]
```

`source_ref` grammar: `fact:{id}` · `snapshot:{profile_id}:{column}` · `profile:{profile_id}:{field}`
· `derived:{rule}({inputs})`. This is what Part 2 scenarios cite (D10.6 "grounded in user-confirmed
facts") and what D9.2 fact-gating reads.

Fact lookups follow the shipped detector pattern:
`currentFact($userId, $key, null, $taxYear) ?? currentFact($userId, $key)` — year-scoped first,
then unscoped — across canonical key then aliases; most recent `asserted_at` wins on multi-hit.

### 4.3 Derivation rules (deterministic, config-parameterized, engine-adjacent)

| Rule id | Computation | Inputs |
|---|---|---|
| `annualize_ytd_gross` | `ytd_gross ÷ elapsed_year_fraction(pay_date)` | latest Ready PayStub doc `fields.ytd_gross.value`, `fields.pay_date.value` |
| `annualize_ytd_federal_tax` | same, over `ytd_federal_tax` | latest Ready PayStub |
| `per_period_times_frequency` | `per_period_cents × periods_per_year[pay.frequency]` | resolved `pay.*` facts |
| `frequency_from_paystub` | pay-period span days → 6–8: weekly · 12–15: biweekly · 15–16 w/ 1st/15th anchors: semimonthly · 27–32: monthly; two+ stubs: median gap between `pay_date`s wins | PayStub `pay_period_start/end`, `pay_date` |
| `age_from_birth_year` | `tax_year − birth_year` | `person.birth_year` |
| `spouse_annual_from_profile` | `monthly spouse_income × 12` | `UserFinancialProfile.spouse_income` |
| `contribution_pct_from_ytd` | `401k_ytd ÷ ytd_gross` | resolved facts |

All derivations produce `source='derived'`, `confirmed=false`, and record their input refs. Ambiguous
frequency spans (13–16 days) resolve to `null` → falls through to the interview ask. Dollar math is
plain deterministic arithmetic (assembler-style); anything touching brackets/limits stays in
`TaxRulesEngineService` (Part 2).

### 4.4 What the resolver never does

- Never calls Claude (readiness/resolution is 100% deterministic — Claude only ever words things).
- Never writes `UserTaxFact` rows (except via the interview answer path; §5.2 keeps the store
  free of duplicated snapshot/profile data).
- Never returns decrypted values through any API endpoint (§7 responses are keys/labels/states only).

---

## 5. Persistence & provenance rules

### 5.1 Two-tier resolution: `known` vs `confirmed`

- **`known`** — value resolvable from snapshot / unconfirmed doc-sum / profile / derivation.
  Sufficient for **scenario math** (detectors already compute on snapshot values; same standard).
- **`confirmed`** — value traced to `fact:` provenance with user assent: `interview_answer`,
  `user_edit`, or confirmed `document_extraction` (`confirmProposal`). Required for **D9.2
  fact-gated directives**: a checklist step may render as an imperative ("Contact payroll and change
  your W-4 from Head of Household to Married Filing Jointly") ONLY when every fact it anchors to is
  `confirmed`; otherwise the step renders as the confirmation ask.

Objective readiness (§6) gates on `known`. Directive rendering (Part 3) gates on `confirmed`.
The readiness API exposes both so the UI can show "2 to answer · 3 to confirm".

### 5.2 No duplication into the facts store

Profile fields and snapshot columns are NOT copied into `UserTaxFact` during resolution (the store
stays append-only user-assertion territory; Decision 1 keeps profile the anchor). A value enters the
store only through: interview answer (incl. suggested-confirm), user edit in Settings
(`DurableFactsController::supersede`), or confirmed doc proposal. This keeps `answerableFields()`
semantics intact and avoids circular provenance.

### 5.3 Interview answers (unchanged path, typed conversion added)

`InterviewOrchestratorService::recordAnswer()` remains the single write path for interview facts.
Additive behavior: before `recordFact()`, look up the template for `$factKey`; if
`answer_type='money_dollars'`, convert to cents-string; set `volatility` and `taxYear` from the
template (`tax_year_scoped: true` → session's tax year, else null). Label from template. Transcript
append unchanged (encrypted `assertions`).

### 5.4 `estimated_age` backfill (additive assembler touch)

`IncomeOptimizationProfile.estimated_age` exists but is never populated. Additive: assembler's
`buildProfile()` sets it from `UserTaxFact::currentFact($userId, 'person.birth_year')` when present
(`taxYear − birth_year`). No column/API change; detectors that ignore it keep ignoring it.

---

## 6. Readiness model & versioned fact sets

### 6.1 `App\Services\ObjectiveReadinessService` (NEW, thin — consumes the resolver)

```php
final class ObjectiveReadinessService
{
    public function __construct(
        private readonly ScenarioFactResolverService $resolver,
        private readonly InterviewOrchestratorService $orchestrator,
    ) {}

    /** Per-objective readiness DTO for the UI + Part 2 gate. */
    public function readiness(User $user, int $taxYear): array;

    /** §3.2 — front-inserts gap questions for one objective into the interview session. */
    public function enqueueGaps(User $user, int $taxYear, string $objective): array;
}
```

Per-objective computation over the §2 map + `resolveAll()` output:

- `blocking_missing` — blocking facts with no resolution (and not `not_applicable` per §3.3).
- `confirm_needed` — blocking facts resolved but `confirmed=false` (drives suggested-confirms; does
  NOT block readiness).
- `optional_missing` — unresolved optional facts (each with its documented default).
- `ready` = `count(blocking_missing) === 0`.
- `questions_to_unlock` = count of distinct question templates covering `blocking_missing`
  (multi-part asks count each part; facts satisfiable only by derivation don't exist — every
  blocking fact has a template by rule §2).
- `completeness_pct` = `round(100 × (2·resolved_blocking + resolved_optional) ÷ (2·total_blocking + total_optional))`
  (blocking double-weighted; `not_applicable` counts as resolved). Purely presentational.

**No caching** in v1: the computation is a handful of indexed lookups (one snapshot row, one
`currentFact` batch, one profile row). If profiling ever demands it, cache 60s keyed
`objective-readiness:{user}:{year}` and invalidate where `MarkOptimizationReportStale` already hooks.
Readiness must reflect an answer given one second ago — the interview loop re-fetches it per answer.

### 6.2 Versioned fact set — `ScenarioFactSet` model + migration (NEW, additive)

Scenario results (Part 2) must cite exactly the facts they used (D10.6, D9.2). Pattern mirrors the
assembler's `profile_hash`.

```php
// database/migrations/2026_07_XX_XXXXXX_create_scenario_fact_sets_table.php  (forward-only, additive)
Schema::create('scenario_fact_sets', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // GDPR cascade
    $table->integer('tax_year');
    $table->string('fact_set_hash', 64);      // HMAC-SHA256, see below
    $table->text('resolved_facts');           // encrypted JSON: fact_key => ResolvedFact
    $table->timestamps();
    $table->index(['user_id', 'tax_year']);
});
```

Model: `resolved_facts` cast `'encrypted'` (TEXT + manual json_encode/decode — the
`InterviewSession.assertions` precedent, avoiding `encrypted:array` double-encode), `$hidden` on
`resolved_facts`, `scopeForUser()`.

- `snapshotFactSet()` freezes `resolveAll()` output; Part 2 scenario rows carry `fact_set_id` FK.
- `fact_set_hash = hash_hmac('sha256', canonical_json(fact_key => [source_ref, value]), config('app.key'))`.
  HMAC (not bare SHA-256) because money values have a small search space — a bare hash of
  `'842000'`-style inputs is brute-forceable from the unencrypted hash column.
- `isStale($set)`: recompute current hash, compare — same contract as the assembler's `isStale()`.
  Part 2 marks dependent scenarios stale (listener pattern already exists:
  `MarkOptimizationReportStale`).
- Citation surface: report/checklist render "Based on N facts you provided" with fact
  labels + source types (never values) — all fields already non-hidden on `UserTaxFact`.

### 6.3 UX contract — "answer 2 more questions to unlock Retirement optimization"

Surface: `resources/js/Pages/Optimize/Index.tsx` gains an **Objectives panel** (three cards; full
visual spec in the Part 2/3 UI contract — Decision 6/7 skills apply). Behavioral contract fixed here:

1. Card per objective: label, state chip — `Ready` (green, sw-success) · `N questions to unlock`
   (accent CTA) · `Confirm M values` (secondary chip when `ready && confirm_needed > 0`).
2. Copy pattern (owner's verbatim target): *"Take-home optimization ready · answer 2 more questions
   to unlock Retirement optimization."* Rendered from `ready` + `questions_to_unlock` only — the
   frontend never computes readiness.
3. CTA tap → `POST /optimizer/objectives/{year}/{objective}/enqueue` → scroll/route into the
   existing `InterviewCard` flow (`/{interview}/next` loop). On each recorded answer the panel
   re-fetches readiness; count visibly ticks down (2 → 1 → Ready) — this feedback loop is the
   acquisition engine.
4. When all three ready: panel collapses into the scenario comparison entry point (Part 2).
5. Doc affordance: templates with `doc_affordance` render an "upload a pay stub instead" secondary
   action linking the existing vault upload flow; on doc Ready + proposal confirm, the question
   auto-skips via shipped `isAlreadyAnswered()`.

---

## 7. API shape (all NEW routes additive, under the shipped `optimizer/` prefix, auth:sanctum)

```
GET  /api/v1/optimizer/objectives/{year}                     throttle:60,1
POST /api/v1/optimizer/objectives/{year}/{objective}/enqueue throttle:10,1
```

Controller: `App\Http\Controllers\Api\OptimizationObjectiveController` (NEW; `{objective}` validated
against `config('optimization-objectives.objectives')` keys → 404 otherwise).

`GET /objectives/{year}` response — **keys, labels, states only; never values** (encryption/$hidden
discipline; readiness must be safe to log):

```json
{
  "tax_year": 2026,
  "objectives": {
    "take_home": {
      "label": "Take-home income",
      "ready": true,
      "completeness_pct": 88,
      "questions_to_unlock": 0,
      "blocking": [
        {"fact_key": "w4.filing_status", "label": "Filing status", "state": "confirmed", "source": "fact"},
        {"fact_key": "employer.federal_withholding", "label": "Federal withholding", "state": "known", "source": "derived"}
      ],
      "confirm_needed": [
        {"fact_key": "employer.federal_withholding", "label": "Federal withholding"}
      ],
      "optional_missing": [
        {"fact_key": "prior_year.federal_liability_cents", "label": "Last year's federal tax", "default_note": "safe-harbor guardrail applied conservatively"}
      ]
    },
    "tax_burden":  { "ready": false, "questions_to_unlock": 1, "...": "..." },
    "retirement":  { "ready": false, "questions_to_unlock": 2, "...": "..." }
  }
}
```

`POST .../enqueue` response:

```json
{
  "session": {"id": 41, "tax_year": 2026, "status": "in_progress"},
  "enqueued": ["person.birth_year", "retirement.target_age"],
  "queue_size": 5,
  "message": "2 questions added to your interview."
}
```

`InterviewController::next()` — additive response fields only (existing keys untouched):
`objective_tags`, `answer_type`, `choices` (from template), `prefill_display`/`prefill_value`
(transient, §3.5), `doc_affordance`.

---

## 8. Implementation touch-points (complete file list)

| # | File | Change |
|---|---|---|
| 1 | `config/optimization-objectives.php` | NEW — objectives map, aliases, templates, periods-per-year, prerequisites |
| 2 | `app/Services/ScenarioFactResolverService.php` | NEW — §4 |
| 3 | `app/Services/ObjectiveReadinessService.php` | NEW — §6.1 |
| 4 | `app/Models/ScenarioFactSet.php` + migration `create_scenario_fact_sets_table` | NEW — §6.2 (forward-only) |
| 5 | `app/Http/Controllers/Api/OptimizationObjectiveController.php` | NEW — §7 |
| 6 | `routes/api.php` | ADD two routes in the existing `optimizer/` group |
| 7 | `app/Services/InterviewOrchestratorService.php` | ADDITIVE: template branch in `createOptimizationQuestion()` (config template → no Claude); config-merged gate map alongside `GATED_PROBES`; typed answer conversion + template-driven volatility/taxYear in `recordAnswer()`; `prefill_source` resolution on confirm answers. No existing behavior removed |
| 8 | `app/Http/Controllers/Api/InterviewController.php` | ADDITIVE response fields in `next()` (§7) |
| 9 | `app/Services/AI/PaystubFactExtractorService.php` | ADDITIVE map entries: `PAYSTUB_FACT_MAP` += `federal_tax_withheld`, `gross_pay`; NEW `RETIREMENT_STATEMENT_FACT_MAP` (§3.4) |
| 10 | `app/Services/IncomeOptimizerDataAssemblerService.php` | ADDITIVE: `estimated_age` backfill from `person.birth_year` (§5.4) |
| 11 | `resources/js/Pages/Optimize/Index.tsx` + new `Components/SpendifiAI/ObjectiveReadinessPanel.tsx` | Objectives panel per §6.3 contract (visual spec in later part; Decision 6/7 skill workflow mandatory) |
| 12 | `app/Policies/` | none needed — new endpoints operate on the authed user only (no route-model binding on user-owned models beyond session, already policied) |

Explicitly UNTOUCHED: `UserTaxFact` model, `DurableFactsController`, `answerableFields()`,
`buildInitialQueue()` ordering, all detectors, `UserFinancialProfile` API, existing migrations.

### Test plan (Pest, additive)

1. Resolver source-priority: snapshot beats fact beats profile beats ask; alias fallback; year-scoped-then-unscoped fact lookup.
2. Derivations: frequency-from-paystub span table (incl. ambiguous → null); YTD annualization; confirmed-zero handling.
3. Readiness: blocking vs optional; `not_applicable` via prerequisite='no'; `questions_to_unlock` counts; conditional-blocking (B5/B6, R6/R7).
4. Enqueue: front-insert order; dedupe vs queue/asked/answered; battery stays last; cap not applied; idempotent double-POST.
5. Template questions: no HTTP call to Anthropic (assert `Http::fake` untouched); typed answer conversion (money→cents); choice validation 422; volatility/tax_year from template.
6. Suggested-confirm: `prefill_source` pointer never persists a value in `options`; confirm answer records resolved value as `interview_answer`.
7. FactSet: HMAC hash stability; `isStale()` flips on fact supersession; `resolved_facts` hidden + encrypted; GDPR cascade.
8. API: readiness response contains no numeric money values (regression guard: assert no `/\d{4,}/` in payload besides year/ids).

---

## 9. Open questions for Part 2 handoff

1. Which engine extensions Part 2 adds to `TaxRulesEngineService` (per-paycheck withholding delta,
   match-capture benefit, illustration growth constants in `config/tax-rules.php` per D9.7) —
   the resolver's `ResolvedFact` value conventions were chosen to feed those signatures cents-native.
2. Whether the Balanced scenario (D10.5) needs any facts beyond the union of the three maps
   (current answer: no — it's a knob blend over the same fact set).
3. Consolidating the alias keys (§1.3) into canonical writes across detectors is deliberately
   deferred — owner sign-off required before touching shipped detector code (scope rule 8).
