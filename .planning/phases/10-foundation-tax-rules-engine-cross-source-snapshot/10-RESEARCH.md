# Phase 10: Foundation — Tax Rules Engine & Cross-Source Snapshot - Research

**Researched:** 2026-07-01
**Domain:** Deterministic PHP tax math engine + per-user financial snapshot assembly (no AI)
**Confidence:** HIGH (stack and existing codebase verified by direct inspection; 2026 IRS constants from authoritative sources; architecture patterns follow proven codebase conventions)

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| TAX-01 | Year-versioned `config/tax-rules.php` holding all 2026 IRS constants (brackets, standard deduction, 401k/IRA/HSA limits, SE tax, Roth phase-outs, QBI thresholds) — single source of truth | Full config structure documented in Standard Stack section; all 2026 values with citations |
| TAX-02 | `TaxRulesEngineService` computes marginal + effective federal tax rate from taxable income + filing status, reading only from config (zero Claude calls) | Method contracts in Architecture Patterns; bracket iteration algorithm documented |
| TAX-03 | Engine computes standard-vs-itemized comparison | Algorithm documented: compare summed itemized fields from snapshot vs standard deduction by filing status |
| TAX-04 | Engine computes remaining 401(k)/IRA/HSA contribution headroom including age-based catch-up | Headroom formulas documented per IRS Notice 2025-67 + IRS Notice 2026-05 with all catch-up tiers |
| TAX-05 | Engine produces deterministic Traditional-vs-Roth recommendation band (≤12% Roth, ≥32% Traditional, middle split) + SECURE 2.0 mandatory-Roth-catch-up flag | Band logic documented; §603 flag algorithm documented with threshold caveat |
| TAX-06 | Engine surfaces QBI deduction eligibility and SE-tax deduction where applicable | QBI 2026 thresholds documented; SE tax calculation algorithm documented |
| TAX-07 | Pest tests asserting exact matches to config values at bracket boundaries | Test map in Validation Architecture section; 6 test classes specified with exact command |
| CTX-01 | `IncomeOptimizerDataAssemblerService` assembles per-user snapshot from existing sources (no Claude) | Source reading strategy documented: 4 sources × field mapping |
| CTX-02 | Snapshot persisted in new `IncomeOptimizationProfile` cache model, rebuilt via background job | Full schema documented; migration pattern (additive, encrypted TEXT columns); job pattern |
| CTX-03 | `CrossSourceReviewService` compares documents vs bank deposits vs email deterministically (Claude only explains discrepancy) | Discrepancy algorithm documented: tolerance thresholds, comparison logic, finding creation |
| CTX-04 | Interview and detectors skip anything already answerable from the snapshot | "Answerable" fields enumerated; snapshot field → skip-logic mapping documented |
</phase_requirements>

---

## Summary

Phase 10 is the load-bearing foundation for every subsequent Optimize My Income phase. It delivers two things:

**1. A deterministic tax math engine** (`TaxRulesEngineService`) that reads exclusively from a year-versioned `config/tax-rules.php` and computes effective/marginal rates, contribution headroom, standard-vs-itemized comparison, Roth eligibility, SE tax, and QBI deduction. No Claude calls. No magic numbers in service code. A single config file that any developer can update each November when IRS publishes inflation adjustments.

**2. A per-user financial snapshot** (`IncomeOptimizationProfile`) assembled by `IncomeOptimizerDataAssemblerService` reading the existing v2.0 vault (TaxDocument.extracted_data), bank transactions via the existing IncomeDetectorService, and UserFinancialProfile. The snapshot is persisted as an encrypted cache model rebuilt by a background job. `CrossSourceReviewService` then compares the snapshot's document-sourced figures against bank deposits and flags deterministic discrepancies.

**Primary recommendation:** Build `TaxRulesEngineService` and `config/tax-rules.php` first (no DB dependencies), write all 6+ Pest tests against them immediately, then add the snapshot models and assembler, then the CrossSourceReviewService. The rules engine tests act as a correctness contract before any downstream code (Phase 11 detectors, Phase 12 report) relies on the math.

**Critical constraint from milestone research:** All 2026 IRS figures must live in `config/tax-rules.php`. No hardcoded dollar amounts in service class code. Verify via `grep -r "24_500\|7_500\|4_400\|8_750\|24500\|7500\|4400\|8750" app/Services/` to confirm zero leakage after implementation.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Tax bracket math / rate lookup | API / Backend (PHP service) | — | Pure deterministic PHP; no frontend math, no AI |
| Contribution headroom calculation | API / Backend (PHP service) | — | IRS limits are constants; computed from config + user snapshot |
| Standard vs itemized comparison | API / Backend (PHP service) | — | Arithmetic on snapshot fields |
| Roth vs Traditional recommendation band | API / Backend (PHP service) | — | Deterministic rule on marginal rate; Claude writes the narrative later (Phase 12) |
| SE tax and QBI deduction surfacing | API / Backend (PHP service) | — | Conditional logic on employment_type + income from snapshot |
| Financial snapshot assembly | API / Backend (job + service) | — | Reads encrypted DB records and aggregates; no client involvement |
| Snapshot persistence (IncomeOptimizationProfile) | Database / Storage | — | New table with encrypted TEXT income columns; SHA-256 staleness hash |
| Cross-source discrepancy detection | API / Backend (PHP service) | — | Arithmetic comparison with tolerance; Claude called only for description (Phase 11) |
| Snapshot staleness detection | API / Backend (service) | — | profile_hash SHA-256 of inputs; compare on read |
| Config/constants storage | API / Backend (config file) | — | PHP config — version-controlled, zero-latency, testable via Config::set() |

---

## Standard Stack

### Core (all existing — zero new packages)
| Component | Version | Purpose | Why Standard |
|-----------|---------|---------|--------------|
| Laravel 12 | existing | Framework, config, jobs, events | Existing project framework |
| PHP 8.3 | existing | Language for pure-PHP tax bracket iteration | Named arguments, typed properties, match expressions |
| PostgreSQL 15+ | existing | Persistence for IncomeOptimizationProfile | Encrypted TEXT columns, JSONB for data_sources field |
| Redis 7+ | existing | 'optimization' queue for BuildIncomeOptimizationProfile job | Existing queue infrastructure |
| Pest PHP 3 | existing | Unit + feature tests for rules engine | Existing test framework with RefreshDatabase trait |

**Installation:**
```bash
# No new packages. Zero composer require. Zero npm install.
```

**Package Legitimacy Audit:** Not applicable — this phase installs no external packages.

---

## Architecture Patterns

### System Architecture Diagram

```
USER TRIGGER (manual "Analyze" or post-extraction event)
                    |
                    v
  ┌─────────────────────────────────────────────┐
  │   BuildIncomeOptimizationProfile Job         │
  │   queue: 'optimization', tries=3, timeout=180│
  └──────────────────┬──────────────────────────┘
                     │
         ┌───────────┴───────────┐
         v                       v
  ┌─────────────────┐    ┌──────────────────────┐
  │IncomeOptimizer  │    │ CrossSourceReview     │
  │DataAssembler    │    │ Service               │
  │Service          │    │ (deterministic only   │
  │(no Claude)      │    │  in Phase 10;         │
  └───────┬─────────┘    │  Claude for desc      │
          │ reads from:   │  in Phase 11)         │
    ┌─────┴──────────┐   └──────────┬────────────┘
    │                │              │
    v                v              │
  TaxDocument   Transaction    reads IncomeOpt
  .extracted_   + Income       imizationProfile
  data          Detector       + compares to
  (by category) Service        TaxDocument
  UserFinancial (bank          sources
  Profile       deposits)      │
                               v
          │             OptimizationFinding
          v             (discrepancy records)
  IncomeOptimization
  Profile (new model)
  SHA-256 hash for
  staleness detection

         |
         v
  OptimizationProfileBuilt event
  (Phase 11 listeners attach here)

═══════════════════════════════════
  TaxRulesEngineService (standalone)
  reads config/tax-rules.php ONLY
  → effectiveTaxRate()
  → marginalRate()
  → standardDeductionCents()
  → compareStandardVsItemized()
  → remaining401kRoom()
  → remainingIraRoom()
  → remainingHsaRoom()
  → rothIraEligible()
  → selfEmploymentTaxDeductionCents()
  → qbiDeductionCents()
  → taxSavingsFromDeductionCents()
  (used by Phase 11 detectors and Phase 12 report)
```

### Recommended Project Structure (new files only)
```
config/
└── tax-rules.php                   # TAX-01: year-versioned IRS constants

app/
├── Services/
│   ├── TaxRulesEngineService.php   # TAX-02 through TAX-06
│   ├── IncomeOptimizerDataAssemblerService.php  # CTX-01
│   └── CrossSourceReviewService.php             # CTX-03
├── Models/
│   ├── IncomeOptimizationProfile.php  # CTX-02
│   └── OptimizationFinding.php        # discrepancy findings from CTX-03
├── Jobs/
│   └── BuildIncomeOptimizationProfile.php  # CTX-02
└── Events/
    └── OptimizationProfileBuilt.php

database/migrations/
├── XXXX_create_income_optimization_profiles_table.php
└── XXXX_create_optimization_findings_table.php

tests/Unit/Services/
└── TaxRulesEngineServiceTest.php   # TAX-07
tests/Feature/
└── IncomeOptimizerDataAssemblerTest.php  # CTX-01, CTX-02
tests/Feature/
└── CrossSourceReviewServiceTest.php      # CTX-03, CTX-04
```

---

### Pattern 1: config/tax-rules.php — Exact Structure for Phase 10

All dollar values are stored as plain integers (dollars, not cents) and the service converts to cents internally. This matches the pattern in the existing codebase where config values are human-readable and the service applies business logic.

```php
// Source: IRS Rev. Proc. 2025-32 (brackets, standard deductions, LTCG);
//         IRS Notice 2025-67 (401k/IRA limits);
//         IRS Notice 2026-05 (HSA limits);
//         IRS.gov Topic 751 (FICA/SE tax)
// config/tax-rules.php
return [
    2026 => [

        // ── Federal Income Tax Brackets ──────────────────────────────────
        // [CITED: IRS Rev. Proc. 2025-32, Table 1]
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

        // ── Standard Deductions ───────────────────────────────────────────
        // [CITED: IRS Rev. Proc. 2025-32]
        'standard_deduction' => [
            'single'            => 16_100,
            'married_joint'     => 32_200,
            'married_separate'  => 16_100,
            'head_of_household' => 24_150,
        ],

        // Additional standard deduction for age 65+, per qualifying person
        // [CITED: IRS Rev. Proc. 2025-32]
        'standard_deduction_senior_addition' => [
            'single'            => 2_050,
            'married_joint'     => 1_650,  // per qualifying spouse
            'married_separate'  => 1_650,
            'head_of_household' => 2_050,
        ],

        // ── 401(k) / Employer Plan Limits ────────────────────────────────
        // [CITED: IRS Notice 2025-67]
        '401k' => [
            'employee_deferral'               => 24_500,
            'catchup_age_50_plus'             => 8_000,   // ages 50-59 and 64+; total 32,500
            'catchup_age_60_to_63'            => 11_250,  // SECURE 2.0 §109; replaces 50+ for 60-63; total 35,750
            // SECURE 2.0 §603: prior-year FICA wages >= threshold → catch-up MUST be Roth
            // [ASSUMED: exact 2026 indexed threshold; IRS Notice confirms base $145k indexed;
            //  milestone research uses $150k — treat this value as needing tax-professional confirmation]
            'mandatory_roth_catchup_threshold' => 150_000,
            'highly_compensated_threshold'     => 160_000,
        ],

        // ── IRA Limits ───────────────────────────────────────────────────
        // [CITED: IRS Notice 2025-67]
        'ira' => [
            'annual_limit'    => 7_500,
            'catchup_age_50_plus' => 1_100,   // total 8,600 for 50+; new in 2026 (was $1,000)

            // Roth IRA contribution phase-out (MAGI)
            // [CITED: IRS Notice 2025-67 + IRS Rev. Proc. 2025-32]
            'roth_phaseout' => [
                'single'            => ['from' => 153_000, 'to' => 168_000],
                'married_joint'     => ['from' => 242_000, 'to' => 252_000],
                'married_separate'  => ['from' => 0,       'to' => 10_000],
                'head_of_household' => ['from' => 153_000, 'to' => 168_000],
            ],

            // Traditional IRA deduction phase-out when covered by workplace plan
            // [CITED: IRS Notice 2025-67]
            'traditional_deduction_phaseout_covered' => [
                'single'            => ['from' => 81_000,  'to' => 91_000],
                'married_joint'     => ['from' => 129_000, 'to' => 149_000],
                'married_separate'  => ['from' => 0,       'to' => 10_000],
                'head_of_household' => ['from' => 81_000,  'to' => 91_000],
            ],

            // Phase-out when NOT covered but spouse IS covered
            // [CITED: IRS Notice 2025-67]
            'traditional_deduction_phaseout_spouse_covered' => [
                'married_joint' => ['from' => 242_000, 'to' => 252_000],
            ],
        ],

        // ── HSA Limits ───────────────────────────────────────────────────
        // [CITED: IRS Notice 2026-05]
        'hsa' => [
            'self_only'           => 4_400,
            'family'              => 8_750,
            'catchup_age_55_plus' => 1_000,  // statutory, not inflation-adjusted
            'hdhp_min_deductible_self'   => 1_700,
            'hdhp_min_deductible_family' => 3_400,
            'hdhp_max_oop_self'          => 8_500,
            'hdhp_max_oop_family'        => 17_000,
        ],

        // ── Self-Employment / FICA ────────────────────────────────────────
        // [CITED: IRS.gov Topic 751; SSA.gov 2026 COLA announcement]
        'se_tax' => [
            'net_earnings_multiplier' => 0.9235,  // 1 - (0.153/2) normalizes for employer share
            'rate'                    => 0.153,    // 12.4% SS + 2.9% Medicare
            'ss_rate'                 => 0.124,
            'medicare_rate'           => 0.029,
            'ss_wage_base'            => 184_500,  // SS portion only applies up to this limit
            'deductible_fraction'     => 0.5,      // half of SE tax deducted from AGI
            // Additional Medicare surtax (0.9%) on earned income above:
            'additional_medicare_threshold_single' => 200_000,
            'additional_medicare_threshold_joint'  => 250_000,
            'additional_medicare_rate'             => 0.009,
        ],

        // ── QBI (§199A) Deduction Thresholds ─────────────────────────────
        // [CITED: IRS Rev. Proc. 2025-32; OBBBA §70105 (permanent extension + $400 minimum)]
        'qbi' => [
            'rate'                    => 0.20,   // 20% of qualified business income
            'phase_out_start_single'  => 201_750,
            'phase_out_start_joint'   => 403_500,
            'phase_out_window_single' => 75_000,  // OBBBA-expanded (was $50k pre-OBBBA)
            'phase_out_window_joint'  => 150_000, // OBBBA-expanded (was $100k pre-OBBBA)
            // Derived: full SSTB elimination at single=$276,750; joint=$553,500
            'minimum_deduction'       => 400,     // OBBBA §70105: if QBI >= $1,000 and materially participates
            'minimum_qbi_for_floor'   => 1_000,   // QBI must be at least $1,000 to claim $400 minimum
        ],

        // ── Roth Optimization Decision Bands ─────────────────────────────
        // [ASSUMED: logic derived from standard financial planning guidance; not IRS-mandated]
        'roth_optimization' => [
            'prefer_roth_at_or_below' => 0.12,   // marginal rate <= 12% → Roth lean
            'prefer_traditional_at_or_above' => 0.32,  // marginal rate >= 32% → Traditional lean
            // 22% and 24% brackets → 'split' (context-dependent)
        ],
    ],
];
```

**Config location:** `config/tax-rules.php` (new file, not merged into `config/spendifiai.php` — tax rules are a distinct domain with annual update cadence; separation makes November updates less risky).

**Config access pattern:** `config('tax-rules.2026.brackets.single')` — TaxRulesEngineService passes the year as a parameter and reads the correct sub-array.

---

### Pattern 2: TaxRulesEngineService — Method Contracts

**Location:** `app/Services/TaxRulesEngineService.php`
**Uses Claude:** Never. Zero AI calls. This is the deterministic math bedrock.

```php
// Source: codebase architecture research + IRS computation rules
class TaxRulesEngineService
{
    // TAX-02: Federal income tax on taxable income
    public function computeTax(int $taxableIncomeCents, string $filingStatus, int $year = 2026): int
    // TAX-02: Marginal rate — top bracket the income falls into
    public function marginalRate(int $taxableIncomeCents, string $filingStatus, int $year = 2026): float
    // TAX-02: Effective rate = tax / income
    public function effectiveRate(int $taxableIncomeCents, string $filingStatus, int $year = 2026): float

    // TAX-03: Standard deduction for filing status + optional senior addition
    public function standardDeductionCents(string $filingStatus, ?int $age = null, int $year = 2026): int
    // TAX-03: Compare standard vs itemized
    // Returns: ['recommendation' => 'standard'|'itemized', 'standard_cents' => int,
    //           'itemized_cents' => int, 'difference_cents' => int]
    public function compareStandardVsItemized(int $itemizedTotalCents, string $filingStatus, ?int $age = null, int $year = 2026): array

    // TAX-04: Remaining contribution room
    public function remaining401kRoomCents(int $ytdContribCents, ?int $age = null, int $year = 2026): int
    public function remainingIraRoomCents(int $ytdContribCents, ?int $age = null, int $year = 2026): int
    public function remainingHsaRoomCents(int $ytdContribCents, string $coverageType = 'self_only', ?int $age = null, int $year = 2026): int

    // TAX-05: Roth eligibility and recommendation
    // Returns: 'roth' | 'traditional' | 'split'
    public function rothVsTraditionalBand(float $marginalRate, int $year = 2026): string
    // Returns: ['eligible' => bool, 'limit_cents' => int, 'phase_out_pct' => float]
    public function rothIraEligibility(int $magiCents, string $filingStatus, int $year = 2026): array
    // TAX-05: SECURE 2.0 §603 flag
    public function requiresMandatoryRothCatchup(int $priorYearFicaWagesCents, int $year = 2026): bool
    // Returns: ['deductible' => bool, 'partial_limit_cents' => int|null]
    public function traditionalIraDeductibility(int $magiCents, string $filingStatus, bool $coveredByPlan, bool $spouseCoveredByPlan, int $year = 2026): array

    // TAX-06: Self-employment tax computation
    // Returns: ['se_tax_cents' => int, 'deductible_half_cents' => int]
    public function selfEmploymentTax(int $netSelfEmploymentProfitCents, int $year = 2026): array
    // TAX-06: QBI deduction
    // Returns: ['eligible' => bool, 'deduction_cents' => int, 'reason' => string]
    public function qbiDeduction(int $qualifiedBusinessIncomeCents, int $taxableIncomeCents, string $filingStatus, bool $isSstb, int $year = 2026): array

    // General: estimated tax savings from a deduction at current marginal rate
    public function taxSavingsFromDeductionCents(int $deductionCents, int $taxableIncomeCents, string $filingStatus, int $year = 2026): int
}
```

**Private bracket iteration algorithm (TAX-02):**
```php
private function computeBracketTax(int $incomeCents, array $brackets): int
{
    $tax = 0;
    foreach ($brackets as $bracket) {
        $from = $bracket['from'] * 100;  // dollars to cents
        $to = $bracket['to'] !== null ? $bracket['to'] * 100 : PHP_INT_MAX;
        if ($incomeCents <= $from) break;
        $taxableInBracket = min($incomeCents, $to) - $from;
        $tax += (int) round($taxableInBracket * $bracket['rate']);
    }
    return $tax;
}
```

**SE tax algorithm (TAX-06):**
```php
// net earnings = net_profit_cents * 0.9235
// SS portion: min(net_earnings, ss_wage_base_cents) * 0.124
// Medicare portion: net_earnings * 0.029
// se_tax = SS + Medicare
// deductible_half = se_tax / 2  (deducted from gross income to arrive at AGI)
```

**QBI deduction algorithm (TAX-06):**
```php
// Phase-out check for SSTB:
// if taxable_income <= phase_out_start: full deduction = min(qbi, taxable_income) * 0.20
// if taxable_income >= phase_out_start + window: no deduction (SSTB fully phased out)
// in between: prorate reduction linearly across the window
// Non-SSTB above threshold: subject to W-2 wage and UBIA limitations (out of scope for Phase 10 —
//   surface as 'eligible, verify with professional' without computing the W-2 wage limit)
// OBBBA minimum: max(computed_deduction, $400) if qbi >= $1,000 and materially participates
```

**401k headroom algorithm (TAX-04):**
```php
// Base limit: $24,500 for all eligible participants
// Age 60-63: total limit = $24,500 + $11,250 = $35,750 (SECURE 2.0 §109)
// Age 50-59 or 64+: total limit = $24,500 + $8,000 = $32,500
// remaining_room = max(0, total_limit - ytd_contributions)
// SECURE 2.0 §603 flag: if prior_year_fica_wages >= mandatory_roth_catchup_threshold
//   AND age >= 50: set mandatory_roth_catchup = true on the finding
```

---

### Pattern 3: IncomeOptimizationProfile Migration

**Principle:** New standalone table. Follows existing encrypted TEXT convention for all income/deduction dollar amounts (matching `UserFinancialProfile.monthly_income` pattern). No `updated_at` — always create new row; old row replaced (or upserted by user_id+tax_year unique constraint).

```php
// Source: ARCHITECTURE.md + codebase convention from UserFinancialProfile model
Schema::create('income_optimization_profiles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->unsignedSmallInteger('tax_year');

    // Income signals — all encrypted TEXT (never plain integer for financial amounts)
    $table->text('w2_wages')->nullable();                       // encrypted
    $table->text('self_employment_income')->nullable();          // encrypted
    $table->text('interest_income')->nullable();                 // encrypted
    $table->text('dividend_income')->nullable();                 // encrypted
    $table->text('retirement_distributions')->nullable();        // encrypted
    $table->text('bank_deposit_total')->nullable();             // encrypted

    // Deduction signals — encrypted TEXT
    $table->text('mortgage_interest')->nullable();               // encrypted (from 1098)
    $table->text('property_tax_paid')->nullable();              // encrypted
    $table->text('student_loan_interest')->nullable();           // encrypted (from 1098-E)
    $table->text('charitable_contributions')->nullable();        // encrypted

    // Retirement contribution signals — encrypted TEXT
    $table->text('traditional_401k_ytd')->nullable();           // encrypted
    $table->text('roth_401k_ytd')->nullable();                  // encrypted
    $table->text('ira_ytd')->nullable();                        // encrypted
    $table->text('hsa_ytd')->nullable();                        // encrypted

    // Non-sensitive computed flags (plain columns — not financial amounts)
    $table->string('filing_status', 30)->nullable();
    $table->boolean('has_home_office')->default(false);
    $table->boolean('has_self_employment')->default(false);
    $table->boolean('has_hsa_eligible_plan')->default(false);
    $table->boolean('has_ira')->default(false);
    $table->string('ira_type', 20)->nullable();                 // 'traditional' / 'roth' / 'both'
    $table->string('employment_type', 30)->nullable();
    $table->unsignedTinyInteger('estimated_age')->nullable();   // derived from DOB if available

    // Metadata (non-sensitive)
    $table->jsonb('data_sources')->default('{}');               // {doc_ids: [], transaction_range: {}}
    $table->unsignedSmallInteger('doc_count')->default(0);
    $table->string('profile_hash', 64)->nullable();             // SHA-256 of inputs; staleness detection
    $table->timestamp('built_at')->nullable();
    $table->timestamps();

    $table->unique(['user_id', 'tax_year']);
    $table->index(['user_id', 'tax_year']);
});
```

**Model casts (matches existing encrypted TEXT convention):**
```php
protected function casts(): array
{
    return [
        'w2_wages'                   => 'encrypted',
        'self_employment_income'     => 'encrypted',
        'interest_income'            => 'encrypted',
        'dividend_income'            => 'encrypted',
        'retirement_distributions'   => 'encrypted',
        'bank_deposit_total'         => 'encrypted',
        'mortgage_interest'          => 'encrypted',
        'property_tax_paid'          => 'encrypted',
        'student_loan_interest'      => 'encrypted',
        'charitable_contributions'   => 'encrypted',
        'traditional_401k_ytd'       => 'encrypted',
        'roth_401k_ytd'              => 'encrypted',
        'ira_ytd'                    => 'encrypted',
        'hsa_ytd'                    => 'encrypted',
        'has_home_office'            => 'boolean',
        'has_self_employment'        => 'boolean',
        'has_hsa_eligible_plan'      => 'boolean',
        'has_ira'                    => 'boolean',
        'data_sources'               => 'array',
        'built_at'                   => 'datetime',
    ];
}
```

**Note on cents vs dollars in the model:** Encrypted columns store values as strings. Store dollar amounts as integer cents (e.g., '750000' for $7,500.00) for precision. Use a consistent convention documented in the model's class docblock. The service layer always works in cents.

---

### Pattern 4: IncomeOptimizerDataAssemblerService — Source Reading Strategy

```php
// Source: direct codebase inspection of TaxDocument model, UserFinancialProfile,
//         IncomeDetectorService, TaxDocumentCategory enum
class IncomeOptimizerDataAssemblerService
{
    public function buildProfile(User $user, int $taxYear): IncomeOptimizationProfile
    public function isStale(IncomeOptimizationProfile $profile): bool  // compare profile_hash

    // Source 1: UserFinancialProfile (direct model read — no decryption needed for flags)
    private function readProfileFlags(User $user): array
    // Reads: employment_type, tax_filing_status, has_hsa, has_fsa, has_ira, ira_type,
    //        has_home_office, has_rental_property, monthly_income (encrypted)

    // Source 2: TaxDocument.extracted_data grouped by TaxDocumentCategory
    private function sumFromDocuments(User $user, int $taxYear): array
    // Reads TaxDocument::forUser($user->id)->byYear($taxYear)->byStatus(DocumentStatus::Extracted)
    // Groups by category, sums target fields:
    //   W2           → wages, federal_tax_withheld (sum across all W2s for multi-employer)
    //   NEC_1099     → nonemployee_compensation → self_employment_income
    //   MISC_1099    → other_income, rents, royalties → self_employment_income
    //   INT_1099     → interest_income
    //   DIV_1099     → ordinary_dividends → dividend_income
    //   R_1099       → gross_distribution → retirement_distributions
    //   Mortgage_1098 → interest_income (mortgage) → mortgage_interest
    //   PropertyTax  → amount → property_tax_paid
    //   E_1098       → interest → student_loan_interest
    //   CharitableDonation → amount → charitable_contributions
    //   HSA_5498     → contributions_made → hsa_ytd
    //   IRA_5498     → contributions_made → ira_ytd

    // Source 3: Transactions via IncomeDetectorService (bank deposits)
    private function sumBankDeposits(User $user, int $taxYear): int
    // IncomeDetectorService::analyze($user->id, monthsBack: 12)
    // Sum all sources where classification = 'primary' or 'extra' AND type != 'transfer'
    // Use the tax year range (Jan 1 to Dec 31) not a rolling 12-month window

    // Staleness hash: SHA-256 of (user_id + tax_year + sorted extracted TaxDocument IDs + profile.updated_at)
    private function computeProfileHash(User $user, int $taxYear, array $docIds): string
}
```

**TaxDocument field access pattern:**
```php
// extracted_data is encrypted:array — Laravel decrypts automatically on access
$doc = TaxDocument::forUser($user->id)->byYear($taxYear)
    ->where('category', TaxDocumentCategory::W2->value)
    ->where('status', DocumentStatus::Extracted->value)
    ->get();

foreach ($doc as $taxDoc) {
    $wages = $taxDoc->extracted_data['wages'] ?? null;
    if ($wages !== null) {
        $totalWagesCents += (int) round((float) $wages * 100);
    }
}
```

**Key design decision:** All values from `extracted_data` are stored as floating-point strings (e.g., `"72500.00"`). Always cast to float before multiplying by 100 to get cents. Never use intval directly.

---

### Pattern 5: CrossSourceReviewService — Discrepancy Algorithm (CTX-03)

```php
// Source: ARCHITECTURE.md + standard tolerance patterns in financial data reconciliation
class CrossSourceReviewService
{
    // Phase 10 scope: deterministic comparison only; Claude descriptions are Phase 11
    const W2_DEPOSIT_TOLERANCE = 0.15;  // 15% — accounts for pre-tax deductions, timing
    const SE_INCOME_TOLERANCE  = 0.20;  // 20% — SE income often irregular timing

    public function review(IncomeOptimizationProfile $profile, User $user, int $taxYear): array

    // CTX-03: W-2 vs bank deposits comparison
    private function compareW2VsDeposits(IncomeOptimizationProfile $profile): ?array
    // Logic:
    //   w2 = (int)(string $profile->w2_wages)   // decrypt + cast to int (cents)
    //   bank = (int)(string $profile->bank_deposit_total)
    //   if w2 == 0 OR bank == 0: return null (insufficient data)
    //   gap = abs(w2 - bank)
    //   gap_pct = gap / max(w2, bank)
    //   if gap_pct > self::W2_DEPOSIT_TOLERANCE:
    //     return ['type' => 'w2_deposit_mismatch', 'w2_cents' => $w2, 'bank_cents' => $bank,
    //             'gap_cents' => $gap, 'gap_pct' => $gap_pct]
    //   return null

    // CTX-03: 1099/SE income vs deposits
    private function compareSEIncomeVsDeposits(IncomeOptimizationProfile $profile): ?array
    // Same logic with SE_INCOME_TOLERANCE; flag if self_employment_income > 0 AND bank_deposit_total > 0

    // Returns array of OptimizationFinding data arrays (not persisted here — job persists them)
}
```

**Finding creation pattern (in BuildIncomeOptimizationProfile job):**
```php
// After CrossSourceReviewService::review() returns findings:
foreach ($findings as $findingData) {
    OptimizationFinding::updateOrCreate(
        ['user_id' => $user->id, 'tax_year' => $taxYear, 'finding_key' => $findingData['key']],
        $findingData
    );
}
```

**CTX-04 — Snapshot "answerable" field mapping** (used by Phase 11 to skip interview questions):

| Snapshot Field | Interview Question Skipped |
|----------------|---------------------------|
| `filing_status` is not null | "What is your expected filing status?" |
| `has_hsa_eligible_plan` = true | "Are you on an HDHP-eligible health plan?" |
| `has_ira` = true | "Do you have an IRA?" |
| `ira_type` = 'traditional' | "What type of IRA do you have?" |
| `has_home_office` = true | "Do you work from a home office?" |
| `traditional_401k_ytd` > 0 | "Have you contributed to a 401(k) this year?" |
| `hsa_ytd` > 0 | "Have you contributed to an HSA this year?" |
| `employment_type` = 'self_employed' | "Are you self-employed?" |

---

### Pattern 6: BuildIncomeOptimizationProfile Job

**Mirrors existing job conventions exactly:**

```php
class BuildIncomeOptimizationProfile implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(
        public readonly int $userId,
        public readonly int $taxYear
    ) {}

    public function handle(
        IncomeOptimizerDataAssemblerService $assembler,
        CrossSourceReviewService $crossSource
    ): void {
        $user = User::findOrFail($this->userId);

        // Step 1: Build/refresh snapshot
        $profile = $assembler->buildProfile($user, $this->taxYear);

        // Step 2: Cross-source review (deterministic; Claude descriptions in Phase 11)
        $findings = $crossSource->review($profile, $user, $this->taxYear);

        // Step 3: Upsert findings
        foreach ($findings as $finding) {
            OptimizationFinding::updateOrCreate(
                ['user_id' => $user->id, 'tax_year' => $this->taxYear, 'finding_key' => $finding['key']],
                array_merge($finding, ['user_id' => $user->id, 'tax_year' => $this->taxYear])
            );
        }

        // Step 4: Fire event (Phase 11 listeners attach here)
        event(new OptimizationProfileBuilt($user->id, $this->taxYear, count($findings)));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('BuildIncomeOptimizationProfile failed', [
            'user_id'  => $this->userId,
            'tax_year' => $this->taxYear,
            'error'    => $exception->getMessage(),
        ]);
    }
}
```

**Queue:** 'optimization' (new queue name — add to `.env` as `QUEUE_OPTIMIZATION_CONNECTION=redis` and configure a new queue worker handle. The existing `queue:work redis` command can include `--queue=optimization,default`).

---

### Anti-Patterns to Avoid

- **Hardcoding IRS figures in service code:** `remaining401kRoomCents(ytd: 100_00, age: 55)` should compute from config, not from `$limit = 24500 * 100`. Any hardcoded dollar amount in a service file is a bug. Verify after implementation: `grep -r "24500\|7500\|4400\|8750\|32200\|16100" app/Services/` must return zero hits.
- **Storing income amounts as unencrypted integers in the migration:** All money columns in `income_optimization_profiles` must be TEXT (not `unsignedBigInteger`). Using an integer column silently stores plaintext income amounts. The `encrypted` cast only works with TEXT/VARCHAR/LONGTEXT columns.
- **Calling IncomeOptimizerDataAssemblerService on every API request:** The assembler reads 10+ encrypted records. It belongs in a job, not a controller. The controller reads from the already-built `IncomeOptimizationProfile`, not from the assembler.
- **Using `updated_at` as the staleness signal:** The profile is replaced (upserted), not updated in place. Staleness is detected via `profile_hash` — recompute the hash from current inputs and compare to stored hash. If they differ, the profile is stale.
- **Adding columns to existing high-traffic tables:** Phase 10 adds ONLY new tables (`income_optimization_profiles`, `optimization_findings`). No columns are added to `transactions`, `ai_questions`, or any existing table. This rule is absolute per CLAUDE.md safety constraints.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Tax bracket iteration | A branching if/else chain or switch statement | Pure foreach over config array | Config array is updateable without code changes; foreach is O(7) and perfectly fast |
| Contribution room calculation | Separate hardcoded methods per account type | Single pattern: `limit - ytd`, with catch-up added by age tier from config | Consistent, testable, updated by changing one config value each year |
| Profile hash for staleness | A timestamp-based TTL | SHA-256 of sorted doc IDs + profile.updated_at | TTL-based staleness misses new document uploads that happen within the TTL window |
| Encrypted column storage | Manual `encrypt()`/`decrypt()` | Laravel `encrypted` cast on TEXT column | Existing convention (see UserFinancialProfile.monthly_income); manual calls break if key rotates |
| Cross-source tolerance logic | Claude to "decide" if the discrepancy is meaningful | Hardcoded `W2_DEPOSIT_TOLERANCE = 0.15` in CrossSourceReviewService | Tolerance is a business decision, not an AI judgment; deterministic and auditable |
| QBI deduction calculation | Asking Claude for the deduction amount | TaxRulesEngineService::qbiDeduction() with config thresholds | Claude will hallucinate QBI thresholds; exact cents must come from deterministic code |

**Key insight:** Every dollar amount, every rate, every threshold in this phase must trace to `config/tax-rules.php`. If you can't point to the config key that produced a number, the architecture is wrong.

---

## Common Pitfalls

### Pitfall 1: SECURE 2.0 §603 Threshold Ambiguity
**What goes wrong:** The `mandatory_roth_catchup_threshold` in config is set to `$150,000` but the IRS Notice underlying SECURE 2.0 established the base at `$145,000` (indexed). Sources conflict. If the flag is triggered at the wrong threshold, a user may be incorrectly told catch-up must be Roth (or incorrectly told it doesn't need to be).
**Why it happens:** Multiple web sources cite different numbers; the IRS Notice was indexed for 2026 and the adjusted value differs from the original statute.
**How to avoid:** Tag this value as `[ASSUMED]` in the config comment. The Phase 10 implementation sets the flag correctly from the config key. A human verification step in Phase 13 confirms the exact 2026 indexed value from the IRS final regulations. Never hard-code or present this as certain to users — the educational framing ("your employer plan may require Roth catch-up if your wages were over $X") handles the uncertainty.
**Warning signs:** Any test that asserts a specific threshold for mandatory Roth catch-up without citing the IRS Notice that sets the 2026 indexed value.

### Pitfall 2: Cents vs Dollars Inconsistency
**What goes wrong:** `extracted_data['wages']` from a TaxDocument is a float like `72500.0` (dollars). If the assembler stores it as `725000` (thinking it's already in cents, but forgetting to multiply by 100), TaxRulesEngineService will compute tax on $7,250 instead of $72,500.
**Why it happens:** The mixed environment: config values are in dollars (human-readable), service layer works in cents, extracted_data from AI extraction is in dollars.
**How to avoid:** Establish an inviolable rule: `extracted_data` fields are always dollars. `IncomeOptimizationProfile` encrypted fields always store cents as string integers. The assembler always applies `(int) round((float) $fieldValue * 100)` when reading from extracted_data and writing to the profile. Add a conversion comment at the top of the assembler.
**Warning signs:** A test where the expected tax on $72,500 returns the tax on $7,250 or $725,000 instead.

### Pitfall 3: IncomeDetectorService Date Range Mismatch
**What goes wrong:** `IncomeDetectorService::analyze()` defaults to 3 months back from now. But for a tax year snapshot, you need January 1 to December 31 of the tax year. Using the default rolling 3-month window gives a snapshot biased toward recent income, not annual income.
**Why it happens:** The existing service was designed for the dashboard (recent income analysis), not for tax year aggregation.
**How to avoid:** In `IncomeOptimizerDataAssemblerService::sumBankDeposits()`, call IncomeDetectorService with `monthsBack: 12` but then filter transactions to the tax year's date range (Jan 1 to Dec 31) using the existing Transaction query directly, bypassing the rolling 3-month default. Or query transactions directly for the tax year range and apply the same income type classification logic.
**Warning signs:** A snapshot that shows lower bank deposit total for a mid-year test (because it's only looking back 3 months from the test date).

### Pitfall 4: Prorating QBI for SSTB Without Phase 10 Scope
**What goes wrong:** TaxRulesEngineService is asked to compute the exact prorated SSTB phase-out amount, which requires knowing W-2 wages paid by the business and UBIA of qualified property. These are not available in the snapshot in Phase 10.
**Why it happens:** The full §199A calculation for non-SSTB businesses above the threshold is complex; Phase 10 only has income totals, not W-2 payroll amounts.
**How to avoid:** Phase 10 scope for QBI is: detect eligibility and estimate the 20% deduction for users below the phase-out threshold. For users above the threshold, return `['eligible' => true, 'deduction_cents' => null, 'reason' => 'above_threshold_requires_professional_review']`. The RedFlagDetector (Phase 11) surfaces this as a finding. Do not attempt to compute the W-2 wage limitation in Phase 10.
**Warning signs:** Any call to compute `0.5 * w2_wages` within TaxRulesEngineService — this requires data not available in Phase 10.

---

## Code Examples

### Bracket Tax Computation (TAX-02)
```php
// Source: IRS bracket iteration pattern, verified against Rev. Proc. 2025-32
public function computeTax(int $taxableIncomeCents, string $filingStatus, int $year = 2026): int
{
    $brackets = config("tax-rules.{$year}.brackets.{$filingStatus}");
    if (empty($brackets)) {
        throw new \InvalidArgumentException("Unknown filing status: {$filingStatus} for year {$year}");
    }

    $tax = 0;
    foreach ($brackets as $bracket) {
        $fromCents = $bracket['from'] * 100;
        $toCents = $bracket['to'] !== null ? $bracket['to'] * 100 : PHP_INT_MAX;
        if ($taxableIncomeCents <= $fromCents) break;
        $taxableInBracket = min($taxableIncomeCents, $toCents) - $fromCents;
        $tax += (int) round($taxableInBracket * $bracket['rate']);
    }
    return $tax;
}
```

### 401k Headroom (TAX-04)
```php
// Source: IRS Notice 2025-67 + SECURE 2.0 §109 catch-up tiers
public function remaining401kRoomCents(int $ytdContribCents, ?int $age = null, int $year = 2026): int
{
    $cfg = config("tax-rules.{$year}.401k");
    $baseLimitCents = $cfg['employee_deferral'] * 100;

    $totalLimitCents = $baseLimitCents;
    if ($age !== null && $age >= 50) {
        $catchupCents = ($age >= 60 && $age <= 63)
            ? $cfg['catchup_age_60_to_63'] * 100   // SECURE 2.0 §109 super catch-up
            : $cfg['catchup_age_50_plus'] * 100;
        $totalLimitCents += $catchupCents;
    }
    return max(0, $totalLimitCents - $ytdContribCents);
}
```

### SE Tax Calculation (TAX-06)
```php
// Source: IRS Publication 334; Schedule SE computation rules
public function selfEmploymentTax(int $netProfitCents, int $year = 2026): array
{
    $cfg = config("tax-rules.{$year}.se_tax");
    $netEarningsCents = (int) round($netProfitCents * $cfg['net_earnings_multiplier']);
    $wageBaseCents = $cfg['ss_wage_base'] * 100;

    $ssTaxCents = (int) round(min($netEarningsCents, $wageBaseCents) * $cfg['ss_rate']);
    $medicareTaxCents = (int) round($netEarningsCents * $cfg['medicare_rate']);
    $seTaxCents = $ssTaxCents + $medicareTaxCents;
    $deductibleHalfCents = (int) round($seTaxCents * $cfg['deductible_fraction']);

    return [
        'se_tax_cents'          => $seTaxCents,
        'deductible_half_cents' => $deductibleHalfCents,
    ];
}
```

### Profile Staleness Hash
```php
// Source: codebase pattern + SHA-256 for deterministic content hashing
private function computeProfileHash(int $userId, int $taxYear, array $docIds, string $profileUpdatedAt): string
{
    sort($docIds);
    return hash('sha256', implode('|', [
        $userId,
        $taxYear,
        implode(',', $docIds),
        $profileUpdatedAt,
    ]));
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| IRA catch-up $1,000 for age 50+ | IRA catch-up $1,100 for age 50+ in 2026 | IRS Notice 2025-67 (2026) | Must update config; old code will understate headroom by $100/yr |
| 401k catch-up $7,500 for age 50+ | Age 60-63 gets $11,250 super catch-up (SECURE 2.0 §109) | 2025 (effective 2026) | New age tier logic required; not a simple scalar lookup |
| Roth catch-up voluntary | SECURE 2.0 §603 mandatory Roth for high earners | 2026 plan year | Flag must be surfaced before retirement contribution suggestions |
| QBI deduction sunset after 2025 | OBBBA made §199A permanent + $400 minimum | July 2025 | Planning must include QBI permanently; minimum deduction floor is new |
| QBI phase-out window $50k single | OBBBA expanded to $75k single | July 2025 | More taxpayers in partial deduction zone; thresholds updated in config |

**Deprecated/outdated:**
- 2025 IRA catch-up of $1,000: replaced by $1,100 in 2026 per Notice 2025-67
- QBI deduction "temporary — expires 2025": permanent under OBBBA; remove any "temporary provision" warnings from UI copy

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `mandatory_roth_catchup_threshold` = $150,000 (milestone research used $150k; IRS base is $145k indexed; 2026 adjusted value may differ) | Pattern 1 (config), Pattern 2 (method contract) | Flag set at wrong income level; user gets incorrect catch-up Roth flag |
| A2 | QBI $400 minimum deduction requires "material participation" (OBBBA §70105 language interpreted) | Pattern 1 (config — `minimum_qbi_for_floor`), Pattern 2 (qbiDeduction method) | Minimum deduction eligibility could be over- or under-stated |
| A3 | `bank_deposit_total` from IncomeDetectorService accurately represents taxable income (excludes transfers, refunds, loan proceeds) | Pattern 4 (assembler source reading) | Cross-source comparison inflated/deflated if bank deposit sum includes non-income items |
| A4 | W-2 `wages` field from TaxDocumentExtractorService `extracted_data` represents Box 1 (total wages, tips, other compensation) as a float string | Pattern 4 (assembler TaxDocument reading) | Wrong income base for cross-source comparison |

**If table is populated, every item must be reviewed during Phase 13 security/validation hardening.**

---

## Open Questions

1. **SECURE 2.0 §603 exact 2026 threshold**
   - What we know: Original SECURE 2.0 base was $145,000; indexed for inflation in $5,000 increments; IRS issued final regulations; USC HR source says "$150,000 (indexed)"; Fidelity source says "$145,000"
   - What's unclear: The exact 2026 indexed value in the IRS final regulations
   - Recommendation: Add inline config comment flagging this as `[NEEDS VERIFICATION — confirm exact 2026 indexed value from IRS Notice before Phase 13]`. Educational framing handles uncertainty at UI level.

2. **QBI $400 minimum deduction — material participation test**
   - What we know: OBBBA §70105 added a $400 minimum if QBI >= $1,000 AND taxpayer materially participates
   - What's unclear: Whether "materially participates" can be determined from snapshot data or always requires interview question
   - Recommendation: In Phase 10, surface the `minimum_deduction` floor only for users with `has_self_employment = true` in the snapshot. Phase 11 interview asks about material participation.

3. **IncomeDetectorService tax-year range vs rolling window**
   - What we know: The existing service uses `$monthsBack` counting back from "now"
   - What's unclear: Whether the service can be cleanly adapted for calendar-year ranges or if a direct Transaction query is cleaner
   - Recommendation: Use direct Transaction query for tax year (Jan 1 to Dec 31) in the assembler's `sumBankDeposits()`. Apply the same income type classification from `IncomeDetectorService::classifyType()` by calling the service's protected method or extracting it to a static helper.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PostgreSQL | IncomeOptimizationProfile migration | ✓ | 15+ | — |
| Redis | BuildIncomeOptimizationProfile job queue | ✓ | 7+ | sync driver (dev only) |
| PHP 8.3 | TaxRulesEngineService (named args, match) | ✓ | 8.3 | — |
| Laravel `encrypted` cast | IncomeOptimizationProfile TEXT columns | ✓ | Laravel 12 | — |
| `config/tax-rules.php` | TaxRulesEngineService (new file) | ✗ | — | Must be created in Wave 0 |

---

## Validation Architecture

**Framework:** Pest PHP 3 (existing; `tests/Unit/Services/` for pure-PHP unit tests; `tests/Feature/` for DB-dependent tests)
**Config file:** `phpunit.xml` (existing; both `Unit` and `Feature` suites configured)
**Quick run:** `php artisan test --compact --filter=TaxRulesEngine`
**Full suite:** `php artisan test --compact`

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| TAX-01 | Config file has all required keys for 2026 | unit | `php artisan test --compact --filter=TaxRulesConfigTest` | ❌ Wave 0 |
| TAX-02 | Bracket tax correct at boundary: $12,399 → 10%; $12,400 first dollar → 12%; $50,400 first dollar → 22% | unit | `php artisan test --compact --filter=TaxRulesEngineServiceTest` | ❌ Wave 0 |
| TAX-02 | Marginal rate correct at each bracket boundary for all 4 filing statuses | unit | same | ❌ Wave 0 |
| TAX-02 | Effective rate = computeTax / income (float) | unit | same | ❌ Wave 0 |
| TAX-03 | standardDeductionCents('single') = 1_610_000 (== config value × 100) | unit | same | ❌ Wave 0 |
| TAX-03 | compareStandardVsItemized: itemized < standard → 'standard' recommendation | unit | same | ❌ Wave 0 |
| TAX-04 | remaining401kRoomCents(ytd=0, age=45) = 24_500 × 100 | unit | same | ❌ Wave 0 |
| TAX-04 | remaining401kRoomCents(ytd=0, age=50) = (24_500 + 8_000) × 100 | unit | same | ❌ Wave 0 |
| TAX-04 | remaining401kRoomCents(ytd=0, age=61) = (24_500 + 11_250) × 100 | unit | same | ❌ Wave 0 |
| TAX-04 | remainingIraRoomCents(ytd=0, age=55) = (7_500 + 1_100) × 100 | unit | same | ❌ Wave 0 |
| TAX-04 | remainingHsaRoomCents(ytd=0, 'family', age=56) = (8_750 + 1_000) × 100 | unit | same | ❌ Wave 0 |
| TAX-05 | rothVsTraditionalBand(0.12) = 'roth' | unit | same | ❌ Wave 0 |
| TAX-05 | rothVsTraditionalBand(0.22) = 'split' | unit | same | ❌ Wave 0 |
| TAX-05 | rothVsTraditionalBand(0.32) = 'traditional' | unit | same | ❌ Wave 0 |
| TAX-05 | requiresMandatoryRothCatchup with wages above threshold → true | unit | same | ❌ Wave 0 |
| TAX-06 | selfEmploymentTax(100_000 × 100) → se_tax matches 100_000 × 0.9235 × 0.153 rounded to cents | unit | same | ❌ Wave 0 |
| TAX-06 | qbiDeduction: income below threshold → deduction_cents = qbi × 0.20 | unit | same | ❌ Wave 0 |
| TAX-07 | All values in tests derived from Config::get() not hardcoded | unit | (asserted via Config::set() override in tests) | ❌ Wave 0 |
| CTX-01 | buildProfile() with seeded W2 doc returns correct w2_wages_cents | feature | `php artisan test --compact --filter=IncomeOptimizerDataAssemblerTest` | ❌ Wave 0 |
| CTX-02 | IncomeOptimizationProfile created with all encrypted columns | feature | same | ❌ Wave 0 |
| CTX-02 | Rebuilding profile updates profile_hash when doc set changes | feature | same | ❌ Wave 0 |
| CTX-03 | CrossSourceReviewService flags W2/deposit gap > 15% | feature | `php artisan test --compact --filter=CrossSourceReviewServiceTest` | ❌ Wave 0 |
| CTX-03 | CrossSourceReviewService does not flag gap <= 15% | feature | same | ❌ Wave 0 |
| CTX-04 | Snapshot with filing_status set → Phase 11 skips that question (verified via snapshot field presence) | feature | same | ❌ Wave 0 |

**Critical test pattern (TAX-07):** All assertions must use config values, not hardcoded numbers:
```php
it('remaining 401k room matches config limit exactly', function () {
    Config::set('tax-rules.2026.401k.employee_deferral', 24_500);
    $service = app(TaxRulesEngineService::class);
    $expected = 24_500 * 100;
    expect($service->remaining401kRoomCents(0, age: 40))->toBe($expected);
});
```

This ensures that changing the config value (e.g., when 2027 limits are published) automatically propagates to tests as a reminder to update assertions, not a silent failure.

### Sampling Rate
- Per task commit: `php artisan test --compact --filter=TaxRulesEngine`
- Per wave merge: `php artisan test --compact` (full 225+ suite)
- Phase gate: Full suite green (225+ existing + new tests) before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/Services/TaxRulesEngineServiceTest.php` — covers TAX-02 through TAX-07
- [ ] `tests/Unit/Services/TaxRulesConfigTest.php` — covers TAX-01 (config structure validation)
- [ ] `tests/Feature/IncomeOptimizerDataAssemblerTest.php` — covers CTX-01, CTX-02
- [ ] `tests/Feature/CrossSourceReviewServiceTest.php` — covers CTX-03, CTX-04
- [ ] `config/tax-rules.php` — must exist before any test file can run

---

## Security Domain

**ASVS categories applicable to Phase 10:**

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | No new auth in this phase |
| V3 Session Management | No | No session changes |
| V4 Access Control | Yes | `IncomeOptimizationProfile` must be scoped to `user_id` on all queries; use `forUser()` scope pattern (mirrors `TaxDocument::scopeForUser()`) |
| V5 Input Validation | Yes | TaxRulesEngineService validates: income ≥ 0, filing_status in allowed set ('single','married_joint','married_separate','head_of_household'), year in supported config range; throw `InvalidArgumentException` on invalid inputs |
| V6 Cryptography | Yes | Encrypted TEXT columns via Laravel cast — NEVER call `encrypt()`/`decrypt()` manually; follow existing convention |

### Known Threat Patterns for This Phase

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Requesting another user's IncomeOptimizationProfile | Information Disclosure | `forUser($userId)` scope on every query; policy check in future controller |
| Passing invalid filing_status to TaxRulesEngineService | Tampering | Validate against known enum values; throw InvalidArgumentException |
| Integer overflow in tax computation (very high income × rate) | Tampering | Use `int` (64-bit on PHP 8.3); `PHP_INT_MAX` is ~9.2 × 10^18 cents, far above any realistic income; safe |
| Storing income amounts in unencrypted integer columns | Information Disclosure | Migration review gate: every money column in income_optimization_profiles must be TEXT type |
| Non-additive migration accidentally dropped via `Schema::dropIfExists()` | Tampering (data loss) | CLAUDE.md safety rule; migration review with `php artisan migrate --pretend` before running |

---

## Sources

### Primary (HIGH confidence)
- [CITED: IRS Rev. Proc. 2025-32] — 2026 federal brackets, standard deductions, LTCG thresholds (STACK.md, verified)
- [CITED: IRS Notice 2025-67] — 2026 401(k) employee deferral $24,500; IRA $7,500 + $1,100 catch-up; Roth phase-outs (STACK.md, verified)
- [CITED: IRS Notice 2026-05] — 2026 HSA self-only $4,400; family $8,750; HDHP limits (STACK.md, verified)
- [CITED: IRS.gov Topic 751 + SSA.gov] — SS wage base $184,500; SECA rate 15.3%; SE tax computation (STACK.md, verified)
- Direct codebase inspection 2026-07-01: `TaxDocument.php`, `UserFinancialProfile.php`, `IncomeDetectorService.php`, `TaxDocumentCategory.php`, `QuestionType.php`, `TaxDocumentExtractorService.php`, `phpunit.xml`, existing test patterns

### Secondary (MEDIUM confidence)
- [CITED: nationaltaxtools.com/guides/qbi-deduction/ (IRS Rev. Proc. 2025-32 analysis)] — QBI phase-out thresholds $201,750 single / $403,500 MFJ; OBBBA-expanded window $75k/$150k
- [CITED: warrenaverett.com OBBBA Breakdown QBI] — OBBBA §70105 permanent extension; $400 minimum deduction
- [CITED: IRS.gov final regulations notice on SECURE 2.0 catch-up] — §603 mandatory Roth catch-up effective 2026 plan years
- [CITED: sc.edu/human_resources SECURE 2.0 update] — $150,000 (indexed) threshold reference for §603

### Tertiary (LOW confidence — assumptions flagged)
- [ASSUMED: mandatory_roth_catchup_threshold exact 2026 value] — multiple sources conflict; confirm from IRS final regs before Phase 13
- [ASSUMED: QBI $400 minimum material participation test interpretable from profile data] — OBBBA §70105 language requires legal interpretation

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — zero new packages; all components from existing codebase
- Config/IRS constants: HIGH — verified against IRS Rev. Proc. 2025-32, Notice 2025-67, Notice 2026-05 (one ASSUMED item: §603 exact threshold)
- Architecture: HIGH — service contracts, model schema, and job patterns derived from direct codebase inspection of existing conventions
- Pitfalls: HIGH — each pitfall traced to specific implementation decision with concrete failure mode

**Research date:** 2026-07-01
**Valid until:** 2026-11-01 (IRS typically publishes next-year adjustments in October/November; check Rev. Proc. 2026-xx for 2027 values)
