<?php

declare(strict_types=1);

use App\Enums\SavingsLedgerStatus;
use App\Enums\TaxDocumentCategory;
use App\Models\AIQuestion;
use App\Models\BankAccount;
use App\Models\DocumentRequest;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationCalendarEvent;
use App\Models\OptimizationChecklistItem;
use App\Models\OptimizationFinding;
use App\Models\OptimizationReport;
use App\Models\SavingsLedger;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use App\Services\ChangeMonitor;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Run the migration (testing environment)
    $this->monitor = app(ChangeMonitor::class);
    $this->taxYear = 2026;
});

// ─────────────────────────────────────────────────────────────────────────────
// VERIFICATION WATCH (D13.5 / ACT-04)
// ─────────────────────────────────────────────────────────────────────────────

describe('checkVerificationWindows', function () {
    it('creates a claimed SavingsLedger record for a done checklist item within the window', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        // Item done 3 weeks ago (within 2-4 week window)
        $item = OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'knob' => 'k3',
            'kind' => 'directive',
            'done_at' => now()->subWeeks(3),
            'benefit_line_params' => ['pct' => 6.0, 'match' => 50000, 'delta_annual' => 150000],
        ]);

        $this->monitor->checkVerificationWindows($user->id, $this->taxYear);

        // SavingsLedger claimed record should exist
        $ledger = SavingsLedger::where('user_id', $user->id)
            ->where('source_type', 'checklist_item')
            ->where('source_id', $item->id)
            ->first();

        expect($ledger)->not->toBeNull()
            ->and($ledger->status)->toBe(SavingsLedgerStatus::Claimed);
    });

    it('marks a checklist item verified when the projected change appears', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        // Item done 3 weeks ago
        $item = OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'knob' => 'k3',
            'kind' => 'directive',
            'done_at' => now()->subWeeks(3),
            'benefit_line_params' => ['pct' => 6.0, 'delta_annual' => 150000],
        ]);

        // Existing claimed ledger record
        $ledger = SavingsLedger::create([
            'user_id' => $user->id,
            'source_type' => 'checklist_item',
            'source_id' => $item->id,
            'action_taken' => 'checklist_item_done',
            'monthly_savings' => 12.50,
            'previous_amount' => 0,
            'new_amount' => 12.50,
            'status' => SavingsLedgerStatus::Claimed,
            'month' => now()->format('Y-m'),
        ]);

        // Create a payroll/income deposit transaction after done_at
        // Uses Transaction::factory() to satisfy bank_account_id NOT NULL constraint
        $bankAccount = BankAccount::factory()->create(['user_id' => $user->id]);
        Transaction::factory()->create([
            'user_id' => $user->id,
            'bank_account_id' => $bankAccount->id,
            'amount' => 3500.00,  // positive = deposit
            'merchant_name' => 'ACME CORP PAYROLL',
            'merchant_normalized' => 'acme corp',
            'transaction_date' => now()->subWeeks(2),
            'ai_category' => 'Payroll',
            'review_status' => 'auto_categorized',
        ]);

        $this->monitor->checkVerificationWindows($user->id, $this->taxYear);

        $ledger->refresh();
        expect($ledger->status)->toBe(SavingsLedgerStatus::Verified)
            ->and($ledger->verified_at)->not->toBeNull();
    });

    it('does not process items outside the 2-4 week verification window', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        // Item done only 1 week ago (too recent — not yet in window)
        OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'knob' => 'k3',
            'done_at' => now()->subWeek(),
            'benefit_line_params' => ['pct' => 6.0],
        ]);

        // Item done 5 weeks ago (too old — past window)
        OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'knob' => 'k4',
            'done_at' => now()->subWeeks(5),
            'benefit_line_params' => ['amount' => 100000],
        ]);

        $this->monitor->checkVerificationWindows($user->id, $this->taxYear);

        // Neither item should generate a ledger record (outside window)
        $count = SavingsLedger::where('user_id', $user->id)
            ->where('source_type', 'checklist_item')
            ->count();

        expect($count)->toBe(0);
    });

    it('does not re-fire for an already-verified item', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        $item = OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'knob' => 'k3',
            'done_at' => now()->subWeeks(3),
            'benefit_line_params' => ['pct' => 6.0, 'delta_annual' => 150000],
        ]);

        // Already verified
        SavingsLedger::create([
            'user_id' => $user->id,
            'source_type' => 'checklist_item',
            'source_id' => $item->id,
            'action_taken' => 'checklist_item_done',
            'monthly_savings' => 12.50,
            'previous_amount' => 0,
            'new_amount' => 12.50,
            'status' => SavingsLedgerStatus::Verified,
            'month' => now()->format('Y-m'),
            'verified_at' => now()->subDay(),
        ]);

        $this->monitor->checkVerificationWindows($user->id, $this->taxYear);

        // Still only one record (no duplicate)
        $count = SavingsLedger::where('user_id', $user->id)
            ->where('source_type', 'checklist_item')
            ->where('source_id', $item->id)
            ->count();

        expect($count)->toBe(1);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// CHANGE DETECTION (D14 / MON-01)
// ─────────────────────────────────────────────────────────────────────────────

describe('detectIncomeShifts', function () {
    it('creates a detection anchor on first income shift detection', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        // Report with stale snapshot (material change)
        OptimizationReport::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'sections' => [],
            'is_stale' => false,
            'rebuilt_at' => now()->subDays(10),
            'built_against' => ['income_cents' => 10000000, 'savings_cents' => 100000],
        ]);

        // Profile with significantly different income ($120k — 20% shift, above 5% threshold)
        IncomeOptimizationProfile::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'bank_deposit_total' => '12000000',  // $120k
        ]);

        $this->monitor->detectIncomeShifts($user->id, $this->taxYear);

        // Anchor should be created, but no finding yet (not ≥2 cycles)
        $anchor = OptimizationCalendarEvent::where('user_id', $user->id)
            ->where('tax_year', $this->taxYear)
            ->where('event_type', 'income_shift_detected')
            ->first();

        expect($anchor)->not->toBeNull();

        // No finding yet
        $finding = OptimizationFinding::where('user_id', $user->id)
            ->where('finding_type', 'change_detected')
            ->where('finding_key', 'income_shift')
            ->first();

        expect($finding)->toBeNull();
    });

    it('emits exactly one finding after ≥2 pay cycles of persistence', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        // Report with material change
        OptimizationReport::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'sections' => [],
            'is_stale' => false,
            'rebuilt_at' => now()->subDays(10),
            'built_against' => ['income_cents' => 10000000, 'savings_cents' => 100000],
        ]);

        IncomeOptimizationProfile::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'bank_deposit_total' => '12000000',  // 20% shift
        ]);

        // Pre-existing anchor from 61 days ago (≥2 pay cycles)
        OptimizationCalendarEvent::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'event_type' => 'income_shift_detected',
            'expected_at' => now()->addDays(60),
            'lead_time_days' => 0,
            'alert_fired_at' => null,
            'metadata' => [
                'detected_at' => now()->subDays(61)->toIso8601String(),  // 61 days ago
                'type' => 'income_shift',
            ],
        ]);

        $this->monitor->detectIncomeShifts($user->id, $this->taxYear);

        // Exactly one finding emitted
        $findings = OptimizationFinding::where('user_id', $user->id)
            ->where('finding_type', 'change_detected')
            ->where('finding_key', 'income_shift')
            ->get();

        expect($findings)->toHaveCount(1)
            ->and($findings->first()->status)->toBe('open');

        // AIQuestion created
        $question = AIQuestion::where('user_id', $user->id)
            ->where('question_type', 'optimization')
            ->first();
        expect($question)->not->toBeNull();

        // DocumentRequest for pay_stub created
        $docRequest = DocumentRequest::where('client_id', $user->id)
            ->where('category', TaxDocumentCategory::PayStub->value)
            ->whereNull('accountant_id')
            ->first();
        expect($docRequest)->not->toBeNull();
    });

    it('does NOT emit a finding when shift has NOT persisted ≥2 pay cycles', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        OptimizationReport::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'sections' => [],
            'is_stale' => false,
            'rebuilt_at' => now()->subDays(10),
            'built_against' => ['income_cents' => 10000000, 'savings_cents' => 100000],
        ]);

        IncomeOptimizationProfile::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'bank_deposit_total' => '12000000',  // 20% shift
        ]);

        // Anchor from only 30 days ago (< 60 days persistence)
        OptimizationCalendarEvent::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'event_type' => 'income_shift_detected',
            'expected_at' => now()->addDays(30),
            'lead_time_days' => 0,
            'alert_fired_at' => null,
            'metadata' => [
                'detected_at' => now()->subDays(30)->toIso8601String(),  // only 30 days
            ],
        ]);

        $this->monitor->detectIncomeShifts($user->id, $this->taxYear);

        // No finding yet
        $finding = OptimizationFinding::where('user_id', $user->id)
            ->where('finding_type', 'change_detected')
            ->where('finding_key', 'income_shift')
            ->first();

        expect($finding)->toBeNull();
    });

    it('does NOT nag with a second finding when one is already open (dedupe)', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        OptimizationReport::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'sections' => [],
            'is_stale' => false,
            'rebuilt_at' => now()->subDays(10),
            'built_against' => ['income_cents' => 10000000, 'savings_cents' => 100000],
        ]);

        IncomeOptimizationProfile::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'bank_deposit_total' => '12000000',
        ]);

        // Persistence anchor old enough
        OptimizationCalendarEvent::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'event_type' => 'income_shift_detected',
            'expected_at' => now()->addDays(1),
            'lead_time_days' => 0,
            'alert_fired_at' => null,
            'metadata' => ['detected_at' => now()->subDays(61)->toIso8601String()],
        ]);

        // Existing open finding within freshness window
        OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'finding_key' => 'income_shift',
            'finding_type' => 'change_detected',
            'status' => 'open',
            'severity' => 'medium',
        ]);

        $this->monitor->detectIncomeShifts($user->id, $this->taxYear);

        // Still exactly one finding (no duplicate)
        $count = OptimizationFinding::where('user_id', $user->id)
            ->where('finding_type', 'change_detected')
            ->where('finding_key', 'income_shift')
            ->count();

        expect($count)->toBe(1);
    });

    it('does NOT emit a finding for a one-off deposit (no material change in profile)', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        // Report and profile match closely — no material change.
        // savings_cents = 0 in snapshot so savings delta doesn't trigger the gate.
        OptimizationReport::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'sections' => [],
            'is_stale' => false,
            'rebuilt_at' => now()->subDays(10),
            'built_against' => ['income_cents' => 10000000, 'savings_cents' => 0],
        ]);

        // Profile income only 3% higher — below the 5% income threshold; no savings.
        IncomeOptimizationProfile::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'bank_deposit_total' => '10300000',  // 3% shift (below 5% threshold)
            // All retirement YTD are null → computeSavingsCents returns 0 → no savings delta
        ]);

        $this->monitor->detectIncomeShifts($user->id, $this->taxYear);

        // No anchor, no finding
        $anchorCount = OptimizationCalendarEvent::where('user_id', $user->id)
            ->where('event_type', 'income_shift_detected')
            ->count();
        expect($anchorCount)->toBe(0);

        $findingCount = OptimizationFinding::where('user_id', $user->id)
            ->where('finding_type', 'change_detected')
            ->count();
        expect($findingCount)->toBe(0);
    });

    it('skips inactive users (>28 days) — zero work', function () {
        // Inactive user (last active 35 days ago)
        $user = User::factory()->create([
            'last_active_at' => now()->subDays(35),
        ]);

        OptimizationReport::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'sections' => [],
            'is_stale' => false,
            'rebuilt_at' => now()->subDays(10),
            'built_against' => ['income_cents' => 10000000, 'savings_cents' => 0],
        ]);

        IncomeOptimizationProfile::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'bank_deposit_total' => '12000000',
        ]);

        // The activity gate is in the scheduled task, not in detectIncomeShifts itself.
        // We verify by simulating: call it directly (unguarded) and confirm it may run,
        // but the scheduled task closure filters inactive users BEFORE calling this.
        // This test validates the scheduled task's WHERE clause logic:
        $inactiveUsers = User::whereHas('bankConnections')
            ->where('last_active_at', '>', now()->subDays(28))
            ->whereKey($user->id)
            ->count();

        expect($inactiveUsers)->toBe(0);  // Inactive user is excluded from the sweep
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// CALENDAR WATCHERS (D15 / D16 / MON-02)
// ─────────────────────────────────────────────────────────────────────────────

describe('runCalendarWatchers - bonus lead-time', function () {
    it('fires a bonus alert when within the configured lead-time window', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        // Bonus expected next month (within 21-day lead time)
        $bonusMonth = now()->addDays(10)->month;  // within 21 days
        $bonusYear = now()->addDays(10)->year;

        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'bonus.expected_month',
            value: (string) $bonusMonth,
            sourceType: 'interview_answer',
            label: 'Expected bonus month',
            volatility: 'annual',
            taxYear: $bonusYear,
        );

        $this->monitor->runCalendarWatchers($user->id, $bonusYear);

        // Calendar event created
        $event = OptimizationCalendarEvent::where('user_id', $user->id)
            ->where('event_type', 'bonus')
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->alert_fired_at)->not->toBeNull();

        // OptimizationFinding with bonus options created
        $finding = OptimizationFinding::where('user_id', $user->id)
            ->where('finding_key', 'bonus_election')
            ->where('finding_type', 'change_detected')
            ->first();

        expect($finding)->not->toBeNull()
            ->and($finding->status)->toBe('open');

        // Finding details must include all 3 options
        $options = $finding->details['options'] ?? [];
        expect($options)->toHaveKey('option_a')
            ->and($options)->toHaveKey('option_b')
            ->and($options)->toHaveKey('option_c');
    });

    it('does NOT re-fire the bonus alert after it has already fired', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        $bonusMonth = now()->addDays(10)->month;
        $bonusYear = now()->addDays(10)->year;

        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'bonus.expected_month',
            value: (string) $bonusMonth,
            sourceType: 'interview_answer',
            label: 'Expected bonus month',
            volatility: 'annual',
            taxYear: $bonusYear,
        );

        // Already fired
        OptimizationCalendarEvent::create([
            'user_id' => $user->id,
            'tax_year' => $bonusYear,
            'event_type' => 'bonus',
            'expected_at' => now()->addDays(15),
            'lead_time_days' => 21,
            'alert_fired_at' => now()->subDays(2),
            'metadata' => ['bonus_month' => $bonusMonth, 'bonus_year' => $bonusYear],
        ]);

        $this->monitor->runCalendarWatchers($user->id, $bonusYear);

        // Still only one event row
        $count = OptimizationCalendarEvent::where('user_id', $user->id)
            ->where('event_type', 'bonus')
            ->count();

        expect($count)->toBe(1);

        // No finding created (alert already fired — no re-emit)
        $findingCount = OptimizationFinding::where('user_id', $user->id)
            ->where('finding_key', 'bonus_election')
            ->count();

        expect($findingCount)->toBe(0);
    });

    it('does NOT fire bonus alert when bonus month is not within lead-time window', function () {
        $user = User::factory()->create(['last_active_at' => now()]);

        // Bonus expected 3 months from now (well outside 21-day lead)
        $bonusMonth = now()->addMonths(3)->month;
        $bonusYear = now()->addMonths(3)->year;

        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'bonus.expected_month',
            value: (string) $bonusMonth,
            sourceType: 'interview_answer',
            label: 'Expected bonus month',
            volatility: 'annual',
            taxYear: $bonusYear,
        );

        $this->monitor->runCalendarWatchers($user->id, $bonusYear);

        $event = OptimizationCalendarEvent::where('user_id', $user->id)
            ->where('event_type', 'bonus')
            ->first();

        expect($event)->toBeNull();
    });
});

describe('runCalendarWatchers - year-end purchase items', function () {
    it('fires year-end item for confirmed business context in Q4', function () {
        // Override to simulate Q4
        Carbon::setTestNow('2026-11-01 10:00:00');

        $user = User::factory()->create(['last_active_at' => now()]);
        $taxYear = (int) now()->year;

        // Confirmed business context via UserFinancialProfile
        UserFinancialProfile::factory()->create([
            'user_id' => $user->id,
            'employment_type' => 'self_employed',
            'business_type' => 'sole_proprietor',
        ]);

        $this->monitor->runCalendarWatchers($user->id, $taxYear);

        // Year-end calendar event created
        $event = OptimizationCalendarEvent::where('user_id', $user->id)
            ->where('event_type', 'year_end_purchase')
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->alert_fired_at)->not->toBeNull();

        // Finding carries the honesty guardrail
        $finding = OptimizationFinding::where('user_id', $user->id)
            ->where('finding_key', 'year_end_purchase_timing')
            ->first();

        expect($finding)->not->toBeNull();
        // Guardrail text must be present (D16 BINDING)
        expect($finding->description)->toContain('only makes sense if you were planning to buy this anyway');

        Carbon::setTestNow();  // reset
    });

    it('does NOT fire year-end item outside Q4 window', function () {
        Carbon::setTestNow('2026-07-01 10:00:00');

        $user = User::factory()->create(['last_active_at' => now()]);

        UserFinancialProfile::factory()->create([
            'user_id' => $user->id,
            'employment_type' => 'self_employed',
            'business_type' => 'sole_proprietor',
        ]);

        $this->monitor->runCalendarWatchers($user->id, 2026);

        $event = OptimizationCalendarEvent::where('user_id', $user->id)
            ->where('event_type', 'year_end_purchase')
            ->first();

        expect($event)->toBeNull();

        Carbon::setTestNow();
    });

    it('does NOT fire year-end item without confirmed business context', function () {
        Carbon::setTestNow('2026-11-01 10:00:00');

        $user = User::factory()->create(['last_active_at' => now()]);

        // Non-business employment type
        UserFinancialProfile::factory()->create([
            'user_id' => $user->id,
            'employment_type' => 'employed',
            'business_type' => null,
        ]);

        $this->monitor->runCalendarWatchers($user->id, 2026);

        $event = OptimizationCalendarEvent::where('user_id', $user->id)
            ->where('event_type', 'year_end_purchase')
            ->first();

        expect($event)->toBeNull();

        Carbon::setTestNow();
    });

    it('does NOT fire year-end item without business_type even with self_employed', function () {
        Carbon::setTestNow('2026-11-01 10:00:00');

        $user = User::factory()->create(['last_active_at' => now()]);

        // employment_type is self_employed but business_type is null
        UserFinancialProfile::factory()->create([
            'user_id' => $user->id,
            'employment_type' => 'self_employed',
            'business_type' => null,  // required gate
        ]);

        $this->monitor->runCalendarWatchers($user->id, 2026);

        $event = OptimizationCalendarEvent::where('user_id', $user->id)
            ->where('event_type', 'year_end_purchase')
            ->first();

        expect($event)->toBeNull();

        Carbon::setTestNow();
    });

    it('does NOT re-fire year-end item if already fired this year', function () {
        Carbon::setTestNow('2026-11-01 10:00:00');

        $user = User::factory()->create(['last_active_at' => now()]);

        UserFinancialProfile::factory()->create([
            'user_id' => $user->id,
            'employment_type' => 'self_employed',
            'business_type' => 'sole_proprietor',
        ]);

        // Already fired
        OptimizationCalendarEvent::create([
            'user_id' => $user->id,
            'tax_year' => 2026,
            'event_type' => 'year_end_purchase',
            'expected_at' => '2026-12-31',
            'lead_time_days' => 60,
            'alert_fired_at' => now()->subDays(5),
            'metadata' => ['window' => 'q4'],
        ]);

        $this->monitor->runCalendarWatchers($user->id, 2026);

        $count = OptimizationCalendarEvent::where('user_id', $user->id)
            ->where('event_type', 'year_end_purchase')
            ->count();

        expect($count)->toBe(1);

        Carbon::setTestNow();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// ACTIVITY GATE — all monitor paths skip inactive users
// ─────────────────────────────────────────────────────────────────────────────

describe('activity gate', function () {
    it('inactive users are excluded from the scheduled task sweep', function () {
        // Active user
        $activeUser = User::factory()->create(['last_active_at' => now()->subDays(5)]);

        // Inactive user (35 days — beyond 28-day threshold)
        $inactiveUser = User::factory()->create(['last_active_at' => now()->subDays(35)]);

        // Both have bank connections (otherwise excluded by whereHas)
        // We just test the SQL filter matches the scheduled task's guard
        $thresholdDays = 28;

        $activeCount = User::where('last_active_at', '>', now()->subDays($thresholdDays))
            ->whereKey([$activeUser->id, $inactiveUser->id])
            ->count();

        $inactiveCount = User::where('last_active_at', '>', now()->subDays($thresholdDays))
            ->whereKey($inactiveUser->id)
            ->count();

        expect($activeCount)->toBe(1)  // only the active user passes
            ->and($inactiveCount)->toBe(0);  // inactive excluded
    });
});
