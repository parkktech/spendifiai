<?php

use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Profile saves dispatch BuildIncomeOptimizationProfile on the sync queue,
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

// ── Save New Profile Fields ──

it('can save enhanced profile fields', function () {
    $user = createAuthenticatedUser();

    $response = $this->postJson('/api/v1/profile/financial', [
        'employment_type' => 'employed',
        'is_student' => true,
        'school_name' => 'MIT',
        'enrollment_status' => 'full_time',
        'has_hsa' => true,
        'has_ira' => true,
        'ira_type' => 'roth',
    ]);

    $response->assertOk();

    $profile = UserFinancialProfile::where('user_id', $user->id)->first();
    expect($profile->is_student)->toBeTrue();
    expect($profile->school_name)->toBe('MIT');
    expect($profile->enrollment_status)->toBe('full_time');
    expect($profile->has_hsa)->toBeTrue();
    expect($profile->has_ira)->toBeTrue();
    expect($profile->ira_type)->toBe('roth');
});

// ── Retrieve Profile With New Fields ──

it('returns enhanced fields in profile response', function () {
    $user = createAuthenticatedUser();

    UserFinancialProfile::create([
        'user_id' => $user->id,
        'employment_type' => 'self_employed',
        'is_student' => false,
        'has_hsa' => true,
        'has_529_plan' => true,
        'is_military' => true,
    ]);

    $response = $this->getJson('/api/v1/profile/financial');

    $response->assertOk()
        ->assertJsonPath('profile.has_hsa', true)
        ->assertJsonPath('profile.has_529_plan', true)
        ->assertJsonPath('profile.is_military', true);
});

// ── Spouse Fields ──

it('can save spouse information', function () {
    $user = createAuthenticatedUser();

    $response = $this->postJson('/api/v1/profile/financial', [
        'spouse_name' => 'Jane Doe',
        'spouse_employment_type' => 'employed',
        'spouse_income' => 5000,
    ]);

    $response->assertOk();

    $profile = UserFinancialProfile::where('user_id', $user->id)->first();
    expect($profile->spouse_name)->toBe('Jane Doe');
    expect($profile->spouse_employment_type)->toBe('employed');
});

// ── Encrypted Fields ──

it('stores spouse_income as encrypted text', function () {
    $user = createAuthenticatedUser();

    $this->postJson('/api/v1/profile/financial', [
        'spouse_income' => 7500,
    ]);

    $profile = UserFinancialProfile::where('user_id', $user->id)->first();

    // Encrypted field should be accessible via model cast
    expect($profile->spouse_income)->not->toBeNull();

    // Raw DB should not contain plain number
    $raw = \Illuminate\Support\Facades\DB::table('user_financial_profiles')
        ->where('user_id', $user->id)
        ->value('spouse_income');

    expect($raw)->not->toBe('7500');
    expect($raw)->not->toBe(7500);
});

// ── Backwards Compatibility ──

it('existing profiles are not affected by new fields', function () {
    $user = createAuthenticatedUser();

    $profile = UserFinancialProfile::create([
        'user_id' => $user->id,
        'employment_type' => 'employed',
        'tax_filing_status' => 'single',
    ]);

    // New fields should default to false/null
    expect($profile->is_student)->toBeFalsy();
    expect($profile->has_hsa)->toBeFalsy();
    expect($profile->spouse_name)->toBeNull();
    expect($profile->ira_type)->toBeNull();
});

// ── Childcare Fields ──

it('can save childcare expense details', function () {
    $user = createAuthenticatedUser();

    $response = $this->postJson('/api/v1/profile/financial', [
        'has_childcare_expenses' => true,
        'childcare_annual_cost' => 12000,
    ]);

    $response->assertOk();

    $profile = UserFinancialProfile::where('user_id', $user->id)->first();
    expect($profile->has_childcare_expenses)->toBeTrue();
});

// ── Fix 1: IRA Multi-Select ──

it('saves ira_types as a JSON array and backfills ira_type from first element', function () {
    $user = createAuthenticatedUser();

    $response = $this->postJson('/api/v1/profile/financial', [
        'has_ira' => true,
        'ira_types' => ['traditional', 'roth'],
    ]);

    $response->assertOk();

    $profile = UserFinancialProfile::where('user_id', $user->id)->first();
    expect($profile->has_ira)->toBeTrue();
    expect($profile->ira_types)->toBe(['traditional', 'roth']);
    // Legacy backward-compat: ira_type = first element
    expect($profile->ira_type)->toBe('traditional');
});

it('accepts single ira_types value and keeps backward compat', function () {
    $user = createAuthenticatedUser();

    $this->postJson('/api/v1/profile/financial', [
        'has_ira' => true,
        'ira_types' => ['roth'],
    ])->assertOk();

    $profile = UserFinancialProfile::where('user_id', $user->id)->first();
    expect($profile->ira_types)->toBe(['roth']);
    expect($profile->ira_type)->toBe('roth');
});

it('validates ira_types values against allowed enum', function () {
    createAuthenticatedUser();

    $this->postJson('/api/v1/profile/financial', [
        'ira_types' => ['traditional', 'invalid_type'],
    ])->assertStatus(422);
});

it('returns derived_accounts block from GET profile endpoint', function () {
    $user = createAuthenticatedUser();

    UserFinancialProfile::create([
        'user_id' => $user->id,
        'has_hsa' => false,
        'has_ira' => true,
        'ira_types' => ['roth', 'traditional'],
    ]);

    $response = $this->getJson('/api/v1/profile/financial');

    $response->assertOk()
        ->assertJsonStructure([
            'profile',
            'derived_accounts' => [
                'hsa' => ['value', 'source'],
                'ira' => ['value', 'source'],
                'ira_types' => [
                    'traditional' => ['value', 'source'],
                    'roth' => ['value', 'source'],
                ],
            ],
        ]);
});

it('writes finance.has_hsa=yes user_edit fact when has_hsa is saved as true', function () {
    $user = createAuthenticatedUser();

    $this->postJson('/api/v1/profile/financial', [
        'has_hsa' => true,
    ])->assertOk();

    $fact = UserTaxFact::currentFact($user->id, 'finance.has_hsa');
    expect($fact)->not->toBeNull();
    expect($fact->value)->toBe('yes');
    expect($fact->source_type)->toBe('user_edit');
});

it('writes finance.has_hsa=no user_edit fact when has_hsa is unchecked', function () {
    $user = createAuthenticatedUser();

    $this->postJson('/api/v1/profile/financial', [
        'has_hsa' => false,
    ])->assertOk();

    $fact = UserTaxFact::currentFact($user->id, 'finance.has_hsa');
    expect($fact)->not->toBeNull();
    expect($fact->value)->toBe('no');
    expect($fact->source_type)->toBe('user_edit');
});

it('writes finance.has_ira=yes user_edit fact when has_ira is saved as true', function () {
    $user = createAuthenticatedUser();

    $this->postJson('/api/v1/profile/financial', [
        'has_ira' => true,
    ])->assertOk();

    $fact = UserTaxFact::currentFact($user->id, 'finance.has_ira');
    expect($fact)->not->toBeNull();
    expect($fact->value)->toBe('yes');
    expect($fact->source_type)->toBe('user_edit');
});

it('derived_accounts hsa block reflects finance.has_hsa=no fact', function () {
    $user = createAuthenticatedUser();

    UserFinancialProfile::create(['user_id' => $user->id]);

    // Simulate the N/A exclusion fact written by TaxDocumentController
    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'finance.has_hsa',
        value: 'no',
        sourceType: 'user_edit',
        label: 'HSA N/A',
    );

    $response = $this->getJson('/api/v1/profile/financial');

    $response->assertOk()
        ->assertJsonPath('derived_accounts.hsa.value', 'no')
        ->assertJsonPath('derived_accounts.hsa.source', 'your answers');
});

it('derived_accounts ira_types reflect IRA YTD contribution facts from interview', function () {
    $user = createAuthenticatedUser();
    UserFinancialProfile::create(['user_id' => $user->id]);

    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'ira.traditional_ytd_contribution_cents',
        value: '600000',
        sourceType: 'interview_answer',
    );
    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'ira.roth_ytd_contribution_cents',
        value: '350000',
        sourceType: 'interview_answer',
    );

    $response = $this->getJson('/api/v1/profile/financial');

    $response->assertOk()
        ->assertJsonPath('derived_accounts.ira.value', 'yes')
        ->assertJsonPath('derived_accounts.ira_types.traditional.value', true)
        ->assertJsonPath('derived_accounts.ira_types.roth.value', true);
});
