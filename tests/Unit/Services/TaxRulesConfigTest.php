<?php

use Illuminate\Support\Facades\Config;

it('has the 2026 tax year key in tax-rules config', function () {
    expect(config('tax-rules'))->toHaveKey(2026);
});

it('has all four filing statuses in 2026 brackets', function () {
    $brackets = config('tax-rules.2026.brackets');

    expect($brackets)->toHaveKey('single')
        ->and($brackets)->toHaveKey('married_joint')
        ->and($brackets)->toHaveKey('married_separate')
        ->and($brackets)->toHaveKey('head_of_household');
});

it('has bracket lists starting at from=0 for all filing statuses', function () {
    $statuses = ['single', 'married_joint', 'married_separate', 'head_of_household'];

    foreach ($statuses as $status) {
        $brackets = config("tax-rules.2026.brackets.{$status}");
        expect($brackets[0]['from'])->toBe(0, "First bracket for {$status} must start at 0");
    }
});

it('has bracket lists ending with to=null for all filing statuses', function () {
    $statuses = ['single', 'married_joint', 'married_separate', 'head_of_household'];

    foreach ($statuses as $status) {
        $brackets = config("tax-rules.2026.brackets.{$status}");
        $last = end($brackets);
        expect($last['to'])->toBeNull("Last bracket for {$status} must have to=null");
    }
});

it('has standard_deduction for all four filing statuses', function () {
    $deductions = config('tax-rules.2026.standard_deduction');

    expect($deductions)->toHaveKey('single')
        ->and($deductions)->toHaveKey('married_joint')
        ->and($deductions)->toHaveKey('married_separate')
        ->and($deductions)->toHaveKey('head_of_household');

    foreach ($deductions as $status => $amount) {
        expect($amount)->toBeGreaterThan(0, "standard_deduction for {$status} must be positive");
    }
});

it('has standard_deduction_senior_addition for all four filing statuses', function () {
    $additions = config('tax-rules.2026.standard_deduction_senior_addition');

    expect($additions)->toHaveKey('single')
        ->and($additions)->toHaveKey('married_joint')
        ->and($additions)->toHaveKey('married_separate')
        ->and($additions)->toHaveKey('head_of_household');
});

it('has 401k sub-keys present and non-empty', function () {
    $cfg = config('tax-rules.2026.401k');

    expect($cfg)->toHaveKey('employee_deferral')
        ->and($cfg)->toHaveKey('catchup_age_50_plus')
        ->and($cfg)->toHaveKey('catchup_age_60_to_63')
        ->and($cfg)->toHaveKey('mandatory_roth_catchup_threshold')
        ->and($cfg)->toHaveKey('highly_compensated_threshold');

    expect($cfg['employee_deferral'])->toBeGreaterThan(0);
    expect($cfg['catchup_age_50_plus'])->toBeGreaterThan(0);
    expect($cfg['catchup_age_60_to_63'])->toBeGreaterThan(0);
});

it('has ira sub-keys present and non-empty', function () {
    $cfg = config('tax-rules.2026.ira');

    expect($cfg)->toHaveKey('annual_limit')
        ->and($cfg)->toHaveKey('catchup_age_50_plus')
        ->and($cfg)->toHaveKey('roth_phaseout')
        ->and($cfg)->toHaveKey('traditional_deduction_phaseout_covered')
        ->and($cfg)->toHaveKey('traditional_deduction_phaseout_spouse_covered');

    expect($cfg['annual_limit'])->toBeGreaterThan(0);
    expect($cfg['catchup_age_50_plus'])->toBeGreaterThan(0);
});

it('has hsa sub-keys present and non-empty', function () {
    $cfg = config('tax-rules.2026.hsa');

    expect($cfg)->toHaveKey('self_only')
        ->and($cfg)->toHaveKey('family')
        ->and($cfg)->toHaveKey('catchup_age_55_plus');

    expect($cfg['self_only'])->toBeGreaterThan(0);
    expect($cfg['family'])->toBeGreaterThan(0);
    expect($cfg['family'])->toBeGreaterThan($cfg['self_only']);
});

it('has se_tax sub-keys present and non-empty', function () {
    $cfg = config('tax-rules.2026.se_tax');

    expect($cfg)->toHaveKey('net_earnings_multiplier')
        ->and($cfg)->toHaveKey('rate')
        ->and($cfg)->toHaveKey('ss_rate')
        ->and($cfg)->toHaveKey('medicare_rate')
        ->and($cfg)->toHaveKey('ss_wage_base')
        ->and($cfg)->toHaveKey('deductible_fraction');

    expect($cfg['ss_wage_base'])->toBeGreaterThan(0);
});

it('has qbi sub-keys present and non-empty', function () {
    $cfg = config('tax-rules.2026.qbi');

    expect($cfg)->toHaveKey('rate')
        ->and($cfg)->toHaveKey('phase_out_start_single')
        ->and($cfg)->toHaveKey('phase_out_start_joint')
        ->and($cfg)->toHaveKey('phase_out_window_single')
        ->and($cfg)->toHaveKey('phase_out_window_joint')
        ->and($cfg)->toHaveKey('minimum_deduction')
        ->and($cfg)->toHaveKey('minimum_qbi_for_floor');

    expect($cfg['rate'])->toBe(0.20);
});

it('has roth_optimization sub-keys present and non-empty', function () {
    $cfg = config('tax-rules.2026.roth_optimization');

    expect($cfg)->toHaveKey('prefer_roth_at_or_below')
        ->and($cfg)->toHaveKey('prefer_traditional_at_or_above');

    expect($cfg['prefer_roth_at_or_below'])->toBeLessThan($cfg['prefer_traditional_at_or_above']);
});

it('has the assumed section 603 mandatory roth catchup threshold key', function () {
    // This value is flagged as [ASSUMED] in config — exact 2026 indexed amount needs verification.
    // The test asserts the key exists and is readable from config, not a specific hardcoded value.
    $threshold = config('tax-rules.2026.401k.mandatory_roth_catchup_threshold');

    expect($threshold)->toBeGreaterThan(0);
    expect($threshold)->toBeInt();
});

it('brackets are ordered with ascending from values for all filing statuses', function () {
    $statuses = ['single', 'married_joint', 'married_separate', 'head_of_household'];

    foreach ($statuses as $status) {
        $brackets = config("tax-rules.2026.brackets.{$status}");
        $prevFrom = -1;

        foreach ($brackets as $bracket) {
            expect($bracket['from'])->toBeGreaterThan($prevFrom, "Brackets for {$status} must be in ascending order");
            $prevFrom = $bracket['from'];
        }
    }
});
