<?php

use App\Models\OptimizationFinding;
use App\Models\User;
use App\Services\NarrationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('narrates a template finding_type deterministically with ZERO Claude/HTTP calls (D17 template-first)', function () {
    Http::fake();

    $user = User::factory()->create();
    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_type' => 'income_discrepancy',  // present in finding_narration_templates
        'description' => null,
    ]);

    $service = new NarrationService;
    $result = $service->narrateFinding($finding);

    // Deterministic template output — exactly the configured copy
    $expected = config('optimization-report.finding_narration_templates.income_discrepancy');
    expect($result)->toBe($expected)
        ->and($finding->fresh()->description)->toBe($expected);

    // The binding SCN-03 / D17 guarantee: no Claude call whatsoever
    Http::assertNothingSent();
});

it('is deterministic across repeated calls for the same template finding_type', function () {
    Http::fake();

    $user = User::factory()->create();
    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'finding_type' => 'withholding',
        'description' => null,
    ]);

    $service = new NarrationService;
    $first = $service->narrateFinding($finding);
    $second = $service->narrateFinding($finding->fresh());

    expect($first)->toBe($second);
    Http::assertNothingSent();
});

it('falls through to the (Haiku) Claude path for a bespoke finding_type with no template', function () {
    // D19: response must be valid JSON {hook, detail, action_cue}
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'hook' => 'A bespoke educational note you may wish to review.',
                'detail' => 'This area may be worth discussing with a tax professional.',
                'action_cue' => 'Consider reviewing with a professional.',
            ])]],
        ]),
    ]);

    $user = User::factory()->create();
    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'finding_type' => 'bespoke_custom_finding_xyz',  // NOT in templates
        'description' => null,
    ]);

    $service = new NarrationService;
    $result = $service->narrateFinding($finding);

    // D19: narrateFinding() returns the hook string (backward compat)
    expect($result)->toBe('A bespoke educational note you may wish to review.');

    // Bespoke path DID call Claude, on the narration (Haiku) model string
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.anthropic.com')
            && $request['model'] === config('services.anthropic.model_narration');
    });

    expect(config('services.anthropic.model_narration'))->toBe('claude-haiku-4-5');
});
