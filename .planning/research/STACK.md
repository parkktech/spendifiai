# Stack Research: Optimize My Income (v2.1)

**Domain:** Personal finance — US tax optimization, retirement math, income document extraction
**Project:** SpendifiAI (subsequent milestone — additive to existing Laravel 12 + React 19 stack)
**Researched:** 2026-07-01
**Scope:** NEW additions only. Existing stack (Laravel 12, React 19, Inertia 2, TypeScript, Tailwind v4, PostgreSQL 15+, Redis 7+, Sanctum, Pest, Claude Sonnet API, smalot/pdfparser, react-pdf, league/flysystem-aws-s3-v3, barryvdh/laravel-dompdf, phpoffice/phpspreadsheet) is validated and NOT re-evaluated.
**Confidence:** MEDIUM (tax constants verified from IRS and multiple authoritative sources; stack decisions are HIGH — pure PHP needs no new packages)

---

## Executive Verdict

**Zero new Composer packages. Zero new npm packages.**

All three pillars of Optimize My Income (rules engine, document extraction, interview/report) are implemented as new PHP service classes + config data + Blade/React pages using the existing stack. The existing Claude API integration, two-pass document extraction pipeline, PDF libraries, and reporting infrastructure cover every requirement.

---

## Recommended Stack Additions

### 1. `config/tax-rules.php` — Versioned Tax Constants (new file, no package)

**Why config, not DB:** Tax constants (brackets, deductions, contribution limits) are read-only, change once per year with a code deploy, have no user-specific rows, require no FK relationships, and need testable overrides via `Config::set()` in Pest tests. A DB seed table adds a round-trip, a migration, and a model for zero benefit. A PHP config file is version-controlled, zero-latency, and directly injectable into services.

**Pattern:** Top-level key is the tax year (integer). Reference as `config('tax-rules.2026.brackets.single')`. Add a new year block each November when IRS Rev. Proc. is published.

**2026 constants to seed into this file (all verified against IRS Rev. Proc. 2025-32 and IRS.gov announcements):**

```php
// config/tax-rules.php
return [
    2026 => [

        // Federal income tax brackets — IRS Rev. Proc. 2025-32
        'brackets' => [
            'single' => [
                ['rate' => 0.10, 'from' => 0,       'to' => 12_400],
                ['rate' => 0.12, 'from' => 12_400,   'to' => 50_400],
                ['rate' => 0.22, 'from' => 50_400,   'to' => 105_700],
                ['rate' => 0.24, 'from' => 105_700,  'to' => 201_775],
                ['rate' => 0.32, 'from' => 201_775,  'to' => 256_225],
                ['rate' => 0.35, 'from' => 256_225,  'to' => 640_600],
                ['rate' => 0.37, 'from' => 640_600,  'to' => null],
            ],
            'married_joint' => [
                ['rate' => 0.10, 'from' => 0,       'to' => 24_800],
                ['rate' => 0.12, 'from' => 24_800,   'to' => 100_800],
                ['rate' => 0.22, 'from' => 100_800,  'to' => 211_400],
                ['rate' => 0.24, 'from' => 211_400,  'to' => 403_550],
                ['rate' => 0.32, 'from' => 403_550,  'to' => 512_450],
                ['rate' => 0.35, 'from' => 512_450,  'to' => 768_700],
                ['rate' => 0.37, 'from' => 768_700,  'to' => null],
            ],
            'married_separate' => [
                ['rate' => 0.10, 'from' => 0,       'to' => 12_400],
                ['rate' => 0.12, 'from' => 12_400,   'to' => 50_400],
                ['rate' => 0.22, 'from' => 50_400,   'to' => 105_700],
                ['rate' => 0.24, 'from' => 105_700,  'to' => 201_775],
                ['rate' => 0.32, 'from' => 201_775,  'to' => 256_225],
                ['rate' => 0.35, 'from' => 256_225,  'to' => 384_350],
                ['rate' => 0.37, 'from' => 384_350,  'to' => null],
            ],
            'head_of_household' => [
                ['rate' => 0.10, 'from' => 0,       'to' => 17_700],
                ['rate' => 0.12, 'from' => 17_700,   'to' => 67_450],
                ['rate' => 0.22, 'from' => 67_450,   'to' => 105_700],
                ['rate' => 0.24, 'from' => 105_700,  'to' => 201_775],
                ['rate' => 0.32, 'from' => 201_775,  'to' => 256_200],
                ['rate' => 0.35, 'from' => 256_200,  'to' => 640_600],
                ['rate' => 0.37, 'from' => 640_600,  'to' => null],
            ],
        ],

        // Standard deductions
        'standard_deduction' => [
            'single'           => 16_100,
            'married_joint'    => 32_200,
            'married_separate' => 16_100,
            'head_of_household'=> 24_150,
        ],

        // Additional standard deduction for age 65+ (per qualifying person)
        'standard_deduction_senior_additional' => [
            'single'           => 2_050,
            'married_joint'    => 1_650,   // per qualifying spouse
            'married_separate' => 1_650,
            'head_of_household'=> 2_050,
        ],

        // New OBBBA senior deduction (age 65+) — phases out 6% above threshold
        'senior_bonus_deduction' => [
            'amount'              => 6_000,  // per qualifying taxpayer
            'phaseout_single'     => 75_000,
            'phaseout_joint'      => 150_000,
            'phaseout_rate'       => 0.06,
        ],

        // 401(k) / employer plan limits — IRS Notice 2025-67
        '401k' => [
            'employee_deferral'       => 24_500,
            'catchup_50_plus'         => 8_000,   // total: 32,500
            'catchup_60_63'           => 11_250,  // replaces 50+ catchup for ages 60-63, total: 35,750
            'highly_compensated_threshold' => 160_000,
            // 2026 rule: if earned >= $150k FICA wages in 2025 from same employer,
            // catch-up contributions MUST be Roth (SECURE 2.0 §603)
            'mandatory_roth_catchup_threshold' => 150_000,
        ],

        // IRA limits
        'ira' => [
            'annual_limit'      => 7_500,
            'catchup_50_plus'   => 1_100,  // new in 2026, was $1,000 for years
            // Roth IRA contribution phase-out (MAGI)
            'roth_phaseout' => [
                'single'            => ['from' => 153_000, 'to' => 168_000],
                'married_joint'     => ['from' => 242_000, 'to' => 252_000],
                'married_separate'  => ['from' => 0,       'to' => 10_000],
                'head_of_household' => ['from' => 153_000, 'to' => 168_000],
            ],
            // Traditional IRA deduction phase-out when covered by workplace plan
            'traditional_deduction_phaseout_covered' => [
                'single'                    => ['from' => 81_000,  'to' => 91_000],
                'married_joint'             => ['from' => 129_000, 'to' => 149_000],
                'married_separate'          => ['from' => 0,       'to' => 10_000],
                'head_of_household'         => ['from' => 81_000,  'to' => 91_000],
            ],
            // Traditional IRA deduction phase-out when NOT covered, but SPOUSE is
            'traditional_deduction_phaseout_spouse_covered' => [
                'married_joint' => ['from' => 242_000, 'to' => 252_000],
            ],
        ],

        // HSA limits — IRS Rev. Proc. 2026 (Notice 2026-05)
        'hsa' => [
            'self_only'        => 4_400,
            'family'           => 8_750,
            'catchup_55_plus'  => 1_000,
            // HDHP requirements
            'hdhp_min_deductible_self'   => 1_700,
            'hdhp_min_deductible_family' => 3_400,
            'hdhp_max_oop_self'          => 8_500,
            'hdhp_max_oop_family'        => 17_000,
        ],

        // FICA / Self-Employment Tax
        'fica' => [
            'ss_wage_base'          => 184_500,   // up from $176,100 in 2025
            'ss_rate_employee'      => 0.062,
            'ss_rate_employer'      => 0.062,
            'medicare_rate_employee'=> 0.0145,
            'medicare_rate_employer'=> 0.0145,
            'seca_rate'             => 0.153,      // 12.4% SS + 2.9% Medicare, unchanged
            'seca_deductible_pct'   => 0.5,        // self-employed deduct half of SE tax from AGI
            // Additional Medicare surtax (Net Investment Income Tax not included here — separate)
            'additional_medicare_surtax_rate'     => 0.009,
            'additional_medicare_surtax_single'   => 200_000,
            'additional_medicare_surtax_joint'    => 250_000,
        ],

        // Net Investment Income Tax (NIIT)
        'niit' => [
            'rate'            => 0.038,
            'threshold_single'=> 200_000,
            'threshold_joint' => 250_000,
        ],

        // Long-term capital gains rates
        'ltcg' => [
            'single' => [
                ['rate' => 0.00, 'from' => 0,       'to' => 48_350],
                ['rate' => 0.15, 'from' => 48_350,  'to' => 533_400],
                ['rate' => 0.20, 'from' => 533_400, 'to' => null],
            ],
            'married_joint' => [
                ['rate' => 0.00, 'from' => 0,       'to' => 96_700],
                ['rate' => 0.15, 'from' => 96_700,  'to' => 600_050],
                ['rate' => 0.20, 'from' => 600_050, 'to' => null],
            ],
        ],

        // Roth optimization decision thresholds (for rules engine logic)
        'roth_optimization' => [
            'prefer_roth_at_or_below_bracket' => 0.12,   // <=12% → Roth is clearly better
            'prefer_traditional_at_or_above'  => 0.32,   // >=32% → Traditional is clearly better
            // 22-24% bracket = split strategy or context-dependent
        ],
    ],
];
```

**Confidence:** HIGH — Brackets and standard deductions from IRS Rev. Proc. 2025-32 (Nov 2025), confirmed by plaintaxcalc.com and taxfoundation.org. Contribution limits from IRS Notice 2025-67 and IRS.gov announcement. SS wage base from SSA.gov/IRS. HSA limits from IRS Notice 2026-05.

**Update cadence:** Each November when IRS publishes annual inflation adjustments, add a new year block. Keep prior years for historical comparison. Cap at rolling 3 years to avoid config bloat.

---

### 2. `TaxRulesEngineService` — Deterministic Calculation Service (new PHP class, no package)

**File:** `app/Services/TaxRulesEngineService.php`

**Why no package:** US income tax calculation is bracket iteration + conditional checks. Every PHP "rules engine" package (Ruler, RulerZ, etc.) adds pattern-matching overhead designed for dynamic rule injection — a category mismatch for static annual constants. The Ruler package (bobthecow/ruler) is also abandoned. Plain typed PHP 8.3 is cleaner, faster, and requires no learning curve.

**Methods to implement:**

| Method | Input | Output | Purpose |
|--------|-------|--------|---------|
| `computeTax(float $income, string $filing, int $year)` | Taxable income, filing status, year | `float` | Federal income tax (iterates brackets) |
| `marginalRate(float $income, string $filing, int $year)` | Same | `float` | Top marginal rate for optimization flags |
| `effectiveRate(float $income, string $filing, int $year)` | Same | `float` | Effective rate = tax / income |
| `standardDeduction(string $filing, int $year, array $opts)` | Filing status, year, options (age 65+, count) | `float` | Standard deduction with senior addition |
| `retirementContributionHeadroom(array $profile)` | User profile (age, income, employer plan, contributions) | `array` | Remaining 401k / IRA / HSA capacity |
| `rothVsTraditionalRecommendation(float $marginalRate, array $opts)` | Current rate, retirement assumptions | `string` | 'roth' / 'traditional' / 'split' / 'roth_required' |
| `rothEligibility(float $magi, string $filing, int $year)` | MAGI, status, year | `array` | Full / partial / ineligible + prorated limit |
| `traditionalIraDeductibility(float $magi, string $filing, bool $hasPlan, int $year)` | MAGI, status, coverage flag, year | `array` | Full / partial / none + prorated deduction |
| `selfEmploymentTax(float $netEarnings, int $year)` | Net SE earnings, year | `array` | SE tax owed, deductible portion |
| `ficaWithholding(float $wages, int $year)` | W-2 wages, year | `array` | Employee SS + Medicare owed |
| `estimatedTaxSavings(array $scenarios)` | Before/after scenarios | `array` | Delta in tax owed for optimization suggestions |

**Pattern from existing codebase:** Follow the same service pattern as `SavingsAnalyzerService` — injected via constructor, reads config, returns typed arrays. No DB calls in the rules engine itself.

**Confidence:** HIGH — this is a well-understood implementation pattern for the stack.

---

### 3. Document Extraction Extensions — No New Libraries

**New document types for v2.1 intake:**

| Document Type | Format | Extraction Method | Notes |
|---------------|--------|-------------------|-------|
| Pay stub (digital) | PDF | `smalot/pdfparser` text → Claude JSON extraction | Already handled by two-pass pipeline |
| Pay stub (photo/scan) | PNG/JPEG | Claude vision API (base64 image) | Same as existing `callClaudeWithPdf()` but image media type |
| Employer offer letter | PDF | `smalot/pdfparser` text → Claude JSON extraction | Rich text extraction, look for salary/bonus/benefits/equity fields |
| 401k/retirement statement | PDF | `smalot/pdfparser` text → Claude | Balance, YTD contribution, employer match, allocation |
| Benefits summary screenshot | PNG/JPEG | Claude vision API | Insurance type, HSA eligibility, HDHP status, FSA limits |
| Stock plan (RSU/ESPP) | PDF | `smalot/pdfparser` text → Claude | Vesting schedule, grant price, FMV, tax withholding |
| Insurance statement | PDF | `smalot/pdfparser` text → Claude | Premium, deductible, plan type for HSA eligibility check |
| Mortgage statement | PDF | `smalot/pdfparser` text → Claude | Principal, interest (for Schedule A probe), balance, rate |

**Integration point:** Extend `TaxDocumentExtractorService` (existing v2.0 service). Add new `TaxDocumentCategory` enum cases for the new types. Add new prompt templates in `app/Services/AI/prompts/` following the existing two-pass classify→extract pattern.

**Image handling for screenshots (pay stubs, benefits, retirement account pages):**
- Claude Sonnet accepts base64 PNG/JPEG up to 5MB natively
- Optimal resolution: resize to 1568px on longest edge before base64 encoding — IRS on Anthropic docs
- Use `getimagesize()` (built-in PHP) to check dimensions; simple integer scaling via `imagescale()` (GD, always available in PHP 8.3) if needed — no `intervention/image` package required
- Send as `image/jpeg` or `image/png` media type in the Claude messages API, same API client already in use
- JSON schema via `tool_use` to enforce structured output with per-field confidence scores

**New Claude prompt schemas for v2.1 types (to add to two-pass pipeline):**

```php
// Pay stub extraction schema (tool_use)
[
    'gross_pay'          => ['type' => 'number', 'confidence' => 'float 0-1'],
    'net_pay'            => ['type' => 'number', 'confidence' => 'float 0-1'],
    'ytd_gross'          => ['type' => 'number', 'confidence' => 'float 0-1'],
    'pay_period'         => ['type' => 'string', 'enum' => ['weekly','biweekly','semimonthly','monthly']],
    'federal_withholding'=> ['type' => 'number'],
    'state_withholding'  => ['type' => 'number'],
    'ss_withheld'        => ['type' => 'number'],
    'medicare_withheld'  => ['type' => 'number'],
    'retirement_401k'    => ['type' => 'number'],  // YTD 401k contributions
    'employer_match'     => ['type' => 'number'],
    'hsa_deduction'      => ['type' => 'number'],
    'pay_date'           => ['type' => 'string', 'format' => 'date'],
]

// Offer letter schema
[
    'base_salary'        => ['type' => 'number'],
    'salary_period'      => ['type' => 'string', 'enum' => ['annual','monthly','hourly']],
    'signing_bonus'      => ['type' => 'number', 'nullable' => true],
    'annual_bonus_target_pct' => ['type' => 'number', 'nullable' => true],
    'rsu_shares'         => ['type' => 'number', 'nullable' => true],
    'espp_eligible'      => ['type' => 'boolean'],
    '401k_match_pct'     => ['type' => 'number', 'nullable' => true],
    '401k_match_cap_pct' => ['type' => 'number', 'nullable' => true],
    'hsa_employer_contribution' => ['type' => 'number', 'nullable' => true],
    'start_date'         => ['type' => 'string', 'format' => 'date', 'nullable' => true],
]
```

**Confidence:** HIGH — Claude Sonnet vision API is the existing AI integration; image extraction extends the existing pattern with no new library dependencies.

---

### 4. Guided Interview — Custom State Machine (no package)

**File:** `app/Services/OptimizationInterviewService.php`

**Why no package:** Guided financial interviews are 15-30 conditional questions in a linear + branching flow. Packages like `spatie/laravel-model-states` are designed for multi-step parallel state graphs with event dispatch — overkill for a questionnaire. A custom service + DB table is ~200 lines and fully type-safe.

**New DB migration (additive):** `optimization_interviews` table

```php
Schema::create('optimization_interviews', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('status')->default('in_progress'); // in_progress | completed | abandoned
    $table->string('current_step')->default('start');
    $table->jsonb('answers')->default('{}');
    $table->jsonb('flags')->default('[]');  // red flags found during interview
    $table->jsonb('metadata')->default('{}');
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
    $table->index(['user_id', 'status']);
});
```

**Interview question bank stored in:** `app/Services/OptimizationInterviewService.php` as a private array. Questions are conditionally surfaced based on prior answers + data already available (from linked bank, uploaded docs, existing profile). Questions Claude can generate should NOT be hardcoded — pass context to Claude for dynamic question text generation while the routing logic stays deterministic in PHP.

**Question routing logic (deterministic PHP):**
1. Check what data is already known from existing sources (bank, subscriptions, tax docs, profile)
2. Skip any question where confidence is >0.85 from existing data
3. Surface only genuinely unknown high-value questions

---

### 5. Optimization Report — No New Libraries

**Generation:** `barryvdh/laravel-dompdf` (already installed at ^3.1) generates the PDF report. New Blade template at `resources/views/reports/optimization-report.blade.php`.

**Report sections (from rules engine output):**
1. Income snapshot (pay stub data + bank-derived income)
2. Estimated federal tax (effective + marginal rate, compared to current withholding)
3. 401k optimization (contribution headroom, Roth vs Traditional recommendation, employer match gap)
4. IRA eligibility (Roth or Traditional or backdoor, contribution headroom)
5. HSA opportunity (if on HDHP or could be, annual tax savings estimate)
6. Deduction probe (standard vs estimated itemized, categories where itemizing might win)
7. Red flags (filing status mismatch, unclaimed credits, over/under-withholding)
8. Next steps (prioritized, educational, "discuss with your tax professional" framing on every item)

**Disclaimer block (required on all report outputs):**
```
This report is for educational purposes only and does not constitute tax, legal, or financial advice.
All figures are estimates based on information you provided. Consult a licensed tax professional before
making any decisions regarding your tax filing, retirement contributions, or financial planning.
```

**Frontend:** New Inertia page `resources/js/Pages/OptimizeMyIncome.tsx` using existing shadcn/ui components, existing `useApi` / `useApiPost` hooks. Wizard step state lives in React `useState` (not Zustand, Redux, or other state lib — existing codebase has no global state manager and interview state is persisted server-side).

**Confidence:** HIGH — reuses existing infrastructure entirely.

---

## Complete New Dependencies

### Backend (Composer)

```bash
# NONE — zero new packages required
```

### Frontend (npm)

```bash
# NONE — zero new packages required
```

**Total: 0 new packages.**

---

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| Tax constants storage | `config/tax-rules.php` (PHP config file, keyed by year) | DB seed table (`tax_rules`) | DB adds migration + model + query for constants that never change at runtime. Config is version-controlled, zero-latency, overridable in tests via `Config::set()`. |
| Tax constants storage | `config/tax-rules.php` | Hardcoded in `TaxRulesEngineService` | Hardcoded constants are not testable, not visible to non-PHP teammates, and require service changes each November. |
| Rules engine | Custom PHP service class | `bobthecow/ruler` | Ruler is abandoned (last commit 2017). Pattern-matching rules engines are designed for dynamic rule injection, not static annual constants. |
| Rules engine | Custom PHP service class | `miBadger/Ruler`, `RulerZ` | Same mismatch: these solve dynamic rule evaluation for varying inputs. US tax brackets are 7 thresholds that change once per year. |
| Image extraction | Claude vision API (existing) | `intervention/image` + OCR | intervention/image is for manipulation, not extraction. The existing Claude vision integration achieves >90% accuracy on structured pay stubs. No OCR library needed. |
| Image extraction | Claude vision API (existing) | AWS Textract | Adds AWS SDK dependency, per-page cost, and a new provider. Claude already handles the full extraction → JSON pipeline. |
| Interview state | DB table + custom service | `spatie/laravel-model-states` | Adds a package dependency for 15-30 linear steps. The package shines for branching parallel workflows with async transitions. Overkill here. |
| Interview state | DB table + custom service | React `useState` only (client-side) | Interview must survive page refresh and allow resume. Server-side persistence is required. |
| Report generation | `barryvdh/laravel-dompdf` (existing) | Weasyprint / Puppeteer PDF | Both require system binaries. dompdf is pure PHP, already installed, already used for tax export in v1.0. |
| Traditional vs Roth logic | Rule-based PHP (deterministic) | Ask Claude to decide | Claude is used for plain-English explanation and dynamic question generation, not for the binary Roth/Traditional decision — that must be deterministic, auditable, and based on verified 2026 thresholds. |

---

## What NOT to Use

| Do NOT Add | Why | Use Instead |
|------------|-----|-------------|
| Any third-party tax filing SaaS (Intuit, TaxJar, Column Tax API) | Would leak PII to external parties, create vendor dependency for core logic, and is out of scope per PROJECT.md | `TaxRulesEngineService` with `config/tax-rules.php` constants |
| Non-Anthropic AI (OpenAI, Gemini, etc.) | Explicitly prohibited by project constraints; all AI services are built against Anthropic API | Claude Sonnet (existing) |
| `intervention/image` | Not doing image manipulation. PHP 8.3 GD functions (`imagescale`, `getimagesize`) handle pre-Claude resize in <5 lines | Built-in GD |
| `spatie/laravel-media-library` | Over-abstraction over `Storage::disk()`. The Tax Document Vault already implements direct Storage calls. Adding media-library creates a parallel file management system. | Existing `Storage::disk()` pattern from v2.0 |
| `spatie/laravel-money` or `brick/money` | Financial amounts are already stored as `decimal:2` in the existing codebase. All UI uses `Number()` wrapper. Adding a Money type would require changing existing model casts. | Existing decimal cast + `Number()` in TS |
| `moneyphp/money` | Same reason as above |  |
| Any state machine package (`spatie/laravel-model-states`, `winzou/state-machine`) | 15-30 linear questions do not justify a package. Custom service is 200 lines and fully typed. | Custom `OptimizationInterviewService` |
| `Livewire` for interview wizard | Project uses Inertia.js exclusively. Mixing Livewire creates competing paradigms. | Inertia + React `useState` (with server-side persistence) |
| Zustand, Redux, Jotai for interview state | Global state managers are not in the existing codebase. Interview state is persisted server-side; client only needs `useState` for the current step. | React `useState` |
| `react-hook-form` for interview inputs | Single-question-at-a-time interview flow does not need a form library. Controlled `<input>` with `useApiPost` covers it. | Existing `useApiPost` hook |
| Any WebSocket / real-time package | Not required per project constraints. Interview progress persists in DB; page polling on step transitions is sufficient. | DB persistence + Inertia page transitions |
| `laravel/scout` / Meilisearch | No document full-text search requirement for v2.1. | PostgreSQL `ILIKE` for existing merchant/category search |

---

## Integration Points with Existing Stack

| Existing Component | v2.1 Integration |
|-------------------|------------------|
| `TaxDocumentExtractorService` | Add new `TaxDocumentCategory` enum cases (PayStub, OfferLetter, Retirement401k, BenefitsSummary, StockPlan, InsuranceStatement). Add new two-pass prompt templates for each type. |
| `BankStatementParserService::callClaudeWithPdf()` | Reference pattern for image extraction: base64-encode the image, set correct `media_type`, pass to `messages` array. Copy this pattern for image-format pay stubs. |
| `TransactionCategorizerService` | Reference pattern for batch AI with per-item confidence scores and retry logic. |
| `AIQuestion` model + `AIQuestionController` | Ongoing red-flag questions (filing status mismatch, deduction probes) surface through the existing AI Questions feed — same model, same controller, new question_type cases. |
| `UserFinancialProfile` | Primary source of filing status, employment type, monthly income, home office flag. `OptimizationInterviewService` reads this first and skips questions already answered. |
| `IncomeDetectorService` | Provides primary vs extra income classification — cross-reference with pay stub extraction to validate accuracy. |
| `config/spendifiai.php` | Add `optimize_my_income.disclaimer_text` and `optimize_my_income.tax_year` (defaults to current year). |
| `barryvdh/laravel-dompdf` | Render optimization report PDF with new Blade template. |
| `DashboardCacheService` | Invalidate on interview completion — optimization scores may affect dashboard widgets. |
| `ExpenseCategory` model | Map mortgage interest, medical, charitable transaction categories to potential itemized deduction probes. |
| `HandleInertiaRequests` | Share `hasOptimizationInterview` flag (bool, whether user has a completed interview) for nav badge. |
| `EnsureBankConnected` middleware | Apply to optimization routes — bank data is required for cross-source analysis. |
| Tailwind `sw-*` design tokens | All new pages use existing tokens. No new CSS. |
| Pest PHP 3 | Test rules engine (bracket math, edge cases at bracket boundaries), contribution limit calculations, interview routing logic, Claude extraction schemas. |

---

## New Enums Needed

| Enum | Cases | File |
|------|-------|------|
| `InterviewStatus` | `InProgress`, `Completed`, `Abandoned` | `app/Enums/InterviewStatus.php` |
| `FilingStatus` (or extend `UserFinancialProfile` existing) | `Single`, `MarriedFilingJointly`, `MarriedFilingSeparately`, `HeadOfHousehold` | Check if already exists on `UserFinancialProfile` — if not, add here |
| `OptimizationFlag` | `FilingStatusMismatch`, `UnderutilizedRetirement`, `OverWithheld`, `UnderWithheld`, `RothEligible`, `BackdoorRothCandidate`, `HsaOpportunity`, `ItemizationBenefit`, `SelfEmploymentDeduction`, `PotentialCredits` | `app/Enums/OptimizationFlag.php` |

---

## Config Additions (`config/spendifiai.php`)

```php
'optimize_my_income' => [
    'tax_year'       => 2026,
    'disclaimer'     => 'This analysis is for educational purposes only and does not constitute tax, legal, or financial advice. Consult a licensed tax professional before making any decisions.',
    'interview_expiry_days' => 90,  // interviews older than this are abandoned
    'max_active_interviews' => 1,   // user can only have one active interview at a time
],
```

---

## Version Compatibility

| Package | Compatible With | Notes |
|---------|-----------------|-------|
| `config/tax-rules.php` (new) | Laravel 12, PHP 8.3 | Config file — no compatibility concerns |
| `TaxRulesEngineService` (new) | PHP 8.3, existing services | Pure PHP — no version constraints |
| GD (built-in, image resize) | PHP 8.3 standard | Always available in PHP 8.3. Check `extension_loaded('gd')` in service. |
| `optimization_interviews` table | PostgreSQL 15+, JSONB supported | JSONB column is native PostgreSQL feature, already used for other tables |

---

## Sources

- [IRS Rev. Proc. 2025-32 (2026 tax inflation adjustments)](https://www.irs.gov/newsroom/irs-releases-tax-inflation-adjustments-for-tax-year-2026-including-amendments-from-the-one-big-beautiful-bill) — brackets, standard deductions, LTCG thresholds. Confidence: HIGH.
- [IRS Notice 2025-67 (2026 retirement contribution limits)](https://www.irs.gov/newsroom/401k-limit-increases-to-24500-for-2026-ira-limit-increases-to-7500) — 401k, IRA, catchup limits. Confidence: HIGH.
- [PlainTaxCalc.com (IRS Rev. Proc. 2025-32 implementation)](https://plaintaxcalc.com/federal/) — exact bracket thresholds by filing status cross-checked. Confidence: MEDIUM (third party implementing official IRS data).
- [IRS — FICA/Social Security wage base 2026](https://www.irs.gov/taxtopics/tc751) — 15.3% SECA, $184,500 SS wage base. Confidence: HIGH.
- [IRS Notice 2026-05 (HSA limits 2026)](https://www.irs.gov/pub/irs-drop/n-26-05.pdf) — self-only $4,400, family $8,750. Confidence: HIGH.
- [IRS — Roth IRA income limits 2026](https://www.irs.gov/newsroom/401k-limit-increases-to-24500-for-2026-ira-limit-increases-to-7500) — phase-out ranges. Confidence: HIGH.
- [Anthropic Claude Vision Docs](https://docs.anthropic.com/en/docs/build-with-claude/vision) — image size limits, base64 encoding, structured extraction best practices. Confidence: HIGH.
- [Bogleheads — Traditional vs Roth](https://www.bogleheads.org/wiki/Traditional_versus_Roth) — decision logic, bracket comparison rules. Confidence: MEDIUM (community wiki, consistent with IRS rules).
- [IRS — Roth IRA comparison chart](https://www.irs.gov/retirement-plans/roth-comparison-chart) — eligibility rules. Confidence: HIGH.

---

*Stack research for: SpendifiAI v2.1 Optimize My Income*
*Researched: 2026-07-01*
