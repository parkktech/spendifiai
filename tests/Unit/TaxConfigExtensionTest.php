<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

// ── No-Claude / No-HTTP Guard ─────────────────────────────────────────────────
// Config reads must never trigger outbound HTTP calls.

it('makes zero outbound HTTP calls when reading tax config (no-Claude guard)', function () {
    Http::preventStrayRequests();

    // Exercise all new TAX-08 config paths
    $detection = config('tax-rules.2026.detection');
    $taxDetection = config('tax-detection');

    expect($detection)->toBeArray()
        ->and($taxDetection)->toBeArray();
});

// ── TAX-08: Existing Keys Untouched ──────────────────────────────────────────

it('leaves all pre-existing config/tax-rules.php 2026 keys intact', function () {
    // Brackets
    expect(config('tax-rules.2026.brackets.single'))->toBeArray()->not->toBeEmpty();
    expect(config('tax-rules.2026.brackets.married_joint'))->toBeArray()->not->toBeEmpty();
    expect(config('tax-rules.2026.brackets.married_separate'))->toBeArray()->not->toBeEmpty();
    expect(config('tax-rules.2026.brackets.head_of_household'))->toBeArray()->not->toBeEmpty();

    // Standard deduction
    expect(config('tax-rules.2026.standard_deduction.single'))->toBe(16_100);
    expect(config('tax-rules.2026.standard_deduction.married_joint'))->toBe(32_200);

    // Senior addition
    expect(config('tax-rules.2026.standard_deduction_senior_addition.single'))->toBe(2_050);

    // 401(k)
    expect(config('tax-rules.2026.401k.employee_deferral'))->toBe(24_500);
    expect(config('tax-rules.2026.401k.catchup_age_50_plus'))->toBe(8_000);
    expect(config('tax-rules.2026.401k.catchup_age_60_to_63'))->toBe(11_250);
    expect(config('tax-rules.2026.401k.mandatory_roth_catchup_threshold'))->toBe(150_000);

    // IRA
    expect(config('tax-rules.2026.ira.annual_limit'))->toBe(7_500);
    expect(config('tax-rules.2026.ira.catchup_age_50_plus'))->toBe(1_100);

    // HSA
    expect(config('tax-rules.2026.hsa.self_only'))->toBe(4_400);
    expect(config('tax-rules.2026.hsa.family'))->toBe(8_750);

    // SE tax
    expect(config('tax-rules.2026.se_tax.rate'))->toBe(0.153);
    expect(config('tax-rules.2026.se_tax.ss_wage_base'))->toBe(184_500);

    // QBI
    expect(config('tax-rules.2026.qbi.rate'))->toBe(0.20);
    expect(config('tax-rules.2026.qbi.minimum_deduction'))->toBe(400);

    // Roth optimization
    expect(config('tax-rules.2026.roth_optimization.prefer_roth_at_or_below'))->toBe(0.12);
    expect(config('tax-rules.2026.roth_optimization.prefer_traditional_at_or_above'))->toBe(0.32);
});

// ── TAX-08: Detection Block Present ──────────────────────────────────────────

it('exposes the detection block under the 2026 year key', function () {
    $detection = config('tax-rules.2026.detection');
    expect($detection)->toBeArray()->not->toBeEmpty();
});

// ── TAX-08: IRA Shared Limit Note ────────────────────────────────────────────

it('has ira_shared_limit equal to existing ira.annual_limit (D3 correctness)', function () {
    $shared = config('tax-rules.2026.detection.ira_shared_limit');
    $existing = config('tax-rules.2026.ira.annual_limit');
    expect($shared)->toBe($existing);
});

it('has ira_catchup_50_plus matching existing ira config', function () {
    $detectCatchup = config('tax-rules.2026.detection.ira_catchup_50_plus');
    $existing = config('tax-rules.2026.ira.catchup_age_50_plus');
    expect($detectCatchup)->toBe($existing);
});

// ── TAX-08: 457(b) / 403(b) Limits ─────────────────────────────────────────

it('has 457b and 403b employee limits matching 401k deferral', function () {
    $limit401k = config('tax-rules.2026.401k.employee_deferral');
    expect(config('tax-rules.2026.detection.457b_employee_limit'))->toBe($limit401k);
    expect(config('tax-rules.2026.detection.403b_employee_limit'))->toBe($limit401k);
});

it('has 457b pre-retirement catch-up multiplier of 2', function () {
    expect(config('tax-rules.2026.detection.457b_403b_pretire_catchup_multiplier'))->toBe(2);
});

// ── TAX-08: OBBBA Items Present ──────────────────────────────────────────────

it('has tips deduction cap and phaseouts present', function () {
    expect(config('tax-rules.2026.detection.tips_deduction_cap'))->toBe(25_000);
    expect(config('tax-rules.2026.detection.tips_phaseout_magi_single'))->toBe(150_000);
    expect(config('tax-rules.2026.detection.tips_phaseout_magi_mfj'))->toBe(300_000);
});

it('has overtime deduction caps and phaseouts present', function () {
    expect(config('tax-rules.2026.detection.ot_deduction_cap_single'))->toBeInt()->toBeGreaterThan(0);
    expect(config('tax-rules.2026.detection.ot_deduction_cap_mfj'))->toBe(25_000);
    expect(config('tax-rules.2026.detection.ot_phaseout_magi_single'))->toBe(150_000);
    expect(config('tax-rules.2026.detection.ot_phaseout_magi_mfj'))->toBe(300_000);
});

it('has senior deduction fields present', function () {
    expect(config('tax-rules.2026.detection.senior_deduction_amount'))->toBe(6_000);
    expect(config('tax-rules.2026.detection.senior_deduction_age_min'))->toBe(65);
    expect(config('tax-rules.2026.detection.senior_magi_single'))->toBe(75_000);
    expect(config('tax-rules.2026.detection.senior_magi_mfj'))->toBe(150_000);
});

it('has auto loan interest cap present', function () {
    expect(config('tax-rules.2026.detection.auto_loan_interest_cap'))->toBe(10_000);
});

it('has SALT cap present', function () {
    expect(config('tax-rules.2026.detection.salt_cap'))->toBe(40_000);
});

// ── TAX-08: Retirement / Benefits ────────────────────────────────────────────

it('has 415c limit present', function () {
    expect(config('tax-rules.2026.detection.section_415c_limit'))->toBeInt()->toBeGreaterThan(0);
});

it('has solo 401k employer share rate as a float', function () {
    expect(config('tax-rules.2026.detection.solo_401k_employer_share_rate'))->toBe(0.20);
});

it('has cash balance plan range and age minimum', function () {
    expect(config('tax-rules.2026.detection.cash_balance_plan_min'))->toBe(150_000);
    expect(config('tax-rules.2026.detection.cash_balance_plan_max'))->toBe(350_000);
    expect(config('tax-rules.2026.detection.cash_balance_age_min'))->toBe(45);
});

it('has QCD limit and age minimum present', function () {
    expect(config('tax-rules.2026.detection.qcd_limit'))->toBeInt()->toBeGreaterThan(0);
    expect(config('tax-rules.2026.detection.qcd_age_min'))->toBe(70);
});

it('has saver match start year of 2027', function () {
    expect(config('tax-rules.2026.detection.saver_match_starts_year'))->toBe(2027);
});

it('has section 127 limit present', function () {
    expect(config('tax-rules.2026.detection.section_127_limit'))->toBe(5_250);
});

it('has employer child care credit max present', function () {
    expect(config('tax-rules.2026.detection.employer_childcare_credit_max'))->toBe(500_000);
    expect(config('tax-rules.2026.detection.employer_childcare_credit_small_biz'))->toBe(600_000);
});

// ── TAX-08: Credits ───────────────────────────────────────────────────────────

it('has child tax credit amount', function () {
    expect(config('tax-rules.2026.detection.ctc_amount'))->toBe(2_200);
});

it('has adoption credit present', function () {
    expect(config('tax-rules.2026.detection.adoption_credit'))->toBeInt()->toBeGreaterThan(0);
});

it('has AOTC credit amount', function () {
    expect(config('tax-rules.2026.detection.aotc_credit'))->toBe(2_500);
});

it('has 529 K-12 annual limit and lifetime to-Roth limit', function () {
    expect(config('tax-rules.2026.detection.section_529_k12_annual'))->toBe(20_000);
    expect(config('tax-rules.2026.detection.section_529_to_roth_lifetime'))->toBe(35_000);
});

it('has Trump Account constants present', function () {
    expect(config('tax-rules.2026.detection.trump_account_annual'))->toBe(5_000);
    expect(config('tax-rules.2026.detection.trump_account_employer_exclusion'))->toBe(2_500);
    expect(config('tax-rules.2026.detection.trump_account_seed'))->toBe(1_000);
});

// ── TAX-08: Charitable ───────────────────────────────────────────────────────

it('has charitable non-itemizer limits and floor rate', function () {
    expect(config('tax-rules.2026.detection.charitable_non_itemizer_single'))->toBe(1_000);
    expect(config('tax-rules.2026.detection.charitable_non_itemizer_mfj'))->toBe(2_000);
    expect(config('tax-rules.2026.detection.charitable_agi_floor_rate'))->toBe(0.005);
    expect(config('tax-rules.2026.detection.charitable_acknowledgment_floor'))->toBe(250);
    expect(config('tax-rules.2026.detection.charitable_non_cash_appraisal'))->toBe(5_000);
});

// ── TAX-08: Business ─────────────────────────────────────────────────────────

it('has section 179 limit and phaseout', function () {
    expect(config('tax-rules.2026.detection.section_179_limit'))->toBe(2_560_000);
    expect(config('tax-rules.2026.detection.section_179_phaseout_start'))->toBe(4_090_000);
    expect(config('tax-rules.2026.detection.section_179_gvwr_lbs'))->toBe(6_000);
});

it('has section 195 immediate deduction amount', function () {
    expect(config('tax-rules.2026.detection.section_195_immediate'))->toBe(5_000);
});

it('has de minimis safe harbor amount', function () {
    expect(config('tax-rules.2026.detection.de_minimis_safe_harbor'))->toBe(2_500);
});

it('has home office simplified rate and cap', function () {
    expect(config('tax-rules.2026.detection.home_office_simplified_rate'))->toBe(5);
    expect(config('tax-rules.2026.detection.home_office_simplified_cap'))->toBe(1_500);
});

it('has standard mileage rate as a float', function () {
    $rate = config('tax-rules.2026.detection.standard_mileage_rate');
    expect($rate)->toBeFloat()->toBe(0.725);
});

it('has cruise convention cap present', function () {
    expect(config('tax-rules.2026.detection.cruise_convention_cap'))->toBe(2_000);
});

it('has MACRS recovery periods for rental solar pool and guard dog', function () {
    expect(config('tax-rules.2026.detection.macrs_rental_solar_years'))->toBe(5);
    expect(config('tax-rules.2026.detection.macrs_pool_landscape_years'))->toBe(15);
    expect(config('tax-rules.2026.detection.guard_dog_macrs_years'))->toBe(7);
});

// ── TAX-08: §25D / §30D Retro Windows ────────────────────────────────────────

it('has section 25D credit rate and educational recovery range', function () {
    expect(config('tax-rules.2026.detection.section_25d_credit_rate'))->toBe(0.30);
    expect(config('tax-rules.2026.detection.section_25d_recovery_min'))->toBe(10_000);
    expect(config('tax-rules.2026.detection.section_25d_recovery_max'))->toBe(20_000);
    // Verify the range is coherent
    expect(config('tax-rules.2026.detection.section_25d_recovery_max'))
        ->toBeGreaterThan(config('tax-rules.2026.detection.section_25d_recovery_min'));
});

it('has amended return lookback of 3 years', function () {
    expect(config('tax-rules.2026.detection.amended_return_lookback_yr'))->toBe(3);
});

// ── TAX-08: Investor / Estate / Losses ───────────────────────────────────────

it('has LTCG zero bracket estimates present', function () {
    expect(config('tax-rules.2026.detection.ltcg_zero_bracket_single'))->toBeInt()->toBeGreaterThan(0);
    expect(config('tax-rules.2026.detection.ltcg_zero_bracket_mfj'))->toBeInt()->toBeGreaterThan(0);
    // MFJ should be roughly double single
    expect(config('tax-rules.2026.detection.ltcg_zero_bracket_mfj'))
        ->toBeGreaterThan(config('tax-rules.2026.detection.ltcg_zero_bracket_single'));
});

it('has NIIT rate and thresholds', function () {
    expect(config('tax-rules.2026.detection.niit_rate'))->toBe(0.038);
    expect(config('tax-rules.2026.detection.niit_threshold_single'))->toBe(200_000);
    expect(config('tax-rules.2026.detection.niit_threshold_mfj'))->toBe(250_000);
});

it('has gift exclusion annual present', function () {
    expect(config('tax-rules.2026.detection.gift_exclusion_annual'))->toBeInt()->toBeGreaterThan(0);
});

it('has estate exemption constants present', function () {
    expect(config('tax-rules.2026.detection.estate_exemption_single'))->toBeInt()->toBeGreaterThan(10_000_000);
    expect(config('tax-rules.2026.detection.estate_exemption_joint'))
        ->toBeGreaterThan(config('tax-rules.2026.detection.estate_exemption_single'));
});

it('has QSBS constants present', function () {
    expect(config('tax-rules.2026.detection.qsbs_cap_per_issuer'))->toBeInt()->toBeGreaterThan(0);
    expect(config('tax-rules.2026.detection.qsbs_gross_asset_test'))->toBeInt()->toBeGreaterThan(0);
    expect(config('tax-rules.2026.detection.qsbs_gain_pct_3yr'))->toBe(0.50);
    expect(config('tax-rules.2026.detection.qsbs_gain_pct_4yr'))->toBe(0.75);
    expect(config('tax-rules.2026.detection.qsbs_gain_pct_5yr'))->toBe(1.00);
});

it('has section 1244 loss caps', function () {
    expect(config('tax-rules.2026.detection.section_1244_loss_cap_single'))->toBe(50_000);
    expect(config('tax-rules.2026.detection.section_1244_loss_cap_mfj'))->toBe(100_000);
});

it('has section 1341 threshold', function () {
    expect(config('tax-rules.2026.detection.section_1341_threshold'))->toBe(3_000);
});

it('has kiddie tax age max', function () {
    expect(config('tax-rules.2026.detection.kiddie_tax_age_max'))->toBe(24);
});

it('has QOF mandatory recognition year of 2026', function () {
    expect(config('tax-rules.2026.detection.qof_mandatory_recognition_year'))->toBe(2026);
});

// ── TAX-08: Medical / Misc ────────────────────────────────────────────────────

it('has medical AGI floor of 7.5%', function () {
    expect(config('tax-rules.2026.detection.medical_agi_floor'))->toBe(0.075);
});

it('has medical lodging per night', function () {
    expect(config('tax-rules.2026.detection.medical_lodging_per_night'))->toBe(50);
});

it('has student loan interest cap', function () {
    expect(config('tax-rules.2026.detection.student_loan_interest_cap'))->toBe(2_500);
});

it('has educator expense cap', function () {
    expect(config('tax-rules.2026.detection.educator_expense_cap'))->toBe(300);
});

it('has FEIE limit present', function () {
    expect(config('tax-rules.2026.detection.feie_limit'))->toBeInt()->toBeGreaterThan(100_000);
});

it('has Augusta day cap of 14', function () {
    expect(config('tax-rules.2026.detection.augusta_day_cap'))->toBe(14);
});

it('has Medicare HSA lookback months of 6', function () {
    expect(config('tax-rules.2026.detection.medicare_hsa_lookback_months'))->toBe(6);
});

// ── TAX-08: ACA / Safe Harbor ────────────────────────────────────────────────

it('has ACA FPL cliff percentage and approximate thresholds', function () {
    expect(config('tax-rules.2026.detection.aca_fpl_pct'))->toBe(4.00);
    expect(config('tax-rules.2026.detection.aca_fpl_threshold_single'))->toBeInt()->toBeGreaterThan(50_000);
    expect(config('tax-rules.2026.detection.aca_fpl_threshold_family4'))->toBeInt()->toBeGreaterThan(100_000);
});

it('has estimated tax safe harbor rates and high AGI threshold', function () {
    expect(config('tax-rules.2026.detection.estimated_tax_safe_harbor_rate'))->toBe(1.00);
    expect(config('tax-rules.2026.detection.estimated_tax_safe_harbor_high'))->toBe(1.10);
    expect(config('tax-rules.2026.detection.estimated_tax_high_agi'))->toBe(150_000);
});

it('has EITC investment income limit present', function () {
    expect(config('tax-rules.2026.detection.eitc_investment_income_limit'))->toBeInt()->toBeGreaterThan(0);
});

// ── TAX-08: Gambling Loss Percentage ─────────────────────────────────────────

it('has gambling loss pct deductible of 0.90 (not 1.00 — never fully deductible from 2026)', function () {
    $pct = config('tax-rules.2026.detection.gambling_loss_pct_deductible');
    expect($pct)->toBe(0.90)
        ->toBeLessThan(1.00); // Never surface as fully deductible
});

// ── TAX-08: config/tax-detection.php Keys ────────────────────────────────────

it('exposes tax-detection materiality block with all required keys', function () {
    Http::preventStrayRequests();

    expect(config('tax-detection.materiality'))->toBeArray()
        ->toHaveKeys([
            'single_txn_auto_floor_cents',
            'recurring_pattern_annual_cents',
            'single_txn_interrogate_cents',
            'address_match_always',
            'loan_servicer_always',
        ]);

    expect(config('tax-detection.materiality.single_txn_auto_floor_cents'))->toBe(10_000);
    expect(config('tax-detection.materiality.recurring_pattern_annual_cents'))->toBe(50_000);
    expect(config('tax-detection.materiality.single_txn_interrogate_cents'))->toBe(100_000);
    expect(config('tax-detection.materiality.address_match_always'))->toBeTrue();
    expect(config('tax-detection.materiality.loan_servicer_always'))->toBeTrue();
});

it('exposes tax-detection confidence block', function () {
    expect(config('tax-detection.confidence'))->toBeArray()
        ->toHaveKeys([
            'suggested_confirm_threshold',
            'conditional_threshold',
            'specialist_threshold',
        ]);
    expect(config('tax-detection.confidence.suggested_confirm_threshold'))->toBe(0.85);
    expect(config('tax-detection.confidence.conditional_threshold'))->toBe(0.60);
    expect(config('tax-detection.confidence.specialist_threshold'))->toBe(0.40);
});

it('exposes tax-detection facts block with reconfirm_months of 12', function () {
    expect(config('tax-detection.facts.reconfirm_months'))->toBe(12);
});

it('exposes staleness_days as 90', function () {
    expect(config('tax-detection.staleness_days'))->toBe(90);
});

it('exposes onboarding_history_months as 36', function () {
    expect(config('tax-detection.onboarding_history_months'))->toBe(36);
});

it('exposes doc_request_labels as a non-empty array', function () {
    $labels = config('tax-detection.doc_request_labels');
    expect($labels)->toBeArray()->not->toBeEmpty();
    expect($labels)->toHaveKey('mileage_log');
    expect($labels)->toHaveKey('solar_invoice');
    expect($labels)->toHaveKey('ira_contribution_record');
});

it('exposes the rules registry with all sunset rules', function () {
    $rules = config('tax-detection.rules');
    expect($rules)->toBeArray()->not->toBeEmpty();
    expect($rules)->toHaveKeys([
        'tips_deduction',
        'ot_deduction',
        'senior_deduction',
        'auto_loan_interest',
        'salt_deduction_cap',
        'qof_recognition',
        'residential_energy_credit_25d',
        'ev_credit_30d',
        'residential_solar_2026_primary_home',
        'gambling_losses_fully_deductible',
    ]);
});

it('never-surface rules have band=suppress', function () {
    expect(config('tax-detection.rules.residential_solar_2026_primary_home.band'))->toBe('suppress');
    expect(config('tax-detection.rules.gambling_losses_fully_deductible.band'))->toBe('suppress');
});

it('expired credits have status=expired', function () {
    expect(config('tax-detection.rules.residential_energy_credit_25d.status'))->toBe('expired');
    expect(config('tax-detection.rules.ev_credit_30d.status'))->toBe('expired');
});
