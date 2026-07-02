<?php

/*
|--------------------------------------------------------------------------
| Optimization objectives — fact-requirements map (SCENARIOS-SPEC §A.3)
|--------------------------------------------------------------------------
|
| ADDITIVE config (D2 config-not-code precedent). This file is the SINGLE
| source of truth for:
|   - the three readiness objectives (take_home, tax_burden, retirement) and
|     their per-fact source-priority chains (§A.2);
|   - the bonus_election SCENARIO DOMAIN (D15 groundwork consumed by 14-09 —
|     tagged is_scenario_domain, NOT a readiness objective);
|   - resolver fact aliases (§A.1.3);
|   - the pay-frequency periods map (§A.3, [M8] — lives ONLY here);
|   - deterministic question_templates (§A.3) — SCN-03's zero-Claude gap
|     questions; and
|   - prerequisite gate pairs (§A.4.3).
|
| Merge-fix compliance (SCENARIOS-SPEC §M):
|   [M1] pay.frequency (not income.pay_frequency)
|   [M2] hsa.coverage_type (not health.hsa_coverage_type)
|   [M3] NO w4.filing_status ↔ profile.filing_status alias (distinct facts)
|   [M4] objective ids: take_home / tax_burden / retirement
|   [M8] pay_periods_per_year lives here only
|   [M12] w4_on_file optional-with-suppression (suppresses k1_delta)
|
| Chain step grammar (§A.2): 'snapshot:{column}' | 'fact' | 'profile:{field}'
|   | 'derive:{rule}' | 'ask' | 'ask:{fact_key}'. The resolver walks each chain
| in order and stops at the first hit.
|
| 'blocking' values: true | false | 'conditional' (with a 'condition' note).
|
*/

return [

    'objectives' => [

        // ── Objective: take_home — "more money in your paycheck now" ────────
        'take_home' => [
            'label' => 'Take-home income',
            'facts' => [
                // Shared core (C1–C3)
                'filing_status' => [
                    'canonical_key' => 'profile.filing_status',   // [M3] confirmed status, NOT w4.*
                    'blocking' => true,
                    'chain' => ['fact', 'snapshot:filing_status', 'profile:tax_filing_status', 'ask'],
                ],
                'annual_gross_cents' => [
                    'canonical_key' => 'income.annual_gross_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:w2_wages+self_employment_income', 'derive:annualize_ytd_gross',
                        'snapshot:bank_deposit_total', 'fact', 'ask'],
                ],
                'pay_frequency' => [
                    'canonical_key' => 'pay.frequency',
                    'blocking' => true,
                    'chain' => ['derive:frequency_from_paystub', 'fact', 'ask'],
                ],
                'birth_year' => [
                    'canonical_key' => 'person.birth_year',
                    'blocking' => false, // optional for take_home (catch-up precision only)
                    'chain' => ['fact', 'ask'],
                ],
                // take_home-specific (T2–T9)
                'gross_per_period_cents' => [
                    'canonical_key' => 'pay.gross_per_period_cents',
                    'blocking' => true,
                    'chain' => ['derive:paystub_gross_pay', 'derive:annual_gross_over_periods', 'ask'],
                ],
                'federal_withholding_annual' => [
                    'canonical_key' => 'employer.federal_withholding',
                    'blocking' => true,
                    'chain' => ['fact', 'derive:annualize_ytd_federal_tax',
                        'derive:per_period_times_frequency', 'ask:pay.federal_withholding_per_period_cents'],
                ],
                'w4_on_file' => [
                    'canonical_key' => 'w4.dependents_claimed', // + w4.filing_status sibling
                    'blocking' => false,
                    'suppresses' => 'k1_delta',                 // [M12] optional-with-suppression
                    'chain' => ['fact', 'ask'],
                ],
                'w4_filing_status' => [
                    'canonical_key' => 'w4.filing_status',      // [M3] W-4-on-file evidence, distinct fact
                    'blocking' => false,
                    'suppresses' => 'k1_delta',
                    'chain' => ['fact', 'ask'],
                ],
                'dependents_count' => [
                    'canonical_key' => 'family.dependents_count',
                    'blocking' => true,
                    'chain' => ['fact', 'ask'],
                ],
                'qualifying_children_under_17' => [
                    'canonical_key' => 'family.qualifying_children_under_17',
                    'blocking' => false, // surfaced when dependents_count > 0
                    'condition' => 'family.dependents_count > 0',
                    'chain' => ['fact', 'ask'],
                ],
                'traditional_401k_ytd_cents' => [
                    'canonical_key' => 'retirement.traditional_401k_ytd_cents',
                    'blocking' => true, // confirmed-zero allowed
                    'chain' => ['snapshot:traditional_401k_ytd', 'fact', 'ask'],
                ],
                'roth_401k_ytd_cents' => [
                    'canonical_key' => 'retirement.roth_401k_ytd_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:roth_401k_ytd', 'fact', 'ask'],
                ],
                'hsa_ytd_cents' => [
                    'canonical_key' => 'hsa.ytd_contribution_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:hsa_ytd', 'fact', 'ask'],
                ],
                'fsa_ytd_cents' => [
                    'canonical_key' => 'benefits.fsa_ytd_cents',
                    'blocking' => true,
                    'chain' => ['fact', 'ask'],
                ],
                'extra_withholding_per_period_cents' => [
                    'canonical_key' => 'w4.extra_withholding_per_period_cents',
                    'blocking' => false, // default '0'
                    'chain' => ['fact', 'ask'],
                ],
                'prior_year_federal_liability_cents' => [
                    'canonical_key' => 'prior_year.federal_liability_cents',
                    'blocking' => false, // safe-harbor guardrail
                    'chain' => ['fact', 'ask'],
                ],
                'spouse_annual_income_cents' => [
                    'canonical_key' => 'spouse.annual_income_cents',
                    'blocking' => false, // surfaced only when filing_status = married_joint
                    'condition' => 'profile.filing_status = married_joint',
                    'chain' => ['derive:spouse_annual_from_profile', 'fact', 'ask'],
                ],
            ],
        ],

        // ── Objective: tax_burden — "lower this year's tax bill" ────────────
        'tax_burden' => [
            'label' => 'This year’s tax bill',
            'facts' => [
                'filing_status' => [
                    'canonical_key' => 'profile.filing_status',
                    'blocking' => true,
                    'chain' => ['fact', 'snapshot:filing_status', 'profile:tax_filing_status', 'ask'],
                ],
                'annual_gross_cents' => [
                    'canonical_key' => 'income.annual_gross_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:w2_wages+self_employment_income', 'derive:annualize_ytd_gross',
                        'snapshot:bank_deposit_total', 'fact', 'ask'],
                ],
                'pay_frequency' => [
                    'canonical_key' => 'pay.frequency',
                    'blocking' => false, // optional for tax_burden; default biweekly
                    'default' => 'biweekly',
                    'chain' => ['derive:frequency_from_paystub', 'fact', 'ask'],
                ],
                'has_self_employment' => [
                    'canonical_key' => 'has_self_employment',
                    'blocking' => true, // boolean; both values valid
                    'chain' => ['snapshot:has_self_employment', 'profile:employment_type', 'ask'],
                ],
                'traditional_401k_ytd_cents' => [
                    'canonical_key' => 'retirement.traditional_401k_ytd_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:traditional_401k_ytd', 'fact', 'ask'],
                ],
                'roth_401k_ytd_cents' => [
                    'canonical_key' => 'retirement.roth_401k_ytd_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:roth_401k_ytd', 'fact', 'ask'],
                ],
                'hsa_ytd_cents' => [
                    'canonical_key' => 'hsa.ytd_contribution_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:hsa_ytd', 'fact', 'ask'],
                ],
                'fsa_ytd_cents' => [
                    'canonical_key' => 'benefits.fsa_ytd_cents',
                    'blocking' => true,
                    'chain' => ['fact', 'ask'],
                ],
                'ira_traditional_ytd_cents' => [
                    'canonical_key' => 'ira.traditional_ytd_contribution_cents',
                    'blocking' => true, // shared-limit math needs combined total (D2)
                    'chain' => ['snapshot:ira_ytd', 'fact', 'ask'],
                ],
                'ira_roth_ytd_cents' => [
                    'canonical_key' => 'ira.roth_ytd_contribution_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:ira_ytd', 'fact', 'ask'],
                ],
                'hsa_eligible' => [
                    'canonical_key' => 'health.hsa_eligible',
                    'blocking' => 'conditional', // BLOCKING iff HSA lever in play
                    'condition' => 'employer.hdhp_hsa_available=yes OR profile.has_hsa OR hsa.ytd_contribution_cents>0',
                    'chain' => ['fact', 'profile:has_hsa', 'ask'],
                ],
                'hsa_coverage_type' => [
                    'canonical_key' => 'hsa.coverage_type', // [M2]
                    'blocking' => 'conditional', // BLOCKING iff hsa_eligible = yes
                    'condition' => 'health.hsa_eligible=yes',
                    'chain' => ['fact', 'ask'],
                    'prerequisite' => 'health.hsa_eligible',
                ],
                'birth_year' => [
                    'canonical_key' => 'person.birth_year',
                    'blocking' => false, // catch-up precision, senior std-deduction
                    'chain' => ['fact', 'ask'],
                ],
                'estimated_magi_cents' => [
                    'canonical_key' => 'profile.estimated_magi_cents',
                    'blocking' => false, // IRA deductibility / Roth phase-out precision
                    'chain' => ['fact', 'derive:magi_projection'],
                ],
                'dependents_count' => [
                    'canonical_key' => 'family.dependents_count',
                    'blocking' => false, // credit framing
                    'chain' => ['fact', 'ask'],
                ],
                'qualifying_children_under_17' => [
                    'canonical_key' => 'family.qualifying_children_under_17',
                    'blocking' => false,
                    'chain' => ['fact', 'ask'],
                ],
                'prior_year_agi_cents' => [
                    'canonical_key' => 'prior_year.agi_cents',
                    'blocking' => false,
                    'chain' => ['fact', 'ask'],
                ],
                'spouse_covered_by_retirement_plan' => [
                    'canonical_key' => 'spouse.covered_by_retirement_plan', // [M13]
                    'blocking' => false, // default 'no'; surfaced when MFJ + IRA lever
                    'default' => 'no',
                    'condition' => 'profile.filing_status = married_joint',
                    'chain' => ['fact', 'ask'],
                ],
            ],
        ],

        // ── Objective: retirement — "more toward retirement" ────────────────
        'retirement' => [
            'label' => 'Retirement savings',
            'facts' => [
                'filing_status' => [
                    'canonical_key' => 'profile.filing_status',
                    'blocking' => true,
                    'chain' => ['fact', 'snapshot:filing_status', 'profile:tax_filing_status', 'ask'],
                ],
                'annual_gross_cents' => [
                    'canonical_key' => 'income.annual_gross_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:w2_wages+self_employment_income', 'derive:annualize_ytd_gross',
                        'snapshot:bank_deposit_total', 'fact', 'ask'],
                ],
                'pay_frequency' => [
                    'canonical_key' => 'pay.frequency',
                    'blocking' => true,
                    'chain' => ['derive:frequency_from_paystub', 'fact', 'ask'],
                ],
                'birth_year' => [
                    'canonical_key' => 'person.birth_year',
                    'blocking' => true, // BLOCKING here (age math)
                    'chain' => ['fact', 'ask'],
                ],
                'target_age' => [
                    'canonical_key' => 'retirement.target_age',
                    'blocking' => true, // readiness asks; default only for FV horizon
                    'chain' => ['fact', 'ask'],
                ],
                'traditional_401k_ytd_cents' => [
                    'canonical_key' => 'retirement.traditional_401k_ytd_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:traditional_401k_ytd', 'fact', 'ask'],
                ],
                'roth_401k_ytd_cents' => [
                    'canonical_key' => 'retirement.roth_401k_ytd_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:roth_401k_ytd', 'fact', 'ask'],
                ],
                'ira_traditional_ytd_cents' => [
                    'canonical_key' => 'ira.traditional_ytd_contribution_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:ira_ytd', 'fact', 'ask'],
                ],
                'ira_roth_ytd_cents' => [
                    'canonical_key' => 'ira.roth_ytd_contribution_cents',
                    'blocking' => true,
                    'chain' => ['snapshot:ira_ytd', 'fact', 'ask'],
                ],
                'has_401k' => [
                    'canonical_key' => 'employer.has_401k',
                    'blocking' => true, // 'no' short-circuits R6/R7 to not_applicable
                    'chain' => ['fact', 'ask'],
                ],
                'match_pct' => [
                    'canonical_key' => 'employer.match_pct',
                    'blocking' => 'conditional', // BLOCKING iff has_401k = yes
                    'condition' => 'employer.has_401k=yes',
                    'chain' => ['fact', 'derive:from_match_formula', 'ask'],
                    'prerequisite' => 'employer.has_401k',
                ],
                'match_threshold_pct' => [
                    'canonical_key' => 'employer.match_threshold_pct',
                    'blocking' => 'conditional',
                    'condition' => 'employer.has_401k=yes',
                    'chain' => ['fact', 'derive:from_match_formula', 'ask'],
                    'prerequisite' => 'employer.match_pct',
                ],
                'contribution_pct' => [
                    'canonical_key' => 'employer.contribution_pct',
                    'blocking' => 'conditional', // BLOCKING iff has_401k = yes
                    'condition' => 'employer.has_401k=yes',
                    'chain' => ['fact', 'derive:contribution_pct_from_ytd', 'ask'],
                    'prerequisite' => 'employer.has_401k',
                ],
                'statement_balance_cents' => [
                    'canonical_key' => 'retirement.statement_balance_cents',
                    'blocking' => false, // contributions-only illustration without it
                    'chain' => ['fact', 'ask'],
                ],
                'is_cash_constrained' => [
                    'canonical_key' => 'finance.is_cash_constrained',
                    'blocking' => false, // solver floor input
                    'chain' => ['fact', 'ask'],
                ],
            ],
        ],

        // ── Scenario domain: bonus_election (D15 groundwork — NOT readiness) ─
        // Same three-objective shape; tagged is_scenario_domain so readiness
        // (14-05) still iterates ONLY take_home/tax_burden/retirement. 14-09's
        // calendar watchers read this domain for pre-bonus election alerts.
        'bonus_election' => [
            'label' => 'Bonus election',
            'is_scenario_domain' => true,
            'facts' => [
                'filing_status' => [
                    'canonical_key' => 'profile.filing_status',
                    'blocking' => true,
                    'chain' => ['fact', 'snapshot:filing_status', 'profile:tax_filing_status', 'ask'],
                ],
                'bonus_expected_month' => [
                    'canonical_key' => 'bonus.expected_month',
                    'blocking' => true, // when the bonus is expected (calendar lead-time)
                    'chain' => ['fact', 'derive:prior_year_bonus_month', 'ask'],
                ],
                'bonus_expected_amount_cents' => [
                    'canonical_key' => 'bonus.expected_amount_cents',
                    // Prediction sources (D15): prior-year paystub → interview → offer letter
                    'blocking' => true,
                    'chain' => ['derive:prior_year_bonus_amount', 'fact', 'ask'],
                ],
                'traditional_401k_ytd_cents' => [
                    'canonical_key' => 'retirement.traditional_401k_ytd_cents',
                    'blocking' => false, // room-to-defer context for the bonus scenario
                    'chain' => ['snapshot:traditional_401k_ytd', 'fact', 'ask'],
                ],
                'has_401k' => [
                    'canonical_key' => 'employer.has_401k',
                    'blocking' => false,
                    'chain' => ['fact', 'ask'],
                ],
            ],
        ],
    ],

    // ── Resolver fact aliases (§A.1.3) ─────────────────────────────────────
    // Canonical key => read-only fallback aliases (most-recent current row wins).
    // [M3] w4.filing_status ↔ profile.filing_status alias DELETED — distinct facts.
    'fact_aliases' => [
        'retirement.traditional_401k_ytd_cents' => ['retirement.k401_contribution_ytd_cents'],
        'hsa.ytd_contribution_cents' => ['retirement.hsa_ytd_cents', 'employer.hsa_deduction_ytd'],
        'ira.traditional_ytd_contribution_cents' => ['ira.traditional_contribution_ytd'],
    ],

    // ── Pay-frequency periods map (§A.3, [M8] — lives ONLY here) ────────────
    'pay_periods_per_year' => [
        'weekly' => 52,
        'biweekly' => 26,
        'semimonthly' => 24,
        'monthly' => 12,
    ],

    // ── Deterministic question templates (SCN-03 zero-Claude gap questions) ─
    // One entry per askable fact in §A.2. answer_type drives server-side typed
    // conversion in recordAnswer() (14-05). 'prerequisite' entries are merged
    // into the gate map at runtime (§A.4.3).
    'question_templates' => [
        'profile.filing_status' => [
            'question' => 'What is your tax filing status for this year?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'single', 'label' => 'Single'],
                ['value' => 'married_joint', 'label' => 'Married filing jointly'],
                ['value' => 'married_separate', 'label' => 'Married filing separately'],
                ['value' => 'head_of_household', 'label' => 'Head of household'],
            ],
            'volatility' => 'stable',
            'label' => 'Filing status',
        ],
        'income.annual_gross_cents' => [
            'question' => 'Roughly what do you expect your total gross income to be this year, before taxes and deductions?',
            'answer_type' => 'money_dollars',
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Expected annual gross income',
        ],
        'pay.frequency' => [
            'question' => 'How often are you paid?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'weekly', 'label' => 'Every week'],
                ['value' => 'biweekly', 'label' => 'Every 2 weeks'],
                ['value' => 'semimonthly', 'label' => 'Twice a month (e.g. 1st & 15th)'],
                ['value' => 'monthly', 'label' => 'Once a month'],
            ],
            'volatility' => 'stable',
            'label' => 'Pay frequency',
        ],
        'pay.gross_per_period_cents' => [
            'question' => 'How much is your gross pay each paycheck, before taxes and deductions?',
            'answer_type' => 'money_dollars',
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Gross pay per paycheck',
            'doc_affordance' => 'pay_stub',
            'doc_source_label' => "I'm not sure — get this from my paystub",
        ],
        'pay.federal_withholding_per_period_cents' => [
            'question' => 'How much federal income tax is withheld from each paycheck? '
                .'(Look for "Federal Income Tax" or "Fed W/H" on your pay stub.)',
            'answer_type' => 'money_dollars',
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Federal withholding per paycheck',
            'doc_affordance' => 'pay_stub',
            'doc_source_label' => "I'm not sure — get this from my paystub",
        ],
        'w4.filing_status' => [
            'question' => 'What filing status does the W-4 you filed with your employer claim? '
                .'(This is what payroll uses — it may differ from how you actually file.)',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'single_or_mfs', 'label' => 'Single or married filing separately'],
                ['value' => 'married_joint', 'label' => 'Married filing jointly'],
                ['value' => 'head_of_household', 'label' => 'Head of household'],
            ],
            'volatility' => 'stable',
            'label' => 'Filing status on W-4',
        ],
        'w4.dependents_claimed' => [
            'question' => 'How many dependents do you currently claim on the W-4 form you filed with your employer? '
                .'(This is what payroll uses — it may differ from your actual dependents.)',
            'answer_type' => 'integer', 'min' => 0, 'max' => 15,
            'volatility' => 'stable',
            'label' => 'Dependents claimed on W-4',
        ],
        'w4.extra_withholding_per_period_cents' => [
            'question' => 'Do you have any extra federal withholding set on your W-4 (Step 4c)? Enter the amount per paycheck, or 0 if none.',
            'answer_type' => 'money_dollars', 'allow_zero' => true,
            'volatility' => 'stable',
            'label' => 'Extra withholding per paycheck',
        ],
        'family.dependents_count' => [
            'question' => 'How many dependents do you support? Enter 0 if none.',
            'answer_type' => 'integer', 'min' => 0, 'max' => 15, 'allow_zero' => true,
            'volatility' => 'stable',
            'label' => 'Number of dependents',
        ],
        'family.qualifying_children_under_17' => [
            'question' => 'Of your dependents, how many are qualifying children under age 17?',
            'answer_type' => 'integer', 'min' => 0, 'max' => 15, 'allow_zero' => true,
            'volatility' => 'stable',
            'label' => 'Qualifying children under 17',
            'prerequisite' => 'family.dependents_count',
            // D20: only ask when dependents are known to exist
            'when' => 'family.dependents_count > 0',
        ],
        'person.birth_year' => [
            'question' => 'What year were you born? We use this only to compute contribution limits and retirement timelines.',
            'answer_type' => 'year', 'min' => 1920,
            'volatility' => 'permanent',
            'label' => 'Birth year',
        ],
        'retirement.target_age' => [
            'question' => 'At what age would you like to be able to retire?',
            'answer_type' => 'integer', 'min' => 40, 'max' => 80,
            'volatility' => 'stable',
            'label' => 'Target retirement age',
        ],
        'hsa.coverage_type' => [
            'question' => 'Is your health plan coverage for just you, or does it cover your family?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'self_only', 'label' => 'Just me'],
                ['value' => 'family', 'label' => 'My family'],
            ],
            'volatility' => 'stable',
            'label' => 'HSA coverage type',
            'prerequisite' => 'health.hsa_eligible',
        ],
        'health.hsa_eligible' => [
            'question' => 'Are you enrolled in a high-deductible health plan that makes you eligible for a Health Savings Account (HSA)?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
            'volatility' => 'stable',
            'label' => 'HSA eligibility',
        ],
        'retirement.traditional_401k_ytd_cents' => [
            'question' => 'So far this year, roughly how much have you put into your Traditional (pre-tax) 401(k)? Enter 0 if none.',
            'answer_type' => 'money_dollars', 'allow_zero' => true,
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Traditional 401(k) YTD (stated)',
            'doc_affordance' => 'pay_stub',
            'doc_source_label' => "I'm not sure — get this from my paystub",
        ],
        'retirement.roth_401k_ytd_cents' => [
            'question' => 'So far this year, roughly how much have you put into your Roth 401(k)? Enter 0 if none.',
            'answer_type' => 'money_dollars', 'allow_zero' => true,
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Roth 401(k) YTD (stated)',
            'doc_affordance' => 'pay_stub',
            'doc_source_label' => "I'm not sure — get this from my paystub",
        ],
        'hsa.ytd_contribution_cents' => [
            'question' => 'So far this year, roughly how much have you contributed to your HSA? Enter 0 if none.',
            'answer_type' => 'money_dollars', 'allow_zero' => true,
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'HSA YTD (stated)',
            'doc_affordance' => 'pay_stub',
            'doc_source_label' => "I'm not sure — get this from my paystub",
            // D20: HSA is only relevant for HDHP enrollees
            'when' => 'health.hsa_eligible = yes',
        ],
        'benefits.fsa_ytd_cents' => [
            'question' => 'So far this year, roughly how much have you contributed to a Flexible Spending Account (FSA)? Enter 0 if none.',
            'answer_type' => 'money_dollars', 'allow_zero' => true,
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'FSA YTD (stated)',
        ],
        'ira.traditional_ytd_contribution_cents' => [
            'question' => 'So far this year, roughly how much have you contributed to a Traditional IRA? Enter 0 if none.',
            'answer_type' => 'money_dollars', 'allow_zero' => true,
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Traditional IRA YTD (stated)',
        ],
        'ira.roth_ytd_contribution_cents' => [
            'question' => 'So far this year, roughly how much have you contributed to a Roth IRA? Enter 0 if none.',
            'answer_type' => 'money_dollars', 'allow_zero' => true,
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Roth IRA YTD (stated)',
        ],
        'has_self_employment' => [
            'question' => 'Do you have any self-employment or 1099 income this year?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
            'volatility' => 'stable',
            'label' => 'Self-employment income',
        ],
        'employer.has_401k' => [
            'question' => 'Does your employer offer a 401(k) plan?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
            'volatility' => 'stable',
            'label' => 'Employer 401(k) available',
        ],
        'employer.match_pct' => [
            'question' => 'How much does your employer match — what percentage of your pay do they contribute?',
            'answer_type' => 'integer', 'min' => 0, 'max' => 100, 'allow_zero' => true,
            'volatility' => 'stable',
            'label' => 'Employer match percentage',
            'prerequisite' => 'employer.has_401k',
        ],
        'employer.match_threshold_pct' => [
            'question' => 'Up to what percentage of your pay does your employer match? (The point where the match stops.)',
            'answer_type' => 'integer', 'min' => 0, 'max' => 100, 'allow_zero' => true,
            'volatility' => 'stable',
            'label' => 'Employer match threshold',
            'prerequisite' => 'employer.match_pct',
        ],
        'employer.contribution_pct' => [
            'question' => 'What percentage of your pay are you currently contributing to your 401(k)?',
            'answer_type' => 'integer', 'min' => 0, 'max' => 100, 'allow_zero' => true,
            'volatility' => 'stable',
            'label' => 'Your 401(k) contribution percentage',
            'prerequisite' => 'employer.has_401k',
        ],
        'retirement.statement_balance_cents' => [
            'question' => 'What is the current total balance of your retirement accounts? (A rough figure is fine.)',
            'answer_type' => 'money_dollars',
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Retirement balance (stated)',
            'doc_affordance' => 'retirement_statement',
            'doc_source_label' => "I'm not sure — get this from my retirement statement",
        ],
        'prior_year.federal_liability_cents' => [
            'question' => 'What was your total federal tax for last year? (Line labeled "total tax" on last year’s return.)',
            'answer_type' => 'money_dollars',
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Prior-year federal tax',
        ],
        'prior_year.agi_cents' => [
            'question' => 'What was your adjusted gross income (AGI) last year?',
            'answer_type' => 'money_dollars',
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Prior-year AGI',
        ],
        'profile.estimated_magi_cents' => [
            'question' => 'Roughly what do you expect your modified adjusted gross income (MAGI) to be this year?',
            'answer_type' => 'money_dollars',
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Estimated MAGI',
        ],
        'spouse.annual_income_cents' => [
            'question' => 'Roughly what is your spouse’s annual gross income?',
            'answer_type' => 'money_dollars',
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Spouse annual income',
            // D20: only ask when filing jointly
            'when' => 'profile.filing_status = married_joint',
        ],
        'spouse.covered_by_retirement_plan' => [
            'question' => 'Is your spouse covered by a retirement plan at their work?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
            'volatility' => 'stable',
            'label' => 'Spouse covered by retirement plan',
            // D20: only ask when filing jointly
            'when' => 'profile.filing_status = married_joint',
        ],
        'finance.is_cash_constrained' => [
            'question' => 'Would you describe your budget as tight right now — where keeping more take-home pay matters more than saving extra?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'yes', 'label' => 'Yes, keep more in my paycheck'],
                ['value' => 'no', 'label' => 'No, I can set more aside'],
            ],
            'volatility' => 'stable',
            'label' => 'Cash-flow constraint',
        ],
        'bonus.expected_month' => [
            'question' => 'Which month do you usually receive your annual bonus?',
            'answer_type' => 'integer', 'min' => 1, 'max' => 12,
            'volatility' => 'stable',
            'label' => 'Expected bonus month',
        ],
        'bonus.expected_amount_cents' => [
            'question' => 'Roughly how much do you expect your bonus to be, before taxes?',
            'answer_type' => 'money_dollars',
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Expected bonus amount',
        ],
        // D18 exemplar-3 follow-up: fans from a business-flavored vehicle/powersports
        // purpose answer as its OWN question (never crammed). 'willing_to_start' is a
        // CHECKLIST-able state — 14-08/09 wire the mileage/gallons-log Action Center
        // item to this fact key.
        'vehicle.usage_log_status' => [
            'question' => 'Do you currently keep a mileage or fuel-gallons log for these vehicles?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'kept', 'label' => 'Yes, I keep one'],
                ['value' => 'willing_to_start', 'label' => 'Not yet — but I’m willing to start'],
                ['value' => 'not_kept', 'label' => 'No, and I don’t plan to'],
            ],
            'context' => 'A contemporaneous mileage log supports vehicle deductions under either the '
                .'standard mileage or actual expense method, and a gallons log is required for any '
                .'off-road fuel tax credit. Starting one now protects whichever route applies later.',
            'volatility' => 'stable',
            'label' => 'Vehicle mileage / gallons log',
            'prerequisite' => 'category_vehicle_parts',
        ],
    ],

    // ── Prerequisite gate pairs (§A.4.3) — merged with GATED_PROBES at runtime ─
    // gated_key => prerequisite_fact_key. When a prerequisite is answered 'no',
    // dependent blocking facts flip to not_applicable in readiness (§A.4.3).
    'prerequisites' => [
        'hsa.coverage_type' => 'health.hsa_eligible',
        'employer.match_pct' => 'employer.has_401k',
        'employer.match_threshold_pct' => 'employer.match_pct',
        'employer.contribution_pct' => 'employer.has_401k',
        'family.qualifying_children_under_17' => 'family.dependents_count',
        // D18 exemplar 3: the usage-log follow-up only surfaces after the
        // vehicle/powersports purpose question is answered.
        'vehicle.usage_log_status' => 'category_vehicle_parts',
    ],
];
