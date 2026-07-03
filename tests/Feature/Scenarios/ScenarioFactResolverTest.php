<?php

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Models\IncomeOptimizationProfile;
use App\Models\TaxDocument;
use App\Models\User;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use App\Services\ScenarioFactResolverService;

/*
|--------------------------------------------------------------------------
| ScenarioFactResolverService (SCENARIOS-SPEC §A.6 / SCN-02)
|--------------------------------------------------------------------------
| Source-priority chains, alias fallback, M3 filing-status independence,
| year-scoped-before-unscoped, two-tier known-vs-confirmed, and the §A.6.3
| deterministic derivations. The resolver never writes facts, never calls Claude.
*/

/**
 * @return array{profile: null} helper: minimal snapshot builder
 */
function makeSnapshot(User $user, int $taxYear, array $attrs = []): IncomeOptimizationProfile
{
    return IncomeOptimizationProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'tax_year' => $taxYear,
        // Null the money columns by default so chain tests are deterministic.
        'w2_wages' => null,
        'self_employment_income' => null,
        'bank_deposit_total' => null,
        'traditional_401k_ytd' => null,
        'roth_401k_ytd' => null,
        'ira_ytd' => null,
        'hsa_ytd' => null,
        'filing_status' => null,
    ], $attrs));
}

function makeReadyPaystub(User $user, int $taxYear, array $fields): TaxDocument
{
    // Wrap each field in the nested {value: ...} shape the extractor produces.
    $nested = [];
    foreach ($fields as $k => $v) {
        $nested[$k] = ['value' => $v, 'confidence' => 0.95];
    }

    return TaxDocument::create([
        'user_id' => $user->id,
        'original_filename' => 'paystub.pdf',
        'stored_path' => 'tax/'.$user->id.'/paystub.pdf',
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'file_hash' => hash('sha256', uniqid('', true)),
        'tax_year' => $taxYear,
        'category' => TaxDocumentCategory::PayStub->value,
        'status' => DocumentStatus::Ready->value,
        'extracted_data' => ['fields' => $nested],
    ]);
}

beforeEach(function () {
    $this->resolver = app(ScenarioFactResolverService::class);
    $this->user = User::factory()->create();
    $this->year = 2026;
});

// ── Source-priority per fact class ─────────────────────────────────────────

it('resolves identity facts fact-first (filing status beats a stale snapshot)', function () {
    makeSnapshot($this->user, $this->year, ['filing_status' => 'single']);
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'profile.filing_status',
        value: 'married_joint',
        sourceType: 'interview_answer',
    );

    $r = $this->resolver->resolve($this->user, $this->year, 'profile.filing_status');

    expect($r)->not->toBeNull()
        ->and($r['value'])->toBe('married_joint')
        ->and($r['source'])->toBe('fact')
        ->and($r['confirmed'])->toBeTrue();
});

it('resolves money-YTD facts snapshot-first (M14)', function () {
    makeSnapshot($this->user, $this->year, ['traditional_401k_ytd' => '500000']);
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'retirement.traditional_401k_ytd_cents',
        value: '700000',
        sourceType: 'interview_answer',
        taxYear: $this->year,
    );

    $r = $this->resolver->resolve($this->user, $this->year, 'retirement.traditional_401k_ytd_cents');

    expect($r['value'])->toBe('500000')
        ->and($r['source'])->toBe('snapshot')
        ->and($r['confirmed'])->toBeFalse(); // snapshot = known, not confirmed
});

// ── Alias fallback (§A.1.3) ────────────────────────────────────────────────

it('falls back to an alias key when the canonical fact is absent', function () {
    makeSnapshot($this->user, $this->year); // no traditional_401k_ytd snapshot value
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'retirement.k401_contribution_ytd_cents', // alias
        value: '300000',
        sourceType: 'interview_answer',
        taxYear: $this->year,
    );

    $r = $this->resolver->resolve($this->user, $this->year, 'retirement.traditional_401k_ytd_cents');

    expect($r['value'])->toBe('300000')
        ->and($r['source'])->toBe('fact')
        ->and($r['source_ref'])->toContain('fact:');
});

// ── M3: filing-status keys resolve INDEPENDENTLY (never aliased) ───────────

it('never collapses w4.filing_status into profile.filing_status (M3)', function () {
    // Only a snapshot filing status + a distinct W-4 fact exist.
    makeSnapshot($this->user, $this->year, ['filing_status' => 'married_joint']);
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'w4.filing_status',
        value: 'single_or_mfs',
        sourceType: 'interview_answer',
    );

    $profile = $this->resolver->resolve($this->user, $this->year, 'profile.filing_status');
    $w4 = $this->resolver->resolve($this->user, $this->year, 'w4.filing_status');

    // profile.filing_status must NOT pick up the W-4 value; it uses its own chain.
    expect($profile['value'])->toBe('married_joint')
        ->and($profile['source'])->toBe('snapshot')
        ->and($w4['value'])->toBe('single_or_mfs')
        ->and($w4['source'])->toBe('fact')
        ->and($profile['value'])->not->toBe($w4['value']);
});

it('does not leak profile.filing_status into w4.filing_status (M3, reverse)', function () {
    makeSnapshot($this->user, $this->year, ['filing_status' => null]);
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'profile.filing_status',
        value: 'head_of_household',
        sourceType: 'interview_answer',
    );

    // w4.filing_status chain is fact→ask; no w4 fact exists → unresolved.
    expect($this->resolver->resolve($this->user, $this->year, 'w4.filing_status'))->toBeNull();
});

// ── Year-scoped precedes unscoped ──────────────────────────────────────────

it('prefers a year-scoped fact over an unscoped one', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.federal_withholding',
        value: '111111',
        sourceType: 'interview_answer',
        taxYear: null,
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.federal_withholding',
        value: '842000',
        sourceType: 'interview_answer',
        taxYear: $this->year,
    );

    $r = $this->resolver->resolve($this->user, $this->year, 'employer.federal_withholding');
    expect($r['value'])->toBe('842000');
});

it('falls back to an unscoped fact when no year-scoped one exists', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.federal_withholding',
        value: '111111',
        sourceType: 'interview_answer',
        taxYear: null,
    );

    $r = $this->resolver->resolve($this->user, $this->year, 'employer.federal_withholding');
    expect($r['value'])->toBe('111111');
});

// ── Two-tier known vs confirmed (§A.7) ─────────────────────────────────────

it('marks profile_field-sourced facts as known (confirmed=false)', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'profile.filing_status',
        value: 'single',
        sourceType: 'profile_field',
    );

    $r = $this->resolver->resolve($this->user, $this->year, 'profile.filing_status');
    expect($r['source'])->toBe('fact')->and($r['confirmed'])->toBeFalse();
});

it('marks interview/user-edit facts as confirmed=true', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'profile.filing_status',
        value: 'single',
        sourceType: 'user_edit',
    );

    $r = $this->resolver->resolve($this->user, $this->year, 'profile.filing_status');
    expect($r['confirmed'])->toBeTrue();
});

// ── Derivations (§A.6.3) ───────────────────────────────────────────────────

it('derives annualized gross from a paystub ytd_gross (end-of-year fraction = 1)', function () {
    makeSnapshot($this->user, $this->year); // no w2/se snapshot → derivation runs
    makeReadyPaystub($this->user, $this->year, [
        'ytd_gross' => '80000',       // dollars
        'pay_date' => '2026-12-31',   // day 365 of 365 → fraction 1.0
    ]);

    $r = $this->resolver->resolve($this->user, $this->year, 'income.annual_gross_cents');

    expect($r['value'])->toBe('8000000') // $80,000 → cents
        ->and($r['source'])->toBe('derived')
        ->and($r['source_ref'])->toContain('annualize_ytd_gross(doc:');
});

it('derives annualized federal withholding from a paystub ytd_federal_tax', function () {
    makeReadyPaystub($this->user, $this->year, [
        'ytd_federal_tax' => '12000',
        'pay_date' => '2026-12-31',
    ]);

    $r = $this->resolver->resolve($this->user, $this->year, 'employer.federal_withholding');

    expect($r['value'])->toBe('1200000')
        ->and($r['source'])->toBe('derived')
        ->and($r['source_ref'])->toContain('annualize_ytd_federal_tax(doc:');
});

it('derives annual withholding as per-period x frequency when no paystub ytd exists', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'pay.federal_withholding_per_period_cents',
        value: '80000',
        sourceType: 'interview_answer',
        taxYear: $this->year,
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'pay.frequency',
        value: 'biweekly',
        sourceType: 'interview_answer',
    );

    $r = $this->resolver->resolve($this->user, $this->year, 'employer.federal_withholding');

    // 80000 cents x 26 biweekly periods
    expect($r['value'])->toBe('2080000')
        ->and($r['source'])->toBe('derived')
        ->and($r['source_ref'])->toContain('per_period_times_frequency');
});

it('derives pay frequency from an anchored semimonthly span', function () {
    makeReadyPaystub($this->user, $this->year, [
        'pay_period_start' => '2026-01-01',
        'pay_period_end' => '2026-01-15',
    ]);

    $r = $this->resolver->resolve($this->user, $this->year, 'pay.frequency');
    expect($r['value'])->toBe('semimonthly')->and($r['source'])->toBe('derived');
});

it('derives biweekly from a 14-day non-anchored span', function () {
    makeReadyPaystub($this->user, $this->year, [
        'pay_period_start' => '2026-01-05',
        'pay_period_end' => '2026-01-18',
    ]);

    $r = $this->resolver->resolve($this->user, $this->year, 'pay.frequency');
    expect($r['value'])->toBe('biweekly');
});

it('returns null (falls through to ask) for an ambiguous pay span', function () {
    // Item 3 canonical span table: 6-8 weekly, 13-15 biweekly, 27-31 monthly,
    // 15-16 anchored → semimonthly; else null.
    // 10-day span (e.g., 2026-01-03 → 2026-01-12) is genuinely ambiguous → null.
    // NOTE: the old test used a 15-day unanchored span (Jan 3→17); that is now
    // biweekly per the Item 3 spec (13-15 → biweekly). Updated to 10-day span.
    makeReadyPaystub($this->user, $this->year, [
        'pay_period_start' => '2026-01-03',
        'pay_period_end' => '2026-01-12',   // 10 days inclusive — not in any bucket
    ]);

    expect($this->resolver->resolve($this->user, $this->year, 'pay.frequency'))->toBeNull();
});

it('derives spouse annual income from the profile monthly figure', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'spouse_income' => '5000', // monthly dollars
    ]);

    $r = $this->resolver->resolve($this->user, $this->year, 'spouse.annual_income_cents');

    expect($r['value'])->toBe('6000000') // 5000 x 12 x 100
        ->and($r['source'])->toBe('derived')
        ->and($r['source_ref'])->toContain('spouse_annual_from_profile');
});

it('derives contribution pct from 401k ytd over annual gross', function () {
    makeSnapshot($this->user, $this->year, [
        'w2_wages' => '10000000',        // $100k gross → income.annual_gross_cents
        'traditional_401k_ytd' => '1000000', // $10k
    ]);

    $r = $this->resolver->resolve($this->user, $this->year, 'employer.contribution_pct');

    expect($r['value'])->toBe('10') // 10% of gross
        ->and($r['source'])->toBe('derived')
        ->and($r['source_ref'])->toContain('contribution_pct_from_ytd');
});

it('derives age from birth year (tax_year - birth_year)', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'person.birth_year',
        value: '1985',
        sourceType: 'interview_answer',
    );

    // age_from_birth_year is not chain-wired (consumed by the engine in 14-06);
    // exercise the deterministic derivation directly via reflection.
    $method = new ReflectionMethod(ScenarioFactResolverService::class, 'deriveAgeFromBirthYear');
    $method->setAccessible(true);
    $r = $method->invoke($this->resolver, $this->user, $this->year, 'person.age');

    expect($r['value'])->toBe('41') // 2026 - 1985
        ->and($r['source'])->toBe('derived')
        ->and($r['confirmed'])->toBeFalse()
        ->and($r['source_ref'])->toContain('age_from_birth_year(fact:');
});

// ── §A.6.4 never-does ──────────────────────────────────────────────────────

it('never writes UserTaxFact rows during resolution', function () {
    makeSnapshot($this->user, $this->year, ['filing_status' => 'single', 'w2_wages' => '9000000']);
    $before = UserTaxFact::count();

    $this->resolver->resolveAll($this->user, $this->year);

    expect(UserTaxFact::count())->toBe($before);
});

it('resolveAll unions objective keys and skips scenario-only domains', function () {
    makeSnapshot($this->user, $this->year, ['filing_status' => 'single']);

    $all = $this->resolver->resolveAll($this->user, $this->year);

    expect($all)->toHaveKey('profile.filing_status')
        ->and($all)->toHaveKey('retirement.target_age')      // retirement objective
        ->and($all)->not->toHaveKey('bonus.expected_month'); // is_scenario_domain excluded
});
