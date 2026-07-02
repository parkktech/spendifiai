<?php

namespace App\Services\Detectors;

use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * EmployerMatchGapDetector — FLAG-04
 *
 * Flags unclaimed employer 401(k) match when durable facts (from paystub interview
 * or document extraction) show the user contributes less than the match threshold.
 *
 * LIABILITY BOUNDARY (D10, locked):
 *   - "If your plan allows" framing is mandatory — eligibility is plan-rule-dependent.
 *   - This detector never claims the user is missing a specific dollar amount.
 *   - estimated_value_cents is NOT set here (SAFE-03).
 *
 * Required durable facts:
 *   employer.match_pct       — employer match percentage (e.g. '50' for 50% match)
 *   employer.match_threshold_pct — contribution % required for full match (e.g. '6')
 *   employer.contribution_pct    — user's current 401k contribution % (e.g. '3')
 */
class EmployerMatchGapDetector
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

        // employer.match_pct can be a permanent or year-specific fact
        $matchPctFact = UserTaxFact::currentFact($userId, 'employer.match_pct', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'employer.match_pct');

        if (! $matchPctFact) {
            return $emitted;
        }

        $matchPct = (float) $matchPctFact->value;
        if ($matchPct <= 0) {
            return $emitted;
        }

        // Match threshold — how much the user must contribute to earn the full match
        // Defaults to the match_pct itself if not separately specified
        $thresholdFact = UserTaxFact::currentFact($userId, 'employer.match_threshold_pct', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'employer.match_threshold_pct');

        $matchThresholdPct = $thresholdFact ? (float) $thresholdFact->value : $matchPct;

        // Current user contribution %
        $contribFact = UserTaxFact::currentFact($userId, 'employer.contribution_pct', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'employer.contribution_pct');

        if (! $contribFact) {
            // Cannot determine if user is under-contributing without contribution data
            return $emitted;
        }

        $userContribPct = (float) $contribFact->value;

        // Only emit when user is under the match threshold
        if ($userContribPct >= $matchThresholdPct) {
            return $emitted;
        }

        // Unclaimed match detected — emit educational finding
        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'employer_match_gap',
            findingType: 'retirement',
            band: 'auto',
            // Treatment: "if your plan allows" framing is MANDATORY (FLAG-04, D10)
            treatment: 'If your plan allows, increasing your 401(k) contribution may let you '
                . 'capture your full employer match — often the highest-return financial action '
                . 'available in a W-2 benefit package. Confirm the match rules with your HR or '
                . 'benefits administrator before making changes.',
            legalBasis: 'IRC §401(k)',
            ruleId: 'employer_match_gap',
            electionFacts: $electionFacts,
        );

        if ($key !== null) {
            $emitted[] = $key;
        }

        return $emitted;
    }
}
