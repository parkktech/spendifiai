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
            'single'            => 16_100,
            'married_joint'     => 32_200,
            'married_separate'  => 16_100,
            'head_of_household' => 24_150,
        ],

        // Additional standard deduction for age 65+, per qualifying person
        // [CITED: IRS Rev. Proc. 2025-32]
        'standard_deduction_senior_addition' => [
            'single'            => 2_050,
            'married_joint'     => 1_650,  // per qualifying spouse
            'married_separate'  => 1_650,
            'head_of_household' => 2_050,
        ],

        // ── 401(k) / Employer Plan Limits ────────────────────────────────
        // [CITED: IRS Notice 2025-67]
        '401k' => [
            'employee_deferral'               => 24_500,
            'catchup_age_50_plus'             => 8_000,   // ages 50-59 and 64+; total 32,500
            'catchup_age_60_to_63'            => 11_250,  // SECURE 2.0 §109; replaces 50+ for 60-63; total 35,750
            // SECURE 2.0 §603: prior-year FICA wages >= threshold → catch-up MUST be Roth
            // [ASSUMED: exact 2026 indexed threshold; IRS Notice confirms base $145k indexed;
            //  milestone research uses $150k — treat this value as needing tax-professional confirmation]
            'mandatory_roth_catchup_threshold' => 150_000,
            'highly_compensated_threshold'     => 160_000,
        ],

        // ── IRA Limits ───────────────────────────────────────────────────
        // [CITED: IRS Notice 2025-67]
        'ira' => [
            'annual_limit'        => 7_500,
            'catchup_age_50_plus' => 1_100,   // total 8,600 for 50+; new in 2026 (was $1,000)

            // Roth IRA contribution phase-out (MAGI)
            // [CITED: IRS Notice 2025-67 + IRS Rev. Proc. 2025-32]
            'roth_phaseout' => [
                'single'            => ['from' => 153_000, 'to' => 168_000],
                'married_joint'     => ['from' => 242_000, 'to' => 252_000],
                'married_separate'  => ['from' => 0,       'to' => 10_000],
                'head_of_household' => ['from' => 153_000, 'to' => 168_000],
            ],

            // Traditional IRA deduction phase-out when covered by workplace plan
            // [CITED: IRS Notice 2025-67]
            'traditional_deduction_phaseout_covered' => [
                'single'            => ['from' => 81_000,  'to' => 91_000],
                'married_joint'     => ['from' => 129_000, 'to' => 149_000],
                'married_separate'  => ['from' => 0,       'to' => 10_000],
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
            'self_only'                  => 4_400,
            'family'                     => 8_750,
            'catchup_age_55_plus'        => 1_000,  // statutory, not inflation-adjusted
            'hdhp_min_deductible_self'   => 1_700,
            'hdhp_min_deductible_family' => 3_400,
            'hdhp_max_oop_self'          => 8_500,
            'hdhp_max_oop_family'        => 17_000,
        ],

        // ── Self-Employment / FICA ────────────────────────────────────────
        // [CITED: IRS.gov Topic 751; SSA.gov 2026 COLA announcement]
        'se_tax' => [
            'net_earnings_multiplier'              => 0.9235,  // 1 - (0.153/2) normalizes for employer share
            'rate'                                 => 0.153,   // 12.4% SS + 2.9% Medicare
            'ss_rate'                              => 0.124,
            'medicare_rate'                        => 0.029,
            'ss_wage_base'                         => 184_500, // SS portion only applies up to this limit
            'deductible_fraction'                  => 0.5,     // half of SE tax deducted from AGI
            // Additional Medicare surtax (0.9%) on earned income above:
            'additional_medicare_threshold_single' => 200_000,
            'additional_medicare_threshold_joint'  => 250_000,
            'additional_medicare_rate'             => 0.009,
        ],

        // ── QBI (§199A) Deduction Thresholds ─────────────────────────────
        // [CITED: IRS Rev. Proc. 2025-32; OBBBA §70105 (permanent extension + $400 minimum)]
        'qbi' => [
            'rate'                    => 0.20,    // 20% of qualified business income
            'phase_out_start_single'  => 201_750,
            'phase_out_start_joint'   => 403_500,
            'phase_out_window_single' => 75_000,  // OBBBA-expanded (was $50k pre-OBBBA)
            'phase_out_window_joint'  => 150_000, // OBBBA-expanded (was $100k pre-OBBBA)
            // Derived: full SSTB elimination at single=$276,750; joint=$553,500
            'minimum_deduction'       => 400,     // OBBBA §70105: if QBI >= $1,000 and materially participates
            'minimum_qbi_for_floor'   => 1_000,   // QBI must be at least $1,000 to claim $400 minimum
        ],

        // ── Roth Optimization Decision Bands ─────────────────────────────
        // [ASSUMED: logic derived from standard financial planning guidance; not IRS-mandated]
        'roth_optimization' => [
            'prefer_roth_at_or_below'         => 0.12,  // marginal rate <= 12% → Roth lean
            'prefer_traditional_at_or_above'  => 0.32,  // marginal rate >= 32% → Traditional lean
            // 22% and 24% brackets → 'split' (context-dependent)
        ],

    ],

];
