<?php

namespace App\Services\Detectors;

use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;
use Carbon\Carbon;

/**
 * TimeCriticalAlarmDetector — FLAG-16
 *
 * Emits highest-severity findings for time-critical tax events with hard deadlines.
 * Every finding in this class is critical severity — urgency is stated as fact,
 * followed by a consider-a-professional framing.
 *
 * TIME-CRITICAL ALARMS (three items, all FLAG-16):
 *  1. 83(b) election — 30-day window from restricted stock grant date
 *  2. QOF mandatory gain recognition — end-2026 for pre-2027 QOF holders
 *  3. QSBS early-eligibility at C-corp formation — paired with §1244 note
 *
 * LIABILITY BOUNDARIES:
 *  - Urgency is stated as FACT (the deadline exists; this is not advice)
 *  - Every alarm includes "consider contacting a professional" framing
 *  - No dollar amounts computed; no strategies recommended
 *  - Blocked content: NO AMT modeling, NO portfolio-drawdown language, NO blocked items
 *
 * SAFE-03: No estimated_value_cents is assigned by this class.
 */
class TimeCriticalAlarmDetector
{
    /**
     * @param  array<string, string>  $electionFacts  Preloaded method-election facts
     * @return string[] Finding keys emitted
     */
    public function run(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $emitted = [];

        // ── Alarm 1: 83(b) election — 30-day window ──────────────────────────────
        // Trigger: equity.restricted_stock_grant_date fact within the last 30 days
        // BINDING: highest severity; urgency stated as fact; professional framing
        $key = $this->check83bAlarm($userId, $taxYear, $service, $electionFacts);
        if ($key !== null) {
            $emitted[] = $key;
        }

        // ── Alarm 2: QOF mandatory gain recognition end-2026 ─────────────────────
        // Trigger: investment.has_qof = true AND investment.qof_invested_before_2027 = true
        // BINDING: pre-2027 holders face mandatory gain recognition end of 2026
        $key = $this->checkQofAlarm($userId, $taxYear, $service, $electionFacts);
        if ($key !== null) {
            $emitted[] = $key;
        }

        // ── Alarm 3: QSBS early-eligibility at C-corp formation ──────────────────
        // Trigger: business.entity_type = c_corp + recent formation_date
        // BINDING: paired with §1244 note; professional framing; alarm is eligibility-awareness
        $key = $this->checkQsbsAlarm($userId, $taxYear, $service, $electionFacts);
        if ($key !== null) {
            $emitted[] = $key;
        }

        return $emitted;
    }

    // ── Alarm helpers ──────────────────────────────────────────────────────────

    private function check83bAlarm(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): ?string {
        $grantDateFact = UserTaxFact::currentFact($userId, 'equity.restricted_stock_grant_date');
        if ($grantDateFact === null) {
            return null;
        }

        $grantDate = Carbon::parse($grantDateFact->value);
        $daysSinceGrant = $grantDate->diffInDays(now(), false);

        // Only within the 30-day window
        if ($daysSinceGrant < 0 || $daysSinceGrant > 29) {
            return null;
        }

        return $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'alarm_83b_election',
            findingType: 'time_critical',
            band: 'time_critical',
            // BINDING: urgency stated as fact; 30-day is the deadline; professional framing
            treatment: 'An 83(b) election has a strict 30-day deadline from the grant date — '
                .'this window cannot be extended or recovered if missed. '
                .'If you received restricted stock (not RSUs), filing an 83(b) election with the IRS '
                .'within 30 days of the grant date may lock in a lower tax basis on the shares. '
                .'Consider contacting a tax professional or attorney immediately to evaluate '
                .'whether an 83(b) election is appropriate for your situation.',
            legalBasis: 'IRC §83(b); Reg. §1.83-2 (30-day election period, non-waivable)',
            ruleId: null, // alarm fires on time-critical signal, bypasses rule registry validation
            electionFacts: $electionFacts,
            docsMissing: ['83b_election', 'esop_or_equity_grant'],
        );
    }

    private function checkQofAlarm(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): ?string {
        $hasQofFact = UserTaxFact::currentFact($userId, 'investment.has_qof');
        if ($hasQofFact === null || $hasQofFact->value !== 'true') {
            return null;
        }

        $before2027Fact = UserTaxFact::currentFact($userId, 'investment.qof_invested_before_2027');
        if ($before2027Fact === null || $before2027Fact->value !== 'true') {
            return null;
        }

        return $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'alarm_qof_recognition',
            findingType: 'time_critical',
            band: 'time_critical',
            // BINDING: urgency stated as fact; 2026 end date is fact; professional framing
            treatment: 'Opportunity Zone (QOF) investors who invested before 2027 must recognize '
                .'any deferred gain by December 31, 2026 — the OBBBA removes the deferral '
                .'for pre-2027 QOF investments after this date. '
                .'If you hold a Qualified Opportunity Fund investment, this is a time-sensitive '
                .'tax event that may significantly affect your 2026 return. '
                .'Consider contacting a tax professional before year-end to understand your options, '
                .'which may include loss harvesting, re-deferral strategies, or other approaches '
                .'depending on your full picture.',
            legalBasis: 'IRC §1400Z-2; OBBBA (removes deferral for pre-2027 QOF holders end-2026)',
            ruleId: 'qof_recognition',
            electionFacts: $electionFacts,
        );
    }

    private function checkQsbsAlarm(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): ?string {
        $entityTypeFact = UserTaxFact::currentFact($userId, 'business.entity_type');
        if ($entityTypeFact === null || $entityTypeFact->value !== 'c_corp') {
            return null;
        }

        $formationDateFact = UserTaxFact::currentFact($userId, 'business.formation_date');
        if ($formationDateFact === null) {
            return null;
        }

        $formationDate = Carbon::parse($formationDateFact->value);
        $monthsSinceFormation = $formationDate->diffInMonths(now(), false);

        // Alert if recently formed (within 12 months) — QSBS clock starts at issuance
        if ($monthsSinceFormation < 0 || $monthsSinceFormation > 11) {
            return null;
        }

        return $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'alarm_qsbs_eligibility',
            findingType: 'time_critical',
            band: 'time_critical',
            // BINDING: QSBS early-eligibility; §1244 note is mandatory; professional framing
            treatment: 'Your C-corporation was recently formed — this may be early enough to '
                .'qualify original-issue shares as Qualified Small Business Stock (§1202 QSBS), '
                .'which can exclude up to $15 million of gain per taxpayer after a 5-year hold. '
                .'Eligibility depends on gross assets at issuance (under $75M), active business '
                .'requirements, and original issuance to non-corporate shareholders. '
                .'Additionally, consider filing a §1244 stock election at formation — this converts '
                .'potential losses on the stock to ordinary losses (up to $50,000/$100,000 MFJ), '
                .'providing downside protection at essentially zero cost now. '
                .'Both elections are time-sensitive and require a tax professional or attorney '
                .'to set up correctly.',
            legalBasis: 'IRC §1202 (QSBS, $15M cap, $75M gross-asset test); IRC §1244 (ordinary loss, $50K/$100K cap)',
            ruleId: null, // no specific rule validation — alarm fires on formation signal
            electionFacts: $electionFacts,
            docsMissing: ['qsbs_issuance_cert'],
        );
    }
}
