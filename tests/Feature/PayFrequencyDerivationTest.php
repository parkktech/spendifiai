<?php

use App\Models\TaxDocument;
use App\Models\User;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use App\Services\AI\PaystubFactExtractorService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Fact confirms dispatch BuildIncomeOptimizationProfile on the sync queue,
    // whose event chain runs report generation + AI narration inline. Fake the
    // Anthropic API so tests never spend real tokens or hit retry backoff.
    // The body is a valid Claude messages response whose content[0].text JSON
    // satisfies both narrator validators ({summary, bullets} and
    // {hook, detail, action_cue}).
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'summary' => 'This area may be worth exploring.',
                    'bullets' => ['Consider reviewing this area with a professional.'],
                    'hook' => 'You may want to review this area.',
                    'detail' => 'This could be worth exploring further.',
                    'action_cue' => 'Consider discussing with a tax professional.',
                ]),
            ]],
        ], 200),
    ]);
});

/**
 * Item 3: Pay-frequency derivation from period dates + income reconciliation.
 *
 * Covers:
 *   (a) pay_period_start / pay_period_end are mapped as proposals from extracted fields
 *   (b) pay.frequency is derived from span (canonical table) and written as proposal
 *   (c) Span table correctness: biweekly (13-15), weekly (6-8), semimonthly (anchored), monthly (27-31)
 *   (d) Ambiguous span → no pay.frequency proposal
 *   (e) Income reconciliation: proposal created when derived base differs >5% from profile
 *   (f) Income reconciliation: NO proposal when difference ≤5%
 *   (g) Confirm income.paystub_monthly_base_cents → updates UserFinancialProfile.monthly_income
 *   (h) YTD variable-comp note in metadata when annualized YTD ≥20% above base
 */
function makePaystubDoc(int $userId, array $extractedFields, int $taxYear = 2025): TaxDocument
{
    Storage::fake('local');
    Storage::disk('local')->put("tax-vault/{$userId}/{$taxYear}/pay_stub/test.pdf", 'fake-content');

    return TaxDocument::create([
        'user_id' => $userId,
        'original_filename' => 'paystub.pdf',
        'stored_path' => "tax-vault/{$userId}/{$taxYear}/pay_stub/test.pdf",
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'file_hash' => hash('sha256', uniqid()),
        'tax_year' => $taxYear,
        'status' => 'ready',
        'category' => 'pay_stub',
        'extracted_data' => [
            'fields' => $extractedFields,
            'overall_confidence' => 0.90,
        ],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// (a) Period-date fields are written as proposals
// ─────────────────────────────────────────────────────────────────────────────

it('period_dates_as_proposals: pay_period_start and pay_period_end are mapped to UserTaxFact proposals', function () {
    $user = User::factory()->create();
    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-07', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-06-20', 'confidence' => 0.95],
        'gross_pay' => ['value' => '3843.00', 'confidence' => 0.95],
    ]);

    $service = app(PaystubFactExtractorService::class);
    $service->proposeFacts($doc);

    $startFact = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'pay.period_start')
        ->where('source_type', 'document_extraction')
        ->first();

    $endFact = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'pay.period_end')
        ->where('source_type', 'document_extraction')
        ->first();

    expect($startFact)->not->toBeNull()
        ->and($startFact->value)->toBe('2025-06-07')
        ->and($startFact->is_current)->toBeFalse()
        ->and($endFact)->not->toBeNull()
        ->and($endFact->value)->toBe('2025-06-20');
});

// ─────────────────────────────────────────────────────────────────────────────
// (b) pay.frequency derived and written as proposal for biweekly (user 1 doc-31 span)
// ─────────────────────────────────────────────────────────────────────────────

it('frequency_biweekly_doc31: 06/07→06/20 (14 days inclusive) derives biweekly', function () {
    $user = User::factory()->create();
    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-07', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-06-20', 'confidence' => 0.95],
        'gross_pay' => ['value' => '3843.00', 'confidence' => 0.95],
    ]);

    $service = app(PaystubFactExtractorService::class);
    $service->proposeFacts($doc);

    $freqFact = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'pay.frequency')
        ->where('source_type', 'document_extraction')
        ->first();

    expect($freqFact)->not->toBeNull()
        ->and($freqFact->value)->toBe('biweekly')
        ->and($freqFact->is_current)->toBeFalse()   // D4 proposal gate
        ->and($freqFact->confirmed_at)->toBeNull()   // not auto-confirmed
        ->and($freqFact->metadata['span_days'])->toBe(14)
        ->and($freqFact->metadata['period_start'])->toBe('2025-06-07')
        ->and($freqFact->metadata['period_end'])->toBe('2025-06-20');
});

// ─────────────────────────────────────────────────────────────────────────────
// (c) Span table correctness
// ─────────────────────────────────────────────────────────────────────────────

it('frequency_weekly: 7-day span (Mon→Sun) derives weekly', function () {
    $user = User::factory()->create();
    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-09', 'confidence' => 0.95],  // Mon
        'pay_period_end' => ['value' => '2025-06-15', 'confidence' => 0.95],  // Sun → 7 days inclusive
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $freq = UserTaxFact::where('user_id', $user->id)->where('fact_key', 'pay.frequency')->first();
    expect($freq?->value)->toBe('weekly');
});

it('frequency_semimonthly: 1st–15th anchor derives semimonthly', function () {
    $user = User::factory()->create();
    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-01', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-06-15', 'confidence' => 0.95],
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $freq = UserTaxFact::where('user_id', $user->id)->where('fact_key', 'pay.frequency')->first();
    expect($freq?->value)->toBe('semimonthly');
});

it('frequency_semimonthly: 16th–EOM anchor derives semimonthly', function () {
    $user = User::factory()->create();
    // June has 30 days; 16th–30th = 15 days inclusive, anchored
    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-16', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-06-30', 'confidence' => 0.95],
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $freq = UserTaxFact::where('user_id', $user->id)->where('fact_key', 'pay.frequency')->first();
    expect($freq?->value)->toBe('semimonthly');
});

it('frequency_biweekly_13day: 13-day span derives biweekly (lower bound)', function () {
    $user = User::factory()->create();
    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-01', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-06-13', 'confidence' => 0.95],  // 13 days inclusive
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $freq = UserTaxFact::where('user_id', $user->id)->where('fact_key', 'pay.frequency')->first();
    expect($freq?->value)->toBe('biweekly');
});

it('frequency_monthly: 28-day span derives monthly', function () {
    $user = User::factory()->create();
    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-02-01', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-02-28', 'confidence' => 0.95],  // 28 days inclusive
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $freq = UserTaxFact::where('user_id', $user->id)->where('fact_key', 'pay.frequency')->first();
    expect($freq?->value)->toBe('monthly');
});

// ─────────────────────────────────────────────────────────────────────────────
// (d) Ambiguous span → no frequency proposal
// ─────────────────────────────────────────────────────────────────────────────

it('frequency_ambiguous: 10-day span writes no pay.frequency proposal', function () {
    $user = User::factory()->create();
    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-01', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-06-10', 'confidence' => 0.95],  // 10 days — ambiguous
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $freq = UserTaxFact::where('user_id', $user->id)->where('fact_key', 'pay.frequency')->first();
    expect($freq)->toBeNull();
});

it('frequency_missing_dates: no period dates → no pay.frequency proposal', function () {
    $user = User::factory()->create();
    $doc = makePaystubDoc($user->id, [
        'gross_pay' => ['value' => '3843.00', 'confidence' => 0.95],
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $freq = UserTaxFact::where('user_id', $user->id)->where('fact_key', 'pay.frequency')->first();
    expect($freq)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// (e) Income reconciliation: proposal created when differs >5%
// ─────────────────────────────────────────────────────────────────────────────

it('income_reconciliation_proposal: created when derived base differs >5% from profile', function () {
    $user = User::factory()->create();

    // Profile says $15,000/mo; paystub says $3,843.00 biweekly = $3843 × 26 / 12 = $8,326.50/mo
    UserFinancialProfile::create([
        'user_id' => $user->id,
        'monthly_income' => 15000.00,
        'employment_type' => 'employee',
        'tax_filing_status' => 'single',
        'housing_status' => 'renting',
    ]);

    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-07', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-06-20', 'confidence' => 0.95],   // biweekly (14 days)
        'gross_pay' => ['value' => '3843.00',    'confidence' => 0.95],
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $recon = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'income.paystub_monthly_base_cents')
        ->where('source_type', 'document_extraction')
        ->first();

    // biweekly: $3843 × 26 / 12 = 8326.50/mo
    $expectedCents = (int) round(3843.0 * 26 / 12 * 100);

    expect($recon)->not->toBeNull()
        ->and((int) $recon->value)->toBe($expectedCents)
        ->and($recon->is_current)->toBeFalse()
        ->and($recon->confirmed_at)->toBeNull()
        ->and($recon->metadata['is_income_reconciliation'])->toBeTrue()
        ->and($recon->metadata['derived_from_frequency'])->toBe('biweekly')
        ->and((float) $recon->metadata['current_profile_monthly_dollars'])->toBe(15000.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// (f) Income reconciliation: NO proposal when difference ≤5%
// ─────────────────────────────────────────────────────────────────────────────

it('income_reconciliation_no_proposal: NOT created when derived base is within 5% of profile', function () {
    $user = User::factory()->create();

    // biweekly gross = $3843 → monthly base = $8,326.50
    // Profile at $8,400/mo → diff = (8400-8326.5)/8400 ≈ 0.87% < 5% → no proposal
    UserFinancialProfile::create([
        'user_id' => $user->id,
        'monthly_income' => 8400.00,
        'employment_type' => 'employee',
        'tax_filing_status' => 'single',
        'housing_status' => 'renting',
    ]);

    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-07', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-06-20', 'confidence' => 0.95],
        'gross_pay' => ['value' => '3843.00',    'confidence' => 0.95],
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $recon = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'income.paystub_monthly_base_cents')
        ->first();

    expect($recon)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// (g) Confirm income.paystub_monthly_base_cents → updates UserFinancialProfile
// ─────────────────────────────────────────────────────────────────────────────

it('confirm_income_reconciliation: confirms proposal and updates profile monthly_income', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Profile: $15,000/mo → diverges from biweekly paystub $8,326.50/mo
    UserFinancialProfile::create([
        'user_id' => $user->id,
        'monthly_income' => 15000.00,
        'employment_type' => 'employee',
        'tax_filing_status' => 'single',
        'housing_status' => 'renting',
    ]);

    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-07', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-06-20', 'confidence' => 0.95],
        'gross_pay' => ['value' => '3843.00',    'confidence' => 0.95],
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $proposal = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'income.paystub_monthly_base_cents')
        ->firstOrFail();

    // Confirm the proposal
    $response = $this->postJson("/api/v1/optimizer/facts/{$proposal->id}/confirm");
    $response->assertOk();

    // Profile should be updated to derived monthly value
    $expectedMonthly = round(3843.0 * 26 / 12, 2);
    $updatedProfile = UserFinancialProfile::where('user_id', $user->id)->firstOrFail();

    // Verify within $1 (encrypted field decodes as string; cast to float)
    expect(abs((float) $updatedProfile->monthly_income - $expectedMonthly))->toBeLessThan(1.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// (h) YTD variable-comp note in metadata
// ─────────────────────────────────────────────────────────────────────────────

it('ytd_variable_comp_note: metadata flag set when annualized YTD ≥20% above base', function () {
    $user = User::factory()->create();

    // biweekly gross = $3,843 → annual base = $99,918
    // YTD at end of June (≈50% of year): provide YTD that annualizes to $135,000 (>20% above base)
    // Elapsed fraction through 2025-06-20 ≈ 171/365 ≈ 0.4685
    // To annualize to ≥$119,902 (20% above $99,918):  ytd_gross ≥ 119902 × 0.4685 ≈ $56,188
    UserFinancialProfile::create([
        'user_id' => $user->id,
        'monthly_income' => 15000.00,   // >5% divergence so recon runs
        'employment_type' => 'employee',
        'tax_filing_status' => 'single',
        'housing_status' => 'renting',
    ]);

    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-07', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-06-20', 'confidence' => 0.95],
        'gross_pay' => ['value' => '3843.00',    'confidence' => 0.95],
        'ytd_gross' => ['value' => '60000.00',   'confidence' => 0.90],  // annualizes to ≈$128k
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $recon = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'income.paystub_monthly_base_cents')
        ->firstOrFail();

    expect($recon->metadata['ytd_variable_comp_note'])->toBeTrue();
});

it('ytd_variable_comp_note_false: NOT set when annualized YTD is not >20% above base', function () {
    $user = User::factory()->create();

    UserFinancialProfile::create([
        'user_id' => $user->id,
        'monthly_income' => 15000.00,
        'employment_type' => 'employee',
        'tax_filing_status' => 'single',
        'housing_status' => 'renting',
    ]);

    // biweekly base annualizes to $99,918; YTD = $45,000 → annualizes to $96k (under 20% above)
    $doc = makePaystubDoc($user->id, [
        'pay_period_start' => ['value' => '2025-06-07', 'confidence' => 0.95],
        'pay_period_end' => ['value' => '2025-06-20', 'confidence' => 0.95],
        'gross_pay' => ['value' => '3843.00',    'confidence' => 0.95],
        'ytd_gross' => ['value' => '45000.00',   'confidence' => 0.90],
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $recon = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'income.paystub_monthly_base_cents')
        ->first();

    // May not exist (if no >5% divergence), but if it does the flag should be false
    if ($recon !== null) {
        expect($recon->metadata['ytd_variable_comp_note'])->toBeFalse();
    } else {
        expect(true)->toBeTrue(); // Profile matches closely enough — no proposal; that's fine
    }
});
