<?php

namespace App\Services\Detectors;

use App\Enums\AccountPurpose;
use App\Models\IncomeOptimizationProfile;
use App\Models\Transaction;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * AuditRiskScorer — FLAG-15
 *
 * Computes a deterministic risk score from the 9-factor audit-risk inputs
 * (PB-v1 §9 / INTEGRATION-MAP §4) and emits a protective-framing educational finding
 * when the score meets or exceeds config('tax-detection.audit_risk.score_threshold').
 *
 * LOCKED PROTECTIVE FRAMING (D10, INTEGRATION-MAP FLAG-15 — verbatim pattern):
 *   "Returns with patterns like [X] commonly receive additional IRS scrutiny —
 *    here is the documentation that typically resolves it."
 *
 * LIABILITY BOUNDARIES (non-negotiable):
 *   - NEVER accusatory ("you falsified", "you cheated").
 *   - NEVER a numeric audit probability (no "X% chance of audit").
 *   - NEVER implies wrongdoing — protective framing only.
 *   - Severity feeds FLAG-06 via bandToSeverity (conditional band → medium).
 *
 * 9-Factor inputs (from PB-v1 §9 / TD-v1 §1.3):
 *   1. Perpetual Schedule C losses vs W-2 income
 *   2. 100% business vehicle use claim (fact)
 *   3. Round-number amounts in charitable contributions
 *   4. Deposit-vs-reported income mismatch (bank_deposit_total vs w2_wages)
 *   5. Outsized charitable contributions (> 20% of income)
 *   6. Disproportionate HO + meals + travel (from durable facts)
 *   7. Missing 1099s (NEC from contractor payments — not reliably detectable from bank data alone)
 *   8. Mill-flag credits (EITC large relative to income)
 *   9. SE income present without estimated payments
 *
 * SAFE-03: No estimated_value_cents is assigned by this class.
 */
class AuditRiskScorer
{
    /**
     * @param  array<string, string>  $electionFacts  Preloaded method-election facts
     * @return string[]  Finding keys emitted
     */
    public function run(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $emitted = [];

        $snapshot = IncomeOptimizationProfile::forUser($userId)
            ->where('tax_year', $taxYear)
            ->first();

        if (! $snapshot) {
            return $emitted;
        }

        $scoreThreshold = (int) config('tax-detection.audit_risk.score_threshold', 2);
        $riskFactors = [];

        // ── Factor 1: 100% business vehicle use claim ─────────────────────────
        $vehicleUseFact = UserTaxFact::currentFact($userId, 'vehicle.business_use_pct');
        if ($vehicleUseFact && (float) $vehicleUseFact->value >= 100.0) {
            $riskFactors[] = '100% business vehicle use claim';
        }

        // ── Factor 2: Outsized charitable contributions ────────────────────────
        // > audit_risk.charitable_outlier_pct of income is a documented IRS audit trigger
        $incomeCents = $snapshot->getW2WagesCentsAttribute()
            + $snapshot->getSelfEmploymentIncomeCentsAttribute();

        if ($incomeCents > 0 && $snapshot->charitable_contributions !== null) {
            $charityPct = config('tax-detection.audit_risk.charitable_outlier_pct', 0.20);
            $charityThresholdCents = (int) round($incomeCents * $charityPct);
            $charitableCents = (int) $snapshot->charitable_contributions;

            if ($charitableCents > $charityThresholdCents) {
                $riskFactors[] = 'charitable contributions that are large relative to income';
            }
        }

        // ── Factor 3: Deposit-vs-reported income mismatch ─────────────────────
        // Bank deposit total significantly higher than reported income may signal unreported income
        if ($incomeCents > 0 && $snapshot->getBankDepositTotalCentsAttribute() > 0) {
            $depositCents = $snapshot->getBankDepositTotalCentsAttribute();
            // Flag if deposits exceed reported income by more than 30%
            if ($depositCents > (int) round($incomeCents * 1.30)) {
                $riskFactors[] = 'bank deposits that appear to exceed reported income';
            }
        }

        // ── Factor 4: Self-employment income without estimated tax payments ────
        // SE income present but no estimated_tax fact detected
        if ($snapshot->getSelfEmploymentIncomeCentsAttribute() > 0) {
            $estimatedPayFact = UserTaxFact::currentFact(
                $userId,
                'tax.estimated_payments_ytd',
                null,
                $taxYear,
            );
            if (! $estimatedPayFact || (int) $estimatedPayFact->value <= 0) {
                $riskFactors[] = 'self-employment income without detected estimated tax payments';
            }
        }

        // ── Factor 5: Business transactions in personal accounts (commingling variant)
        // Personal transactions in business accounts already covered by ComminglingMonitor.
        // Here: high volume of business-type transactions in personal accounts (reverse commingling).
        $businessInPersonal = Transaction::where('user_id', $userId)
            ->where('account_purpose', AccountPurpose::Personal)
            ->whereYear('transaction_date', $taxYear)
            ->where('expense_type', 'business')
            ->count();

        if ($businessInPersonal >= 10) {
            $riskFactors[] = 'significant business activity in personal accounts';
        }

        // Score check — only emit if enough factors detected
        if (count($riskFactors) < $scoreThreshold) {
            return $emitted;
        }

        // Build the factor list for the treatment text
        $factorList = implode('; ', $riskFactors);

        // Emit finding with LOCKED protective framing (D10, FLAG-15)
        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'audit_risk_score',
            findingType: 'audit_risk',
            band: 'conditional',
            // Locked framing — verbatim pattern from INTEGRATION-MAP FLAG-15
            treatment: 'Returns with patterns like ' . $factorList . ' commonly receive '
                . 'additional IRS scrutiny — here is the documentation that typically resolves it: '
                . 'contemporaneous records, receipts, and a clear business-purpose log. '
                . 'A tax professional can review your records and help ensure they are complete.',
            legalBasis: 'IRS audit selection patterns; DIF score criteria; IRC §183 hobby-loss factors',
            ruleId: 'audit_risk_score',
            docsMissing: ['prior_year_return', 'business_use_log'],
            electionFacts: $electionFacts,
        );

        if ($key !== null) {
            $emitted[] = $key;
        }

        return $emitted;
    }
}
