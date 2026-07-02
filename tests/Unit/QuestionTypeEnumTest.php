<?php

use App\Enums\QuestionType;

// ─── FEED-01: QuestionType::Optimization additive enum case ──────────────────

it('optimization case exists with value optimization', function () {
    expect(QuestionType::Optimization->value)->toBe('optimization');
});

it('from optimization round-trips correctly', function () {
    $case = QuestionType::from('optimization');
    expect($case)->toBe(QuestionType::Optimization);
    expect($case->value)->toBe('optimization');
});

it('tryFrom optimization returns enum case', function () {
    expect(QuestionType::tryFrom('optimization'))->toBe(QuestionType::Optimization);
});

it('tryFrom unknown value returns null', function () {
    expect(QuestionType::tryFrom('not_a_real_question_type_xyz'))->toBeNull();
});

it('has all expected cases including optimization', function () {
    $cases = QuestionType::cases();
    $values = array_map(fn (QuestionType $c) => $c->value, $cases);

    expect($values)->toContain('category')
        ->toContain('business_personal')
        ->toContain('split')
        ->toContain('confirm')
        ->toContain('optimization');
});
