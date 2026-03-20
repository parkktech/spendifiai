<?php

use App\Models\TaxDeduction;
use App\Models\Transaction;
use App\Models\UserTaxDeduction;

it('can list deductions for a year', function () {
    ['user' => $user, 'account' => $account] = createUserWithBankAndProfile();

    $response = $this->getJson('/api/v1/tax/deductions?year=2026');

    $response->assertOk()
        ->assertJsonStructure([
            'year',
            'deductions' => [
                'auto_detected',
                'profile_matched',
                'claimed',
            ],
            'questionnaire_remaining',
            'total_discovered',
            'total_estimated_savings',
        ]);
});

it('can scan transactions for deductions', function () {
    ['user' => $user, 'account' => $account] = createUserWithBankAndProfile();

    // Create a deduction with transaction keywords
    $deduction = TaxDeduction::create([
        'slug' => 'test-office-supplies',
        'name' => 'Office Supplies',
        'description' => 'Business office supplies',
        'category' => 'schedule_c',
        'subcategory' => 'Business',
        'detection_method' => 'transaction_scan',
        'transaction_keywords' => ['staples', 'office depot'],
        'is_active' => true,
        'sort_order' => 1,
        'is_credit' => false,
        'is_refundable' => false,
    ]);

    // Create matching transactions
    Transaction::factory()->count(3)->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'merchant_name' => 'STAPLES #123',
        'amount' => -75.00,
        'transaction_date' => '2026-02-15',
    ]);

    $response = $this->postJson('/api/v1/tax/deductions/scan', ['year' => 2026]);

    $response->assertOk()
        ->assertJsonPath('message', 'Deduction scan complete');

    // Verify user tax deduction was created
    expect(UserTaxDeduction::where('user_id', $user->id)
        ->where('tax_deduction_id', $deduction->id)
        ->where('tax_year', 2026)
        ->exists()
    )->toBeTrue();
});

it('can get questionnaire questions', function () {
    ['user' => $user] = createUserWithBankAndProfile();

    // Create questionnaire deductions
    TaxDeduction::create([
        'slug' => 'test-child-tax-credit',
        'name' => 'Child Tax Credit',
        'description' => 'Credit for children under 17',
        'category' => 'credit',
        'subcategory' => 'Family',
        'detection_method' => 'profile_question',
        'question_text' => 'Do you have children under age 17?',
        'is_active' => true,
        'is_credit' => true,
        'is_refundable' => true,
        'sort_order' => 1,
    ]);

    $response = $this->getJson('/api/v1/tax/deductions/questionnaire?year=2026');

    $response->assertOk()
        ->assertJsonStructure([
            'questions' => [['id', 'slug', 'name', 'question_text']],
            'total_remaining',
        ]);

    expect($response->json('total_remaining'))->toBeGreaterThanOrEqual(1);
});

it('can submit questionnaire answers', function () {
    ['user' => $user] = createUserWithBankAndProfile();

    $deduction = TaxDeduction::create([
        'slug' => 'test-educator-expenses',
        'name' => 'Educator Expenses',
        'description' => 'K-12 educator expenses',
        'category' => 'above_the_line',
        'subcategory' => 'Education',
        'detection_method' => 'profile_question',
        'question_text' => 'Are you a K-12 educator?',
        'max_amount' => 300,
        'is_active' => true,
        'is_credit' => false,
        'is_refundable' => false,
        'sort_order' => 1,
    ]);

    $response = $this->postJson('/api/v1/tax/deductions/questionnaire', [
        'year' => 2026,
        'answers' => [
            [
                'deduction_id' => $deduction->id,
                'answer' => ['response' => true],
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'eligible');

    $record = UserTaxDeduction::where('user_id', $user->id)
        ->where('tax_deduction_id', $deduction->id)
        ->first();

    expect($record)->not->toBeNull();
    expect($record->status)->toBe('eligible');
    expect($record->detected_from)->toBe('questionnaire');
});

it('can answer individual deduction question', function () {
    ['user' => $user] = createUserWithBankAndProfile();

    $deduction = TaxDeduction::create([
        'slug' => 'test-hsa-contributions',
        'name' => 'HSA Contributions',
        'description' => 'Health Savings Account contributions',
        'category' => 'above_the_line',
        'subcategory' => 'Health',
        'detection_method' => 'both',
        'question_text' => 'Do you contribute to an HSA?',
        'max_amount' => 4400,
        'is_active' => true,
        'is_credit' => false,
        'is_refundable' => false,
        'sort_order' => 1,
    ]);

    $response = $this->postJson("/api/v1/tax/deductions/{$deduction->id}/answer", [
        'year' => 2026,
        'answer' => ['response' => true, 'amount' => 3000],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'eligible');

    expect((float) $response->json('estimated_amount'))->toBe(3000.0);
});

it('can claim a deduction with actual amount', function () {
    ['user' => $user] = createUserWithBankAndProfile();

    $deduction = TaxDeduction::create([
        'slug' => 'test-mortgage-interest',
        'name' => 'Mortgage Interest',
        'description' => 'Home mortgage interest deduction',
        'category' => 'itemized',
        'subcategory' => 'Home',
        'detection_method' => 'both',
        'is_active' => true,
        'is_credit' => false,
        'is_refundable' => false,
        'sort_order' => 1,
    ]);

    $response = $this->postJson("/api/v1/tax/deductions/{$deduction->id}/claim", [
        'year' => 2026,
        'amount' => 12500.00,
        'notes' => 'Primary residence mortgage',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Deduction claimed');

    expect((float) $response->json('actual_amount'))->toBe(12500.0);

    $record = UserTaxDeduction::where('user_id', $user->id)
        ->where('tax_deduction_id', $deduction->id)
        ->first();

    expect($record->status)->toBe('claimed');
    expect($record->notes)->toBe('Primary residence mortgage');
});

it('marks negative response as not eligible', function () {
    ['user' => $user] = createUserWithBankAndProfile();

    $deduction = TaxDeduction::create([
        'slug' => 'test-ev-credit',
        'name' => 'EV Tax Credit',
        'description' => 'Electric vehicle purchase credit',
        'category' => 'credit',
        'subcategory' => 'Vehicle',
        'detection_method' => 'profile_question',
        'question_text' => 'Did you buy an electric vehicle?',
        'max_amount' => 7500,
        'is_active' => true,
        'is_credit' => true,
        'is_refundable' => false,
        'sort_order' => 1,
    ]);

    $response = $this->postJson("/api/v1/tax/deductions/{$deduction->id}/answer", [
        'year' => 2026,
        'answer' => ['response' => false],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'not_eligible');
});

it('deductions endpoint does not require profile complete middleware', function () {
    // createUserWithBank does NOT create a profile
    createUserWithBank();

    $response = $this->getJson('/api/v1/tax/deductions?year=2026');

    // Should be 200, NOT 403
    $response->assertOk();
});

it('profile matching detects eligible deductions', function () {
    ['user' => $user] = createUserWithBankAndProfile();

    // Create a deduction matching self-employed profile
    TaxDeduction::create([
        'slug' => 'test-home-office',
        'name' => 'Home Office',
        'description' => 'Home office deduction',
        'category' => 'schedule_c',
        'subcategory' => 'Business',
        'detection_method' => 'both',
        'max_amount' => 1500,
        'eligibility_rules' => ['requires_self_employed' => true],
        'is_active' => true,
        'is_credit' => false,
        'is_refundable' => false,
        'sort_order' => 1,
    ]);

    // The profile from createUserWithBankAndProfile has employment_type = 'self_employed'
    $response = $this->postJson('/api/v1/tax/deductions/scan', ['year' => 2026]);

    $response->assertOk();

    // Check that profile match found the deduction
    $match = UserTaxDeduction::where('user_id', $user->id)
        ->where('detected_from', 'profile_match')
        ->first();

    expect($match)->not->toBeNull();
    expect($match->status)->toBe('eligible');
});
