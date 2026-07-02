<?php

namespace App\Services\Detectors;

use App\Models\IncomeOptimizationProfile;
use App\Models\UserFinancialProfile;
use App\Services\RedFlagDetectorService;

/**
 * FilingStatusDetector — FLAG-02
 *
 * SURFACES (never asserts) a mismatch between:
 *   a) the stated filing status in UserFinancialProfile.tax_filing_status, and
 *   b) the filing status derived from the IncomeOptimizationProfile snapshot.
 *
 * LIABILITY BOUNDARY (D10, LOCKED):
 *   - Treatment framing is ALWAYS "your documents may suggest" — never "you should file as X".
 *   - This detector never asserts the correct filing status.
 *   - "Asserting filing status" is locked out-of-scope (INTEGRATION-MAP Blocked section).
 *
 * FLAG-28 extends this detector's evidence plane in ProfileConformanceDetector (plane 1).
 */
class FilingStatusDetector
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

        // Load the stated filing status from the user's financial profile
        $profile = UserFinancialProfile::where('user_id', $userId)->first();
        if (! $profile || ! $profile->tax_filing_status) {
            return $emitted;
        }

        // Load the filing status derived from the income optimization snapshot
        $snapshot = IncomeOptimizationProfile::forUser($userId)
            ->where('tax_year', $taxYear)
            ->first();

        if (! $snapshot || ! $snapshot->filing_status) {
            return $emitted;
        }

        // Normalize both to lowercase underscore for comparison
        $profileStatus = $this->normalizeStatus($profile->tax_filing_status);
        $snapshotStatus = $this->normalizeStatus($snapshot->filing_status);

        if ($profileStatus === $snapshotStatus) {
            return $emitted;
        }

        // Mismatch detected — surface educationally, never assert the correct status
        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'filing_status_mismatch',
            findingType: 'filing_status',
            band: 'conditional',
            // Treatment: surfaced never asserted. No phrase like "you should file as X".
            treatment: 'Your profile filing status and your financial documents may not match. '
                . 'A tax professional could confirm which filing status applies to your situation — '
                . 'selecting the right status may significantly affect your tax outcome.',
            legalBasis: 'IRS Publication 501; IRC §1(a)–(d)',
            ruleId: 'filing_status_mismatch',
            electionFacts: $electionFacts,
        );

        if ($key !== null) {
            $emitted[] = $key;
        }

        return $emitted;
    }

    private function normalizeStatus(string $status): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($status)));
    }
}
