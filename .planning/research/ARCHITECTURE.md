# Architecture Patterns

**Domain:** Optimize My Income — v2.1 feature layer over SpendifiAI (Laravel 12 + React 19 + Inertia 2)
**Researched:** 2026-07-01
**Confidence:** HIGH (direct codebase analysis; all patterns verified against existing models, services, enums, jobs)

---

## Existing Architecture Snapshot (v2.0 baseline)

Confirmed by directory inspection. The baseline this milestone integrates WITH:

| Layer | Count | Key v2.0 Additions |
|-------|-------|-------------------|
| Models | 40+ | TaxDocument, TaxVaultAuditLog, DocumentAnnotation, DocumentRequest, AccountingFirm, Household, Dependent, CharitableOrganization |
| Services | 15+ | TaxVaultStorageService, TaxVaultAuditService, AI/TaxDocumentExtractorService, AI/TaxDocumentIntelligenceService |
| Jobs | 13 | ExtractTaxDocument, SplitMultiDocumentPdf, MigrateStorageJob (all new in v2.0) |
| Enums | 14 | DocumentStatus, TaxDocumentCategory (25 cases), DocumentRequestStatus, UserType |
| Events | 5 | BankConnected, TransactionCategorized, TransactionsImported, UserAnsweredQuestion, OnboardingComplete |

**Key v2.0 facts that drive v2.1 design:**

- `TaxDocument.extracted_data` is `encrypted:array` — the full extraction JSON lives there, ready to read
- `TaxDocumentCategory` already has W2, 1099-NEC, 1099-INT, 1098 Mortgage, 1099-R, 5498-SA, 5498, PropertyTax, CharitableDonation — NEW doc types (check stubs, 401k statements, offer letters) need to be added as new enum cases
- `UserFinancialProfile` already has `has_hsa`, `has_fsa`, `has_ira`, `ira_type`, `has_home_office`, `has_rental_property`, `tax_filing_status`, `spouse_income (encrypted)` — far richer than CLAUDE.md shows; optimization engine can read these directly
- `TaxDocumentIntelligenceService` runs on-demand with 4-hour cache — pattern to follow for optimization profile
- `ExtractTaxDocument` job already exists and handles the classify→extract pipeline
- `UserAnsweredQuestion` event fires for every AIQuestion answer — the hook for bridging optimization findings into the existing AI Questions feed
- `QuestionType` enum has 4 cases (`Category`, `BusinessPersonal`, `Split`, `Confirm`) — adding `Optimization` is additive

---

## System Overview

```
╔══════════════════════════════════════════════════════════════════════╗
║                     DATA SOURCES (existing)                          ║
║  TaxDocument.extracted_data │ Transaction records │ ParsedEmail/Order ║
║  UserFinancialProfile       │ Subscription        │ IncomeDetector    ║
╚══════════════╤══════════════╧═══════════════╤══════════════╤═════════╝
               │ reads (no writes)            │              │
               ▼                              │              │
╔══════════════════════════════╗              │              │
║  IncomeOptimizerData         ║◄─────────────┘              │
║  AssemblerService            ║◄────────────────────────────┘
║  (builds snapshot)           ║
╚══════════════╤═══════════════╝
               │ writes
               ▼
╔══════════════════════════════╗
║  IncomeOptimizationProfile   ║  (new model — materialized snapshot)
║  (cached per user+year)      ║
╚══════════════╤═══════════════╝
               │ reads
       ┌───────┼───────────────┐
       ▼       ▼               ▼
╔══════════╗ ╔══════════════╗ ╔══════════════════════╗
║ TaxRules ║ ║ RedFlagDetec ║ ║ CrossSourceReview    ║
║ Engine   ║ ║ torService   ║ ║ Service              ║
║ (pure    ║ ║ (deterministic║ ║ (doc vs bank vs email║
║ math)    ║ ║ triggers)    ║ ║ discrepancy scanner) ║
╚══════════╝ ╚══════╤═══════╝ ╚══════════╤═══════════╝
                    │ creates              │ creates
                    └──────────┬───────────┘
                               ▼
                   ╔═══════════════════════╗
                   ║  OptimizationFinding  ║  (new model — persisted findings)
                   ║  (per user+year)      ║
                   ╚═══════════╤═══════════╝
                               │
             ┌─────────────────┼──────────────────────┐
             ▼                 ▼                       ▼
   ╔══════════════════╗ ╔═════════════════╗ ╔═════════════════════╗
   ║ SurfaceOptimiza  ║ ║ InterviewOrches ║ ║ OptimizationReport  ║
   ║ tionQuestions    ║ ║ tratorService   ║ ║ GeneratorService    ║
   ║ (Job → AIQuestion║ ║ (state machine) ║ ║ (Claude for prose)  ║
   ║  existing model) ║ ╚═════════╤═══════╝ ╚═════════╤═══════════╝
   ╚══════════════════╝           │                   │
                                  ▼                   ▼
                       ╔══════════════════╗ ╔══════════════════╗
                       ║ OptimizationQues ║ ║ OptimizationRepo ║
                       ║ tion (new model) ║ ║ rt   (new model) ║
                       ╚══════════════════╝ ╚══════════════════╝
```

---

## Component Boundaries

| Component | Responsibility | Communicates With | Uses Claude? |
|-----------|---------------|-------------------|--------------|
| **IncomeOptimizerDataAssemblerService** | Reads all sources, builds materialized snapshot into IncomeOptimizationProfile | TaxDocument, Transaction, UserFinancialProfile, Subscription, ParsedEmail | No |
| **TaxRulesEngineService** | 2026 tax brackets, contribution limits, standard vs itemized math, SE deduction, QBI — pure PHP | IncomeOptimizationProfile, OptimizationReportGeneratorService | No |
| **RedFlagDetectorService** | Deterministic pattern triggers (filing status, deduction probes, Roth vs Traditional) | IncomeOptimizationProfile, TaxRulesEngineService | No (detection); Yes (description generation only) |
| **CrossSourceReviewService** | Compares doc extractions vs bank deposits vs email orders for discrepancies and opportunities | TaxDocument, Transaction, Order | No (detection); Yes (explanation generation) |
| **InterviewOrchestratorService** | Session state machine: prioritize findings → generate questions → record answers → advance | OptimizationFinding, InterviewSession, OptimizationQuestion | Yes (question wording and follow-ups) |
| **OptimizationReportGeneratorService** | Assembles 4-section report; TaxRulesEngine provides all numbers; Claude writes narratives | TaxRulesEngineService, OptimizationFinding, InterviewSession | Yes (narratives only) |
| **SurfaceOptimizationQuestions (Job)** | Bridges high-priority red flags into existing AIQuestion feed | AIQuestion (existing model), QuestionType::Optimization (new enum case) | No |
| **BuildIncomeOptimizationProfile (Job)** | Orchestrates assembler + detectors; fires OptimizationProfileBuilt event | All services above except Interview and Report | Minimal (finding descriptions) |
| **GenerateOptimizationReport (Job)** | Triggers report generation after session completion | OptimizationReportGeneratorService | Yes |

---

## New Models (6 tables)

### Model Relationship Map

```
User (existing)
 |-- hasOne  --> IncomeOptimizationProfile  (per user+year, one active)
 |-- hasMany --> OptimizationFinding        (many per user+year)
 |-- hasMany --> InterviewSession           (one active at a time)
 |               |-- hasMany --> OptimizationQuestion
 |-- hasOne  --> OptimizationReport         (per user+year)

OptimizationFinding
 |-- belongsTo --> User
 |-- belongsTo --> InterviewSession (nullable — pre-session findings also exist)
 |-- has: finding_type, category, estimated_impact, source, is_red_flag

InterviewSession
 |-- belongsTo --> User
 |-- hasMany   --> OptimizationQuestion
 |-- hasOne    --> OptimizationReport

OptimizationQuestion
 |-- belongsTo --> InterviewSession
 |-- belongsTo --> OptimizationFinding (nullable — question may relate to a finding)

OptimizationReport
 |-- belongsTo --> User
 |-- belongsTo --> InterviewSession

IncomeOptimizationProfile
 |-- belongsTo --> User
 |-- (no relationships outward — pure snapshot cache)
```

### Detailed Model Specs

#### IncomeOptimizationProfile

Materialized financial snapshot. Rebuilt by `BuildIncomeOptimizationProfile` job. Treated as a cache — destroyed and recreated on refresh, not updated in place.

```
id, user_id, tax_year
-- Income signals (all encrypted TEXT columns)
w2_wages_cents            encrypted TEXT  -- from TaxDocument W-2 extractions, summed
self_employment_income_cents encrypted TEXT
interest_income_cents     encrypted TEXT  -- 1099-INT
dividend_income_cents     encrypted TEXT  -- 1099-DIV
retirement_distributions_cents encrypted TEXT -- 1099-R
bank_deposit_total_cents  encrypted TEXT  -- from Transaction records, income-classified
-- Deduction signals
mortgage_interest_cents   encrypted TEXT  -- from 1098 extraction
property_tax_cents        encrypted TEXT
student_loan_interest_cents encrypted TEXT -- 1098-E
charitable_contributions_cents encrypted TEXT
-- Retirement signals
traditional_401k_ytd_cents encrypted TEXT -- from retirement doc extractions
roth_401k_ytd_cents       encrypted TEXT
ira_ytd_cents             encrypted TEXT
hsa_ytd_cents             encrypted TEXT
-- Computed flags (non-sensitive, plain integers/booleans)
filing_status             varchar(20)     -- from UserFinancialProfile
has_home_office           boolean
has_self_employment       boolean
estimated_age             integer nullable -- derived if DOB available
-- Metadata
data_sources              jsonb           -- which TaxDocuments/date-ranges contributed
doc_count                 integer
profile_hash              varchar(64)     -- SHA-256 of inputs; detect staleness
built_at                  timestamp
```

Migration rule: all money fields are encrypted TEXT (matching existing convention). Non-sensitive computed fields are plain columns. No `updated_at` — always create new row.

#### OptimizationFinding

```
id, user_id, tax_year
finding_key               varchar(100)    -- unique slug: 'roth_vs_traditional', 'guard_dog_deduction'
finding_type              OptimizationFindingType enum
category                  OptimizationFindingCategory enum -- taxes/retirement/deductions/filings
title                     varchar(255)
description               text            -- Claude-generated, educational
estimated_annual_impact_cents integer     -- deterministic math result (nullable if unknown)
confidence                varchar(10)     -- high/medium/low (from TaxRulesEngineService)
is_red_flag               boolean         -- true = surfaced in AI Questions feed
action_items              jsonb           -- array of {text, url_label, url} objects
supporting_data           jsonb           -- what data triggered this (sanitized, no raw PII)
disclaimer                text
source                    varchar(30)     -- 'rules_engine' / 'red_flag_detector' / 'cross_source'
status                    OptimizationFindingStatus enum
interview_session_id      bigint nullable FK
acknowledged_at           timestamp nullable
dismissed_at              timestamp nullable
timestamps                (standard created_at/updated_at)
```

Unique constraint: `(user_id, tax_year, finding_key)` — prevents duplicate findings. Use `updateOrCreate` on upsert.

#### InterviewSession

```
id, user_id, tax_year
status                    InterviewSessionStatus enum
current_question_index    integer default 0
findings_addressed        jsonb   -- array of finding_key strings covered so far
questions_count           integer -- total questions planned
completed_at              timestamp nullable
report_generated_at       timestamp nullable
timestamps
```

One active session per user per tax year. Enforce with unique partial index: `WHERE status IN ('draft','active')`.

#### OptimizationQuestion

```
id, interview_session_id, user_id
finding_key               varchar(100) nullable -- which finding triggered this question
question_order            integer
question_type             OptimizationQuestionType enum
question_text             text            -- Claude-generated
help_text                 text nullable   -- plain-English context Claude adds
options                   jsonb nullable  -- for multiple_choice type
answer                    text nullable
answered_at               timestamp nullable
skipped                   boolean default false
source                    varchar(30)     -- 'rules_engine' / 'claude' / 'manual_seed'
timestamps
```

#### OptimizationReport

```
id, user_id, tax_year, interview_session_id
status                    OptimizationReportStatus enum -- generating/ready/stale
summary_text              text encrypted  -- Claude executive summary
deductions_section        encrypted:array -- structured JSON
taxes_section             encrypted:array
retirement_section        encrypted:array
filings_section           encrypted:array
total_estimated_impact_cents integer nullable
disclaimer_text           text
expires_at                timestamp       -- stale after 30 days or new doc upload
generated_at              timestamp nullable
timestamps
```

---

## New Enums (5)

All follow existing backed-string pattern with `label()` method.

```php
enum OptimizationFindingType: string {
    case FilingStatusOptimization = 'filing_status_optimization';
    case MissedHomeOfficeDeduction = 'missed_home_office_deduction';
    case MissedGuardDogDeduction = 'missed_guard_dog_deduction';
    case MissedWorkElectronicsDeduction = 'missed_work_electronics_deduction';
    case RetirementContributionGap = 'retirement_contribution_gap';
    case TraditionalVsRothOptimization = 'traditional_vs_roth_optimization';
    case HsaOpportunity = 'hsa_opportunity';
    case IncomeDiscrepancy = 'income_discrepancy';
    case SelfEmploymentDeductionMissed = 'se_deduction_missed';
    case QbiDeductionEligible = 'qbi_deduction_eligible';
    case DeductibleSubscription = 'deductible_subscription';
    case CharitableContributionDeduction = 'charitable_contribution_deduction';
    case Other = 'other';
}

enum OptimizationFindingCategory: string {
    case Taxes = 'taxes';           // Tax bracket / SE tax / QBI
    case Retirement = 'retirement'; // 401k / IRA / Roth
    case Deductions = 'deductions'; // Home office / electronics / charitable
    case Filings = 'filings';       // Filing status / overlooked forms
}

enum OptimizationFindingStatus: string {
    case New = 'new';
    case Acknowledged = 'acknowledged';
    case Dismissed = 'dismissed';
    case Implemented = 'implemented';
}

enum InterviewSessionStatus: string {
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Abandoned = 'abandoned';
}

enum OptimizationQuestionType: string {
    case YesNo = 'yes_no';
    case MultipleChoice = 'multiple_choice';
    case Amount = 'amount';         // Numeric entry
    case Informational = 'informational'; // No answer; displays a finding
}

enum OptimizationReportStatus: string {
    case Generating = 'generating';
    case Ready = 'ready';
    case Stale = 'stale';
}
```

**Existing enum addition (additive only):**

```php
// app/Enums/QuestionType.php — ADD one case:
case Optimization = 'optimization';

// app/Enums/TaxDocumentCategory.php — ADD new doc type cases:
case CheckStub = 'check_stub';
case OfferLetter = 'offer_letter';
case RetirementStatement = 'retirement_statement';
case BenefitsStatement = 'benefits_statement';
case StockStatement = 'stock_statement';
case InsuranceStatement = 'insurance_statement';
// (1098 Mortgage already exists; no addition needed)
```

---

## New Services (6)

### 1. IncomeOptimizerDataAssemblerService

**Location:** `app/Services/IncomeOptimizerDataAssemblerService.php`
**Uses Claude:** No
**Purpose:** Reads all existing data sources and materializes an `IncomeOptimizationProfile`.

```php
class IncomeOptimizerDataAssemblerService
{
    public function buildProfile(User $user, int $taxYear): IncomeOptimizationProfile;
    public function isStale(IncomeOptimizationProfile $profile): bool; // compare profile_hash
    private function sumW2Wages(User $user, int $taxYear): int;        // from TaxDocument extractions
    private function sumBankDeposits(User $user, int $taxYear): int;   // from Transaction records (income-classified)
    private function sumRetirementContributions(User $user, int $taxYear): array; // from retirement docs
    private function extractMortgageInterest(User $user, int $taxYear): int; // from 1098 extractions
    private function computeProfileHash(array $inputs): string;
}
```

Reading `TaxDocument.extracted_data` (already `encrypted:array`): group by category, sum target fields per document type. No Claude needed — the extraction already ran via the existing `ExtractTaxDocument` job pipeline.

Reading Transactions: use existing `IncomeDetectorService` to separate primary vs extra income. Sum deposits for the tax year.

### 2. TaxRulesEngineService

**Location:** `app/Services/TaxRulesEngineService.php`
**Uses Claude:** Never
**Purpose:** All deterministic 2026 tax math. Single source of truth for tax logic. Config-driven limits so values can be updated without code changes.

```php
class TaxRulesEngineService
{
    // Rate lookups
    public function effectiveTaxRate(int $agiCents, string $filingStatus): float;
    public function marginalRate(int $agiCents, string $filingStatus): float;

    // Deduction comparison
    public function standardDeductionCents(string $filingStatus, ?int $age = null): int;
    public function compareStandardVsItemized(int $itemizedTotal, string $filingStatus): array;
    // returns: ['recommendation' => 'standard'|'itemized', 'difference_cents' => int, 'confidence' => string]

    // Retirement limits (2026 IRS limits from config)
    public function remaining401kRoom(int $ytdContributionCents, ?int $age = null): int;
    public function remainingIraRoom(int $ytdContributionCents, ?int $age = null): int;
    public function remainingHsaRoom(int $ytdContributionCents, string $coverageType): int;

    // Roth eligibility
    public function rothIraEligible(int $magiCents, string $filingStatus): bool;
    public function rothPhaseOutRemaining(int $magiCents, string $filingStatus): int;

    // SE-specific
    public function selfEmploymentTaxDeductionCents(int $netSelfEmploymentCents): int;
    public function qbiDeductionCents(int $qualifiedBusinessIncomeCents, int $taxableIncomeCents): int;

    // Impact projections
    public function taxSavingsFromDeductionCents(int $deductionCents, int $agiCents, string $filingStatus): int;
}
```

All tax bracket tables and limits live in `config/spendifiai.php` under a new `tax_rules` key:

```php
'tax_rules' => [
    'tax_year' => 2026,
    '401k_limit_cents' => 2350000,           // $23,500
    '401k_catchup_cents' => 750000,          // +$7,500 if age >= 50
    'ira_limit_cents' => 700000,             // $7,000
    'ira_catchup_cents' => 100000,           // +$1,000 if age >= 50
    'hsa_individual_cents' => 430000,        // $4,300 (estimate; verify 2026 IRS announcement)
    'hsa_family_cents' => 860000,            // $8,600
    'standard_deduction' => [
        'single' => 1500000,                 // $15,000 (2026 estimate, inflation-adjusted from 2025 $14,600)
        'married_filing_jointly' => 3000000,
        'head_of_household' => 2250000,
        'married_filing_separately' => 1500000,
    ],
    'brackets' => [ /* ... 2026 bracket arrays by filing status */ ],
],
```

**Why no Claude here:** Tax math is deterministic. Claude is expensive, slow, and can hallucinate dollar thresholds. This is pure PHP arithmetic. The rules engine is the "always-right" layer; Claude only writes the human-readable story around it.

### 3. RedFlagDetectorService

**Location:** `app/Services/RedFlagDetectorService.php`
**Uses Claude:** Only to generate `description` and `action_items` for detected flags (not for detection logic)
**Purpose:** Named detector methods, each returning a `RedFlag` DTO or null.

```php
class RedFlagDetectorService
{
    /**
     * Run all detectors. Returns array of OptimizationFinding data to persist.
     */
    public function detectAll(IncomeOptimizationProfile $profile, User $user): array;

    // Individual detectors — each is deterministic
    private function detectFilingStatusMismatch(IncomeOptimizationProfile $profile): ?RedFlag;
    private function detectMissedHomeOfficeDeduction(IncomeOptimizationProfile $profile, UserFinancialProfile $ufp): ?RedFlag;
    private function detectGuardDogDeduction(IncomeOptimizationProfile $profile, User $user): ?RedFlag;
    private function detectWorkElectronicsDeduction(IncomeOptimizationProfile $profile, User $user): ?RedFlag;
    private function detectTraditionalVsRothOptimization(IncomeOptimizationProfile $profile, UserFinancialProfile $ufp): ?RedFlag;
    private function detectRetirementContributionGap(IncomeOptimizationProfile $profile): ?RedFlag;
    private function detectHsaOpportunity(IncomeOptimizationProfile $profile, UserFinancialProfile $ufp): ?RedFlag;
    private function detectQbiEligibility(IncomeOptimizationProfile $profile): ?RedFlag;
    private function detectSEDeductionMissed(IncomeOptimizationProfile $profile): ?RedFlag;

    // Called per detected flag to generate human-readable content
    private function generateFindingContent(RedFlag $flag, IncomeOptimizationProfile $profile): array;
    // ^ calls Claude once per detected flag; not per scan
}
```

**Detection logic examples (deterministic):**

- `detectGuardDogDeduction`: scan Transaction records for pet-related merchants (vet, PetSmart, Chewy) AND check `has_home_office OR has_rental_property OR employment_type = 'self_employed'`. If both true → red flag.
- `detectTraditionalVsRothOptimization`: if `traditional_401k_ytd > 0 AND marginalRate(agi) > 0.22 AND rothIraEligible(agi)` → flag opportunity to compare. If `marginalRate < 0.12 AND traditional_401k_ytd > 0` → flag "Roth may be better at your bracket."
- `detectRetirementContributionGap`: `remaining401kRoom(ytd_contributions) > 0 AND (annual_income_cents / 12 * remaining_months_in_year) > 500_00` → flag with gap amount.

**Claude call (after detection only):** A single prompt per detected flag generates `description` (2-3 sentences, educational framing) and `action_items` array. Example prompt structure:
```
"Write a brief, friendly explanation for a taxpayer about the following potential tax optimization.
Finding: {flag.title}
Key numbers: {flag.estimated_impact}
Always end with: 'Review this with a licensed tax professional before making any changes.'
Format: {description: string, action_items: string[]}"
```

### 4. CrossSourceReviewService

**Location:** `app/Services/CrossSourceReviewService.php`
**Uses Claude:** Only for plain-English explanation of detected gaps (same pattern as RedFlagDetectorService)
**Purpose:** Reads extracted doc data, bank deposits, and email/order data; surfaces discrepancies and opportunities.

```php
class CrossSourceReviewService
{
    public function review(IncomeOptimizationProfile $profile, User $user, int $taxYear): array;

    private function compareW2VsDeposits(IncomeOptimizationProfile $profile): ?IncomeDiscrepancy;
    // Compare profile.w2_wages_cents vs profile.bank_deposit_total_cents (within 15% tolerance)

    private function compare1099VsDeposits(IncomeOptimizationProfile $profile): ?IncomeDiscrepancy;
    // Compare self_employment_income (from 1099-NEC/K/MISC) vs bank deposits from self-employment sources

    private function findDeductibleSubscriptions(User $user, int $taxYear): array;
    // Scan Subscription records with business-related merchants (Adobe, AWS, GitHub, Zoom, Notion)
    // Cross-reference with UserFinancialProfile.employment_type = 'self_employed'

    private function findUnclaimedBusinessExpenses(User $user, int $taxYear): array;
    // Look for transaction categories that are often deductible when self-employed
    // (professional services, software, office supplies) not yet claimed as deductions

    private function findMortgageDeductionOpportunity(IncomeOptimizationProfile $profile, UserFinancialProfile $ufp): ?array;
    // If mortgage_interest_cents > 0 AND user may benefit from itemizing
}
```

### 5. InterviewOrchestratorService

**Location:** `app/Services/InterviewOrchestratorService.php`
**Uses Claude:** Yes — generates question_text and help_text for each question
**Purpose:** State machine managing the guided interview session.

```php
class InterviewOrchestratorService
{
    public function startSession(User $user, int $taxYear): InterviewSession;
    public function getNextQuestion(InterviewSession $session): ?OptimizationQuestion;
    public function recordAnswer(OptimizationQuestion $question, string $answer): void;
    public function skipQuestion(OptimizationQuestion $question): void;
    public function completeSession(InterviewSession $session): void;
    public function planQuestions(InterviewSession $session, Collection $findings): void;

    private function prioritizeFindings(Collection $findings): Collection;
    // Sort by: is_red_flag DESC, estimated_annual_impact_cents DESC, confidence DESC

    private function generateQuestionForFinding(OptimizationFinding $finding, InterviewSession $session): OptimizationQuestion;
    // Calls Claude with finding context to generate conversational question_text + help_text

    private function shouldSkipFinding(OptimizationFinding $finding, UserFinancialProfile $ufp): bool;
    // De-duplicates: skip if UserFinancialProfile already has the answer
    // (e.g., skip "Do you have an HSA?" if has_hsa = true already stored)
}
```

**Session state transitions:**
```
draft → active (on startSession)
active → completed (on completeSession, fires InterviewSessionCompleted event)
active → abandoned (after 30 days without activity, via scheduled task)
```

**De-duplication with AI Questions feed:** Before generating an interview question, check if an `AIQuestion` with `question_type = Optimization` and matching `finding_key` already exists and is pending. If so, link to it instead of creating a duplicate.

### 6. OptimizationReportGeneratorService

**Location:** `app/Services/AI/OptimizationReportGeneratorService.php`
**Uses Claude:** Yes — generates executive summary and section narratives
**Purpose:** Assembles the 4-section report after interview completion.

```php
class OptimizationReportGeneratorService
{
    public function generate(InterviewSession $session): OptimizationReport;
    public function refresh(OptimizationReport $report): OptimizationReport;

    private function buildDeductionsSection(User $user, int $taxYear, Collection $findings, IncomeOptimizationProfile $profile): array;
    // Uses TaxRulesEngineService for all dollar amounts
    // Returns structured JSON: [{title, estimated_savings_cents, explanation, action_items, disclaimer}]

    private function buildTaxesSection(...): array;
    private function buildRetirementSection(...): array;
    private function buildFilingsSection(...): array;

    private function generateExecutiveSummary(array $sections, IncomeOptimizationProfile $profile): string;
    // Single Claude call for the summary; all numbers already computed deterministically
}
```

**Claude prompt discipline for report:** Pass all computed numbers (from TaxRulesEngineService) as structured context. Claude writes prose only. Example:

```
Context: {
  filing_status: "single",
  estimated_agi: "$68,000",
  marginal_rate: "22%",
  traditional_401k_room: "$15,200 remaining",
  retirement_findings: [{...}],
  deduction_findings: [{...}]
}
Task: Write a 3-sentence executive summary of the top optimization opportunities.
Include total estimated annual impact of $X. End with the standard disclaimer.
```

---

## New API Controller

**Location:** `app/Http/Controllers/Api/IncomeOptimizerController.php`

```
GET  /api/v1/optimize                       status + findings summary (cached)
GET  /api/v1/optimize/findings              list findings for current tax year
POST /api/v1/optimize/analyze               trigger BuildIncomeOptimizationProfile (rate: 5/min)

GET  /api/v1/optimize/interview             get current/latest session
POST /api/v1/optimize/interview/start       start new session
GET  /api/v1/optimize/interview/{session}/question  get next question
POST /api/v1/optimize/interview/{session}/answer    record answer
POST /api/v1/optimize/interview/{session}/skip      skip question
POST /api/v1/optimize/interview/{session}/complete  mark complete → fires event

GET  /api/v1/optimize/report/{year?}        get optimization report (current year default)
POST /api/v1/optimize/findings/{finding}/dismiss
POST /api/v1/optimize/findings/{finding}/acknowledge
```

**Middleware:** `auth:sanctum` + `bank.connected` (bank data needed for cross-source review). Rate limit `POST /analyze` at 5/min to match existing sensitive-action pattern.

**Policy:** `OptimizationPolicy` — `user_id === auth()->id()` check. No accountant access (personal optimization data).

---

## New Jobs (2)

### BuildIncomeOptimizationProfile

```
Location:  app/Jobs/BuildIncomeOptimizationProfile.php
Trigger:   IncomeOptimizerController::analyze() (manual)
           Listener: TransactionCategorized (after bank sync)
           Listener: TaxDocumentExtracted (after vault extraction)
Input:     user_id, tax_year
Process:   1. IncomeOptimizerDataAssemblerService::buildProfile()
           2. RedFlagDetectorService::detectAll()
           3. CrossSourceReviewService::review()
           4. Upsert OptimizationFindings (updateOrCreate by user_id+tax_year+finding_key)
           5. fire OptimizationProfileBuilt event
Queue:     'optimization' (new queue name)
$tries=3, $timeout=180
```

No Claude calls during detection. Claude called only for descriptions of detected findings (bounded cost: only fires for findings that trigger).

### GenerateOptimizationReport

```
Location:  app/Jobs/GenerateOptimizationReport.php
Trigger:   InterviewSessionCompleted event listener
Input:     interview_session_id
Process:   1. OptimizationReportGeneratorService::generate()
           2. Update OptimizationReport status to 'ready'
           3. fire OptimizationReportReady event
Queue:     'optimization'
$tries=3, $timeout=300 (Claude calls for narratives)
```

---

## New Events (3)

```
app/Events/OptimizationProfileBuilt.php
  payload: user_id, tax_year, findings_count, red_flag_count
  listeners:
    - SurfaceHighPriorityRedFlags (creates AIQuestion records for is_red_flag=true findings)
    - NotifyOptimizationReady (if findings_count > 0 and user has no active session)

app/Events/InterviewSessionCompleted.php
  payload: interview_session_id, user_id
  listeners:
    - DispatchReportGeneration (dispatches GenerateOptimizationReport job)

app/Events/OptimizationReportReady.php
  payload: user_id, report_id
  listeners:
    - NotifyReportReady (database notification; no email for now)
```

---

## New Listeners (3)

```
app/Listeners/SurfaceHighPriorityRedFlags.php
  - Fired by: OptimizationProfileBuilt
  - Creates AIQuestion records (existing model) for all findings where is_red_flag=true
  - Uses QuestionType::Optimization (new enum case, additive)
  - Sets question text from OptimizationFinding.title + a short prompt
  - Idempotent: checks for existing pending AIQuestion with same finding_key before creating

app/Listeners/DispatchReportGeneration.php
  - Fired by: InterviewSessionCompleted
  - Dispatches GenerateOptimizationReport job

app/Listeners/NotifyOptimizationReady.php
  - Fired by: OptimizationProfileBuilt
  - Creates database notification record if new high-impact findings detected
```

**Bridge to existing AI Questions feed (CRITICAL for backwards compatibility):**

`SurfaceHighPriorityRedFlags` creates standard `AIQuestion` records using the EXISTING model. The existing `AIQuestionController::answer()` handles them unchanged — the `UserAnsweredQuestion` event fires as normal. A NEW listener `UpdateOptimizationFromAnswer` (to be added to `UserAnsweredQuestion`) reads the `question_type = 'optimization'` case and updates the corresponding `OptimizationFinding.status` to `acknowledged`. No changes to existing handler logic.

---

## Modified Existing Components (minimal, additive only)

### Existing enum additions (2 files, additive cases only)

**`app/Enums/QuestionType.php`** — add `case Optimization = 'optimization';`
**`app/Enums/TaxDocumentCategory.php`** — add 5-6 new doc type cases (check stub, offer letter, retirement statement, benefits statement, stock statement, insurance statement)

### Existing event listener registration

**`app/Providers/EventServiceProvider.php`** (or `bootstrap/app.php` listener registration) — add 3 new listeners to new events + add `UpdateOptimizationFromAnswer` to existing `UserAnsweredQuestion` event. No changes to existing listeners.

### Existing `TaxDocumentExtractorService`

**No changes.** The extractor already handles doc types via `TaxDocumentCategory` enum. When new enum cases are added, add corresponding extraction prompt configurations in `config/spendifiai.php` under `extraction.prompts`. The service reads from config, not from hardcoded arrays.

### Existing scheduled tasks

Add to `routes/console.php`:
```php
// Daily — mark abandoned sessions (inactive > 30 days)
Schedule::job(new AbandonStaleInterviewSessions)->dailyAt('05:00');

// Weekly — refresh stale optimization profiles (built > 7 days ago)
Schedule::job(new RefreshStaleOptimizationProfiles)->weeklyOn(1, '05:30');
```

---

## Data Flow: Docs + Bank + Email → Report

```
TRIGGER: User clicks "Analyze" or new doc extracted or bank sync completes
    ↓
BuildIncomeOptimizationProfile Job
    ↓
    ├── IncomeOptimizerDataAssemblerService
    │     ├── reads TaxDocument.extracted_data (WHERE user_id AND tax_year AND status='extracted')
    │     ├── reads Transaction (income-classified, WHERE date BETWEEN tax_year start/end)
    │     ├── reads UserFinancialProfile (filing_status, has_home_office, employment_type, etc.)
    │     ├── reads Subscription (active, business-purpose accounts)
    │     └── writes IncomeOptimizationProfile (upsert by user_id+tax_year)
    │
    ├── RedFlagDetectorService.detectAll(profile)
    │     ├── each detector: pure PHP boolean logic → RedFlag DTO or null
    │     ├── for each detected flag: one Claude call → description + action_items
    │     └── upsert OptimizationFinding records (is_red_flag=true)
    │
    └── CrossSourceReviewService.review(profile)
          ├── compareW2VsDeposits() → IncomeDiscrepancy or null
          ├── findDeductibleSubscriptions() → array
          ├── findUnclaimedBusinessExpenses() → array
          └── upsert OptimizationFinding records (is_red_flag=false)

    ↓
OptimizationProfileBuilt event fires
    ↓
    ├── SurfaceHighPriorityRedFlags listener
    │     └── Creates AIQuestion records for is_red_flag=true findings
    │           (existing model, new QuestionType::Optimization case)
    │
    └── NotifyOptimizationReady listener → database notification

INTERVIEW FLOW (user-initiated):
    ↓
POST /api/v1/optimize/interview/start
    ↓
    InterviewOrchestratorService.startSession()
        ↓ loads all OptimizationFindings for user+year
        ↓ prioritizes by impact + is_red_flag
        ↓ filters: skip if UserFinancialProfile already has the answer
        ↓ generates OptimizationQuestion records (Claude per question for wording)
        ↓ returns first question

    GET /api/v1/optimize/interview/{session}/question → one question at a time
    POST /api/v1/optimize/interview/{session}/answer  → record, advance index
    (repeat until questions_count reached or user completes)

POST /api/v1/optimize/interview/{session}/complete
    ↓
    InterviewSessionCompleted event fires
    ↓
    DispatchReportGeneration listener → GenerateOptimizationReport job
    ↓
    OptimizationReportGeneratorService.generate(session)
        ↓ TaxRulesEngineService → all dollar amounts (no Claude)
        ↓ assembles 4 sections from OptimizationFindings + interview answers
        ↓ ONE Claude call → executive summary prose
        ↓ ONE Claude call per section → section narrative (max 4 calls)
        ↓ writes OptimizationReport

    OptimizationReportReady event fires → database notification
```

---

## Deterministic Math vs Claude — Explicit Boundary

| Task | Where it lives | Why |
|------|----------------|-----|
| Calculate effective/marginal tax rate | `TaxRulesEngineService` | Deterministic; Claude can hallucinate dollar thresholds |
| Compare standard vs itemized deduction | `TaxRulesEngineService` | Arithmetic; must be correct |
| Remaining 401k/IRA contribution room | `TaxRulesEngineService` | IRS limits are fixed; PHP is cheaper and faster |
| Detect filing status mismatch | `RedFlagDetectorService` | Boolean logic on profile fields |
| Detect guard dog / work electronics | `RedFlagDetectorService` | Merchant pattern + profile flag matching |
| Detect Roth vs Traditional opportunity | `RedFlagDetectorService` + `TaxRulesEngineService` | Rate comparison is math |
| Compare W-2 wages vs bank deposits | `CrossSourceReviewService` | Arithmetic comparison with tolerance |
| Estimate tax savings from deduction | `TaxRulesEngineService` | `deduction * marginal_rate` |
| Write finding description + action items | Claude (via RedFlagDetectorService) | Natural language, context-aware explanation |
| Write interview question text + help text | Claude (via InterviewOrchestratorService) | Conversational, personalized wording |
| Write report section narratives | Claude (via OptimizationReportGeneratorService) | Synthesis prose; numbers pre-computed |
| Write executive summary | Claude (via OptimizationReportGeneratorService) | Synthesis prose; numbers pre-computed |

**Maximum Claude calls per full optimization cycle (one user, one year):**
- Finding descriptions: 1 call per detected finding (typically 3-8 findings) = ~5 calls
- Interview questions: 1 call per question generated (typically 5-12 questions) = ~8 calls
- Report generation: 1 summary + 4 section narratives = 5 calls
- Total: ~18 Claude calls per complete cycle (bounded, not per-request)

---

## New Files — Complete List

### New Models (6)
```
app/Models/IncomeOptimizationProfile.php
app/Models/OptimizationFinding.php
app/Models/InterviewSession.php
app/Models/OptimizationQuestion.php
app/Models/OptimizationReport.php
```
(5 models; IncomeOptimizationProfile is a single-model cache, so 5 total new model files)

### New Enums (6)
```
app/Enums/OptimizationFindingType.php
app/Enums/OptimizationFindingCategory.php
app/Enums/OptimizationFindingStatus.php
app/Enums/InterviewSessionStatus.php
app/Enums/OptimizationQuestionType.php
app/Enums/OptimizationReportStatus.php
```

### New Services (6)
```
app/Services/IncomeOptimizerDataAssemblerService.php
app/Services/TaxRulesEngineService.php
app/Services/RedFlagDetectorService.php
app/Services/CrossSourceReviewService.php
app/Services/InterviewOrchestratorService.php
app/Services/AI/OptimizationReportGeneratorService.php
```

### New Jobs (4)
```
app/Jobs/BuildIncomeOptimizationProfile.php
app/Jobs/GenerateOptimizationReport.php
app/Jobs/AbandonStaleInterviewSessions.php
app/Jobs/RefreshStaleOptimizationProfiles.php
```

### New Events (3)
```
app/Events/OptimizationProfileBuilt.php
app/Events/InterviewSessionCompleted.php
app/Events/OptimizationReportReady.php
```

### New Listeners (4)
```
app/Listeners/SurfaceHighPriorityRedFlags.php
app/Listeners/DispatchReportGeneration.php
app/Listeners/NotifyOptimizationReady.php
app/Listeners/UpdateOptimizationFromAnswer.php
```

### New Controllers (1)
```
app/Http/Controllers/Api/IncomeOptimizerController.php
```

### New Policies (1)
```
app/Policies/OptimizationPolicy.php
```

### New Form Requests (4)
```
app/Http/Requests/StartInterviewSessionRequest.php
app/Http/Requests/AnswerOptimizationQuestionRequest.php
app/Http/Requests/DismissOptimizationFindingRequest.php
app/Http/Requests/TriggerOptimizationAnalysisRequest.php
```

### New Migrations (5, in dependency order)
```
database/migrations/XXXX_create_income_optimization_profiles_table.php
database/migrations/XXXX_create_optimization_findings_table.php
database/migrations/XXXX_create_interview_sessions_table.php
database/migrations/XXXX_create_optimization_questions_table.php
database/migrations/XXXX_create_optimization_reports_table.php
```

### New Frontend
```
resources/js/Pages/OptimizeIncome.tsx          (main page, 3 steps)
resources/js/Components/SpendifiAI/OptimizationFindingCard.tsx
resources/js/Components/SpendifiAI/InterviewQuestionCard.tsx
resources/js/Components/SpendifiAI/OptimizationReportSection.tsx
resources/js/Components/SpendifiAI/OptimizationProgressBar.tsx
resources/js/Components/SpendifiAI/OptimizationDisclaimer.tsx
```

### Modified Existing Files (minimal)
```
app/Enums/QuestionType.php              ADD: case Optimization = 'optimization'
app/Enums/TaxDocumentCategory.php       ADD: 6 new doc type cases
routes/api.php                          ADD: /api/v1/optimize route group
routes/console.php                      ADD: 2 new scheduled jobs
config/spendifiai.php                   ADD: tax_rules section
bootstrap/app.php (or EventServiceProvider)  ADD: event→listener mappings
resources/js/Layouts/AuthenticatedLayout.tsx  ADD: "Optimize My Income" nav item
resources/js/types/spendifiai.d.ts      ADD: new TypeScript interfaces
```

---

## Suggested Build Order (dependency-ordered)

### Phase 1: Foundation — Data Assembly + Rules Engine

Build first because everything else depends on these.

1. `TaxRulesEngineService` (pure PHP, no dependencies, no DB)
2. `IncomeOptimizationProfile` model + migration
3. `OptimizationFinding` model + migration + enums (FindingType, FindingCategory, FindingStatus)
4. `IncomeOptimizerDataAssemblerService` (reads existing models, writes profile)
5. `BuildIncomeOptimizationProfile` job (no Claude yet — just data assembly)
6. Config additions to `spendifiai.php` (tax_rules section)
7. Tests: TaxRulesEngineService unit tests (pure math, no Claude needed in tests)

**Gate:** Profile builds correctly from existing data before adding detectors.

### Phase 2: Detection — Red Flags + Cross-Source

Depends on Phase 1 (profile exists).

1. `RedFlagDetectorService` (deterministic detectors only; Claude calls stubbed)
2. `CrossSourceReviewService` (deterministic comparison)
3. Wire detectors into `BuildIncomeOptimizationProfile` job
4. `OptimizationProfileBuilt` event + `SurfaceHighPriorityRedFlags` listener
5. `UpdateOptimizationFromAnswer` listener (added to existing `UserAnsweredQuestion` event)
6. Add `QuestionType::Optimization` enum case
7. `OptimizationPolicy` + `IncomeOptimizerController` (GET /optimize, GET /optimize/findings, POST /optimize/analyze)
8. Frontend: basic OptimizeIncome page Step 1 (findings list, static)
9. Tests: red-flag detectors (mock TransactionCategorizerService pattern for Claude)

**Gate:** Findings generate correctly, surface in AI Questions feed, no existing AI Questions tests break.

### Phase 3: Interview State Machine

Depends on Phase 2 (findings exist to interview about).

1. `InterviewSession` model + migration + `InterviewSessionStatus` enum
2. `OptimizationQuestion` model + migration + `OptimizationQuestionType` enum
3. `InterviewOrchestratorService` (Claude integration for question wording)
4. `InterviewSessionCompleted` event + `DispatchReportGeneration` listener (stub only)
5. Controller methods: start, get-question, answer, skip, complete
6. Frontend: OptimizeIncome page Step 2 (interview flow, one question card at a time)
7. Tests: session state machine logic (mock Claude)

**Gate:** Complete interview flow from start to complete works end-to-end.

### Phase 4: Report Generation

Depends on Phase 3 (completed session exists).

1. `OptimizationReport` model + migration + `OptimizationReportStatus` enum
2. `OptimizationReportGeneratorService` (Claude integration for narratives)
3. `GenerateOptimizationReport` job
4. `OptimizationReportReady` event + `NotifyReportReady` listener
5. Controller method: GET /optimize/report/{year}
6. Frontend: OptimizeIncome page Step 3 (4-section report, collapsible, with disclaimer)
7. Stale-report logic: mark report stale on new doc upload or new transaction sync
8. Tests: report structure, disclaimer present, estimated impact calculations

**Gate:** Report generates with correct numbers from TaxRulesEngineService. Claude narratives are present. All existing 225 tests still pass.

### Phase 5: Polish + New Doc Types

1. Add new `TaxDocumentCategory` enum cases (check stub, offer letter, retirement statement, etc.)
2. Add extraction prompt configurations for new doc types
3. Wire new doc types into `IncomeOptimizerDataAssemblerService` (retirement contributions from retirement_statement)
4. Navigation: add "Optimize My Income" to `AuthenticatedLayout` nav
5. Scheduled tasks: abandon stale sessions, refresh stale profiles
6. End-to-end test: new doc upload → profile rebuild → report stale → user refreshes → report updated

---

## Anti-Patterns to Avoid

### Anti-Pattern 1: Claude for Tax Math

**What people do:** Ask Claude "what is this user's effective tax rate?" and use the response.
**Why wrong:** Claude can hallucinate dollar thresholds, bracket boundaries, and limit amounts. A mistake here gives users incorrect financial guidance.
**Do this instead:** `TaxRulesEngineService` for all numbers. Claude writes prose around pre-computed numbers only.

### Anti-Pattern 2: Modifying Existing AIQuestion Handling

**What people do:** Add optimization logic to the existing `AIQuestionController::answer()` or `UpdateTransactionCategory` listener.
**Why wrong:** Breaks the existing question flow. Violates backwards compatibility.
**Do this instead:** Add `UpdateOptimizationFromAnswer` as a new listener on the existing `UserAnsweredQuestion` event. The existing handler stays unchanged.

### Anti-Pattern 3: Rebuilding the Profile on Every Request

**What people do:** Call `IncomeOptimizerDataAssemblerService::buildProfile()` on each GET /optimize.
**Why wrong:** Reads dozens of encrypted records and runs AI detection — expensive.
**Do this instead:** `IncomeOptimizationProfile` is the cache. Build via job, check `profile_hash` for staleness, return cached data on reads.

### Anti-Pattern 4: One Giant "OptimizationService"

**What people do:** Put detection, interview, and report generation in one service class.
**Why wrong:** The detection logic (deterministic, no Claude) and interview logic (Claude-heavy, user-paced) and report logic (Claude batch) have completely different execution contexts, costs, and failure modes.
**Do this instead:** Four distinct services with clear single responsibilities, coordinated by the job layer.

### Anti-Pattern 5: Storing Computed Tax Amounts as Plain Integers Without Encryption

**What people do:** Store `w2_wages_cents` as plain `integer` column.
**Why wrong:** Income amounts are highly sensitive PII. Existing encrypted-TEXT convention exists for exactly this.
**Do this instead:** All income/deduction amounts in `IncomeOptimizationProfile` as `encrypted TEXT`. Match the pattern in `UserFinancialProfile.monthly_income`.

---

## Scalability Considerations

| Concern | At 1K users | At 100K users |
|---------|------------|---------------|
| Profile rebuild cost | Job queue handles it | Dedicated 'optimization' queue workers; rate-limit triggered rebuilds (same user can't re-trigger within 5 min) |
| Claude call volume | ~18 calls/complete cycle = fine | Cache finding descriptions by (finding_key + profile_hash); same finding for same financial situation reuses cached text |
| Interview session storage | Single table fine | Partition by created_at year if > 1M rows |
| Report storage (encrypted JSON) | Single table fine | Report sections can be S3 objects if they grow large; store S3 key in report row |

---

## Integration Points

| Existing System | How v2.1 Reads It | Notes |
|-----------------|------------------|-------|
| `TaxDocument.extracted_data` | `IncomeOptimizerDataAssemblerService` reads directly (already `encrypted:array`) | No change to TaxDocument; read-only |
| `UserFinancialProfile` | Assembler + all detectors read directly | Already has HSA, home office, employment type, filing status |
| `Transaction` + `IncomeDetectorService` | Assembler calls `IncomeDetectorService::classify()` for deposit totals | Re-uses existing classification, no duplicate logic |
| `Subscription` | `CrossSourceReviewService` scans for deductible business subscriptions | Read-only; no Subscription model changes |
| `AIQuestion` model | `SurfaceHighPriorityRedFlags` creates new AIQuestion records | Additive only; new `QuestionType::Optimization` case |
| `UserAnsweredQuestion` event | New `UpdateOptimizationFromAnswer` listener added | Additive; no changes to existing listeners |
| `ExtractTaxDocument` job | No change; triggers `BuildIncomeOptimizationProfile` via event | Add `OptimizationProfileBuilt` dispatch after extraction |

---

## Sources

- Direct codebase inspection: `app/Models/`, `app/Services/AI/`, `app/Enums/`, `app/Jobs/`, `app/Events/` (2026-07-01)
- `app/Models/TaxDocument.php` — confirmed `extracted_data` as `encrypted:array`, `TaxDocumentCategory` enum
- `app/Models/UserFinancialProfile.php` — confirmed rich existing fields (HSA, IRA, home office, spouse income)
- `app/Enums/QuestionType.php` — confirmed 4 existing cases; `Optimization` is additive
- `.planning/PROJECT.md` — v2.1 requirements and constraints
- Prior milestone ARCHITECTURE.md (v2.0, 2026-03-30) — house style patterns followed

---

*Architecture research for: Optimize My Income (v2.1 milestone)*
*Researched: 2026-07-01*
