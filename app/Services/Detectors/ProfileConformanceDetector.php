<?php

namespace App\Services\Detectors;

use App\Models\IncomeOptimizationProfile;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * ProfileConformanceDetector — FLAG-28 / D13 (LOCKED owner Decision 3)
 *
 * Conforms the Enhanced Tax Profile to observed reality by surfacing mismatches
 * educationally across three planes and in BOTH directions. Every mismatch
 * emits an OptimizationFinding + educational question. Profile updates flow
 * ONLY through user confirmation (D3/D4 gate). This class NEVER writes to
 * UserFinancialProfile directly.
 *
 * THREE PLANES (D13, LOCKED):
 *
 * Plane 1 — Filing status vs W-4/withholding evidence:
 *   Dir A: profile.tax_filing_status vs snapshot.filing_status (mismatch)
 *   Dir B: profile.tax_filing_status vs w4.filing_status durable fact (mismatch)
 *
 * Plane 2 — IRA/HSA stated facts vs detected transfers/payroll deductions:
 *   Dir A1: profile.has_hsa=false but employer.hsa_deduction_ytd fact present
 *   Dir A2: profile.ira_type=roth but ira.traditional_contribution_ytd fact present
 *
 * Plane 3 — Checkbox facts vs transaction patterns (both directions):
 *   Dir A: profile.has_rental_property=false but income.rental_income_detected fact present
 *   Dir B: profile.has_student_loans=false but payment.student_loan_detected fact present
 *   Dir C: profile.has_childcare_expenses=false but payment.childcare_detected fact present
 *
 * MANDATORY FRAMING (D13 verbatim):
 *   "Your paystub appears to show X while your profile says Y —
 *    want to update your profile or tell us more?"
 *
 * LIABILITY BOUNDARIES (non-negotiable):
 *   - NEVER assert the correct value.
 *   - NEVER auto-write UserFinancialProfile.
 *   - Educational-only: "may / could / consider / appears to show".
 *   - Banned phrases: "you are required to", "you must update", "your profile is wrong".
 *
 * SAFE-03: No estimated_value_cents is assigned by this class.
 */
class ProfileConformanceDetector
{
    /**
     * @param  array<string, string>  $electionFacts  Preloaded method-election facts
     * @return string[] Finding keys emitted (deduplicated — one key per plane)
     */
    public function run(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $emitted = [];

        $profile = UserFinancialProfile::where('user_id', $userId)->first();
        $snapshot = IncomeOptimizationProfile::forUser($userId)
            ->where('tax_year', $taxYear)
            ->first();

        // ── Plane 1: Filing Status Conformance ────────────────────────────────
        $plane1Mismatch = false;

        // Dir A: profile vs snapshot
        if ($profile && $profile->tax_filing_status && $snapshot && $snapshot->filing_status) {
            $profileStatus = $this->normalizeStatus($profile->tax_filing_status);
            $snapshotStatus = $this->normalizeStatus($snapshot->filing_status);
            if ($profileStatus !== $snapshotStatus) {
                $plane1Mismatch = true;
            }
        }

        // Dir B: profile vs W-4 filing status durable fact
        if (! $plane1Mismatch && $profile && $profile->tax_filing_status) {
            $w4Fact = UserTaxFact::currentFact($userId, 'w4.filing_status', null, $taxYear)
                ?? UserTaxFact::currentFact($userId, 'w4.filing_status');

            if ($w4Fact) {
                $profileStatus = $this->normalizeStatus($profile->tax_filing_status);
                $w4Status = $this->normalizeStatus((string) $w4Fact->value);
                if ($profileStatus !== $w4Status) {
                    $plane1Mismatch = true;
                }
            }
        }

        if ($plane1Mismatch) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'conformance_filing_status',
                findingType: 'conformance',
                band: 'conditional',
                // D13 framing: "appears to show X while your profile says Y"
                treatment: 'Your tax documents or withholding records appear to show a different '
                    .'filing status than what your profile currently indicates. '
                    .'Want to update your profile or tell us more about your situation? '
                    .'A tax professional can confirm which status applies to you.',
                legalBasis: 'IRS Publication 501; IRC §1(a)–(d)',
                ruleId: 'conformance_filing_status',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Plane 2: IRA/HSA Conformance ─────────────────────────────────────
        $plane2Mismatch = false;
        $plane2Description = '';

        if ($profile) {
            // Dir A1: profile.has_hsa=false but HSA deduction fact detected
            if ($profile->has_hsa === false) {
                $hsaFact = UserTaxFact::currentFact($userId, 'employer.hsa_deduction_ytd', null, $taxYear)
                    ?? UserTaxFact::currentFact($userId, 'employer.hsa_deduction_ytd');

                if ($hsaFact && (int) $hsaFact->value > 0) {
                    $plane2Mismatch = true;
                    $plane2Description = 'Your records appear to show HSA payroll deductions '
                        .'while your profile indicates you do not have an HSA.';
                }
            }

            // Dir A2: profile says Roth-only but Traditional IRA contribution detected
            if (! $plane2Mismatch && $profile->has_ira === true
                && strtolower((string) $profile->ira_type) === 'roth') {

                $tradFact = UserTaxFact::currentFact($userId, 'ira.traditional_contribution_ytd', null, $taxYear)
                    ?? UserTaxFact::currentFact($userId, 'ira.traditional_contribution_ytd');

                if ($tradFact && (int) $tradFact->value > 0) {
                    $plane2Mismatch = true;
                    $plane2Description = 'Your records appear to show a Traditional IRA contribution '
                        .'while your profile indicates a Roth-only account.';
                }
            }
        }

        if ($plane2Mismatch) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'conformance_ira_hsa',
                findingType: 'conformance',
                band: 'conditional',
                // D13 framing: educational, never assertive
                treatment: $plane2Description
                    .' Want to update your profile or tell us more about your accounts? '
                    .'Keeping your profile current ensures the most relevant recommendations.',
                legalBasis: 'IRC §408 (IRA); IRC §223 (HSA)',
                ruleId: 'conformance_ira_hsa',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Plane 3: Checkbox Conformance ─────────────────────────────────────
        // Each check may emit a finding; we deduplicate to a single 'conformance_checkbox'
        // finding key (the finding text notes all mismatches).
        $plane3Details = [];

        if ($profile) {
            // Dir A: rental property
            if ($profile->has_rental_property === false) {
                $rentalFact = UserTaxFact::currentFact($userId, 'income.rental_income_detected', null, $taxYear)
                    ?? UserTaxFact::currentFact($userId, 'income.rental_income_detected');

                if ($rentalFact && in_array(strtolower((string) $rentalFact->value), ['true', '1', 'yes'], true)) {
                    $plane3Details[] = 'rental income deposits that do not match your profile\'s rental property checkbox';
                }
            }

            // Dir B: student loans
            if ($profile->has_student_loans === false) {
                $loanFact = UserTaxFact::currentFact($userId, 'payment.student_loan_detected', null, $taxYear)
                    ?? UserTaxFact::currentFact($userId, 'payment.student_loan_detected');

                if ($loanFact && in_array(strtolower((string) $loanFact->value), ['true', '1', 'yes'], true)) {
                    $plane3Details[] = 'student loan payments that do not match your profile\'s student loan checkbox';
                }
            }

            // Dir C: childcare
            if ($profile->has_childcare_expenses === false) {
                $childcareFact = UserTaxFact::currentFact($userId, 'payment.childcare_detected', null, $taxYear)
                    ?? UserTaxFact::currentFact($userId, 'payment.childcare_detected');

                if ($childcareFact && in_array(strtolower((string) $childcareFact->value), ['true', '1', 'yes'], true)) {
                    $plane3Details[] = 'childcare payments that do not match your profile\'s childcare checkbox';
                }
            }
        }

        if (! empty($plane3Details)) {
            $detailList = implode('; ', $plane3Details);
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'conformance_checkbox',
                findingType: 'conformance',
                band: 'conditional',
                // D13 framing: "appears to show X while profile says Y"
                treatment: 'Your transaction records appear to show '.$detailList.'. '
                    .'Your profile may not reflect your full financial picture — '
                    .'want to update it or tell us more? '
                    .'Keeping your profile current helps surface the most relevant tax opportunities.',
                legalBasis: 'IRS Schedule E (rental); Form 2441 (childcare); IRC §221 (student loan interest)',
                ruleId: 'conformance_checkbox',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        return $emitted;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function normalizeStatus(string $status): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($status)));
    }
}
