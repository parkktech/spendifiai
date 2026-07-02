<?php

namespace App\Services;

use App\Models\OptimizationFinding;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic red-flag detector harness for the Optimize My Income feature.
 *
 * DESIGN RULES (non-negotiable):
 *  1. This class makes ZERO Claude/HTTP calls. All detectors are pure PHP.
 *  2. No dollar arithmetic is performed here. Any estimated_value_cents
 *     is computed by TaxRulesEngineService and stored there — never inline (SAFE-03).
 *  3. Method-conflict guards (FLAG-09) run BEFORE any finding is emitted.
 *  4. Every finding's severity derives from bandToSeverity() (FLAG-06).
 *  5. Findings are upserted keyed on (user_id, tax_year, finding_key) (Pitfall 5).
 *
 * Wave 11a ships the core harness with one trivial reference detector proving
 * the pipeline. Wave 11b appends detector classes to the registry.
 */
class RedFlagDetectorService
{
    public function __construct(
        protected TaxRulesEngineService $engine,
    ) {}

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Run all registered detectors for a user/year and upsert findings.
     *
     * Sequence:
     *  1. Load method-election UserTaxFact facts (method conflict guards)
     *  2. Run each detector class in the registry
     *  3. Each detector calls registerFinding() for candidates
     *  4. registerFinding() applies: validateRule + passesMaterialityGate + method-conflict guards
     *
     * @return array<string, mixed> Array of upserted finding keys
     */
    public function detectAll(int $userId, int $taxYear): array
    {
        // Load method-election facts for this user/year (FLAG-09 guards)
        $electionFacts = $this->loadMethodElectionFacts($userId, $taxYear);

        $emittedKeys = [];

        foreach ($this->detectorClasses() as $detectorClass) {
            try {
                $detector = app($detectorClass);
                $keys = $detector->run($userId, $taxYear, $this, $electionFacts);
                $emittedKeys = array_merge($emittedKeys, $keys);
            } catch (\Throwable $e) {
                Log::error('RedFlagDetector error', [
                    'detector' => $detectorClass,
                    'user_id' => $userId,
                    'tax_year' => $taxYear,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $emittedKeys;
    }

    /**
     * Helper called by individual detector classes to register a candidate finding.
     *
     * Applies (in order):
     *  1. TaxRulesEngineService::validateRule — suppresses if rule expired or band=suppress/hard_block
     *  2. passesMaterialityGate — gates transaction-derived findings on dollar thresholds
     *  3. checkMethodConflictGuards — suppresses findings conflicting with elected methods
     *
     * On pass: upserts an OptimizationFinding row with the full contract.
     * On fail: silently skips (no throw — detectors should emit candidates freely).
     *
     * @param  array<string, mixed>  $electionFacts  Preloaded UserTaxFact method-election facts
     * @param  string|null  $ruleId  Rule ID to validate (null = skip rule validation)
     * @param  int  $amountCents  Transaction amount for materiality gate (0 = skip gate)
     * @param  bool  $isRecurring  Whether this is a recurring-payee pattern
     * @param  int  $annualTotalCents  Annual total for recurring patterns
     * @param  bool  $addressMatch  Address-matched payee (always passes materiality)
     * @param  bool  $loanServicer  Loan servicer (always passes materiality)
     * @param  array<string, mixed>  $assumptions  Static config citations (never Claude output)
     * @param  array<int>  $transactionIds  Related transaction IDs
     * @param  string[]  $docsMissing  Doc request label keys from config/tax-detection.php
     * @return string|null The finding_key if registered, null if suppressed/skipped
     */
    public function registerFinding(
        int $userId,
        int $taxYear,
        string $findingKey,
        string $findingType,
        string $band,
        string $treatment,
        string $legalBasis,
        ?string $ruleId = null,
        int $amountCents = 0,
        bool $isRecurring = false,
        int $annualTotalCents = 0,
        bool $addressMatch = false,
        bool $loanServicer = false,
        array $assumptions = [],
        array $transactionIds = [],
        array $docsMissing = [],
        array $electionFacts = [],
    ): ?string {
        // ── 1. Rule validation (suppress expired / hard-blocked rules) ──────
        if ($ruleId !== null) {
            try {
                $ruleResult = $this->engine->validateRule($ruleId);
                if ($ruleResult['suppressed']) {
                    return null;
                }
            } catch (\InvalidArgumentException $e) {
                Log::warning('RedFlagDetector: unknown rule ID', [
                    'rule_id' => $ruleId,
                    'finding_key' => $findingKey,
                ]);

                return null;
            }
        }

        // ── 2. Materiality gate (skip if amount too small) ──────────────────
        if ($amountCents > 0 || $isRecurring) {
            if (! $this->engine->passesMaterialityGate(
                amountCents: $amountCents,
                isRecurring: $isRecurring,
                annualTotalCents: $annualTotalCents,
                addressMatch: $addressMatch,
                loanServicer: $loanServicer,
            )) {
                return null;
            }
        }

        // ── 3. Method-conflict guards (FLAG-09) ─────────────────────────────
        if ($this->checkMethodConflictGuards($findingKey, $electionFacts)) {
            return null;
        }

        // ── 4. Severity from band (FLAG-06) ─────────────────────────────────
        $severity = $this->engine->bandToSeverity($band);

        // ── 5. Upsert (Pitfall 5: always pass all three key fields) ─────────
        OptimizationFinding::updateOrCreate(
            [
                'user_id' => $userId,
                'tax_year' => $taxYear,
                'finding_key' => $findingKey,
            ],
            [
                'finding_type' => $findingType,
                'severity' => $severity,
                'band' => $band,
                'treatment' => $treatment,
                'legal_basis' => $legalBasis,      // static config citation, NEVER Claude
                'assumptions' => $assumptions,     // static config citations, NEVER Claude
                'transaction_ids' => $transactionIds ?: null,
                'docs_missing' => $docsMissing ?: null,
                'description' => null,             // narration fills this (NarrationService)
                'status' => 'open',
            ]
        );

        return $findingKey;
    }

    // ── Registry ───────────────────────────────────────────────────────────────

    /**
     * Detector class registry.
     *
     * Wave 11a: contains one trivial reference detector proving the pipeline.
     * Wave 11b: appends per-category detector class names here.
     *
     * Each class must implement:
     *   run(int $userId, int $taxYear, RedFlagDetectorService $service, array $electionFacts): array
     *
     * @return class-string[]
     */
    protected function detectorClasses(): array
    {
        return [
            // Wave 11a: reference detector (proves pipeline; emits a placeholder finding)
            // Wave 11b content plans append detector classes here
        ];
    }

    // ── Method-Conflict Guards (FLAG-09) ───────────────────────────────────────

    /**
     * Check if a candidate finding is suppressed by a method-election conflict.
     *
     * Returns true (suppress this finding) when:
     *  - standard-mileage election → suppress vehicle_actual_expense findings
     *  - simplified home-office election → suppress home_office_actual_allocation findings
     *  - §121 recapture tracking → suppress recapture findings that are already tracked
     *  - accountable plan → route reimbursables through the plan (suppress direct-expense prompt)
     *
     * @param  array<string, string>  $electionFacts  Keyed by fact_key, value is current value
     * @return bool True if the finding should be suppressed
     */
    protected function checkMethodConflictGuards(string $findingKey, array $electionFacts): bool
    {
        // Guard 1: Standard-mileage election suppresses vehicle actual-expense (FLAG-09)
        if ($findingKey === 'vehicle_actual_expense') {
            if (($electionFacts['method.mileage_election'] ?? null) === 'standard') {
                return true;
            }
        }

        // Guard 2: Simplified home-office election suppresses actual-allocation prompt (FLAG-09)
        if ($findingKey === 'home_office_actual_allocation') {
            if (($electionFacts['method.home_office_election'] ?? null) === 'simplified') {
                return true;
            }
        }

        // Guard 3: §121 exclusion tracking — recapture findings suppressed if already tracked
        if ($findingKey === 'section_121_recapture_risk') {
            if (isset($electionFacts['section_121.recapture_tracked'])) {
                return true;
            }
        }

        // Guard 4: Accountable plan routes reimbursables through the plan
        if ($findingKey === 'reimbursable_expense_direct') {
            if (($electionFacts['method.accountable_plan'] ?? null) === 'enrolled') {
                return true;
            }
        }

        return false;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Load method-election UserTaxFact values for this user/year.
     *
     * Only reads 'method.*' and 'section_121.*' namespaced fact keys
     * to avoid pulling in unrelated sensitive data.
     *
     * @return array<string, string> fact_key → decrypted value (confirmed facts only)
     */
    protected function loadMethodElectionFacts(int $userId, int $taxYear): array
    {
        // UserTaxFact::currentFactKeys() returns confirmed fact keys (proposals excluded)
        // We filter to method.* and section_121.* namespaces
        $allKeys = UserTaxFact::currentFactKeys($userId);

        $electionKeys = array_filter(
            $allKeys,
            fn ($k) => str_starts_with($k, 'method.') || str_starts_with($k, 'section_121.')
        );

        if (empty($electionKeys)) {
            return [];
        }

        $facts = UserTaxFact::where('user_id', $userId)
            ->where('is_current', true)
            ->when($taxYear !== null, fn ($q) => $q->where(function ($q) use ($taxYear) {
                $q->where('tax_year', $taxYear)->orWhereNull('tax_year');
            }))
            ->whereIn('fact_key', $electionKeys)
            ->get(['fact_key', 'value']);

        $map = [];
        foreach ($facts as $fact) {
            // value is encrypted — reading via model casts decrypts automatically
            $map[$fact->fact_key] = $fact->value;
        }

        return $map;
    }
}
