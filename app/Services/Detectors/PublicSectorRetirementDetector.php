<?php

namespace App\Services\Detectors;

use App\Models\IncomeOptimizationProfile;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * PublicSectorRetirementDetector — FLAG-21
 *
 * Employer-type-gated branch for public-sector and nonprofit employees.
 * Big lever: 457(b) separate contribution limit and stacking 403(b) + 457(b).
 *
 * Eligible employer types (EXP M3 verbatim):
 * school, university, hospital, nonprofit, city, county, state, government, police, fire
 *
 * LIABILITY BOUNDARIES:
 *  - Non-governmental 457(b): employer-creditor-risk caveat is MANDATORY and must
 *    appear BEFORE any 457(b) content (INTEGRATION-MAP binding rule)
 *  - Governmental 457(b): separate trust, vested amounts protected — no creditor caveat
 *  - 3-year pre-retirement catch-up question: always ask (EXP M3)
 *  - Pension-projection question: ask-level only (DOC-09 future, answer persists as durable fact)
 *  - Limits from config (TAX-08): $24,500 each for 457(b)/403(b); 3-yr catch-up doubles
 *  - SAFE-03: No estimated_value_cents assigned here
 */
class PublicSectorRetirementDetector
{
    /** Employer types that gate this detector (EXP M3 verbatim) */
    private const ELIGIBLE_TYPES = [
        'school', 'university', 'hospital', 'nonprofit',
        'city', 'county', 'state', 'government', 'police', 'fire',
    ];

    /** Non-governmental employer types: 457(b) balances subject to employer creditor risk */
    private const NON_GOVERNMENTAL = ['hospital', 'nonprofit', 'university', 'school'];

    /**
     * @param  array<string, string>  $electionFacts
     * @return string[]
     */
    public function run(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $profile = IncomeOptimizationProfile::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->first();

        if ($profile === null || (int) $profile->w2_wages <= 0) {
            return [];
        }

        $employerTypeFact = UserTaxFact::currentFact($userId, 'employer.employer_type', null, $taxYear);
        if ($employerTypeFact === null) {
            return [];
        }

        $employerType = $employerTypeFact->value;

        // Gate: only public-sector / nonprofit employers
        if (! in_array($employerType, self::ELIGIBLE_TYPES, true)) {
            return [];
        }

        $emitted = [];

        // ── 457(b) education ─────────────────────────────────────────────────────
        $has457b = UserTaxFact::currentFact($userId, 'employer.has_457b', null, $taxYear);

        if ($has457b?->value === 'true') {
            $isNonGov = in_array($employerType, self::NON_GOVERNMENTAL, true);

            if ($isNonGov) {
                // BINDING (FLAG-21, INTEGRATION-MAP): creditor-risk caveat BEFORE any 457(b) content
                // "creditor" must appear textually BEFORE "457" in the treatment string
                $treatment = 'Important: this plan carries employer creditor risk — '
                    .'deferred balances remain on the employer\'s balance sheet and are '
                    .'not held in a separate trust. If your employer becomes insolvent, '
                    .'plan assets could be at risk. (Governmental plans have separate trust '
                    .'protection; non-governmental plans do not.) '
                    .'With that caveat understood: a 457(b) plan provides its own contribution '
                    .'limit separate from your 403(b) or 401(k), allowing you to potentially '
                    .'double your pre-tax retirement savings. '
                    .'Are you within 3 years of normal retirement age? The 3-year pre-retirement '
                    .'catch-up provision may allow contributions up to double the annual limit. '
                    .'Consider discussing the trade-offs with a tax or financial professional.';
            } else {
                // Governmental 457(b): separate trust, different withdrawal rules
                $treatment = 'Your employer offers a governmental 457(b) plan with its own contribution '
                    .'limit separate from your 403(b) or 401(k). '
                    .'This allows eligible public-sector employees to potentially double their '
                    .'pre-tax retirement savings. Governmental 457(b) plans have no 10% early '
                    .'withdrawal penalty after separation from service (regardless of age). '
                    .'Are you within 3 years of normal retirement age? '
                    .'The 3-year pre-retirement catch-up provision in a 457(b) may allow '
                    .'contributions up to double the standard annual limit. '
                    .'Consider discussing stacking your 403(b) and 457(b) with a tax professional.';
            }

            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'ps_457b_education',
                findingType: 'retirement',
                band: 'conditional',
                treatment: $treatment,
                legalBasis: 'IRC §457(b) (eligible deferred compensation plans); separate limit from §401(k)/§403(b)',
                ruleId: 'employer_match_gap',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        return $emitted;
    }
}
