<?php

/*
|--------------------------------------------------------------------------
| Fact-Key Registry — D24 reliability hardening
|--------------------------------------------------------------------------
|
| THE single canonical map of every fact key used anywhere in the
| optimization/income-optimizer subsystem.
|
| Schema per entry:
|   type    — 'money_cents' | 'int' | 'string' | 'enum' | 'bool' | 'date'
|   label   — human-readable label (used by UI and readiness blocks)
|   enum    — (enum type only) canonical allowed values
|   sources — valid source_type values for UserTaxFact rows
|   notes   — optional clarification
|
| HOW TO ADD A KEY:
|   1. Add it here with type/label/enum/sources.
|   2. If it is askable (has a question), also add it to
|      config/optimization-objectives.php question_templates.
|   3. The FactRegistryContractTest will catch any key referenced in
|      code/config that is absent from this file.
|
| HOW NOT TO ADD A KEY:
|   Do NOT invent a key inline in code and skip this file — the contract
|   test will fail the build and block the PR.
|
| Alias keys (fact_aliases in optimization-objectives.php) are listed
| here with type 'alias' and a 'canonical' pointer.
|
*/

return [

    // ── Identity plane ────────────────────────────────────────────────────────
    'identity.employee_name' => [
        'type' => 'string',
        'label' => 'Employee legal name (paystub)',
        'sources' => ['document_extraction'],
    ],
    'identity.employee_address' => [
        'type' => 'string',
        'label' => 'Employee address (paystub)',
        'sources' => ['document_extraction'],
    ],

    // ── Profile plane ─────────────────────────────────────────────────────────
    'profile.filing_status' => [
        'type' => 'enum',
        'label' => 'Tax filing status',
        'enum' => ['single', 'married_joint', 'married_separate', 'head_of_household'],
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'profile.estimated_magi_cents' => [
        'type' => 'money_cents',
        'label' => 'Estimated MAGI',
        'sources' => ['interview_answer', 'user_edit'],
    ],

    // ── Income plane ──────────────────────────────────────────────────────────
    'income.annual_gross_cents' => [
        'type' => 'money_cents',
        'label' => 'Expected annual gross income',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction', 'derived'],
    ],
    'income.paystub_monthly_base_cents' => [
        'type' => 'money_cents',
        'label' => 'Monthly base pay (paystub)',
        'sources' => ['document_extraction'],
    ],
    'income.prior_year_income_cents' => [
        'type' => 'money_cents',
        'label' => 'Prior-year income',
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'income.bonus_annual_cents' => [
        'type' => 'money_cents',
        'label' => 'Bonus / variable pay this year (beyond base)',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
        'notes' => 'Derived from paystub YTD gross annualized vs base pace (≥20% gate). Enters annual tax/MAGI math only — never per-paycheck figures. Optional: absent → base-only behavior. Superseded by income.bonus_structure_pct when that is set.',
    ],
    'income.bonus_structure_pct' => [
        'type' => 'int',
        'label' => 'Annual bonus (% of base salary)',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction', 'profile_field'],
        'notes' => 'Bonus STRUCTURE — e.g. 25 = a 25% annual bonus. Takes precedence over income.bonus_annual_cents in assembleBaseline (bonus = pct × annual base). Set via profile setting or proposed from paystub YTD excess.',
    ],

    // ── Pay plane ─────────────────────────────────────────────────────────────
    'pay.frequency' => [
        'type' => 'enum',
        'label' => 'Pay frequency',
        'enum' => ['weekly', 'biweekly', 'semimonthly', 'monthly'],
        'sources' => ['interview_answer', 'user_edit', 'document_extraction', 'derived'],
    ],
    'pay.gross_per_period_cents' => [
        'type' => 'money_cents',
        'label' => 'Gross pay per paycheck',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'pay.federal_withholding_per_period_cents' => [
        'type' => 'money_cents',
        'label' => 'Federal withholding per paycheck',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'pay.period_start' => [
        'type' => 'date',
        'label' => 'Pay period start date',
        'sources' => ['document_extraction'],
    ],
    'pay.period_end' => [
        'type' => 'date',
        'label' => 'Pay period end date',
        'sources' => ['document_extraction'],
    ],
    'pay.state_withholding_per_period_cents' => [
        'type' => 'money_cents',
        'label' => 'State income tax withheld per paycheck',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
        'notes' => 'Knob-invariant paycheck deduction — anchors banner take-home to the real check. Never enters federal delta math.',
    ],
    'pay.health_premium_per_period_cents' => [
        'type' => 'money_cents',
        'label' => 'Health insurance premium per paycheck',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
        'notes' => 'Knob-invariant paycheck deduction — anchors banner take-home to the real check. Never enters federal delta math.',
    ],
    'pay.dental_vision_premium_per_period_cents' => [
        'type' => 'money_cents',
        'label' => 'Dental/vision premium per paycheck',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
        'notes' => 'Knob-invariant paycheck deduction — anchors banner take-home to the real check. Never enters federal delta math.',
    ],
    'pay.ytd_gross_cents' => [
        'type' => 'money_cents',
        'label' => 'Year-to-date gross pay (paystub)',
        'sources' => ['document_extraction'],
        'notes' => 'Evidence fact — source for the income.bonus_annual_cents derivation.',
    ],
    'pay.ytd_federal_withheld_cents' => [
        'type' => 'money_cents',
        'label' => 'Year-to-date federal tax withheld (paystub)',
        'sources' => ['document_extraction'],
        'notes' => 'Evidence fact — context for withholding-vs-liability comparisons.',
    ],

    // ── W-4 plane ─────────────────────────────────────────────────────────────
    'w4.filing_status' => [
        'type' => 'enum',
        'label' => 'Filing status on W-4',
        'enum' => ['single_or_mfs', 'married_joint', 'head_of_household'],
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'w4.dependents_claimed' => [
        'type' => 'int',
        'label' => 'Dependents claimed on W-4',
        'sources' => ['interview_answer', 'user_edit'],
        'notes' => 'COUNT of dependents, NOT a dollar amount. See w4.step3_annual_credits_cents for dollar value.',
    ],
    'w4.step3_annual_credits_cents' => [
        'type' => 'money_cents',
        'label' => 'W-4 Step 3 dependent credits (annual)',
        'sources' => ['document_extraction', 'user_edit'],
        'notes' => 'Modern W-4 Step 3 is an annual dollar credit amount, not a count.',
    ],
    'w4.extra_withholding_per_period_cents' => [
        'type' => 'money_cents',
        'label' => 'Extra withholding per paycheck (W-4 Step 4c)',
        'sources' => ['interview_answer', 'user_edit'],
    ],

    // ── Person plane ──────────────────────────────────────────────────────────
    'person.birth_year' => [
        'type' => 'int',
        'label' => 'Birth year',
        'sources' => ['interview_answer', 'user_edit'],
    ],

    // ── Family plane ──────────────────────────────────────────────────────────
    'family.dependents_count' => [
        'type' => 'int',
        'label' => 'Number of dependents',
        'sources' => ['interview_answer', 'user_edit'],
    ],
    'family.qualifying_children_under_17' => [
        'type' => 'int',
        'label' => 'Qualifying children under 17',
        'sources' => ['interview_answer', 'user_edit'],
    ],
    'family.has_children' => [
        'type' => 'enum',
        'label' => 'Has children',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit', 'derived'],
    ],
    'family.has_529_account' => [
        'type' => 'enum',
        'label' => 'Has 529 college savings account',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit'],
    ],

    // ── Spouse plane ──────────────────────────────────────────────────────────
    'spouse.annual_income_cents' => [
        'type' => 'money_cents',
        'label' => 'Spouse annual income',
        'sources' => ['interview_answer', 'user_edit'],
    ],
    'spouse.covered_by_retirement_plan' => [
        'type' => 'enum',
        'label' => 'Spouse covered by retirement plan',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit'],
    ],

    // ── Employer plane ────────────────────────────────────────────────────────
    'employer.federal_withholding' => [
        'type' => 'money_cents',
        'label' => 'Annual federal withholding',
        'sources' => ['derived'],
        'notes' => 'DERIVED fact — never directly asked. Terminal: ask:pay.federal_withholding_per_period_cents.',
    ],
    'employer.has_401k' => [
        'type' => 'enum',
        'label' => 'Employer 401(k) available',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'employer.match_pct' => [
        'type' => 'int',
        'label' => 'Employer match percentage',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction', 'derived'],
    ],
    'employer.bonus_401k_eligible' => [
        'type' => 'enum',
        'label' => '401(k) deferrals taken from bonus checks',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
        'notes' => 'When yes, the engine includes the annual bonus in deferral-eligible comp (contributions, 402(g) clamps, match capture). Per-paycheck figures always stay base-only. Absent/unknown → no (conservative).',
    ],
    'retirement.statement_ytd_employer_contributions_cents' => [
        'type' => 'money_cents',
        'label' => 'Employer YTD contributions (retirement statement)',
        'sources' => ['document_extraction'],
        'notes' => 'Evidence fact — source for the employer match derivation (ratio vs employee YTD).',
    ],
    'employer.match_threshold_pct' => [
        'type' => 'int',
        'label' => 'Employer match threshold',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction', 'derived'],
    ],
    'employer.contribution_pct' => [
        'type' => 'int',
        'label' => 'Your 401(k) contribution percentage',
        'sources' => ['interview_answer', 'user_edit', 'derived'],
    ],
    'employer.match_formula' => [
        'type' => 'string',
        'label' => 'Employer match formula (raw text)',
        'sources' => ['document_extraction'],
    ],
    'employer.hsa_deduction_ytd' => [
        'type' => 'alias',
        'canonical' => 'hsa.ytd_contribution_cents',
        'label' => 'HSA payroll deduction YTD (alias)',
        'sources' => ['document_extraction'],
    ],
    'employer.hdhp_hsa_available' => [
        'type' => 'enum',
        'label' => 'HDHP/HSA plan available from employer',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.fsa_available' => [
        'type' => 'enum',
        'label' => 'FSA available from employer',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.dependent_care_fsa_available' => [
        'type' => 'enum',
        'label' => 'Dependent care FSA available',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.after_tax_401k_available' => [
        'type' => 'enum',
        'label' => 'After-tax 401(k) available',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.in_plan_roth_conversion_available' => [
        'type' => 'enum',
        'label' => 'In-plan Roth conversion available',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.espp_available' => [
        'type' => 'enum',
        'label' => 'ESPP available from employer',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.espp_terms' => [
        'type' => 'string',
        'label' => 'ESPP terms (raw text)',
        'sources' => ['document_extraction'],
    ],
    'employer.nqdc_available' => [
        'type' => 'enum',
        'label' => 'Non-qualified deferred compensation available',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.section_127_available' => [
        'type' => 'enum',
        'label' => 'Section 127 education assistance available',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.commuter_benefits_available' => [
        'type' => 'enum',
        'label' => 'Commuter benefits available',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.group_legal_available' => [
        'type' => 'enum',
        'label' => 'Group legal plan available',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.trump_account_available' => [
        'type' => 'enum',
        'label' => 'Trump account available',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.trump_account_employer_contribution' => [
        'type' => 'money_cents',
        'label' => 'Trump account employer contribution',
        'sources' => ['document_extraction'],
    ],
    'employer.employer_type' => [
        'type' => 'string',
        'label' => 'Employer type',
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.has_457b' => [
        'type' => 'enum',
        'label' => 'Employer offers 457(b)',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.has_hsa_plan' => [
        'type' => 'enum',
        'label' => 'Employer has HSA plan',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.hsa_enrolled' => [
        'type' => 'enum',
        'label' => 'Employee enrolled in employer HSA',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.has_espp' => [
        'type' => 'enum',
        'label' => 'Employer has ESPP',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.espp_enrolled' => [
        'type' => 'enum',
        'label' => 'Employee enrolled in ESPP',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.has_nqdc' => [
        'type' => 'enum',
        'label' => 'Employer offers NQDC plan',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.allows_after_tax_401k' => [
        'type' => 'enum',
        'label' => 'Employer allows after-tax 401(k)',
        'enum' => ['yes', 'no'],
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],
    'employer.has_w2_work_expenses' => [
        'type' => 'enum',
        'label' => 'Has W-2 work expenses',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit'],
    ],

    // ── Retirement plane ──────────────────────────────────────────────────────
    'retirement.traditional_401k_ytd_cents' => [
        'type' => 'money_cents',
        'label' => 'Traditional 401(k) YTD contributions',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'retirement.roth_401k_ytd_cents' => [
        'type' => 'money_cents',
        'label' => 'Roth 401(k) YTD contributions',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    // Bug-2 fix (2026-07-03): per-period deduction keys — written by PaystubFactExtractorService
    // when extracting traditional_401k_deduction / roth_401k_deduction from a pay stub.
    // These hold PER-PAYCHECK amounts (e.g. $608.70 = 60870 cents) and must be annualized
    // by multiplying by pay_periods_per_year, NOT by dividing by the year-elapsed fraction.
    // The old _ytd_cents keys remain for true YTD amounts entered via interview or user_edit.
    'retirement.traditional_401k_per_period_cents' => [
        'type' => 'money_cents',
        'label' => 'Traditional 401(k) per-paycheck deduction (paystub)',
        'sources' => ['document_extraction'],
    ],
    'retirement.roth_401k_per_period_cents' => [
        'type' => 'money_cents',
        'label' => 'Roth 401(k) per-paycheck deduction (paystub)',
        'sources' => ['document_extraction'],
    ],
    'retirement.target_age' => [
        'type' => 'int',
        'label' => 'Target retirement age',
        'sources' => ['interview_answer', 'user_edit'],
    ],
    'retirement.statement_balance_cents' => [
        'type' => 'money_cents',
        'label' => 'Retirement account balance',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'retirement.statement_ytd_contributions_cents' => [
        'type' => 'money_cents',
        'label' => 'Retirement statement YTD contributions',
        'sources' => ['document_extraction'],
    ],
    'retirement.elected_roth_share_pct' => [
        'type' => 'int',
        'label' => 'Elected Roth share percentage',
        'sources' => ['user_edit'],
    ],
    'retirement.has_ira_balance' => [
        'type' => 'enum',
        'label' => 'Has IRA balance',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'retirement.hsa_ytd_cents' => [
        'type' => 'alias',
        'canonical' => 'hsa.ytd_contribution_cents',
        'label' => 'HSA YTD (paystub alias)',
        'sources' => ['document_extraction'],
    ],
    'retirement.k401_contribution_ytd_cents' => [
        'type' => 'alias',
        'canonical' => 'retirement.traditional_401k_ytd_cents',
        'label' => '401(k) combined YTD (legacy alias)',
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],

    // ── IRA plane ─────────────────────────────────────────────────────────────
    'ira.traditional_ytd_contribution_cents' => [
        'type' => 'money_cents',
        'label' => 'Traditional IRA YTD contributions',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'ira.roth_ytd_contribution_cents' => [
        'type' => 'money_cents',
        'label' => 'Roth IRA YTD contributions',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'ira.traditional_contribution_ytd' => [
        'type' => 'alias',
        'canonical' => 'ira.traditional_ytd_contribution_cents',
        'label' => 'Traditional IRA YTD (legacy alias)',
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],

    // ── HSA plane ─────────────────────────────────────────────────────────────
    'hsa.ytd_contribution_cents' => [
        'type' => 'money_cents',
        'label' => 'HSA YTD contributions',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'hsa.coverage_type' => [
        'type' => 'enum',
        'label' => 'HSA coverage type',
        'enum' => ['self_only', 'family'],
        'sources' => ['interview_answer', 'user_edit'],
    ],

    // ── Benefits plane ────────────────────────────────────────────────────────
    'benefits.fsa_ytd_cents' => [
        'type' => 'money_cents',
        'label' => 'FSA YTD contributions',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],

    // ── Health plane ──────────────────────────────────────────────────────────
    'health.hsa_eligible' => [
        'type' => 'enum',
        'label' => 'HSA eligibility',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit'],
    ],
    'health.has_medical_expenses' => [
        'type' => 'enum',
        'label' => 'Has significant medical expenses',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit'],
    ],
    'health.wants_to_fund_hsa' => [
        'type' => 'enum',
        'label' => 'Wants to fund HSA',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit'],
    ],
    'health.qfd_previously_used' => [
        'type' => 'enum',
        'label' => 'QFD (qualified funding distribution) previously used',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit'],
    ],

    // ── Finance plane ─────────────────────────────────────────────────────────
    'finance.is_cash_constrained' => [
        'type' => 'enum',
        'label' => 'Cash-flow constraint',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit'],
    ],
    'finance.has_hsa' => [
        'type' => 'enum',
        'label' => 'Has HSA',
        'enum' => ['yes', 'no'],
        'sources' => ['user_edit', 'derived'],
    ],
    'finance.has_ira' => [
        'type' => 'enum',
        'label' => 'Has IRA',
        'enum' => ['yes', 'no'],
        'sources' => ['user_edit', 'derived'],
    ],
    'finance.investment_income_cents' => [
        'type' => 'money_cents',
        'label' => 'Investment income',
        'sources' => ['document_extraction', 'interview_answer', 'user_edit'],
    ],

    // ── Prior-year plane ──────────────────────────────────────────────────────
    'prior_year.federal_liability_cents' => [
        'type' => 'money_cents',
        'label' => 'Prior-year federal tax',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],
    'prior_year.agi_cents' => [
        'type' => 'money_cents',
        'label' => 'Prior-year AGI',
        'sources' => ['interview_answer', 'user_edit', 'document_extraction'],
    ],

    // ── Bonus plane ───────────────────────────────────────────────────────────
    'bonus.expected_month' => [
        'type' => 'int',
        'label' => 'Expected bonus month',
        'sources' => ['interview_answer', 'user_edit', 'derived'],
    ],
    'bonus.expected_amount_cents' => [
        'type' => 'money_cents',
        'label' => 'Expected bonus amount',
        'sources' => ['interview_answer', 'user_edit', 'derived'],
    ],

    // ── Vehicle plane ─────────────────────────────────────────────────────────
    'vehicle.usage_log_status' => [
        'type' => 'enum',
        'label' => 'Vehicle mileage / gallons log',
        'enum' => ['kept', 'willing_to_start', 'not_kept'],
        'sources' => ['interview_answer', 'user_edit'],
    ],
    'vehicle.business_use_pct' => [
        'type' => 'int',
        'label' => 'Vehicle business use percentage',
        'sources' => ['interview_answer', 'user_edit', 'derived'],
    ],

    // ── Scenario plane ────────────────────────────────────────────────────────
    'scenario.chosen_option' => [
        'type' => 'string',
        'label' => 'Chosen scenario option',
        'sources' => ['user_edit'],
    ],
    'scenario.chosen_knobs' => [
        'type' => 'string',
        'label' => 'Chosen knob values (JSON)',
        'sources' => ['user_edit'],
    ],
    'scenario.chosen_option_label' => [
        'type' => 'string',
        'label' => 'Chosen scenario display label',
        'sources' => ['user_edit'],
    ],

    // ── Self-employment (legacy top-level key) ────────────────────────────────
    'has_self_employment' => [
        'type' => 'enum',
        'label' => 'Self-employment income',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit', 'document_extraction', 'derived'],
    ],

    // ── Life-event plane ──────────────────────────────────────────────────────
    // Abbreviated alias used in LifeEventTriggerDetector docblock example.
    'life_event.inherited_assets' => [
        'type' => 'alias',
        'canonical' => 'life_event.inherited_assets_this_year',
        'label' => 'Inherited assets (docblock alias)',
        'sources' => ['interview_answer', 'user_edit', 'derived'],
    ],
    'life_event.marital_status_changed' => [
        'type' => 'enum',
        'label' => 'Marital status changed this year',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit', 'derived'],
    ],
    'life_event.birth_or_adoption' => [
        'type' => 'enum',
        'label' => 'Birth or adoption this year',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit', 'derived'],
    ],
    'life_event.job_change' => [
        'type' => 'enum',
        'label' => 'Job change this year',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit', 'derived'],
    ],
    'life_event.inherited_assets_this_year' => [
        'type' => 'enum',
        'label' => 'Inherited assets this year',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit', 'derived'],
    ],
    'life_event.medicare_enrollment_this_year' => [
        'type' => 'enum',
        'label' => 'Medicare enrollment this year',
        'enum' => ['yes', 'no'],
        'sources' => ['interview_answer', 'user_edit', 'derived'],
    ],

    // ── Finding-pattern keys (FindingPatternQuestionService dynamic templates) ─
    // These are finding_key values that drive dynamic question templates (not
    // standard interview questions). Listed here so the contract test knows they
    // are legitimate dotted-key-shaped strings, not fact-key leaks.
    'category_vehicle_parts' => [
        'type' => 'string',
        'label' => 'Vehicle/powersports category finding key',
        'sources' => ['derived'],
        'notes' => 'Finding key, not a UserTaxFact key. Used as prerequisite for vehicle.usage_log_status.',
    ],

    // ── IRA gate plane ────────────────────────────────────────────────────────
    'ira.balance_range' => [
        'type' => 'enum',
        'label' => 'IRA balance range',
        'enum' => ['under_10k', '10k_to_50k', '50k_to_100k', 'over_100k'],
        'sources' => ['interview_answer', 'user_edit'],
        'notes' => 'Gate prerequisite for ira.backdoor_roth_eligible.',
    ],
    'ira.backdoor_roth_eligible' => [
        'type' => 'enum',
        'label' => 'Backdoor Roth IRA eligibility',
        'enum' => ['yes', 'no', 'maybe'],
        'sources' => ['derived', 'interview_answer', 'user_edit'],
    ],

    // ── Income extended plane ─────────────────────────────────────────────────
    'income.rental_income_detected' => [
        'type' => 'enum',
        'label' => 'Rental income detected',
        'enum' => ['yes', 'no'],
        'sources' => ['derived', 'document_extraction'],
    ],
    'income.employment_type' => [
        'type' => 'enum',
        'label' => 'Employment type',
        'enum' => ['w2', 'self_employed', 'mixed', 'retired', 'other'],
        'sources' => ['derived', 'user_edit', 'document_extraction'],
    ],

    // ── Person extended plane ─────────────────────────────────────────────────
    'person.estimated_age' => [
        'type' => 'int',
        'label' => 'Estimated age (derived)',
        'sources' => ['derived'],
        'notes' => 'DERIVED from person.birth_year + current year. Never written to UserTaxFact directly.',
    ],

    // ── Scenario knob divergence epsilon keys ─────────────────────────────────
    // Used as config array keys in config/optimizer-scenarios.php divergence
    // thresholds and as knob labels. They look like fact keys but are scenario
    // knob identifiers, NOT UserTaxFact fact_key values.
    'hsa.annual_election_cents' => [
        'type' => 'scenario_knob',
        'label' => 'HSA annual election knob',
        'sources' => [],
        'notes' => 'Scenario knob identifier (divergence config key). NOT a UserTaxFact key.',
    ],
    'ira.traditional_cents' => [
        'type' => 'scenario_knob',
        'label' => 'IRA traditional contribution knob',
        'sources' => [],
        'notes' => 'Scenario knob identifier (divergence config key). NOT a UserTaxFact key.',
    ],
    'ira.roth_cents' => [
        'type' => 'scenario_knob',
        'label' => 'IRA Roth contribution knob',
        'sources' => [],
        'notes' => 'Scenario knob identifier (divergence config key). NOT a UserTaxFact key.',
    ],
    'transfer.per_period_cents' => [
        'type' => 'scenario_knob',
        'label' => 'Transfer per period knob',
        'sources' => [],
        'notes' => 'Scenario knob identifier (divergence config key). NOT a UserTaxFact key.',
    ],
];
