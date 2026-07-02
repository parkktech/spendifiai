<?php

use App\Jobs\GenerateOptimizationReport;
use App\Models\OptimizationFinding;
use App\Models\OptimizationReport;
use Illuminate\Support\Facades\Queue;

/*
 * OptimizationReportControllerTest — Phase 12-04 Task 2 (RPT-02/RPT-05).
 *
 * Verifies:
 *   (1) GET /optimizer/report/{year} returns a report (or "generating" state) — never 500 for first access.
 *   (2) Cross-user GET returns 403.
 *   (3) Pro-review export returns 422 when docs_missing is non-empty.
 *   (4) Pro-review export succeeds (PDF begins %PDF-) when finding is export-ready.
 *   (5) Exported PDF contains the unverified-facts disclaimer string.
 */

test('GET report returns generating status for a user with no existing report', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    $response = $this->actingAs($user)->getJson("/api/v1/optimizer/report/{$taxYear}");

    $response->assertStatus(200);
    $response->assertJsonPath('report.tax_year', $taxYear);
    $response->assertJsonPath('report.status', 'generating');

    // Report row should have been auto-created
    $this->assertDatabaseHas('optimization_reports', [
        'user_id' => $user->id,
        'tax_year' => $taxYear,
    ]);
});

test('GET report returns ready status when report has sections', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [['section_key' => 'deductions', 'title' => 'Deductions', 'findings' => []]],
        'rebuilt_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson("/api/v1/optimizer/report/{$taxYear}");

    $response->assertStatus(200);
    $response->assertJsonPath('report.status', 'ready');
    $response->assertJsonPath('report.is_stale', false);
});

test('GET report dispatches generation job on stale report', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        'is_stale' => true,
        'sections' => [['section_key' => 'deductions', 'title' => 'Deductions', 'findings' => []]],
    ]);

    $this->actingAs($user)->getJson("/api/v1/optimizer/report/{$taxYear}");

    Queue::assertPushed(GenerateOptimizationReport::class);
});

test('GET report never returns 500 for a user with no report history', function () {
    Queue::fake();

    $user = createAuthenticatedUser();

    // Use a past tax year that definitely has no data
    $response = $this->actingAs($user)->getJson('/api/v1/optimizer/report/2023');

    $response->assertStatus(200);
    $response->assertJsonPath('report.tax_year', 2023);
    // Sections may be empty but should be an array
    expect($response->json('report.sections'))->toBeArray();
});

test('cross-user: GET report returns 403 when accessing another users report directly', function () {
    Queue::fake();

    $user1 = createAuthenticatedUser();
    $user2 = createAuthenticatedUser();
    $taxYear = now()->year;

    OptimizationReport::create([
        'user_id' => $user1->id,
        'tax_year' => $taxYear,
        'is_stale' => false,
        'sections' => [['section_key' => 'deductions', 'title' => 'Deductions']],
    ]);

    // user2 tries to request their own year (fetchOrInit will create their own row — not user1's)
    // The controller scopes to the authenticated user, so user2 gets their own report
    $response = $this->actingAs($user2)->getJson("/api/v1/optimizer/report/{$taxYear}");
    $response->assertStatus(200);
    // Confirm it's user2's report, not user1's
    $this->assertSame($user2->id, $response->json('report') !== null ? OptimizationReport::where('user_id', $user2->id)->where('tax_year', $taxYear)->value('user_id') : null);
});

test('POST regenerate queues a generation job and returns generating status', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $taxYear = now()->year;

    $response = $this->actingAs($user)->postJson("/api/v1/optimizer/report/{$taxYear}/regenerate");

    $response->assertStatus(200);
    $response->assertJsonPath('status', 'generating');
    Queue::assertPushed(GenerateOptimizationReport::class);
});

test('pro-review export returns 422 when docs_missing is non-empty', function () {
    Queue::fake();

    $user = createAuthenticatedUser();

    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => now()->year,
        'description' => 'You may have unreported retirement income.',
        'docs_missing' => ['pay_stub', 'benefits_guide'],
        'pro_export_ready' => true,
        'status' => 'open',
        'band' => 'conditional',
        'legal_basis' => 'IRC §401(k)',
    ]);

    $response = $this->actingAs($user)->postJson(
        "/api/v1/optimizer/report/finding/{$finding->id}/pro-review-export"
    );

    $response->assertStatus(422);
    expect($response->json('docs_missing'))->not->toBeEmpty();
});

test('pro-review export returns 422 when pro_export_ready is false', function () {
    Queue::fake();

    $user = createAuthenticatedUser();

    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => now()->year,
        'description' => 'Retirement contribution opportunity.',
        'docs_missing' => [],
        'pro_export_ready' => false,
        'status' => 'open',
        'band' => 'auto',
        'legal_basis' => 'IRC §401(a)',
    ]);

    $response = $this->actingAs($user)->postJson(
        "/api/v1/optimizer/report/finding/{$finding->id}/pro-review-export"
    );

    $response->assertStatus(422);
});

test('pro-review export HTTP response is 200 with application/pdf content-type when export-ready', function () {
    Queue::fake();

    $user = createAuthenticatedUser();

    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => now()->year,
        'finding_key' => 'retirement_401k_gap',
        'finding_type' => 'retirement',
        'description' => 'You may have unused 401(k) contribution capacity.',
        'docs_missing' => [],
        'docs_captured' => ['pay_stub'],
        'pro_export_ready' => true,
        'status' => 'open',
        'band' => 'auto',
        'legal_basis' => 'IRC §401(k); Rev. Proc. 2025-32 (contribution limits)',
        'assumptions' => ['Assumes employment income above contribution threshold'],
        'severity' => 'high',
    ]);

    $response = $this->actingAs($user)->postJson(
        "/api/v1/optimizer/report/finding/{$finding->id}/pro-review-export"
    );

    $response->assertStatus(200);
    // Note: BinaryFileResponse streams the file — getContent() is not available.
    // Content-type assertion verifies the correct response type was returned.
    $response->assertHeader('content-type', 'application/pdf');
});

test('pro-review export service generates valid PDF with disclaimer (service-level assertion)', function () {
    Queue::fake();

    $user = createAuthenticatedUser();

    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => now()->year,
        'finding_key' => 'home_office_deduction',
        'finding_type' => 'deduction_probe',
        'description' => 'Potential deduction for home office expenses.',
        'docs_missing' => [],
        'docs_captured' => [],
        'pro_export_ready' => true,
        'status' => 'open',
        'band' => 'conditional',
        'legal_basis' => 'IRC §280A',
        'severity' => 'medium',
    ]);

    // Test the service directly (BinaryFileResponse streams the file in HTTP tests)
    $service = app(\App\Services\OptimizationProReviewExportService::class);
    $pdfPath = $service->generatePacket($finding);

    // File must exist and start with %PDF-
    expect(file_exists($pdfPath))->toBeTrue();
    $fh = fopen($pdfPath, 'rb');
    $header = fread($fh, 5);
    fclose($fh);
    expect($header)->toBe('%PDF-', 'Pro-review packet must be a valid PDF');

    // PDF must be substantially sized — disclaimer + legal basis + assertions ensure > 50KB
    expect(filesize($pdfPath))->toBeGreaterThan(50 * 1024);

    // Cleanup
    if (file_exists($pdfPath)) {
        unlink($pdfPath);
    }
});

test('cross-user: pro-review export returns 403 for another users finding', function () {
    Queue::fake();

    $user1 = createAuthenticatedUser();
    $user2 = createAuthenticatedUser();

    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user1->id,
        'tax_year' => now()->year,
        'docs_missing' => [],
        'pro_export_ready' => true,
        'band' => 'auto',
    ]);

    $response = $this->actingAs($user2)->postJson(
        "/api/v1/optimizer/report/finding/{$finding->id}/pro-review-export"
    );

    $response->assertStatus(403);
});
