<?php

use App\Enums\QuestionType;
use App\Listeners\SurfaceHighPriorityRedFlags;
use App\Models\AIQuestion;
use App\Models\InterviewSession;
use App\Models\OptimizationFinding;
use App\Models\Subscription;
use App\Models\TaxDocument;
use App\Models\UserTaxFact;
use App\Services\AI\PaystubFactExtractorService;
use App\Services\Detectors\DeductibleSaasSweep;
use App\Services\InterviewOrchestratorService;
use App\Services\RedFlagDetectorService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * D18QuestionQualityTest — owner Decision 18 (the question-quality bar).
 *
 * Owner-reported live defect: "We noticed something related to
 * deductible_saas_microsoft_365 that may affect your taxes. Does this apply
 * to you?" — an internal key leaked into user copy, asking a contentless
 * question.
 *
 * D18 rules under test:
 *  1. Show the data, ask for the unknown (the SaaS exemplar leads with
 *     merchant names + amounts).
 *  2. Never leak internals — no snake_case internal-key patterns render in
 *     question text or choice labels (automated regex test).
 *  3. Aggregate patterns — ONE multi-select question per pattern, one answer
 *     fans out to per-item facts.
 *  4. Value-density — findings without a data-grounded question template are
 *     EXCLUDED from the interview queue (remain in the findings list).
 *  5. The contentless findingFallbackQuestion output is dead in all paths.
 *
 * D17 gate: the pattern/template path stays zero-Claude (assertNothingSent).
 */

/** D18 rule 2 — the banned internal-key pattern for rendered copy. */
const D18_INTERNAL_KEY_PATTERN = '/\b[a-z0-9]+_[a-z0-9_]+\b/';

function seedSaasSubscriptions(int $userId): void
{
    foreach ([
        ['Microsoft 365', 'microsoft 365', 9.99, 'monthly'],
        ['Adobe Creative Cloud', 'adobe creative cloud', 54.99, 'monthly'],
        ['GitHub', 'github', 4.00, 'monthly'],
    ] as [$name, $normalized, $amount, $frequency]) {
        Subscription::factory()->create([
            'user_id' => $userId,
            'merchant_name' => $name,
            'merchant_normalized' => $normalized,
            'amount' => $amount,
            'frequency' => $frequency,
            'category' => 'Software',
        ]);
    }
}

function runSaasSweep(int $userId, int $taxYear = 2026): array
{
    return (new DeductibleSaasSweep)->run($userId, $taxYear, app(RedFlagDetectorService::class), []);
}

// ─── D18 rule 3 + 1: the aggregated multi-select SaaS exemplar ───────────────

it('d18_saas_pattern: SaaS findings collapse into ONE aggregated humanized multi-select question (zero Claude)', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser();
    seedSaasSubscriptions($user->id);

    $emitted = runSaasSweep($user->id);
    expect($emitted)->toHaveCount(3); // three per-merchant findings exist

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, 2026);

    // ONE pattern key in the queue — never per-item interrogation (D18 rule 3)
    expect($session->queue)->toContain('pattern.deductible_saas');
    expect($session->queue)->not->toContain('deductible_saas_microsoft_365');
    expect($session->queue)->not->toContain('deductible_saas_adobe_creative_cloud');
    expect($session->queue)->not->toContain('deductible_saas_github');

    $question = $service->nextQuestion($session);
    expect($question)->not->toBeNull();
    expect($question->ai_best_guess)->toBe('pattern.deductible_saas');
    expect($question->options['answer_type'] ?? null)->toBe('multi_select');

    // D18 rule 1: the data (merchant + amount + cadence) leads; the unknown
    // (business use) is asked.
    $labels = array_column((array) $question->options['choices'], 'label');
    $labelText = implode(' | ', $labels);
    expect($labelText)->toContain('Microsoft 365');
    expect($labelText)->toContain('$9.99/mo');
    expect($labelText)->toContain('Adobe Creative Cloud');
    expect($labelText)->toContain('GitHub');
    expect($labelText)->toContain('None of these');
    expect(strtolower($question->question))->toContain('business');

    // D18 rule 2: no internal keys in the question text or choice labels.
    expect(preg_match(D18_INTERNAL_KEY_PATTERN, $question->question))->toBe(0);
    expect(preg_match(D18_INTERNAL_KEY_PATTERN, $labelText))->toBe(0);

    // D17: the pattern/template path is zero-Claude.
    Http::assertNothingSent();
});

it('d18_saas_fanout: one multi-select answer records the business-use fact per item and never re-asks', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser();
    seedSaasSubscriptions($user->id);
    runSaasSweep($user->id);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, 2026);
    $question = $service->nextQuestion($session);
    expect($question)->not->toBeNull();

    // Answer via the real API endpoint (e2e): select Microsoft 365 + GitHub.
    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => 'deductible_saas_microsoft_365,deductible_saas_github']
    )->assertOk();

    // Fan-out: per-item business-use facts recorded (yes for selected, no for not).
    expect(UserTaxFact::currentFact($user->id, 'finding.deductible_saas_microsoft_365')?->value)->toBe('yes');
    expect(UserTaxFact::currentFact($user->id, 'finding.deductible_saas_github')?->value)->toBe('yes');
    expect(UserTaxFact::currentFact($user->id, 'finding.deductible_saas_adobe_creative_cloud')?->value)->toBe('no');

    // The aggregate pattern fact is recorded (drives skip-logic).
    expect(UserTaxFact::currentFact($user->id, 'pattern.deductible_saas'))->not->toBeNull();

    // The question never re-appears: a fresh session excludes the answered pattern.
    $resumed = $service->startOrResume($user->id, 2026);
    expect($resumed->queue)->not->toContain('pattern.deductible_saas');
    $next = $service->nextQuestion($resumed);
    if ($next !== null) {
        expect($next->ai_best_guess)->not->toBe('pattern.deductible_saas');
    }

    Http::assertNothingSent();
});

it('d18_saas_none: answering "None of these" records no for every item', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser();
    seedSaasSubscriptions($user->id);
    runSaasSweep($user->id);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, 2026);
    $question = $service->nextQuestion($session);

    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => 'none']
    )->assertOk();

    expect(UserTaxFact::currentFact($user->id, 'finding.deductible_saas_microsoft_365')?->value)->toBe('no');
    expect(UserTaxFact::currentFact($user->id, 'finding.deductible_saas_adobe_creative_cloud')?->value)->toBe('no');
    expect(UserTaxFact::currentFact($user->id, 'finding.deductible_saas_github')?->value)->toBe('no');
});

it('d18_saas_validation: multi-select rejects values outside the choice set and mixed none-selections', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser();
    seedSaasSubscriptions($user->id);
    runSaasSweep($user->id);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, 2026);
    $question = $service->nextQuestion($session);

    // Unknown value → 422
    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => 'deductible_saas_bogus_item']
    )->assertStatus(422);

    // 'none' mixed with a selection → 422
    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => 'none,deductible_saas_github']
    )->assertStatus(422);
});

// ─── D18 rule 4: value-density — untemplated findings excluded from the queue ─

it('d18_exclusion: conditional findings without a data-grounded template never enter the interview queue', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser();

    // Untemplated conditional finding: statement description, no question template.
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'mystery_untemplated_probe',
        'finding_type' => 'deduction_probe',
        'band' => 'conditional',
        'status' => 'open',
        'description' => 'This may affect your taxes.', // narration, NOT a question
        'treatment' => 'Something generic that is not interview-ready.',
    ]);

    // A conditional finding WITH a question-phrased description stays eligible.
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'home_office_probe',
        'finding_type' => 'deduction_probe',
        'band' => 'conditional',
        'status' => 'open',
        'description' => 'Do you maintain a dedicated home office space used regularly for your business?',
    ]);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, 2026);

    expect($session->queue)->not->toContain('mystery_untemplated_probe');
    expect($session->queue)->toContain('home_office_probe');

    // The excluded finding remains OPEN in the findings list (suggested-confirm).
    $finding = OptimizationFinding::forUser($user->id)
        ->where('finding_key', 'mystery_untemplated_probe')
        ->first();
    expect($finding->status)->toBe('open');
});

// ─── D18 rule 5 (fix directive 1): the contentless fallback is DEAD ──────────

it('d18_fallback_dead: budget-capped wording with no narration skips the question instead of emitting garbage', function () {
    Http::preventStrayRequests();
    Http::fake();

    // Force the Claude wording budget to zero — the old code path emitted
    // "We noticed something related to {key}..." here.
    config(['services.anthropic.daily_budget_wording' => 0]);

    $user = createAuthenticatedUser();

    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'deductible_saas_microsoft_365',
        'finding_type' => 'deductible_saas',
        'band' => 'conditional',
        'status' => 'open',
        'description' => null,
        'treatment' => null,
    ]);

    // Legacy session with the per-item key directly in the queue (pre-D18 state).
    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['deductible_saas_microsoft_365'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $question = $service->nextQuestion($session);

    // No data-grounded copy available → the key is SKIPPED, never verbalized.
    expect($question)->toBeNull();

    $garbage = AIQuestion::where('user_id', $user->id)
        ->where('question', 'LIKE', '%We noticed something related to%')
        ->count();
    expect($garbage)->toBe(0);

    // No question row carrying the raw key as copy.
    $leaked = AIQuestion::where('user_id', $user->id)
        ->where('question', 'LIKE', '%deductible_saas_microsoft_365%')
        ->count();
    expect($leaked)->toBe(0);
});

it('d18_fallback_humanized: budget-capped wording WITH a treatment falls back to data-grounded copy without keys', function () {
    Http::preventStrayRequests();
    Http::fake();
    config(['services.anthropic.daily_budget_wording' => 0]);

    $user = createAuthenticatedUser();

    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'equipment_depreciation_probe',
        'finding_type' => 'deduction_probe',
        'band' => 'auto',
        'status' => 'open',
        'description' => null,
        'treatment' => 'Your recent equipment purchases may qualify for accelerated depreciation if used in your business.',
    ]);

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['equipment_depreciation_probe'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $question = $service->nextQuestion($session);

    expect($question)->not->toBeNull();
    // The treatment (the data) leads; a real ask follows; no internal keys.
    expect($question->question)->toContain('equipment purchases');
    expect(str_ends_with(trim($question->question), '?'))->toBeTrue();
    expect(preg_match(D18_INTERNAL_KEY_PATTERN, $question->question))->toBe(0);
});

// ─── D18 rule 2: the feed listener never leaks finding keys either ────────────

it('d18_feed_no_leak: SurfaceHighPriorityRedFlags builds humanized copy when description is null', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser();

    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'w2_benefit_arbitrage_hsa',
        'finding_type' => 'benefit_arbitrage',
        'band' => 'auto',
        'status' => 'open',
        'description' => null,
        'treatment' => 'Your paystub shows an HSA-eligible health plan without HSA contributions, which may leave a pre-tax benefit unused.',
    ]);

    $listener = new SurfaceHighPriorityRedFlags;
    $listener->handle(new \App\Events\OptimizationProfileBuilt($user->id, 2026, 1));

    $question = AIQuestion::where('user_id', $user->id)
        ->where('question_type', QuestionType::Optimization->value)
        ->where('ai_best_guess', 'w2_benefit_arbitrage_hsa')
        ->first();

    expect($question)->not->toBeNull();
    expect($question->question)->not->toContain('w2_benefit_arbitrage_hsa');
    expect($question->question)->not->toContain('related to:');
    expect(preg_match(D18_INTERNAL_KEY_PATTERN, $question->question))->toBe(0);
    // Data-grounded: the treatment copy is surfaced.
    expect($question->question)->toContain('HSA');
});

// ─── D18 exemplar 2: the 1099-K / P2P-deposits question (owner-reported) ─────
//
// Owner: the penalty-sweep finding rendered as a dense paragraph containing RAW
// bank memo strings with reference codes ("Zelle payment from RAELYN STILES
// 27868366380", "AMANDA DAVIS BACpz1r5pufa"), full 1099-K education inline, and
// a vague ask. Required shape: humanized payer names, aggregated one-line lead,
// choice answers, education demoted to a collapsible context field.

function seedP2pInflows(int $userId): void
{
    foreach ([
        ['Zelle payment from RAELYN STILES 27868366380', -320.00],
        ['Zelle payment from AMANDA DAVIS BACpz1r5pufa', -185.50],
        ['VENMO PAYMENT 1029384756 APRIL MAYES', -240.00],
        ['Zelle payment from RAELYN STILES 99118822770', -150.00],
    ] as [$memo, $amount]) {
        \App\Models\Transaction::factory()->create([
            'user_id' => $userId,
            'merchant_name' => $memo,
            'merchant_normalized' => strtolower($memo),
            'amount' => $amount, // credits are negative (inflows)
            'transaction_date' => now()->startOfYear()->addDays(40),
        ]);
    }
}

it('d18_p2p_template: the 1099-K finding renders as an aggregated, humanized choice question with demoted education', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser(['last_active_at' => now()]);
    seedP2pInflows($user->id);

    $emitted = (new \App\Services\Sweeps\PenaltyPreventionSweep(app(\App\Services\TaxRulesEngineService::class)))
        ->run($user->id, (int) now()->year, app(RedFlagDetectorService::class), []);
    expect($emitted)->toContain('penalty_1099k_mismatch');

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, (int) now()->year);
    expect($session->queue)->toContain('penalty_1099k_mismatch');

    $question = $service->nextQuestion($session);
    expect($question)->not->toBeNull();
    expect($question->ai_best_guess)->toBe('penalty_1099k_mismatch');

    // 1. HUMANIZED payer names — no raw memo reference codes.
    expect($question->question)->toContain('Raelyn Stiles');
    expect($question->question)->toContain('Amanda Davis');
    expect($question->question)->not->toContain('RAELYN STILES');
    expect($question->question)->not->toContain('27868366380');
    expect($question->question)->not->toContain('BACpz1r5pufa');

    // 2. AGGREGATED lead: total + payment count in one clean line.
    expect($question->question)->toContain('about $');
    expect($question->question)->toMatch('/across \d+ /');

    // 3. CHOICES, not a vague open yes/no.
    expect($question->options['answer_type'] ?? null)->toBe('choice');
    $labels = implode(' | ', array_column((array) $question->options['choices'], 'label'));
    expect(strtolower($labels))->toContain('personal');
    expect(strtolower($labels))->toContain('business');
    expect(strtolower($labels))->toContain('mix');
    expect(strtolower($labels))->toContain('not sure');

    // 4. Education DEMOTED to the collapsible context field — not in the body.
    expect($question->question)->not->toContain('1099-K');
    expect((string) ($question->options['context'] ?? ''))->toContain('1099-K');

    // 5. Question body stays short (≤ 2 short sentences) and leaks no keys.
    expect(mb_strlen($question->question))->toBeLessThanOrEqual(240);
    expect(preg_match(D18_INTERNAL_KEY_PATTERN, $question->question))->toBe(0);

    // D17: zero Claude on the template path.
    Http::assertNothingSent();
});

it('d18_p2p_answer: the choice answer records the income-classification fact and the question never re-appears', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser(['last_active_at' => now()]);
    seedP2pInflows($user->id);
    (new \App\Services\Sweeps\PenaltyPreventionSweep(app(\App\Services\TaxRulesEngineService::class)))
        ->run($user->id, (int) now()->year, app(RedFlagDetectorService::class), []);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, (int) now()->year);
    $question = $service->nextQuestion($session);
    expect($question)->not->toBeNull();

    // Off-menu answers are rejected (typed choice validation).
    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => 'whatever']
    )->assertStatus(422);

    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => 'mostly_business']
    )->assertOk();

    expect(UserTaxFact::currentFact($user->id, 'penalty_1099k_mismatch')?->value)->toBe('mostly_business');

    // Never re-appears in a fresh session.
    $resumed = $service->startOrResume($user->id, (int) now()->year);
    $next = $service->nextQuestion($resumed);
    if ($next !== null) {
        expect($next->ai_best_guess)->not->toBe('penalty_1099k_mismatch');
    }

    Http::assertNothingSent();
});

// ─── D18 exemplar 3: the vehicle/powersports question (owner-reported) ───────
//
// Owner: the Rocky Mountain ATV/MC finding rendered as ONE dense paragraph
// mixing three topics (mileage methods + fuel tax credit + fraud warning),
// ending in a vague confirm ask. Required shape: data lead, purpose choices
// from the detection-spec vehicle question tree, ALL education demoted to the
// collapsible context, follow-ups fanned via gating — never crammed.

function seedPowersportsPurchases(int $userId): void
{
    \App\Models\DetectionMerchant::create([
        'company_name' => 'Rocky Mountain ATV/MC',
        'aliases' => ['ROCKY MOUNTAIN ATV', 'ROCKY MOUNTAIN ATV MC', 'RMATV'],
        'category' => 'vehicle',
        'subdetector_key' => 'offroad_vehicle',
        'defensibility_rating' => 'conditional',
        'gray_area' => true,
        'rule_id' => 'category_vehicle',
    ]);

    foreach ([420.00, 189.99, 315.50] as $amount) {
        \App\Models\Transaction::factory()->create([
            'user_id' => $userId,
            'merchant_name' => 'ROCKY MOUNTAIN ATV MC',
            'merchant_normalized' => 'rocky mountain atv',
            'amount' => $amount, // debits positive
            'transaction_date' => now()->startOfYear()->addDays(60),
        ]);
    }
}

it('d18_vehicle_template: the powersports finding renders as a data-led purpose-choice question with demoted education', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser(['last_active_at' => now()]);
    seedPowersportsPurchases($user->id);

    $emitted = app(\App\Services\Detectors\CategoryLibraryDetector::class)
        ->run($user->id, (int) now()->year, app(RedFlagDetectorService::class), []);
    expect($emitted)->toContain('category_vehicle_parts');

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, (int) now()->year);
    expect($session->queue)->toContain('category_vehicle_parts');

    $question = $service->nextQuestion($session);
    expect($question)->not->toBeNull();
    expect($question->ai_best_guess)->toBe('category_vehicle_parts');

    // Data lead: merchant + purchase count + rough total.
    expect($question->question)->toContain('Rocky Mountain ATV/MC');
    expect($question->question)->toMatch('/\d+ purchases/');
    expect($question->question)->toContain('$');

    // Purpose choices from the detection-spec vehicle question tree.
    expect($question->options['answer_type'] ?? null)->toBe('choice');
    $labels = strtolower(implode(' | ', array_column((array) $question->options['choices'], 'label')));
    expect($labels)->toContain('hobby');
    expect($labels)->toContain('work vehicle');
    expect($labels)->toContain('sponsorship');
    expect($labels)->toContain('resell');
    expect($labels)->toContain('monetize');
    expect($labels)->toContain('off-road');

    // Education DEMOTED: mileage/fuel-credit/fraud-warning copy lives in context,
    // never in the question body.
    $body = strtolower($question->question);
    expect($body)->not->toContain('mileage method');
    expect($body)->not->toContain('fuel tax credit');
    expect($body)->not->toContain('fraud');
    $context = strtolower((string) ($question->options['context'] ?? ''));
    expect($context)->toContain('mileage');
    expect($context)->toContain('fuel');
    expect($context)->toContain('gallons');

    // Copy bar: short body, no internal keys.
    expect(mb_strlen($question->question))->toBeLessThanOrEqual(240);
    expect(preg_match(D18_INTERNAL_KEY_PATTERN, $question->question))->toBe(0);

    Http::assertNothingSent();
});

it('d18_vehicle_followup: a business-flavored purpose answer fans the usage-log follow-up as its OWN question', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser(['last_active_at' => now()]);
    seedPowersportsPurchases($user->id);
    app(\App\Services\Detectors\CategoryLibraryDetector::class)
        ->run($user->id, (int) now()->year, app(RedFlagDetectorService::class), []);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, (int) now()->year);
    $question = $service->nextQuestion($session);
    expect($question)->not->toBeNull();

    // Owner's real answer: sponsorship/advertising race vehicles.
    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => 'sponsorship_advertising']
    )->assertOk();

    expect(UserTaxFact::currentFact($user->id, 'category_vehicle_parts')?->value)->toBe('sponsorship_advertising');

    // The usage-log follow-up surfaces as its OWN question (never crammed).
    $followUp = $service->nextQuestion($session->fresh());
    expect($followUp)->not->toBeNull();
    expect($followUp->ai_best_guess)->toBe('vehicle.usage_log_status');
    expect(strtolower($followUp->question))->toContain('log');
    expect(preg_match(D18_INTERNAL_KEY_PATTERN, $followUp->question))->toBe(0);

    // Answer with the owner's real state: not kept, willing to start.
    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$followUp->id}/answer",
        ['answer' => 'willing_to_start']
    )->assertOk();

    expect(UserTaxFact::currentFact($user->id, 'vehicle.usage_log_status')?->value)->toBe('willing_to_start');

    // Neither question re-appears in a fresh session.
    $resumed = $service->startOrResume($user->id, (int) now()->year);
    expect($resumed->queue)->not->toContain('category_vehicle_parts');
    $next = $service->nextQuestion($resumed);
    if ($next !== null) {
        expect($next->ai_best_guess)->not->toBeIn(['category_vehicle_parts', 'vehicle.usage_log_status']);
    }

    Http::assertNothingSent();
});

it('d18_vehicle_personal_answer: a personal-hobby answer does NOT fan the usage-log follow-up', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser(['last_active_at' => now()]);
    seedPowersportsPurchases($user->id);
    app(\App\Services\Detectors\CategoryLibraryDetector::class)
        ->run($user->id, (int) now()->year, app(RedFlagDetectorService::class), []);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, (int) now()->year);
    $question = $service->nextQuestion($session);

    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => 'personal_hobby']
    )->assertOk();

    // No log follow-up for personal use — value-density rule (D18 rule 4).
    $next = $service->nextQuestion($session->fresh());
    if ($next !== null) {
        expect($next->ai_best_guess)->not->toBe('vehicle.usage_log_status');
    }
});

// ─── D18 exemplar 4: the job-change CONFIRMATION question (owner-reported) ────
//
// Owner's verbatim desired format: "It appears you had a Job Change due to
// bi-weekly income from X then it switched to bi-weekly income from Y. /
// Yes / No / Other, please explain below." — a second question SHAPE
// (shape='confirmation'): evidence lead + Yes/No/escape, education demoted,
// follow-ups fanning from YES via the gated tree.

function seedEmployerSwitch(int $userId): void
{
    // Old employer: biweekly payroll deposits that stopped ~3 months ago.
    for ($i = 0; $i < 6; $i++) {
        \App\Models\Transaction::factory()->create([
            'user_id' => $userId,
            'merchant_name' => 'ACME CORP PAYROLL 8827162',
            'merchant_normalized' => 'acme corp payroll',
            'amount' => -2150.00,
            'transaction_date' => now()->subMonths(6)->addWeeks($i * 2),
        ]);
    }
    // New employer: biweekly deposits that began ~2 months ago and continue.
    for ($i = 0; $i < 4; $i++) {
        \App\Models\Transaction::factory()->create([
            'user_id' => $userId,
            'merchant_name' => 'GLOBEX INDUSTRIES DIRECT DEP 991',
            'merchant_normalized' => 'globex industries direct dep',
            'amount' => -2380.00,
            'transaction_date' => now()->subMonths(2)->addWeeks($i * 2),
        ]);
    }
}

/** Pre-answer every battery fact except the one under test so only it surfaces. */
function suppressBatteriesExcept(int $userId, string $keepFindingKey): void
{
    $batteries = [
        'battery_marriage_status' => 'life_event.marital_status_changed',
        'battery_birth_adoption' => 'life_event.birth_or_adoption',
        'battery_job_change' => 'life_event.job_change',
        'battery_inheritance' => 'life_event.inherited_assets_this_year',
        'battery_medicare_enrollment' => 'life_event.medicare_enrollment_this_year',
    ];
    foreach ($batteries as $findingKey => $factKey) {
        if ($findingKey === $keepFindingKey) {
            continue;
        }
        UserTaxFact::recordFact(
            userId: $userId,
            factKey: $factKey,
            value: 'no',
            sourceType: 'interview_answer',
            taxYear: (int) now()->year,
        );
    }
}

it('d18_jobchange_confirmation: the job-change battery renders as an evidence-led Yes/No confirmation with demoted education', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser(['last_active_at' => now()]);
    seedEmployerSwitch($user->id);
    suppressBatteriesExcept($user->id, 'battery_job_change');

    app(\App\Services\Scanners\LifeEventTriggerDetector::class)
        ->run($user->id, (int) now()->year, app(RedFlagDetectorService::class), []);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, (int) now()->year);
    expect($session->queue)->toContain('battery_job_change');

    // The payroll-stop TRIGGER surfaces first — it must ALSO use the
    // confirmation shape (generalization: all trigger questions inherit it).
    $trigger = $service->nextQuestion($session);
    expect($trigger)->not->toBeNull();
    expect($trigger->ai_best_guess)->toBe('life_event_payroll_stop');
    expect(array_column((array) $trigger->options['choices'], 'value'))->toContain('yes');
    expect(mb_strlen($trigger->question))->toBeLessThanOrEqual(240);
    expect((string) ($trigger->options['context'] ?? ''))->not->toBe('');
    $service->recordAnswer($session->fresh(), 'life_event_payroll_stop', 'no');

    $question = $service->nextQuestion($session->fresh());
    expect($question)->not->toBeNull();
    expect($question->ai_best_guess)->toBe('battery_job_change');

    // 1. Evidence lead with HUMANIZED employer names from the deposit patterns.
    expect($question->question)->toContain('Acme Corp');
    expect($question->question)->toContain('Globex Industries');
    expect($question->question)->not->toContain('8827162');
    expect($question->question)->not->toContain('ACME CORP PAYROLL');

    // 2. Confirmation shape: Yes / No (+ the escape hatch appended).
    $values = array_column((array) $question->options['choices'], 'value');
    expect($values)->toContain('yes');
    expect($values)->toContain('no');
    expect($values)->toContain('__other__');

    // 3. ALL education (W-4, severance, rollover, NUA) demoted to context.
    $body = $question->question;
    expect($body)->not->toContain('W-4');
    expect(strtolower($body))->not->toContain('severance');
    expect(strtolower($body))->not->toContain('rollover');
    $context = strtolower((string) ($question->options['context'] ?? ''));
    expect($context)->toContain('rollover');
    expect($context)->toContain('withholding');

    // Copy bar (addendum 6) + zero Claude on the template path (D17).
    expect(mb_strlen($body))->toBeLessThanOrEqual(240);
    expect(preg_match(D18_INTERNAL_KEY_PATTERN, $body))->toBe(0);
    Http::assertNothingSent();
});

it('d18_jobchange_yes: YES records the life-event fact (year-scoped), fans W-4 follow-ups, and never re-asks', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser(['last_active_at' => now()]);
    seedEmployerSwitch($user->id);
    suppressBatteriesExcept($user->id, 'battery_job_change');

    $detector = app(\App\Services\Scanners\LifeEventTriggerDetector::class);
    $detector->run($user->id, (int) now()->year, app(RedFlagDetectorService::class), []);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, (int) now()->year);

    // Consume the payroll-stop trigger question first (surfaces before battery).
    $trigger = $service->nextQuestion($session);
    expect($trigger->ai_best_guess)->toBe('life_event_payroll_stop');
    $service->recordAnswer($session->fresh(), 'life_event_payroll_stop', 'no');

    $question = $service->nextQuestion($session->fresh());
    expect($question->ai_best_guess)->toBe('battery_job_change');

    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => 'yes']
    )->assertOk();

    // The canonical life-event fact is written tax-year-scoped — this is what
    // 14-09's Action Center checklist items (rollover / W-4 review) key off.
    expect(UserTaxFact::currentFact($user->id, 'life_event.job_change', null, (int) now()->year)?->value)
        ->toBe('yes');

    // Follow-up probes fan out via the gated tree — one topic per follow-up.
    $followUp = $service->nextQuestion($session->fresh());
    expect($followUp)->not->toBeNull();
    expect($followUp->ai_best_guess)->toStartWith('w4.');

    // The detector never re-emits the answered battery question.
    $reEmitted = $detector->surfaceBatteryQuestions(
        $user->id, (int) now()->year, app(RedFlagDetectorService::class), []
    );
    expect($reEmitted)->not->toContain('battery_job_change');
});

it('d18_battery_confirmation_generic: batteries without detected evidence render their clean label with Yes/No/escape', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser(['last_active_at' => now()]);
    suppressBatteriesExcept($user->id, 'battery_marriage_status');

    app(\App\Services\Scanners\LifeEventTriggerDetector::class)
        ->run($user->id, (int) now()->year, app(RedFlagDetectorService::class), []);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, (int) now()->year);
    $question = $service->nextQuestion($session);

    expect($question)->not->toBeNull();
    expect($question->ai_best_guess)->toBe('battery_marriage_status');
    expect($question->question)->toBe('Did your marital status change this year?');

    $values = array_column((array) $question->options['choices'], 'value');
    expect($values)->toContain('yes');
    expect($values)->toContain('no');
    expect($values)->toContain('__other__');

    // The dense marriage/divorce education paragraph lives in context now.
    expect(strtolower((string) ($question->options['context'] ?? '')))->toContain('filing status');

    Http::assertNothingSent();
});

// ─── D18 addendum 7: the escape hatch on every choice question ────────────────

it('d18_escape_hatch: every choice question carries a "Something else" option and free text is interpreted onto the fact', function () {
    Http::preventStrayRequests();

    // The escape-hatch interpretation IS an allowed Claude call (D17 bespoke
    // input) — fake it mapping the free text onto a real choice value.
    Http::fake([
        'https://api.anthropic.com/*' => Http::response([
            'content' => [['text' => '{"value":"business_work_vehicle"}']],
        ], 200),
    ]);

    $user = createAuthenticatedUser(['last_active_at' => now()]);
    seedPowersportsPurchases($user->id);
    app(\App\Services\Detectors\CategoryLibraryDetector::class)
        ->run($user->id, (int) now()->year, app(RedFlagDetectorService::class), []);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, (int) now()->year);
    $question = $service->nextQuestion($session);
    expect($question)->not->toBeNull();

    // The escape option is present on the rendered choice list.
    $choices = (array) $question->options['choices'];
    $escape = collect($choices)->firstWhere('value', '__other__');
    expect($escape)->not->toBeNull();
    expect($escape['label'])->toContain('Something else');

    // Choosing the escape hatch and typing a custom answer → interpreted →
    // written through the same recordFact flow → question resolved.
    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => '__other__: these are parts for the trucks I use in my landscaping business']
    )->assertOk();

    expect(UserTaxFact::currentFact($user->id, 'category_vehicle_parts')?->value)->toBe('business_work_vehicle');

    // Interpreted business-flavored value fans the follow-up like a direct pick.
    $followUp = $service->nextQuestion($session->fresh());
    expect($followUp?->ai_best_guess)->toBe('vehicle.usage_log_status');

    // The interpretation call went to Claude exactly once (wording tier).
    Http::assertSentCount(1);
});

it('d18_escape_hatch_capped: at the wording budget cap the escape answer degrades to a stored "other" without Claude', function () {
    Http::preventStrayRequests();
    Http::fake();
    config(['services.anthropic.daily_budget_wording' => 0]);

    $user = createAuthenticatedUser(['last_active_at' => now()]);
    seedPowersportsPurchases($user->id);
    app(\App\Services\Detectors\CategoryLibraryDetector::class)
        ->run($user->id, (int) now()->year, app(RedFlagDetectorService::class), []);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, (int) now()->year);
    $question = $service->nextQuestion($session);

    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => '__other__: hard to explain']
    )->assertOk();

    // Graceful: fact stored as 'other' (raw text lives in the encrypted transcript).
    expect(UserTaxFact::currentFact($user->id, 'category_vehicle_parts')?->value)->toBe('other');
    Http::assertNothingSent();
});

// ─── D18 rule 2: automated sweep — NO rendered question carries internal keys ─

it('d18_no_internal_keys: every rendered interview question and choice label is free of snake_case internal keys', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser();
    seedSaasSubscriptions($user->id);
    runSaasSweep($user->id);

    // Auto finding with narrated description (the narrated path).
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'vehicle_mileage_deduction',
        'band' => 'auto',
        'status' => 'open',
        'treatment' => 'standard_mileage',
        'description' => 'It looks like you may drive your vehicle for work. Do you track your business mileage?',
    ]);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, 2026);

    // Drain the queue — every produced question must pass the D18 copy bar:
    // no internal keys anywhere; body succinct (addendum 6: data lead ≤ 1
    // sentence + ask ≤ 1 sentence; education goes to the collapsible context).
    while (($q = $service->nextQuestion($session)) !== null) {
        expect(preg_match(D18_INTERNAL_KEY_PATTERN, $q->question))
            ->toBe(0, "Internal key leaked in question copy: {$q->question}");

        expect(mb_strlen($q->question))
            ->toBeLessThanOrEqual(240, "Question body too long (education belongs in context): {$q->question}");

        // ≤ 2 sentences: sentence-ending punctuation followed by whitespace/EOL
        // (decimals like $9.99 and parentheticals do not count).
        preg_match_all('/[.!?](?=\s|$)/u', trim($q->question), $boundaries);
        expect(count($boundaries[0]))
            ->toBeLessThanOrEqual(2, "Question body exceeds 2 sentences: {$q->question}");

        expect(preg_match(D18_INTERNAL_KEY_PATTERN, (string) ($q->options['context'] ?? '')))
            ->toBe(0, 'Internal key leaked in context copy');

        foreach ((array) ($q->options['choices'] ?? []) as $choice) {
            expect(preg_match(D18_INTERNAL_KEY_PATTERN, (string) ($choice['label'] ?? '')))
                ->toBe(0, 'Internal key leaked in choice label: '.($choice['label'] ?? ''));
        }
        $session = $session->fresh();
    }
});

// ─── D18 extension: proposals API surface (owner UX fix cluster) ──────────────
//
// Extends D18 rule 2 to the DurableFactsController proposals response.
// label and display_value must never contain raw snake_case fact_key paths.

it('d18_proposals_no_key_leak: proposals API label + display_value contain no internal key paths', function () {
    Http::preventStrayRequests();
    Http::fake();
    Storage::fake('local');

    $user = createAuthenticatedUser();

    // Create a real paystub document with extracted data
    $doc = TaxDocument::create([
        'user_id' => $user->id,
        'original_filename' => 'paystub.pdf',
        'stored_path' => "tax-vault/{$user->id}/2026/pay_stub/test.pdf",
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'file_hash' => hash('sha256', 'fake-d18-content'),
        'tax_year' => 2026,
        'status' => 'ready',
        'category' => 'pay_stub',
        'extracted_data' => [
            'fields' => [
                'gross_pay' => ['value' => '4250.00', 'confidence' => 0.95],
                'w4_filing_status' => ['value' => 'married', 'confidence' => 0.90],
                'traditional_401k_deduction' => ['value' => '500.00', 'confidence' => 0.88],
            ],
            'overall_confidence' => 0.91,
        ],
    ]);

    // Run the fact extractor to create proposals
    $count = app(PaystubFactExtractorService::class)->proposeFacts($doc);
    expect($count)->toBeGreaterThan(0);

    // Hit the proposals API as the authenticated user
    $response = $this->actingAs($user)
        ->getJson('/api/v1/optimizer/facts');
    $response->assertOk();

    $proposals = $response->json('proposals');
    expect($proposals)->not->toBeEmpty();

    foreach ($proposals as $proposal) {
        $label = (string) ($proposal['label'] ?? '');
        $displayValue = (string) ($proposal['display_value'] ?? '');

        // D18 rule 2 extended: label must be a human sentence, not a key path
        expect(preg_match(D18_INTERNAL_KEY_PATTERN, $label))
            ->toBe(0, "Internal key leaked in proposal label: {$label}");

        // display_value (dollars/Yes/No/human strings) must never contain raw key paths
        if ($displayValue !== '') {
            expect(preg_match(D18_INTERNAL_KEY_PATTERN, $displayValue))
                ->toBe(0, "Internal key leaked in proposal display_value: {$displayValue}");
        }

        // Confirm the VALUE is NOT exposed directly in the response
        expect(array_key_exists('value', $proposal))->toBeFalse('raw encrypted value must not appear in API response');
    }

    // Spot-check humanization: cents facts render as dollars
    $grossFact = collect($proposals)->first(fn ($p) => str_ends_with($p['fact_key'], '_cents'));
    if ($grossFact) {
        expect($grossFact['display_value'])->toStartWith('$');
    }

    // Spot-check: w4.filing_status renders human label
    $filingFact = collect($proposals)->first(fn ($p) => $p['fact_key'] === 'w4.filing_status');
    if ($filingFact) {
        expect($filingFact['display_value'])->not->toBe('married');
        expect($filingFact['display_value'])->toContain('Married');
    }

    Http::assertNothingSent();
});
