<?php

// Source: IRS Rev. Proc. 2025-32 (brackets, standard deductions, LTCG);
//         IRS Notice 2025-67 (401k/IRA limits);
//         IRS Notice 2026-05 (HSA limits);
//         IRS.gov Topic 751 (FICA/SE tax)

return [

    2026 => [

        // ── Federal Income Tax Brackets ──────────────────────────────────
        // [CITED: IRS Rev. Proc. 2025-32, Table 1]
        'brackets' => [
            'single' => [
                ['rate' => 0.10, 'from' => 0,       'to' => 12_400],
                ['rate' => 0.12, 'from' => 12_400,   'to' => 50_400],
                ['rate' => 0.22, 'from' => 50_400,   'to' => 105_700],
                ['rate' => 0.24, 'from' => 105_700,  'to' => 201_775],
                ['rate' => 0.32, 'from' => 201_775,  'to' => 256_225],
                ['rate' => 0.35, 'from' => 256_225,  'to' => 640_600],
                ['rate' => 0.37, 'from' => 640_600,  'to' => null],
            ],
            'married_joint' => [
                ['rate' => 0.10, 'from' => 0,       'to' => 24_800],
                ['rate' => 0.12, 'from' => 24_800,   'to' => 100_800],
                ['rate' => 0.22, 'from' => 100_800,  'to' => 211_400],
                ['rate' => 0.24, 'from' => 211_400,  'to' => 403_550],
                ['rate' => 0.32, 'from' => 403_550,  'to' => 512_450],
                ['rate' => 0.35, 'from' => 512_450,  'to' => 768_700],
                ['rate' => 0.37, 'from' => 768_700,  'to' => null],
            ],
            'married_separate' => [
                ['rate' => 0.10, 'from' => 0,       'to' => 12_400],
                ['rate' => 0.12, 'from' => 12_400,   'to' => 50_400],
                ['rate' => 0.22, 'from' => 50_400,   'to' => 105_700],
                ['rate' => 0.24, 'from' => 105_700,  'to' => 201_775],
                ['rate' => 0.32, 'from' => 201_775,  'to' => 256_225],
                ['rate' => 0.35, 'from' => 256_225,  'to' => 384_350],
                ['rate' => 0.37, 'from' => 384_350,  'to' => null],
            ],
            'head_of_household' => [
                ['rate' => 0.10, 'from' => 0,       'to' => 17_700],
                ['rate' => 0.12, 'from' => 17_700,   'to' => 67_450],
                ['rate' => 0.22, 'from' => 67_450,   'to' => 105_700],
                ['rate' => 0.24, 'from' => 105_700,  'to' => 201_775],
                ['rate' => 0.32, 'from' => 201_775,  'to' => 256_200],
                ['rate' => 0.35, 'from' => 256_200,  'to' => 640_600],
                ['rate' => 0.37, 'from' => 640_600,  'to' => null],
            ],
        ],

        // ── Standard Deductions ───────────────────────────────────────────
        // [CITED: IRS Rev. Proc. 2025-32]
        'standard_deduction' => [
            'single' => 16_100,
            'married_joint' => 32_200,
            'married_separate' => 16_100,
            'head_of_household' => 24_150,
        ],

        // Additional standard deduction for age 65+, per qualifying person
        // [CITED: IRS Rev. Proc. 2025-32]
        'standard_deduction_senior_addition' => [
            'single' => 2_050,
            'married_joint' => 1_650,  // per qualifying spouse
            'married_separate' => 1_650,
            'head_of_household' => 2_050,
        ],

        // ── 401(k) / Employer Plan Limits ────────────────────────────────
        // [CITED: IRS Notice 2025-67]
        '401k' => [
            'employee_deferral' => 24_500,
            'catchup_age_50_plus' => 8_000,   // ages 50-59 and 64+; total 32,500
            'catchup_age_60_to_63' => 11_250,  // SECURE 2.0 §109; replaces 50+ for 60-63; total 35,750
            // SECURE 2.0 §603: prior-year FICA wages >= threshold → catch-up MUST be Roth
            // [ASSUMED: exact 2026 indexed threshold; IRS Notice confirms base $145k indexed;
            //  milestone research uses $150k — treat this value as needing tax-professional confirmation]
            'mandatory_roth_catchup_threshold' => 150_000,
            'highly_compensated_threshold' => 160_000,
        ],

        // ── IRA Limits ───────────────────────────────────────────────────
        // [CITED: IRS Notice 2025-67]
        'ira' => [
            'annual_limit' => 7_500,
            'catchup_age_50_plus' => 1_100,   // total 8,600 for 50+; new in 2026 (was $1,000)

            // Roth IRA contribution phase-out (MAGI)
            // [CITED: IRS Notice 2025-67 + IRS Rev. Proc. 2025-32]
            'roth_phaseout' => [
                'single' => ['from' => 153_000, 'to' => 168_000],
                'married_joint' => ['from' => 242_000, 'to' => 252_000],
                'married_separate' => ['from' => 0,       'to' => 10_000],
                'head_of_household' => ['from' => 153_000, 'to' => 168_000],
            ],

            // Traditional IRA deduction phase-out when covered by workplace plan
            // [CITED: IRS Notice 2025-67]
            'traditional_deduction_phaseout_covered' => [
                'single' => ['from' => 81_000,  'to' => 91_000],
                'married_joint' => ['from' => 129_000, 'to' => 149_000],
                'married_separate' => ['from' => 0,       'to' => 10_000],
                'head_of_household' => ['from' => 81_000,  'to' => 91_000],
            ],

            // Phase-out when NOT covered but spouse IS covered
            // [CITED: IRS Notice 2025-67]
            'traditional_deduction_phaseout_spouse_covered' => [
                'married_joint' => ['from' => 242_000, 'to' => 252_000],
            ],
        ],

        // ── HSA Limits ───────────────────────────────────────────────────
        // [CITED: IRS Notice 2026-05]
        'hsa' => [
            'self_only' => 4_400,
            'family' => 8_750,
            'catchup_age_55_plus' => 1_000,  // statutory, not inflation-adjusted
            'hdhp_min_deductible_self' => 1_700,
            'hdhp_min_deductible_family' => 3_400,
            'hdhp_max_oop_self' => 8_500,
            'hdhp_max_oop_family' => 17_000,
        ],

        // ── Self-Employment / FICA ────────────────────────────────────────
        // [CITED: IRS.gov Topic 751; SSA.gov 2026 COLA announcement]
        'se_tax' => [
            'net_earnings_multiplier' => 0.9235,  // 1 - (0.153/2) normalizes for employer share
            'rate' => 0.153,   // 12.4% SS + 2.9% Medicare
            'ss_rate' => 0.124,
            'medicare_rate' => 0.029,
            'ss_wage_base' => 184_500, // SS portion only applies up to this limit
            'deductible_fraction' => 0.5,     // half of SE tax deducted from AGI
            // Additional Medicare surtax (0.9%) on earned income above:
            'additional_medicare_threshold_single' => 200_000,
            'additional_medicare_threshold_joint' => 250_000,
            'additional_medicare_rate' => 0.009,
        ],

        // ── QBI (§199A) Deduction Thresholds ─────────────────────────────
        // [CITED: IRS Rev. Proc. 2025-32; OBBBA §70105 (permanent extension + $400 minimum)]
        'qbi' => [
            'rate' => 0.20,    // 20% of qualified business income
            'phase_out_start_single' => 201_750,
            'phase_out_start_joint' => 403_500,
            'phase_out_window_single' => 75_000,  // OBBBA-expanded (was $50k pre-OBBBA)
            'phase_out_window_joint' => 150_000, // OBBBA-expanded (was $100k pre-OBBBA)
            // Derived: full SSTB elimination at single=$276,750; joint=$553,500
            'minimum_deduction' => 400,     // OBBBA §70105: if QBI >= $1,000 and materially participates
            'minimum_qbi_for_floor' => 1_000,   // QBI must be at least $1,000 to claim $400 minimum
        ],

        // ── Roth Optimization Decision Bands ─────────────────────────────
        // [ASSUMED: logic derived from standard financial planning guidance; not IRS-mandated]
        'roth_optimization' => [
            'prefer_roth_at_or_below' => 0.12,  // marginal rate <= 12% → Roth lean
            'prefer_traditional_at_or_above' => 0.32,  // marginal rate >= 32% → Traditional lean
            // 22% and 24% brackets → 'split' (context-dependent)
        ],

        // ── Detection Constants (Phase 11 wave 11a — TAX-08) ─────────────────
        // Source: IRS Rev. Proc. 2025-32 / Notice 2025-67 / Notice 2026-05 / OBBBA §§70101-70450
        // [ASSUMED] markers indicate values from INTEGRATION-MAP consolidated table that carry
        //   "(verify)" status and require P13 sign-off gate confirmation.
        // All amounts in whole dollars (service converts to cents internally).
        // ADDITIVE block — no existing keys above are modified.
        'detection' => [

            // ── IRA Shared Limit Note (D3 correctness requirement) ───────────
            // The IRA annual limit is SHARED across Roth + Traditional contributions.
            // TaxRulesEngineService::remainingIraRoomCents() callers MUST pass the
            // COMBINED Roth+Traditional YTD total, never a type-label alone.
            // [CITED: IRS Notice 2025-67; D3 owner correctness requirement]
            'ira_shared_limit' => 7_500,
            'ira_catchup_50_plus' => 1_100,  // total 8,600 for age 50+

            // ── 457(b) / 403(b) Employee Limits ─────────────────────────────
            // [CITED: IRS Notice 2025-67]
            '457b_employee_limit' => 24_500,
            '403b_employee_limit' => 24_500,
            // 3-yr pre-retirement catch-up doubles the limit;
            // governmental 457(b) carries no 10%-early-withdrawal penalty post-separation
            '457b_403b_pretire_catchup_multiplier' => 2,

            // ── Section 415(c) Total Defined-Contribution Limit ──────────────
            // [ASSUMED] ~$72,000 — confirm exact 2026 indexed amount; P13 sign-off gate
            'section_415c_limit' => 72_000,  // [ASSUMED]

            // ── Solo 401(k) Employer Share ───────────────────────────────────
            // [CITED: IRC §401(k); deductible employer contribution = 20% of net SE earnings
            //  (25% of W-2 wages for corporate S-corp; 20% is the SE self-calc rate)]
            'solo_401k_employer_share_rate' => 0.20,

            // ── Cash-Balance Plan Annual Range (age 45+) ─────────────────────
            // [CITED: IRC §415(b); actuarial limits by age — $150K–$350K range reflects
            //  typical age-45+ benefit accruals; confirm with actuary]
            'cash_balance_plan_min' => 150_000,
            'cash_balance_plan_max' => 350_000,
            'cash_balance_age_min' => 45,

            // ── QCD Annual Limit ─────────────────────────────────────────────
            // [ASSUMED] ~$108,000 for 2026; indexed annually; P13 sign-off gate
            // Age threshold is 70½ — coded as integer 70 with the ½-year note in logic
            'qcd_limit' => 108_000, // [ASSUMED]
            'qcd_age_min' => 70,      // age 70½ — apply half-year gate in service

            // ── Saver's Match Arrival Year (SECURE 2.0 §103) ────────────────
            // [CITED: SECURE 2.0 Act of 2022 — effective 2027]
            'saver_match_starts_year' => 2027,

            // ── §127 Education / Student-Loan Assistance ─────────────────────
            // [CITED: IRC §127; permanent per SECURE 2.0 §110; indexed limit]
            'section_127_limit' => 5_250,

            // ── Employer Child-Care Credit ────────────────────────────────────
            // [CITED: IRC §45F; OBBBA increased small-biz limit per §70112]
            'employer_childcare_credit_max' => 500_000,
            'employer_childcare_credit_small_biz' => 600_000,

            // ── OBBBA: Tips Deduction (IRC §224; 2025–2028) ──────────────────
            // [CITED: OBBBA §70101; effective 2025-01-01 through 2028-12-31]
            'tips_deduction_cap' => 25_000,
            'tips_phaseout_magi_single' => 150_000,
            'tips_phaseout_magi_mfj' => 300_000,

            // ── OBBBA: Overtime Deduction (IRC §225; 2025–2028) ──────────────
            // [ASSUMED] INTEGRATION-MAP consolidated table shows $12.5K–$25K range;
            //   half of MFJ cap for single filers — confirm exact single-filer cap; P13 sign-off gate
            // W-2 box codes TP / TT required for eligibility verification
            'ot_deduction_cap_single' => 12_500, // [ASSUMED]
            'ot_deduction_cap_mfj' => 25_000, // [ASSUMED]
            'ot_phaseout_magi_single' => 150_000,
            'ot_phaseout_magi_mfj' => 300_000,

            // ── OBBBA: Senior Deduction (IRC §226; 2025–2028) ────────────────
            // [CITED: OBBBA §70102; age 65+ additional deduction]
            'senior_deduction_amount' => 6_000,
            'senior_deduction_age_min' => 65,
            'senior_magi_single' => 75_000,
            'senior_magi_mfj' => 150_000,

            // ── OBBBA: Auto-Loan Interest Deduction (IRC §163(h); 2025–2028) ─
            // [CITED: OBBBA §70103; US-assembled vehicles purchased on or after 2025-01-01]
            'auto_loan_interest_cap' => 10_000,

            // ── OBBBA: SALT Cap (IRC §164(b)(6); 2025–2029) ──────────────────
            // [CITED: OBBBA §70104; $40K cap through 2029;
            //  phases back toward $10K above ~$500K MAGI — MAGI phaseout TBD in regs]
            'salt_cap' => 40_000,

            // ── Charitable (Non-Itemizer + Itemizer Floor 2026+) ─────────────
            // [CITED: OBBBA §70200–70201; IRC §170]
            'charitable_non_itemizer_single' => 1_000,
            'charitable_non_itemizer_mfj' => 2_000,
            'charitable_agi_floor_rate' => 0.005,  // 0.5% of AGI itemizer floor (2026+)
            'charitable_acknowledgment_floor' => 250,    // written letter required at $250+
            'charitable_non_cash_appraisal' => 5_000,  // independent qualified appraisal required

            // ── Child Tax Credit ─────────────────────────────────────────────
            // [CITED: IRC §24; OBBBA §70301 — increased to $2,200 per child]
            'ctc_amount' => 2_200,

            // ── Adoption Credit ──────────────────────────────────────────────
            // [ASSUMED] ~$17,000 partially refundable; indexed annually; P13 sign-off gate
            'adoption_credit' => 17_000, // [ASSUMED]

            // ── American Opportunity Tax Credit ──────────────────────────────
            // [CITED: IRC §25A(i); $2,500 maximum]
            'aotc_credit' => 2_500,

            // ── 529: K-12 Annual Limit + 529→Roth Lifetime ───────────────────
            // [CITED: OBBBA §70400; 529→Roth per SECURE 2.0 §126 — 15-yr rule + Roth annual cap]
            'section_529_k12_annual' => 20_000,
            'section_529_to_roth_lifetime' => 35_000,

            // ── Trump Account (OBBBA §70450; 2025–2028; opens 2026-07-04) ────
            // [CITED: OBBBA §70450 — per-child savings account, new child at birth]
            'trump_account_annual' => 5_000,
            'trump_account_employer_exclusion' => 2_500,  // employer contribution excluded from income
            'trump_account_seed' => 1_000,  // one-time government seed grant

            // ── §179 Expensing ────────────────────────────────────────────────
            // [CITED: IRC §179; IRS Rev. Proc. 2025-32]
            // listed-property threshold: >50% qualified business use
            // mid-quarter convention trigger: >40% of depreciable basis placed in Q4
            'section_179_limit' => 2_560_000,
            'section_179_phaseout_start' => 4_090_000,
            'section_179_gvwr_lbs' => 6_000,  // heavy-vehicle threshold

            // ── §195 Startup Immediate Deduction ─────────────────────────────
            // [CITED: IRC §195(b)(1); $5,000 immediate; remainder amortized over 15 yrs]
            'section_195_immediate' => 5_000,

            // ── De Minimis Safe Harbor ────────────────────────────────────────
            // [CITED: Treas. Reg. §1.263(a)-1(f); $2,500/invoice with written AFS policy]
            'de_minimis_safe_harbor' => 2_500,

            // ── Home Office (Simplified Method) ──────────────────────────────
            // [CITED: Rev. Proc. 2013-13; $5/sq ft, maximum 300 sq ft → $1,500 cap]
            'home_office_simplified_rate' => 5,      // $5 per square foot
            'home_office_simplified_cap' => 1_500,

            // ── Standard Mileage Rate 2026 ────────────────────────────────────
            // [CITED: IRS Notice 2025-67 / Rev. Proc. 2025-32; 72.5 cents/mile]
            'standard_mileage_rate' => 0.725,  // dollars per mile

            // ── Cruise / Foreign Convention Cap ──────────────────────────────
            // [CITED: IRC §274(h)(2); $2,000 total deduction cap for foreign conventions]
            'cruise_convention_cap' => 2_000,

            // ── MACRS Depreciation Recovery Periods ──────────────────────────
            // [CITED: IRC §168; Rev. Proc. 87-56]
            'macrs_rental_solar_years' => 5,   // 5-yr + 100%/80% bonus eligible
            'macrs_pool_landscape_years' => 15,  // 15-yr land improvement + bonus eligible
            'guard_dog_macrs_years' => 7,   // 7-yr personal property

            // ── §25D Residential Clean Energy Credit (Retro 2022–2025) ───────
            // [CITED: IRC §25D; expired 2025-12-31 for primary home federal credit;
            //  amendment-scanner only — never surface as "available" for 2026+ installs]
            // Educational recovery range is uncertainty-framed: "$10K–$20K" (not a guarantee)
            'section_25d_credit_rate' => 0.30,  // 30%
            'section_25d_recovery_min' => 10_000, // educational range floor
            'section_25d_recovery_max' => 20_000, // educational range ceiling

            // ── Amended Return Lookback Window ────────────────────────────────
            // [CITED: IRC §6511(a); 3-year lookback for refund claims]
            'amended_return_lookback_yr' => 3,

            // ── LTCG 0% Bracket Top ───────────────────────────────────────────
            // [ASSUMED] Rev. Proc. 2025-32 indexes these; approximate 2026 values;
            //  used for bracket-awareness glossary line only (RPT-07) — P13 sign-off gate
            'ltcg_zero_bracket_single' => 49_000, // [ASSUMED]
            'ltcg_zero_bracket_mfj' => 98_000, // [ASSUMED]

            // ── Net Investment Income Tax (§1411) ─────────────────────────────
            // [CITED: IRC §1411; thresholds are NOT inflation-adjusted]
            'niit_rate' => 0.038,
            'niit_threshold_single' => 200_000,
            'niit_threshold_mfj' => 250_000,

            // ── Annual Gift Exclusion ─────────────────────────────────────────
            // [ASSUMED] ~$19,000 for 2026; indexed annually; P13 sign-off gate
            'gift_exclusion_annual' => 19_000, // [ASSUMED]

            // ── Estate / Gift Tax Exemption ───────────────────────────────────
            // [ASSUMED] OBBBA §70500 — approximate 2026 indexed values;
            //  $30M MFJ via portability; indexed from 2027; P13 sign-off gate
            'estate_exemption_single' => 15_000_000, // [ASSUMED]
            'estate_exemption_joint' => 30_000_000, // [ASSUMED]

            // ── QSBS (§1202) ──────────────────────────────────────────────────
            // [CITED: IRC §1202; OBBBA increased cap per §70600]
            // [ASSUMED] $15M cap confirmed per OBBBA; gross-asset test $75M at time of issuance;
            //   10× basis alternative applies if greater — P13 sign-off gate for 10× interpretation
            'qsbs_cap_per_issuer' => 15_000_000, // [ASSUMED] or 10× basis
            'qsbs_gross_asset_test' => 75_000_000,
            'qsbs_gain_pct_3yr' => 0.50,  // 50% exclusion at 3 yrs
            'qsbs_gain_pct_4yr' => 0.75,  // 75% at 4 yrs
            'qsbs_gain_pct_5yr' => 1.00,  // 100% at 5+ yrs

            // ── §1244 Ordinary-Loss Cap ───────────────────────────────────────
            // [CITED: IRC §1244; limits excess stock loss ordinary (vs capital)]
            'section_1244_loss_cap_single' => 50_000,
            'section_1244_loss_cap_mfj' => 100_000,

            // ── §1341 Repayment Threshold ─────────────────────────────────────
            // [CITED: IRC §1341; claim-of-right doctrine applies if repayment > $3,000]
            'section_1341_threshold' => 3_000,

            // ── Kiddie-Tax Age Bound ──────────────────────────────────────────
            // [CITED: IRC §1(g); unearned income of child under 24 who is a full-time student]
            'kiddie_tax_age_max' => 24,

            // ── QOF Mandatory Recognition Year ───────────────────────────────
            // [CITED: IRC §1400Z-2; OBBBA removes QOF recognition deferral end-2026
            //  for holders with pre-2027 investments — recognition required by 2026-12-31]
            'qof_mandatory_recognition_year' => 2026,

            // ── Medical (§213) ────────────────────────────────────────────────
            // [CITED: IRC §213(a); 7.5% floor permanent per TCJA sunset override]
            'medical_agi_floor' => 0.075,
            // [CITED: IRC §213(d)(2); $50/person/night for lodging incidental to medical care]
            'medical_lodging_per_night' => 50,

            // ── Student-Loan Interest ─────────────────────────────────────────
            // [CITED: IRC §221; OBBBA permanently repeals income phaseout (§70201)]
            'student_loan_interest_cap' => 2_500,

            // ── Educator Expense ──────────────────────────────────────────────
            // [CITED: IRC §62(a)(2)(D); $300 per eligible educator; indexed]
            'educator_expense_cap' => 300,

            // ── FEIE (§911) ───────────────────────────────────────────────────
            // [ASSUMED] ~$130,000 for 2026; indexed annually; P13 sign-off gate
            // Federal parts only (housing addback and state FEIE are out of scope)
            'feie_limit' => 130_000, // [ASSUMED]

            // ── Augusta Rule / §280A Day Cap ─────────────────────────────────
            // [CITED: IRC §280A(g); ≤14 rental days → rental income excludable]
            'augusta_day_cap' => 14,

            // ── Medicare / HSA Part A Lookback ───────────────────────────────
            // [CITED: IRS Notice 2004-2 Q&A 28; Medicare Part A retroactively covers
            //  6 months before the month you sign up at age 65 — HSA contributions
            //  during that window are excess and subject to tax + penalty]
            'medicare_hsa_lookback_months' => 6,

            // ── ACA Cliff (400% FPL) ──────────────────────────────────────────
            // [ASSUMED] approximate 2026 400% FPL thresholds for continental US;
            //  indexed annually by HHS; P13 sign-off gate
            // Post-2025: enhanced ACA credits expired (ARPA extension ended 2025-12-31);
            //  the repayment cap no longer applies — full clawback at 400%+ FPL
            'aca_fpl_pct' => 4.00,     // 400% FPL cliff
            'aca_fpl_threshold_single' => 62_600,   // [ASSUMED]
            'aca_fpl_threshold_family4' => 128_600,  // [ASSUMED]

            // ── Estimated-Tax Safe Harbor ─────────────────────────────────────
            // [CITED: IRC §6654; Treas. Reg. §1.6654-2]
            'estimated_tax_safe_harbor_rate' => 1.00,  // 100% of prior-year liability
            'estimated_tax_safe_harbor_high' => 1.10,  // 110% if AGI > $150K
            'estimated_tax_high_agi' => 150_000,

            // ── EITC Investment-Income Limit ──────────────────────────────────
            // [ASSUMED] ~$11,950 for 2026 per Rev. Proc. 2025-32; P13 sign-off gate
            'eitc_investment_income_limit' => 11_950, // [ASSUMED]

            // ── Gambling Losses (suppressed — never surface as fully deductible) ─
            // [CITED: OBBBA §70250; IRC §165(d) amended — only 90% of losses deductible
            //  from 2026; break-even bettors owe tax on 10% phantom income]
            // TAX-09 band=suppress enforces this constant never renders a "fully deductible" finding
            'gambling_loss_pct_deductible' => 0.90,

        ],

    ],

];
