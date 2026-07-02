<?php

use App\Models\OptimizationReport;
use App\Models\User;
use App\Services\OptimizationReportGeneratorService;
use Illuminate\Support\Facades\Http;

/*
 * OptimizationReportDisclaimerTest — covers RPT-03.
 *
 * RPT-03: Every report section must carry a persistent, non-empty educational
 * disclaimer string. Disclaimers are NOT globally dismissable — each section
 * has its own disclaimer that renders independently.
 *
 * Tests:
 *  1. Every section object produced by the generator has a non-empty disclaimer string.
 *  2. Glossary entries contain the [default ruling — owner review pending] annotation
 *     on the approved RIA-reframed lines.
 *  3. The "what we refused" section has WHAT + WHY but not HOW.
 *  4. Year-end section uses educational framing (no pressure/countdown words).
 */

// ─── RPT-03: Per-section disclaimer presence ─────────────────────────────────

it('every_generated_section_has_non_empty_disclaimer', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();

    $generator = app(OptimizationReportGeneratorService::class);
    $report = $generator->generate($user, 2025);

    expect($report->sections)->not->toBeEmpty();

    foreach ($report->sections as $idx => $section) {
        $sectionKey = $section['section_key'] ?? "section_{$idx}";
        expect($section)
            ->toHaveKey('disclaimer')
            ->and($section['disclaimer'])
            ->not->toBeEmpty("Section '{$sectionKey}' must have a non-empty disclaimer (RPT-03)");
    }
});

it('topical_sections_each_have_own_disclaimer_not_shared_reference', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $user = createAuthenticatedUser();

    $generator = app(OptimizationReportGeneratorService::class);
    $report = $generator->generate($user, 2025);

    $topical = collect($report->sections)->where('section_type', 'topical');

    // Every topical section has its own disclaimer string (not null/empty)
    $topical->each(function (array $section): void {
        expect($section['disclaimer'])
            ->not->toBeNull()
            ->not->toBeEmpty();
    });
});

// ─── RPT-07: Approved glossary annotation check ──────────────────────────────

it('approved_ria_reframe_glossary_lines_carry_default_ruling_annotation', function () {
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
    $glossarySection = $sections->firstWhere('section_type', 'glossary');

    expect($glossarySection)->not->toBeNull();
    expect($glossarySection['glossary_entries'])->not->toBeEmpty();

    // Approved RIA-reframed lines must carry "[default ruling — owner review pending]"
    // These are: 0% LTCG bracket-awareness, tax-loss-harvesting, account-type-taxation,
    // stepped-up-basis, MFS ceiling line
    $annotatedTerms = collect($glossarySection['glossary_entries'])
        ->filter(fn (array $e): bool => str_contains($e['explanation'] ?? '', '[default ruling'))
        ->pluck('term')
        ->toArray();

    // At least the 5 approved RIA-reframed lines must carry the annotation
    expect(count($annotatedTerms))->toBeGreaterThanOrEqual(5);
});

it('glossary_ltcg_line_contains_no_sell_rebuy_language', function () {
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
    $glossarySection = $sections->firstWhere('section_type', 'glossary');

    $ltcgEntry = collect($glossarySection['glossary_entries'] ?? [])
        ->first(fn (array $e): bool => str_contains($e['term'] ?? '', 'LTCG') || str_contains($e['term'] ?? '', 'Long-Term Capital'));

    expect($ltcgEntry)->not->toBeNull();

    $explanation = $ltcgEntry['explanation'] ?? '';

    // No sell/rebuy language in the approved LTCG glossary line
    foreach (['sell your', 'buy back', 'rebuy', 'sell and rebuy', 'harvest', 'selling shares', 'selling securities'] as $banned) {
        expect(strtolower($explanation))->not->toContain(
            strtolower($banned),
            "LTCG glossary line must not contain sell/rebuy language: '{$banned}'"
        );
    }
});

// ─── RPT-06: Refusal section — WHAT/WHY only, never HOW ─────────────────────

it('refusal_section_entries_have_what_and_why_but_not_how', function () {
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

    foreach ($refusalSection['refused_recommendations'] as $entry) {
        expect($entry)->toHaveKey('what');
        expect($entry)->toHaveKey('why');
        expect($entry)->not->toHaveKey('how');
        expect($entry['what'])->not->toBeEmpty();
        expect($entry['why'])->not->toBeEmpty();
    }
});

// ─── RPT-08: Year-end section uses educational framing ───────────────────────

it('year_end_section_uses_educational_framing_not_deadline_pressure', function () {
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
    $yearEndSection = $sections->firstWhere('section_type', 'year_end');

    expect($yearEndSection)->not->toBeNull();

    // Educational framing must use "commonly reviewed" not pressure language
    $framing = $yearEndSection['dec_31_framing'] ?? '';
    expect($framing)->toContain('commonly');

    // The section must NOT use countdown/pressure language
    $prohibited = ['only X days left', 'hurry', 'act now', 'deadline approaching', 'time is running out'];
    $description = strtolower($yearEndSection['description'] ?? '');
    foreach ($prohibited as $p) {
        expect($description)->not->toContain($p);
    }
});

// ─── OptimizationReport model: scopeForUser isolates records ────────────────

it('scopeForUser_isolates_reports_between_users', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            ['content' => [['text' => 'Consider discussing these items with a tax professional.']]],
            200
        ),
    ]);

    $userA = createAuthenticatedUser();
    $userB = createAuthenticatedUser();

    $generator = app(OptimizationReportGeneratorService::class);
    $generator->generate($userA, 2025);
    $generator->generate($userB, 2025);

    // User A cannot see user B's report via scopeForUser
    $reportsA = OptimizationReport::forUser($userA->id)->get();
    $reportsB = OptimizationReport::forUser($userB->id)->get();

    expect($reportsA)->toHaveCount(1);
    expect($reportsB)->toHaveCount(1);
    expect($reportsA->first()->user_id)->toBe($userA->id);
    expect($reportsB->first()->user_id)->toBe($userB->id);
});
