<?php

use App\Models\OptimizationFinding;
use App\Models\OptimizationReport;
use App\Models\User;
use App\Services\OptimizationReportGeneratorService;
use Illuminate\Support\Facades\Http;

/*
 * OptimizationReportGeneratorTest
 *
 * Covers:
 *  RPT-01: Report assembles 4 topical + 3 wrapper + year-end + glossary sections
 *  RPT-02: Job model; Claude payload never contains estimated_value_cents (3rd call site)
 *  SAFE-03: Narrator payload check (monetary fields excluded from Claude)
 *
 * All Claude calls are intercepted via Http::fake() — no real API calls.
 */

// ── RPT-01: Section structure ─────────────────────────────────────────────────

it('generates_4_topical_sections', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();

    // Seed findings across multiple sections
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'deduction_1',
        'finding_type' => 'deduction_probe',
        'severity' => 'high',
        'status' => 'open',
    ]);
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'tax_1',
        'finding_type' => 'withholding',
        'severity' => 'medium',
        'status' => 'open',
    ]);
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'filing_1',
        'finding_type' => 'filing_status',
        'severity' => 'low',
        'status' => 'open',
    ]);
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'retirement_1',
        'finding_type' => 'retirement',
        'severity' => 'high',
        'status' => 'open',
    ]);

    $generator = app(OptimizationReportGeneratorService::class);
    $report = $generator->generate($user, 2025);

    $sections = collect($report->sections);

    // Must have exactly 4 topical sections
    $topical = $sections->where('section_type', 'topical');
    expect($topical)->toHaveCount(4);

    $topicalKeys = $topical->pluck('section_key')->sort()->values()->toArray();
    expect($topicalKeys)->toBe(['deductions', 'filings', 'retirement_401k', 'taxes']);
});

it('generates_3_wrapper_sections', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();

    $generator = app(OptimizationReportGeneratorService::class);
    $report = $generator->generate($user, 2025);

    $sections = collect($report->sections);

    $wrapper = $sections->where('section_type', 'wrapper');
    expect($wrapper)->toHaveCount(3);

    $wrapperKeys = $wrapper->pluck('section_key')->sort()->values()->toArray();
    expect($wrapperKeys)->toBe(['documents_missing', 'needs_professional_review', 'what_we_refused']);
});

it('generates_year_end_and_glossary_sections', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();

    $generator = app(OptimizationReportGeneratorService::class);
    $report = $generator->generate($user, 2025);

    $sections = collect($report->sections);

    expect($sections->where('section_type', 'year_end'))->toHaveCount(1);
    expect($sections->where('section_type', 'glossary'))->toHaveCount(1);

    // Total: 4 topical + 3 wrapper + 1 year-end + 1 glossary = 9
    expect($sections)->toHaveCount(9);
});

// ── RPT-01: Ranking order ─────────────────────────────────────────────────────

it('ranks_findings_by_severity_then_value_desc', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();

    // Create findings with known severity + estimated_value_cents for ranking assertions
    // low severity + high value should rank AFTER medium severity + low value
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'deduct_low_highval',
        'finding_type' => 'deduction_probe',
        'severity' => 'low',
        'estimated_value_cents' => 50000_00,   // $50,000
        'status' => 'open',
    ]);
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'deduct_high_lowval',
        'finding_type' => 'deduction_probe',
        'severity' => 'high',
        'estimated_value_cents' => 100_00,    // $100
        'status' => 'open',
    ]);
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'deduct_high_medval',
        'finding_type' => 'deduction_probe',
        'severity' => 'high',
        'estimated_value_cents' => 5000_00,   // $5,000
        'status' => 'open',
    ]);

    $generator = app(OptimizationReportGeneratorService::class);
    $report = $generator->generate($user, 2025);

    $sections = collect($report->sections);
    $deductionSection = $sections->firstWhere('section_key', 'deductions');

    expect($deductionSection)->not->toBeNull();

    $findings = collect($deductionSection['findings']);
    expect($findings)->toHaveCount(3);

    // Rank order: high-$5000 first, high-$100 second (high severity, higher value first),
    // then low-$50000 last (lower severity tier regardless of value)
    expect($findings->get(0)['finding_type'])->toBe('deduction_probe');
    // First two must both be 'high' severity
    expect($findings->get(0)['severity'])->toBe('high');
    expect($findings->get(1)['severity'])->toBe('high');
    // Third must be 'low' severity (lower tier always after higher tier)
    expect($findings->get(2)['severity'])->toBe('low');
});

it('ranks_high_value_before_low_value_within_same_severity', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();

    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'ret_med_lowval',
        'finding_type' => 'retirement',
        'severity' => 'medium',
        'estimated_value_cents' => 100_00,    // $100
        'status' => 'open',
    ]);
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'ret_med_highval',
        'finding_type' => 'retirement',
        'severity' => 'medium',
        'estimated_value_cents' => 10000_00,  // $10,000
        'status' => 'open',
    ]);

    $generator = app(OptimizationReportGeneratorService::class);
    $report = $generator->generate($user, 2025);

    $sections = collect($report->sections);
    $retSection = $sections->firstWhere('section_key', 'retirement_401k');

    expect($retSection)->not->toBeNull();
    $findings = collect($retSection['findings']);
    expect($findings)->toHaveCount(2);

    // Higher estimated_value_cents should rank first (within same severity tier)
    // Note: estimated_value_cents is NOT in the findings array (it's excluded from sections)
    // We verify ordering by the finding keys we know were seeded
    $firstKey = OptimizationFinding::where('user_id', $user->id)
        ->where('finding_key', $findings->get(0)['finding_type'] === 'retirement' ? 'ret_med_highval' : 'ret_med_lowval')
        ->exists();

    // The higher-value one (ret_med_highval with $10,000) must be first
    $highValueFinding = OptimizationFinding::where('user_id', $user->id)
        ->where('finding_key', 'ret_med_highval')
        ->first();
    $lowValueFinding = OptimizationFinding::where('user_id', $user->id)
        ->where('finding_key', 'ret_med_lowval')
        ->first();

    $firstFindingId = $findings->get(0)['finding_id'];
    expect($firstFindingId)->toBe($highValueFinding?->id);
});

// ── SAFE-03: Narrator payload excludes monetary keys ─────────────────────────

it('narrator_payload_excludes_monetary_fields', function () {
    Http::preventStrayRequests();

    $capturedPayloads = [];
    Http::fake([
        'https://api.anthropic.com/*' => function (\Illuminate\Http\Client\Request $request) use (&$capturedPayloads) {
            $capturedPayloads[] = $request->data();

            return Http::response(
                ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
                200
            );
        },
    ]);

    $user = createAuthenticatedUser();

    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'ret_probe_1',
        'finding_type' => 'retirement',
        'severity' => 'high',
        'estimated_value_cents' => 12000_00,
        'net_cash_cost' => 0,
        'tax_saved' => 300000,
        'cliff_bonus_value' => 50000,
        'status' => 'open',
    ]);

    $generator = app(OptimizationReportGeneratorService::class);
    $generator->generate($user, 2025);

    // At least one Claude call should have been made (for narration)
    expect($capturedPayloads)->not->toBeEmpty();

    // Inspect ALL captured payloads — none may contain monetary columns
    $forbiddenKeys = [
        'estimated_value_cents',
        'net_cash_cost',
        'tax_saved',
        'cliff_bonus_value',
    ];

    foreach ($capturedPayloads as $idx => $payload) {
        // The payload is the full HTTP request body sent to Claude
        $payloadJson = json_encode($payload);

        foreach ($forbiddenKeys as $forbidden) {
            expect($payloadJson)->not->toContain(
                $forbidden,
                "Claude payload #{$idx} must not contain monetary key '{$forbidden}'"
            );
        }
    }
});

// ── RPT-02: Model upserted correctly ─────────────────────────────────────────

it('upserts_report_model_with_stale_false', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();

    $generator = app(OptimizationReportGeneratorService::class);
    $report = $generator->generate($user, 2025);

    expect($report)->toBeInstanceOf(OptimizationReport::class);
    expect($report->is_stale)->toBeFalse();
    expect($report->rebuilt_at)->not->toBeNull();
    expect($report->sections)->toBeArray();
    expect($report->user_id)->toBe($user->id);
    expect($report->tax_year)->toBe(2025);
});

it('second_generate_call_upserts_not_duplicates', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();
    $generator = app(OptimizationReportGeneratorService::class);

    $generator->generate($user, 2025);
    $generator->generate($user, 2025);

    // Should be exactly 1 row — unique(user_id, tax_year) constraint
    $count = OptimizationReport::forUser($user->id)
        ->where('tax_year', 2025)
        ->count();

    expect($count)->toBe(1);
});

// ── RPT-06: Wrapper sections carry expected keys ──────────────────────────────

it('wrapper_section_what_we_refused_contains_config_refusal_list', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();
    $generator = app(OptimizationReportGeneratorService::class);
    $report = $generator->generate($user, 2025);

    $sections = collect($report->sections);
    $refusalSection = $sections->firstWhere('section_key', 'what_we_refused');

    expect($refusalSection)->not->toBeNull();
    expect($refusalSection['refused_recommendations'])->not->toBeEmpty();

    // Each refusal entry must have 'what' and 'why' but not 'how'
    foreach ($refusalSection['refused_recommendations'] as $entry) {
        expect($entry)->toHaveKeys(['category', 'what', 'why']);
        // HOW the schemes work must never appear
        expect($entry)->not->toHaveKey('how');
    }
});

it('specialist_band_findings_appear_in_needs_professional_review', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();

    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'specialist_finding',
        'finding_type' => 'retirement',
        'severity' => 'medium',
        'band' => 'specialist',
        'status' => 'open',
    ]);

    $generator = app(OptimizationReportGeneratorService::class);
    $report = $generator->generate($user, 2025);

    $sections = collect($report->sections);
    $proReview = $sections->firstWhere('section_key', 'needs_professional_review');

    expect($proReview)->not->toBeNull();
    expect($proReview['findings'])->not->toBeEmpty();

    $findingTypes = collect($proReview['findings'])->pluck('band')->toArray();
    expect($findingTypes)->toContain('specialist');
});

// ── fetchOrInit helper ────────────────────────────────────────────────────────

it('fetchOrInit_creates_stale_record_when_none_exists', function () {
    $user = createAuthenticatedUser();

    $report = OptimizationReport::fetchOrInit($user->id, 2025);

    expect($report)->toBeInstanceOf(OptimizationReport::class);
    expect($report->is_stale)->toBeTrue();
    expect($report->sections)->toBe([]);
    expect($report->user_id)->toBe($user->id);
});
