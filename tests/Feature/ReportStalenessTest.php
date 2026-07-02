<?php

use App\Enums\QuestionType;
use App\Events\OptimizationProfileBuilt;
use App\Events\TaxDocumentExtracted;
use App\Events\UserAnsweredQuestion;
use App\Jobs\BuildIncomeOptimizationProfile;
use App\Jobs\GenerateOptimizationReport;
use App\Jobs\SyncBankTransactions;
use App\Listeners\DispatchReportGeneration;
use App\Listeners\MarkOptimizationReportStale;
use App\Models\AIQuestion;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationReport;
use App\Models\TaxDocument;
use App\Services\OptimizationReportGeneratorService;
use App\Services\PlaidService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * ReportStalenessTest — Phase 12-04 Task 1 (RPT-02).
 *
 * Verifies:
 *   (1) TaxDocumentExtracted flips is_stale=true + sets stale_since on the report.
 *   (2) No GenerateOptimizationReport job runs inline (Queue::fake confirms delayed unique job only).
 *   (3) 5 UserAnsweredQuestion(optimization) events each flip the flag (no regen dispatch).
 *   (4) A non-optimization UserAnsweredQuestion does NOT mark the report stale.
 *   (5) OptimizationProfileBuilt flips is_stale=true.
 *   (6) Cross-user isolation — staling user1's report does not touch user2's.
 */

/**
 * Create a minimal TaxDocument without a factory (no TaxDocumentFactory exists).
 */
function makeReportStalenessDocument(int $userId, int $taxYear = 2025): TaxDocument
{
    Storage::fake('local');
    Storage::disk('local')->put("tax-vault/{$userId}/{$taxYear}/pay_stub/test.pdf", 'fake-content');

    return TaxDocument::create([
        'user_id' => $userId,
        'original_filename' => 'test-paystub.pdf',
        'stored_path' => "tax-vault/{$userId}/{$taxYear}/pay_stub/test.pdf",
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'file_hash' => hash('sha256', 'fake-content'),
        'tax_year' => $taxYear,
        'status' => 'ready',
    ]);
}

test('TaxDocumentExtracted marks report stale (flag flip only, no inline generation)', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    // Seed a report that is currently fresh
    $report = OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
    ]);

    $document = makeReportStalenessDocument($user->id, $taxYear);

    // Invoke listener directly to isolate behaviour
    $listener = new MarkOptimizationReportStale;
    $listener->handleTaxDocumentExtracted(new TaxDocumentExtracted($document));

    $report->refresh();
    expect($report->is_stale)->toBeTrue();
    expect($report->stale_since)->not->toBeNull();

    // MarkOptimizationReportStale never dispatches jobs — flag flip only
    Queue::assertNothingPushed();
});

test('DispatchReportGeneration queues a delayed unique job on TaxDocumentExtracted (no inline execution)', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    $document = makeReportStalenessDocument($user->id, $taxYear);

    $listener = new DispatchReportGeneration;
    $listener->handleTaxDocumentExtracted(new TaxDocumentExtracted($document));

    // Job should be queued (not run synchronously)
    Queue::assertPushed(GenerateOptimizationReport::class, function ($job) use ($user, $taxYear) {
        return $job->userId === $user->id && $job->taxYear === $taxYear;
    });
});

test('OptimizationProfileBuilt marks report stale when no rebuilt_at (treated as window-expired under D13)', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    // No rebuilt_at, no built_against → D13 treats as window-expired → stale
    $report = OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
    ]);

    $listener = new MarkOptimizationReportStale;
    $listener->handleOptimizationProfileBuilt(new OptimizationProfileBuilt($user->id, $taxYear, 3));

    $report->refresh();
    expect($report->is_stale)->toBeTrue();
    expect($report->stale_since)->not->toBeNull();

    // No inline job dispatch from this listener
    Queue::assertNothingPushed();
});

test('5 optimization UserAnsweredQuestion events each flip the stale flag; no job dispatched', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    $report = OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
    ]);

    $listener = new MarkOptimizationReportStale;

    for ($i = 0; $i < 5; $i++) {
        $question = AIQuestion::factory()->create([
            'user_id' => $user->id,
            'question_type' => QuestionType::Optimization->value,
            'user_answer' => 'yes',
        ]);

        // Reset stale between iterations so we confirm the flip each time
        $report->update(['is_stale' => false, 'stale_since' => null]);
        $listener->handleUserAnsweredQuestion(new UserAnsweredQuestion($question, $user));
        $report->refresh();
        expect($report->is_stale)->toBeTrue();
    }

    // MarkOptimizationReportStale never dispatches GenerateOptimizationReport
    Queue::assertNothingPushed();
});

test('non-optimization UserAnsweredQuestion does NOT mark report stale', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    $report = OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
    ]);

    // Categorization question — not optimization type
    $question = AIQuestion::factory()->create([
        'user_id' => $user->id,
        'question_type' => QuestionType::Category->value,
        'user_answer' => 'Groceries',
    ]);

    $listener = new MarkOptimizationReportStale;
    $listener->handleUserAnsweredQuestion(new UserAnsweredQuestion($question, $user));

    $report->refresh();
    // Categorization answers do not affect the optimization report
    expect($report->is_stale)->toBeFalse();
});

test('stale flag is not set when no report row exists for the user+year (safe no-op)', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    // No OptimizationReport created — listener should handle this gracefully
    $document = makeReportStalenessDocument($user->id, $taxYear);

    $listener = new MarkOptimizationReportStale;
    $listener->handleTaxDocumentExtracted(new TaxDocumentExtracted($document));

    // No report row was created, no exception thrown
    $count = OptimizationReport::forUser($user->id)->where('tax_year', $taxYear)->count();
    expect($count)->toBe(0);
});

test('cross-user: staling user1 report does not affect user2 report', function () {
    Queue::fake();

    $user1 = createAuthenticatedUser();
    $user2 = createAuthenticatedUser();
    $taxYear = now()->year;

    $report1 = OptimizationReport::create([
        'user_id' => $user1->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
    ]);
    $report2 = OptimizationReport::create([
        'user_id' => $user2->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
    ]);

    $document = makeReportStalenessDocument($user1->id, $taxYear);

    $listener = new MarkOptimizationReportStale;
    $listener->handleTaxDocumentExtracted(new TaxDocumentExtracted($document));

    $report1->refresh();
    $report2->refresh();

    expect($report1->is_stale)->toBeTrue();
    expect($report2->is_stale)->toBeFalse();  // Other user's report unaffected
});

/**
 * RPT-02 / ROADMAP SC1 — bank-sync staleness path.
 *
 * Verifies that SyncBankTransactions::handle() dispatches BuildIncomeOptimizationProfile
 * for the connection's user after a successful Plaid sync. That job fires
 * OptimizationProfileBuilt, which MarkOptimizationReportStale handles via the D13
 * staleness policy (conditional: freshness window + material-change gate, not unconditional).
 * No new listeners are needed — this test asserts the dispatch chain only.
 */
test('bank sync dispatches BuildIncomeOptimizationProfile to trigger the staleness chain (RPT-02 / D13 conditional)', function () {
    Queue::fake();

    ['user' => $user, 'connection' => $connection] = createUserWithBank();
    $taxYear = now()->year;

    // Seed a fresh report
    OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
    ]);

    // Mock PlaidService — no real API call in tests
    $plaid = Mockery::mock(PlaidService::class);
    $plaid->shouldReceive('syncTransactions')
        ->once()
        ->with($connection)
        ->andReturn(['added' => 3, 'modified' => 1, 'removed' => 0]);
    app()->instance(PlaidService::class, $plaid);

    $job = new SyncBankTransactions($connection);
    $job->handle($plaid);

    Queue::assertPushed(BuildIncomeOptimizationProfile::class, function ($queued) use ($user, $taxYear) {
        return $queued->userId === $user->id && $queued->taxYear === $taxYear;
    });
});

// ─── D13: Staleness Policy Tests ─────────────────────────────────────────────
// Decision 13 (2026-07-02): 30-day freshness window; user-action events always
// stale; data-churn events (OptimizationProfileBuilt) only stale when outside
// the window OR a material income/savings change is detected.

test('D13: built_against aggregate snapshot is populated when report is generated', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Educational overview.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    // Seed a known IncomeOptimizationProfile so the generator can snapshot it
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'bank_deposit_total' => '10000000',   // $100,000 in cents
        'traditional_401k_ytd' => '500000',   // $5,000 in cents
    ]);

    $report = app(OptimizationReportGeneratorService::class)->generate($user, $taxYear);

    expect($report->built_against)->not->toBeNull();
    expect($report->built_against)->toHaveKey('income_cents');
    expect($report->built_against)->toHaveKey('savings_cents');
    expect($report->built_against)->toHaveKey('finding_keys');
    expect($report->built_against)->toHaveKey('generated_at');
    expect((int) $report->built_against['income_cents'])->toBeGreaterThan(0);
});

test('D13: OptimizationProfileBuilt within freshness window without material change suppresses staleness', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    // Report rebuilt 5 days ago — well within 30-day freshness window
    $report = OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
        'rebuilt_at' => now()->subDays(5),
        'built_against' => [
            'income_cents' => 10_000_000,   // $100,000 snapshot
            'savings_cents' => 500_000,      // $5,000 snapshot
            'finding_keys' => [],
            'generated_at' => now()->subDays(5)->toIso8601String(),
        ],
    ]);

    // Current profile exactly matches the snapshot (no change)
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'bank_deposit_total' => '10000000',   // identical to snapshot
        'traditional_401k_ytd' => '500000',   // identical to snapshot
    ]);

    $listener = new MarkOptimizationReportStale;
    $listener->handleOptimizationProfileBuilt(new OptimizationProfileBuilt($user->id, $taxYear, 0));

    $report->refresh();
    // Within window, no material change → suppressed (D13 §1)
    expect($report->is_stale)->toBeFalse();
    Queue::assertNothingPushed();
});

test('D13: OptimizationProfileBuilt outside freshness window marks report stale (window expired)', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    // Report rebuilt 35 days ago — outside 30-day freshness window
    $report = OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
        'rebuilt_at' => now()->subDays(35),
        'built_against' => [
            'income_cents' => 10_000_000,
            'savings_cents' => 500_000,
            'finding_keys' => [],
            'generated_at' => now()->subDays(35)->toIso8601String(),
        ],
    ]);

    $listener = new MarkOptimizationReportStale;
    $listener->handleOptimizationProfileBuilt(new OptimizationProfileBuilt($user->id, $taxYear, 0));

    $report->refresh();
    // Outside window → stale regardless of material change (D13 §1)
    expect($report->is_stale)->toBeTrue();
    expect($report->stale_since)->not->toBeNull();
});

test('D13: material income delta above threshold within freshness window marks report stale', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    // Fresh report (5 days old) with $100,000 income snapshot
    $report = OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
        'rebuilt_at' => now()->subDays(5),
        'built_against' => [
            'income_cents' => 10_000_000,   // $100,000 snapshot
            'savings_cents' => 500_000,
            'finding_keys' => [],
            'generated_at' => now()->subDays(5)->toIso8601String(),
        ],
    ]);

    // Current income is $106,000 — 6% increase, above the 5% material threshold
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'bank_deposit_total' => '10600000',   // $106,000 = +6%
        'traditional_401k_ytd' => '500000',   // unchanged
    ]);

    $listener = new MarkOptimizationReportStale;
    $listener->handleOptimizationProfileBuilt(new OptimizationProfileBuilt($user->id, $taxYear, 3));

    $report->refresh();
    // Material income change (>5%) → stale even within freshness window (D13 §3)
    expect($report->is_stale)->toBeTrue();
});

test('D13: sub-threshold income change within freshness window suppresses staleness', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    $report = OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
        'rebuilt_at' => now()->subDays(5),
        'built_against' => [
            'income_cents' => 10_000_000,   // $100,000 snapshot
            'savings_cents' => 500_000,
            'finding_keys' => [],
            'generated_at' => now()->subDays(5)->toIso8601String(),
        ],
    ]);

    // Current income is $102,000 — 2% increase, below the 5% material threshold
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'bank_deposit_total' => '10200000',   // $102,000 = +2%
        'traditional_401k_ytd' => '500000',   // unchanged
    ]);

    $listener = new MarkOptimizationReportStale;
    $listener->handleOptimizationProfileBuilt(new OptimizationProfileBuilt($user->id, $taxYear, 2));

    $report->refresh();
    // Sub-threshold change, within window → suppressed (D13 §1 + §3)
    expect($report->is_stale)->toBeFalse();
});

test('D13: TaxDocumentExtracted (user-action) always marks report stale within freshness window', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    // Fresh report within the 30-day window
    $report = OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
        'rebuilt_at' => now()->subDays(5),
        'built_against' => [
            'income_cents' => 10_000_000,
            'savings_cents' => 500_000,
            'finding_keys' => [],
            'generated_at' => now()->subDays(5)->toIso8601String(),
        ],
    ]);

    $document = makeReportStalenessDocument($user->id, $taxYear);

    $listener = new MarkOptimizationReportStale;
    $listener->handleTaxDocumentExtracted(new TaxDocumentExtracted($document));

    $report->refresh();
    // USER_ACTION always stales — no window suppression (D13 §2)
    expect($report->is_stale)->toBeTrue();
});

test('D13: optimization UserAnsweredQuestion (user-action) always marks report stale within freshness window', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    $report = OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
        'rebuilt_at' => now()->subDays(5),
        'built_against' => [
            'income_cents' => 10_000_000,
            'savings_cents' => 500_000,
            'finding_keys' => [],
            'generated_at' => now()->subDays(5)->toIso8601String(),
        ],
    ]);

    $question = AIQuestion::factory()->create([
        'user_id' => $user->id,
        'question_type' => QuestionType::Optimization->value,
        'user_answer' => 'yes',
    ]);

    $listener = new MarkOptimizationReportStale;
    $listener->handleUserAnsweredQuestion(new UserAnsweredQuestion($question, $user));

    $report->refresh();
    // USER_ACTION always stales — no window suppression (D13 §2)
    expect($report->is_stale)->toBeTrue();
});

test('D13: absent built_against treats data-churn as material — marks report stale', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    // Fresh report (5 days old) but built_against is null (first generation path)
    $report = OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
        'rebuilt_at' => now()->subDays(5),
        // No built_against set
    ]);

    IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'bank_deposit_total' => '10000000',
    ]);

    $listener = new MarkOptimizationReportStale;
    $listener->handleOptimizationProfileBuilt(new OptimizationProfileBuilt($user->id, $taxYear, 0));

    $report->refresh();
    // Absent built_against → treated as material change → stale (D13 §3)
    expect($report->is_stale)->toBeTrue();
});

test('D13: DispatchReportGeneration suppresses regen dispatch for churn within freshness window', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    // Fresh report within window, no material change
    OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [],
        'rebuilt_at' => now()->subDays(5),
        'built_against' => [
            'income_cents' => 10_000_000,
            'savings_cents' => 500_000,
            'finding_keys' => [],
            'generated_at' => now()->subDays(5)->toIso8601String(),
        ],
    ]);

    IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'bank_deposit_total' => '10000000',   // no material change
        'traditional_401k_ytd' => '500000',
    ]);

    $listener = new DispatchReportGeneration;
    $listener->handleOptimizationProfileBuilt(new OptimizationProfileBuilt($user->id, $taxYear, 0));

    // Within window, no material change → no regen job queued (D13 §1)
    Queue::assertNotPushed(GenerateOptimizationReport::class);
});
