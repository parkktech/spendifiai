<?php

namespace App\Services\Detectors;

use App\Models\IncomeOptimizationProfile;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;
use Illuminate\Support\Facades\Config;

/**
 * AcaCliffMonitor — FLAG-22
 *
 * Detects marketplace premium payment signals and surfaces a mid-year awareness
 * warning when the user's income trend approaches the 400%-FPL cliff.
 *
 * After the enhanced premium credits expired (Dec 31, 2025), the hard 400%-FPL
 * cliff is back: ONE dollar over the threshold = zero credit for the ENTIRE year,
 * with no repayment cap on excess advance credits (OBBBA).
 *
 * EMITS TWO FINDINGS (if triggered):
 *  1. `aca_magi_management` — at `auto` band (HIGH severity) — sequences BEFORE
 *     any Traditional-vs-Roth narration per D10/2B.1 binding sequencing rule
 *  2. `aca_cliff_awareness` — at `conditional` band (MEDIUM severity)
 *
 * LIABILITY BOUNDARIES (FLAG-22, T-11-08-02):
 *  - AWARENESS only — NEVER compute or present the user's specific subsidy amount
 *  - NEVER compute or present a clawback amount
 *  - Finding treatment must not contain "$X subsidy", "you will repay $", "clawback of $"
 *  - FPL thresholds come from config (config/tax-detection.php) — not hardcoded inline
 *  - SAFE-03: No estimated_value_cents assigned here
 *
 * SEQUENCING RULE (2B.1 verbatim binding):
 *  "Model MAGI management before any Roth-vs-traditional recommendation for
 *   marketplace enrollees." Implemented by making aca_magi_management use
 *   band='auto' → severity='high', which surfaces it before any band='conditional'
 *   finding in the interview feed sort order.
 */
class AcaCliffMonitor
{
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
        // Gate: user must be paying marketplace premiums
        $paysPremiums = UserTaxFact::currentFact($userId, 'marketplace.pays_marketplace_premiums', null, $taxYear);
        if ($paysPremiums === null || $paysPremiums->value !== 'true') {
            return [];
        }

        $profile = IncomeOptimizationProfile::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->first();

        if ($profile === null) {
            return [];
        }

        // Income check — only surface for incomes reasonably near the cliff
        $incomeCents = (int) $profile->w2_wages + (int) ($profile->self_employment_income ?? 0);
        if ($incomeCents <= 0) {
            return [];
        }

        // FPL 400% thresholds from config (TAX-08 equivalent, set here for awareness gate)
        // 2026: ~$62,600 single / ~$128,600 family of 4 (continental US)
        // Gate fires if income is within 30% below the cliff (within striking distance)
        $singleCliffCents = Config::get('tax-detection.aca_cliff_cents.single_2026', 6_260_000);
        $familyCliffCents = Config::get('tax-detection.aca_cliff_cents.family4_2026', 12_860_000);

        $cliffCents = in_array($profile->filing_status ?? '', ['married_filing_jointly', 'qualifying_surviving_spouse'], true)
            ? $familyCliffCents
            : $singleCliffCents;

        // Too far below cliff (< 70% of cliff) — not in warning zone
        if ($incomeCents < (int) ($cliffCents * 0.70)) {
            return [];
        }

        $emitted = [];

        // ── Finding 1: MAGI-management education ────────────────────────────────
        // BINDING (2B.1): auto band → HIGH severity → sequences BEFORE conditional findings
        // This ensures MAGI-management appears before any Trad-vs-Roth narration
        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'aca_magi_management',
            findingType: 'aca_awareness',
            band: 'auto',
            // BINDING (FLAG-22): awareness education, never a computed amount
            treatment: 'You appear to be enrolled in a marketplace health plan. '
                .'For marketplace enrollees, MAGI-management strategies should be reviewed '
                .'BEFORE making Traditional-vs-Roth decisions — because pre-tax contributions '
                .'(traditional 401k, solo 401k, HSA, and self-employment deductions) reduce MAGI '
                .'and can preserve premium tax credits. '
                .'A tax professional could help model whether increasing pre-tax contributions '
                .'makes sense given your specific premium credit situation.',
            legalBasis: 'IRC §36B (Premium Tax Credit); IRC §1 (MAGI definition); post-2025 cliff rules',
            ruleId: 'life_event_marketplace_premium',
            electionFacts: $electionFacts,
        );
        if ($key !== null) {
            $emitted[] = $key;
        }

        // ── Finding 2: ACA cliff proximity awareness ─────────────────────────────
        // BINDING (FLAG-22, T-11-08-02): NEVER emit a computed subsidy or clawback
        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'aca_cliff_awareness',
            findingType: 'aca_awareness',
            band: 'conditional',
            // BINDING: no user-specific subsidy or clawback dollar figures
            treatment: 'Your income trend may be approaching the 400%-FPL threshold where '
                .'marketplace premium credits end. '
                .'After the enhanced subsidies expired (Dec 31, 2025), the hard 400%-FPL cliff '
                .'is back: one dollar over the threshold eliminates premium credits for the entire '
                .'year, with no repayment cap on excess advance credits. '
                .'A mid-year review may leave time to act — for example, consider increasing '
                .'pre-tax retirement or HSA contributions that reduce MAGI. '
                .'A tax professional could help model your year-end income projection; '
                .'a small adjustment before year-end can matter significantly for marketplace enrollees.',
            legalBasis: 'IRC §36B(b)(3)(A) (400% FPL cliff); OBBBA (removes repayment cap post-2025)',
            ruleId: 'life_event_marketplace_premium',
            electionFacts: $electionFacts,
        );
        if ($key !== null) {
            $emitted[] = $key;
        }

        return $emitted;
    }
}
