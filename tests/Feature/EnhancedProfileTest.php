<?php

use App\Models\UserFinancialProfile;

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
