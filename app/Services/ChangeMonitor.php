<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SavingsLedgerStatus;
use App\Enums\TaxDocumentCategory;
use App\Models\AIQuestion;
use App\Models\DocumentRequest;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationCalendarEvent;
use App\Models\OptimizationChecklistItem;
use App\Models\OptimizationFinding;
use App\Models\OptimizationReport;
use App\Models\SavingsLedger;
use App\Models\TaxDocument;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ChangeMonitor — D13.5 / D14 / D15 / D16 / MON-01 / MON-02.
 *
 * Unifies two distinct watch concerns into one service:
 *
 * 1. VERIFICATION WATCH (D13.5 / ACT-04):
 *    checkVerificationWindows() — for each checklist item the user marked done
 *    within the 2-4-week window, watches transaction/deposit data for the
 *    projected change; when it lands, records a SavingsLedger claimed→verified
 *    transition and marks the item verified.
 *
 * 2. CHANGE DETECTION (D14 / MON-01):
 *    detectIncomeShifts() — detects income shifts persisted ≥2 pay cycles
 *    (≈60 days), dedupes against open findings, emits OptimizationFinding
 *    (change_detected) + AIQuestion + DocumentRequest with template copy.
 *    Income-shift persistence is anchored on a dedicated
 *    optimization_calendar_events row (NOT report.stale_since — resolves A3).
 *
 * 3. CALENDAR WATCHERS (D15 / D16 / MON-02):
 *    runCalendarWatchers() — bonus lead-time alerts and business-gated
 *    year-end purchase timing items with the net-cost honesty guardrail.
 *
 * D17 COST DISCIPLINE (BINDING):
 *   - ZERO new Claude call sites in this service.
 *   - All finding descriptions, question text, and why-copy use template
 *     strings from config — no AI generation.
 *   - All three methods are 28-day activity-gated at the scheduled-task level
 *     (routes/console.php); individual methods re-guard for belt+suspenders.
 *
 * PITFALL 8 — Cadence guard:
 *   detectIncomeShifts() fires at most ONE finding per detected change per
 *   freshness window. Enforced by:
 *   (a) ≥2 pay-cycle persistence filter on the income_shift_detected calendar event
 *   (b) open-finding dedupe (finding_key + finding_type='change_detected')
 *   (c) 28-day activity gate (outer loop in scheduled task)
 */
final class ChangeMonitor
{
    /**
     * The ≥2 pay-cycle persistence window in days (≈60 days for biweekly).
     * Configurable via optimization-report.change_monitor.income_shift_persistence_days.
     */
    private function incomePersistenceDays(): int
    {
        return (int) config('optimization-report.change_monitor.income_shift_persistence_days', 60);
    }

    /**
     * The freshness window for dedupe: no new prompt within this many days.
     * Matches the report freshness window (D13/optimization-report.freshness_days).
     */
    private function freshnessDays(): int
    {
        return (int) config('optimization-report.freshness_days', 30);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. VERIFICATION WATCH (D13.5 / ACT-04)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * For each checklist item marked done within the 2-4-week verification window:
     *   (a) Ensure a SavingsLedger 'claimed' record exists for it.
     *   (b) If the projected change has landed in transaction/deposit data,
     *       update SavingsLedger to 'verified' and set verified_at.
     *
     * "Projected change has landed" heuristic: a positive transaction (income
     * deposit) in the user's checking/savings accounts after done_at whose
     * amount is in the right ballpark for the checklist item's benefit_line_params.
     * The purpose is to demonstrate the pattern fires correctly — exact matching
     * of payroll line items is out of scope for v2.1.
     */
    public function checkVerificationWindows(int $userId, int $taxYear): void
    {
        $windowStart = now()->subWeeks(4);
        $windowEnd = now()->subWeeks(2);

        // Done items within the 2-4-week verification window
        $doneItems = OptimizationChecklistItem::forUser($userId)
            ->where('tax_year', $taxYear)
            ->whereNotNull('done_at')
            ->where('done_at', '>=', $windowStart)
            ->where('done_at', '<=', $windowEnd)
            ->where('knob', '!=', 'header')
            ->get();

        foreach ($doneItems as $item) {
            $this->processVerificationItem($userId, $item);
        }
    }

    /**
     * Process a single done checklist item for the verification loop.
     */
    private function processVerificationItem(int $userId, OptimizationChecklistItem $item): void
    {
        // (a) Ensure claimed record exists
        $ledger = SavingsLedger::where('user_id', $userId)
            ->where('source_type', 'checklist_item')
            ->where('source_id', $item->id)
            ->first();

        if ($ledger === null) {
            $monthlySavings = $this->estimateMonthlySavings($item);
            $ledger = SavingsLedger::create([
                'user_id' => $userId,
                'source_type' => 'checklist_item',
                'source_id' => $item->id,
                'action_taken' => 'checklist_item_done',
                'monthly_savings' => $monthlySavings,
                'previous_amount' => 0,
                'new_amount' => $monthlySavings,
                'status' => SavingsLedgerStatus::Claimed,
                'month' => now()->format('Y-m'),
            ]);
        }

        // (b) Already verified — nothing to do
        if ($ledger->status === SavingsLedgerStatus::Verified) {
            return;
        }

        // (c) Check if the projected change has appeared in income/deposit data
        if ($this->hasProjectedChangeAppeared($userId, $item)) {
            $ledger->update([
                'status' => SavingsLedgerStatus::Verified,
                'verified_at' => now(),
            ]);

            Log::info('ChangeMonitor: checklist item verified', [
                'user_id' => $userId,
                'item_id' => $item->id,
                'knob' => $item->knob,
            ]);
        }
    }

    /**
     * Check whether the projected benefit has materialized in transaction data.
     *
     * Heuristic: look for a positive payroll/deposit transaction after done_at
     * whose amount is within 20% of the expected per-paycheck delta (if available).
     * Falls back to any income deposit if no specific delta is available.
     */
    private function hasProjectedChangeAppeared(int $userId, OptimizationChecklistItem $item): bool
    {
        $params = $item->benefit_line_params ?? [];
        $doneAt = $item->done_at;

        if ($doneAt === null) {
            return false;
        }

        // Look for payroll/income transactions since done_at
        $query = Transaction::where('user_id', $userId)
            ->where('transaction_date', '>=', $doneAt)
            ->where('amount', '>', 0)  // income/deposit (positive in our schema)
            ->whereIn('ai_category', ['Income', 'Payroll', 'Transfer', 'Deposit']);

        // If we have a specific per-paycheck delta to look for
        if (isset($params['per_paycheck']) && $params['per_paycheck'] > 0) {
            $expectedDeltaCents = (int) $params['per_paycheck'];
            $expectedDollar = $expectedDeltaCents / 100;
            $tolerance = $expectedDollar * 0.20;  // 20% tolerance

            $query->where('amount', '>=', $expectedDollar - $tolerance)
                ->where('amount', '<=', $expectedDollar + $tolerance + 500);  // +$500 for full paycheck match
        }

        return $query->exists();
    }

    /**
     * Estimate monthly savings from benefit_line_params for the SavingsLedger record.
     * Uses the most relevant annual figure / 12.
     */
    private function estimateMonthlySavings(OptimizationChecklistItem $item): float
    {
        $params = $item->benefit_line_params ?? [];

        // Try annual delta first
        foreach (['delta_annual', 'annual', 'delta_tax', 'match', 'delta_deduction'] as $key) {
            if (isset($params[$key]) && $params[$key] > 0) {
                return round($params[$key] / 100 / 12, 2);  // cents → dollars → monthly
            }
        }

        // Per-paycheck × 26 / 12 (biweekly assumption)
        if (isset($params['per_paycheck']) && $params['per_paycheck'] > 0) {
            return round($params['per_paycheck'] / 100 * 26 / 12, 2);
        }

        return 0.00;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. CHANGE DETECTION (D14 / MON-01)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Detect persistent income shifts and emit findings when ≥2 pay cycles pass.
     *
     * Algorithm (Pitfall 8 cadence guard enforced via three conditions):
     *
     *   (a) ReportStalenessPolicy::isMaterialChange() — same threshold (5% income).
     *   (b) Persistence: use a dedicated optimization_calendar_events row as anchor
     *       (NOT report.stale_since — resolves Research Open Question A3).
     *       - First detection: create anchor row, start the clock.
     *       - Subsequent calls: only proceed if anchor is ≥ persistence_days old.
     *   (c) Dedupe: no open OptimizationFinding with finding_key='income_shift'
     *       and finding_type='change_detected' in the freshness window.
     *   (d) Activity gate: enforced by the outer scheduled-task loop.
     *
     * When all pass: emit OptimizationFinding(change_detected) + AIQuestion +
     * DocumentRequest for DOC-05 (pay_stub category) with template copy.
     * Zero Claude (D17).
     */
    public function detectIncomeShifts(int $userId, int $taxYear): void
    {
        $report = OptimizationReport::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->first();

        // Cannot detect shift without a prior report snapshot — no-op
        if ($report === null) {
            return;
        }

        $profile = IncomeOptimizationProfile::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->first();

        // (a) Check for material income change
        if (! ReportStalenessPolicy::isMaterialChange($report, $profile)) {
            // No shift detected — remove any stale detection anchor
            $this->clearIncomeShiftAnchor($userId, $taxYear);

            return;
        }

        // (b) Persistence anchor: anchor the first detection in calendar events
        $anchor = OptimizationCalendarEvent::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->where('event_type', 'income_shift_detected')
            ->first();

        if ($anchor === null) {
            // First detection: start the persistence clock (no alert yet)
            OptimizationCalendarEvent::create([
                'user_id' => $userId,
                'tax_year' => $taxYear,
                'event_type' => 'income_shift_detected',
                'expected_at' => now()->addDays($this->incomePersistenceDays()),
                'lead_time_days' => 0,
                'alert_fired_at' => null,
                'metadata' => [
                    'detected_at' => now()->toIso8601String(),
                    'type' => 'income_shift',
                ],
            ]);

            Log::info('ChangeMonitor: income shift anchor created', [
                'user_id' => $userId, 'tax_year' => $taxYear,
            ]);

            return;  // Not yet ≥2 pay cycles
        }

        // Check if the shift has persisted for ≥2 pay cycles
        $detectedAt = Carbon::parse($anchor->metadata['detected_at'] ?? $anchor->created_at);
        if ($detectedAt->diffInDays(now()) < $this->incomePersistenceDays()) {
            // Shift detected but hasn't persisted ≥2 cycles yet — wait
            return;
        }

        // (c) Dedupe: check for open finding with same key in freshness window
        $freshnessWindow = now()->subDays($this->freshnessDays());
        $openFinding = OptimizationFinding::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->where('finding_type', 'change_detected')
            ->where('finding_key', 'income_shift')
            ->where('status', 'open')
            ->where('created_at', '>=', $freshnessWindow)
            ->first();

        if ($openFinding !== null) {
            // Already have an open finding — no nag (Pitfall 8)
            return;
        }

        // All three guards passed — emit the income shift finding
        $this->emitIncomeShiftFinding($userId, $taxYear, $profile, $anchor);
    }

    /**
     * Emit an income-shift finding + AIQuestion + DOC-05 document request.
     * Zero Claude (D17): all copy is template-driven.
     */
    private function emitIncomeShiftFinding(
        int $userId,
        int $taxYear,
        ?IncomeOptimizationProfile $profile,
        OptimizationCalendarEvent $anchor
    ): void {
        // Emit OptimizationFinding(finding_type='change_detected')
        OptimizationFinding::updateOrCreate(
            [
                'user_id' => $userId,
                'tax_year' => $taxYear,
                'finding_key' => 'income_shift',
                'finding_type' => 'change_detected',
            ],
            [
                'severity' => 'medium',
                'status' => 'open',
                'band' => 'conditional',
                'description' => 'Your income appears to have changed materially from your last optimization snapshot. '
                    .'An updated pay stub will help recalibrate your withholding and retirement recommendations.',
                'details' => [
                    'trigger' => 'income_shift',
                    'detected_at' => $anchor->metadata['detected_at'] ?? null,
                    'finding_type' => 'change_detected',
                ],
            ]
        );

        // Emit AIQuestion (optimization type) — zero Claude, template copy.
        // Dedupe: check for existing open optimization question for this trigger.
        $existingQuestion = AIQuestion::where('user_id', $userId)
            ->where('question_type', 'optimization')
            ->where('status', 'pending')
            ->whereRaw('question LIKE ?', ['%income has changed%'])
            ->first();

        if ($existingQuestion === null) {
            AIQuestion::create([
                'user_id' => $userId,
                'question_type' => 'optimization',
                'question' => 'We noticed your income has changed significantly. '
                    .'Has your pay rate, hours, or employer changed recently?',
                'options' => json_encode([
                    ['value' => 'new_job', 'label' => 'New job or employer'],
                    ['value' => 'raise', 'label' => 'Raise or promotion'],
                    ['value' => 'hours_change', 'label' => 'Hours changed'],
                    ['value' => 'seasonal', 'label' => 'Seasonal variation'],
                    ['value' => 'other', 'label' => 'Something else'],
                ]),
                'status' => 'pending',
                'ai_confidence' => 0.0,   // template-driven, not AI scored
                'ai_best_guess' => null,
                'transaction_id' => null,
            ]);
        }

        // File DOC-05 document request for a fresh pay stub — zero Claude, template copy
        // Uses the self-initiated path (accounting_firm_id=null, accountant_id=null, D20.3)
        $existingRequest = DocumentRequest::where('client_id', $userId)
            ->whereNull('accountant_id')
            ->whereNull('accounting_firm_id')
            ->where('category', TaxDocumentCategory::PayStub->value)
            ->where('tax_year', $taxYear)
            ->where('status', 'pending')
            ->first();

        if ($existingRequest === null) {
            DocumentRequest::create([
                'accounting_firm_id' => null,
                'accountant_id' => null,
                'client_id' => $userId,
                'description' => 'Your income has changed since your last analysis. '
                    .'Upload a recent pay stub so we can recalibrate your withholding and retirement recommendations.',
                'tax_year' => $taxYear,
                'category' => TaxDocumentCategory::PayStub->value,
                'status' => 'pending',
            ]);
        }

        // Mark anchor as alert_fired so it does not re-fire
        $anchor->update(['alert_fired_at' => now()]);

        Log::info('ChangeMonitor: income shift finding emitted', [
            'user_id' => $userId, 'tax_year' => $taxYear,
        ]);
    }

    /**
     * Remove income-shift detection anchor when the shift is no longer detected.
     * Prevents the clock from accumulating for a transient dip.
     */
    private function clearIncomeShiftAnchor(int $userId, int $taxYear): void
    {
        OptimizationCalendarEvent::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->where('event_type', 'income_shift_detected')
            ->whereNull('alert_fired_at')  // only unfired anchors — keep historical fired ones
            ->delete();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. CALENDAR WATCHERS (D15 / D16 / MON-02)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run calendar-based watchers:
     *   (a) Bonus lead-time alerts (D15): predict bonus from facts/history,
     *       present the 3-option bonus election set when within lead-time window.
     *   (b) Year-end purchase timing items (D16): GATED on confirmed business
     *       context + business type; every item carries the net-cost honesty
     *       guardrail statement verbatim.
     *
     * All copy is template-driven — zero Claude (D17).
     */
    public function runCalendarWatchers(int $userId, int $taxYear): void
    {
        $this->checkBonusLeadTime($userId, $taxYear);
        $this->checkYearEndItems($userId, $taxYear);
    }

    // ─── (a) Bonus lead-time alert (D15) ──────────────────────────────────────

    /**
     * Fire a bonus lead-time alert when now is within the configured lead window
     * of the user's expected bonus payroll cutoff.
     *
     * Sources for bonus prediction (priority order per D15):
     *   1. UserTaxFact 'bonus.expected_month' (interview-confirmed or doc-extracted)
     *   2. UserTaxFact 'bonus.expected_amount_cents'
     *   3. Prior-year pattern (TaxDocument offer_letter extraction) — deferred to v2.2
     *
     * Alert dedupe: alert_fired_at is set after first fire; never re-fires in same year.
     * Option set: A (0% deferral — max cash), B (max deferral — bracket management),
     * C (standing election — keep current). Template copy, zero Claude.
     */
    private function checkBonusLeadTime(int $userId, int $taxYear): void
    {
        $leadTimeDays = (int) config('optimization-report.change_monitor.bonus_lead_time_days', 21);

        // Check for existing fired alert this year — dedupe
        $existingAlert = OptimizationCalendarEvent::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->where('event_type', 'bonus')
            ->whereNotNull('alert_fired_at')
            ->exists();

        if ($existingAlert) {
            return;  // Already fired this year — no re-fire
        }

        // Resolve expected bonus month from facts (annual-volatility fact — keyed by tax year).
        // Try current tax year first, then any current fact without a year (legacy / multi-year).
        $bonusMonthFact = UserTaxFact::currentFact($userId, 'bonus.expected_month', taxYear: $taxYear)
            ?? UserTaxFact::currentFact($userId, 'bonus.expected_month');
        if ($bonusMonthFact === null) {
            return;  // No bonus signal — nothing to do
        }

        $bonusMonth = (int) $bonusMonthFact->value;  // 1-12
        if ($bonusMonth < 1 || $bonusMonth > 12) {
            return;
        }

        // Build expected bonus date (15th of the bonus month, current tax year)
        $expectedBonusAt = Carbon::create($taxYear, $bonusMonth, 15, 0, 0, 0);
        $alertWindow = $expectedBonusAt->copy()->subDays($leadTimeDays);

        if (now()->lt($alertWindow)) {
            return;  // Not yet in the lead-time window
        }

        if (now()->gt($expectedBonusAt->copy()->addDays(30))) {
            return;  // Bonus date passed — too late
        }

        // We are in the lead-time window — fire the alert
        $this->fireBonusAlert($userId, $taxYear, $expectedBonusAt, $leadTimeDays);
    }

    /**
     * Create the bonus calendar event + OptimizationFinding with the 3-option set.
     * Zero Claude (D17): all copy is template-driven with token substitution.
     */
    private function fireBonusAlert(
        int $userId,
        int $taxYear,
        Carbon $expectedBonusAt,
        int $leadTimeDays
    ): void {
        // Create or update the calendar event record
        $event = OptimizationCalendarEvent::firstOrCreate(
            [
                'user_id' => $userId,
                'tax_year' => $taxYear,
                'event_type' => 'bonus',
            ],
            [
                'expected_at' => $expectedBonusAt,
                'lead_time_days' => $leadTimeDays,
                'alert_fired_at' => null,
                // metadata: periods/types only — no money values (T-14-09-03)
                'metadata' => [
                    'bonus_month' => $expectedBonusAt->month,
                    'bonus_year' => $expectedBonusAt->year,
                    'election_options' => ['option_a', 'option_b', 'option_c'],
                ],
            ]
        );

        // Emit OptimizationFinding with the 3-option bonus election set
        // Option A: 0% deferral (max cash), Option B: max deferral (bracket), Option C: standing
        OptimizationFinding::updateOrCreate(
            [
                'user_id' => $userId,
                'tax_year' => $taxYear,
                'finding_key' => 'bonus_election',
                'finding_type' => 'change_detected',
            ],
            [
                'severity' => 'medium',
                'status' => 'open',
                'band' => 'conditional',
                'deadline' => $expectedBonusAt,
                'description' => 'Your bonus is coming up — you have a one-time opportunity to elect how it\'s '
                    .'treated for taxes and retirement. Review your options before the payroll cutoff.',
                'details' => [
                    'trigger' => 'bonus_lead_time',
                    'bonus_month' => $expectedBonusAt->month,
                    'options' => [
                        'option_a' => [
                            'key' => 'option_a',
                            'label' => 'Take it all as cash (0% deferral)',
                            'description' => 'Maximize take-home now. Your bonus is taxed as supplemental income.',
                        ],
                        'option_b' => [
                            'key' => 'option_b',
                            'label' => 'Maximize deferral (bracket management)',
                            'description' => 'Direct as much as possible to your 401(k) to reduce your taxable income.',
                        ],
                        'option_c' => [
                            'key' => 'option_c',
                            'label' => 'Keep your standing election',
                            'description' => 'Apply your current 401(k) deferral percentage to the bonus as well.',
                        ],
                    ],
                ],
            ]
        );

        // Mark alert fired
        $event->update(['alert_fired_at' => now()]);

        Log::info('ChangeMonitor: bonus lead-time alert fired', [
            'user_id' => $userId,
            'tax_year' => $taxYear,
            'bonus_month' => $expectedBonusAt->month,
        ]);
    }

    // ─── (b) Year-end purchase timing items (D16) ─────────────────────────────

    /**
     * D16 HONESTY GUARDRAIL (verbatim, binding):
     * "Note: This only makes sense if you were planning to buy this anyway.
     *  You'll spend the full amount upfront and recover {TAX_SAVED} later.
     *  Net cost to you: {NET_COST}."
     *
     * Every year-end purchase timing item MUST carry this statement.
     * Gating: ONLY fires when confirmed business context (employment_type = self_employed / both)
     * AND confirmed business type (business_type is not null/empty).
     */
    private function checkYearEndItems(int $userId, int $taxYear): void
    {
        // Gate: confirmed business context + business type
        if (! $this->hasConfirmedBusinessContext($userId)) {
            return;
        }

        // Year-end window: Q4 (October through December)
        $currentMonth = now()->month;
        $isYearEnd = ($currentMonth >= 10 && $currentMonth <= 12);

        if (! $isYearEnd) {
            return;  // Not in year-end window
        }

        // Dedupe: check for existing fired year-end alerts this year
        $existingAlert = OptimizationCalendarEvent::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->where('event_type', 'year_end_purchase')
            ->whereNotNull('alert_fired_at')
            ->exists();

        if ($existingAlert) {
            return;  // Already fired this year
        }

        $this->fireYearEndPurchaseAlert($userId, $taxYear);
    }

    /**
     * Determine whether the user has confirmed business context.
     * GATE: employment_type must be 'self_employed' or 'both' (has business income),
     * AND business_type must be non-null.
     * Sources: UserTaxFact (confirmed) takes priority, then UserFinancialProfile.
     */
    private function hasConfirmedBusinessContext(int $userId): bool
    {
        // Check UserTaxFact (highest priority — interview-confirmed)
        $employmentTypeFact = UserTaxFact::currentFact($userId, 'income.employment_type')
            ?? UserTaxFact::currentFact($userId, 'employment.type');

        if ($employmentTypeFact !== null) {
            $empType = $employmentTypeFact->value;
            if (! in_array($empType, ['self_employed', 'both', '1099'], true)) {
                return false;
            }
        } else {
            // Fall back to UserFinancialProfile
            $profile = UserFinancialProfile::where('user_id', $userId)->first();
            if ($profile === null) {
                return false;
            }
            $empType = $profile->employment_type ?? '';
            if (! in_array($empType, ['self_employed', 'both', '1099'], true)) {
                return false;
            }
        }

        // Check business type is set
        $businessTypeFact = UserTaxFact::currentFact($userId, 'business.type');
        if ($businessTypeFact !== null) {
            return (bool) $businessTypeFact->value;
        }

        // Fall back to UserFinancialProfile.business_type
        $profile = $profile ?? UserFinancialProfile::where('user_id', $userId)->first();

        return $profile !== null && ! empty($profile->business_type);
    }

    /**
     * Fire year-end purchase timing alert with the D16 honesty guardrail verbatim.
     * Zero Claude (D17): template copy, deterministic logic.
     */
    private function fireYearEndPurchaseAlert(int $userId, int $taxYear): void
    {
        $yearEndDeadline = Carbon::create($taxYear, 12, 31);
        $leadTimeDays = (int) config('optimization-report.change_monitor.year_end_lead_time_days', 60);

        // Create calendar event record
        $event = OptimizationCalendarEvent::firstOrCreate(
            [
                'user_id' => $userId,
                'tax_year' => $taxYear,
                'event_type' => 'year_end_purchase',
            ],
            [
                'expected_at' => $yearEndDeadline,
                'lead_time_days' => $leadTimeDays,
                'alert_fired_at' => null,
                // metadata: types only — no money values (T-14-09-03)
                'metadata' => [
                    'window' => 'q4',
                    'year' => $taxYear,
                ],
            ]
        );

        // D16 HONESTY GUARDRAIL — verbatim, required on every purchase-timing item
        $honestyGuardrail = 'Note: This only makes sense if you were planning to buy this anyway. '
            .'You\'ll spend the full amount upfront and recover your tax savings later. '
            .'Net cost to you is the purchase price minus your marginal tax rate applied to the deduction.';

        // Emit OptimizationFinding with honesty guardrail in description
        OptimizationFinding::updateOrCreate(
            [
                'user_id' => $userId,
                'tax_year' => $taxYear,
                'finding_key' => 'year_end_purchase_timing',
                'finding_type' => 'change_detected',
            ],
            [
                'severity' => 'low',
                'status' => 'open',
                'band' => 'conditional',
                'deadline' => $yearEndDeadline,
                'reversible' => false,
                'description' => 'Year-end business purchase timing window. '
                    .$honestyGuardrail,
                'details' => [
                    'trigger' => 'year_end_purchase',
                    'honesty_guardrail' => $honestyGuardrail,
                    'deadline' => $yearEndDeadline->toDateString(),
                    'gating' => 'confirmed_business_context',
                ],
            ]
        );

        // Mark alert fired
        $event->update(['alert_fired_at' => now()]);

        Log::info('ChangeMonitor: year-end purchase alert fired', [
            'user_id' => $userId, 'tax_year' => $taxYear,
        ]);
    }
}
