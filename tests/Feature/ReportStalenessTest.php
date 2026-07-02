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
use App\Models\OptimizationReport;
use App\Models\TaxDocument;
use App\Services\PlaidService;
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

test('OptimizationProfileBuilt marks report stale (flag flip only)', function () {
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
 * OptimizationProfileBuilt, which MarkOptimizationReportStale already handles.
 * No new listeners are needed — this test asserts the missing dispatch only.
 */
test('bank sync dispatches BuildIncomeOptimizationProfile to trigger the staleness chain (RPT-02)', function () {
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
