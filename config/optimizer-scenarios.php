<?php

/*
|--------------------------------------------------------------------------
| Optimizer scenario assumptions & knob metadata (SCENARIOS-SPEC §F)
|--------------------------------------------------------------------------
|
| ADDITIVE, engine-consumed configuration for Decision 10 (Optimization
| Scenarios). This file holds SCENARIO ASSUMPTIONS and KNOB METADATA only —
| NEVER IRS constants (those live in config/tax-rules.php).
|
| Merge-fix compliance (SCENARIOS-SPEC §M):
|   [M5] NO 'readiness' key — ObjectiveReadinessService over
|        config/optimization-objectives.php is the single readiness source.
|   [M8] NO pay-frequency periods map here — it lives once in
|        config/optimization-objectives.php ('pay_periods_per_year').
|
| Every number below is illustration/assumption metadata; the no-literal Pest
| guard (§F) requires solver + engine methods to trace every threshold here or
| to config/tax-rules.php.
|
*/

return [

    // ── Scenario computation assumptions (D9.7 illustration rules) ──────────
    'assumptions' => [
        'illustrative_growth_rate_low' => 0.04,    // D9.7 range floor
        'illustrative_growth_rate_high' => 0.07,   // range ceiling — NEVER shown as a single number
        'default_retirement_age' => 65,
        'aca_cliff_buffer_cents' => 200_000,       // $2,000 safety margin below the 400% FPL cliff
        'min_take_home_ratio' => 0.90,
        'min_take_home_ratio_cash_constrained' => 0.97,
        'auto_transfer_max_surplus_share' => 0.80,
        'income_objective_transfer_share' => 0.50,
        'hsa_grid_step_cents' => 25_000,
    ],

    // ── Solver grids (knob domains) ────────────────────────────────────────
    'grids' => [
        'roth_share_pct' => [0, 25, 50, 75, 100],
        'ira_room_fractions' => [0, 0.25, 0.5, 0.75, 1.0],
    ],

    // ── Knob-level divergence epsilons (§C.1) ──────────────────────────────
    // A dimension diverges when the difference between two objective vectors
    // exceeds its epsilon. w4.* never diverges (identical in all scenarios by
    // construction) and therefore carries no epsilon.
    'divergence' => [
        'k401.deferral_pct' => 1.0,                 // ≥ 1.0 pt
        'k401.roth_share_pct' => 25,                // ≥ one grid step
        'hsa.annual_election_cents' => 25_000,      // ≥ $250
        'ira.traditional_cents' => 50_000,          // ≥ ¼ remaining room OR ≥ $500, whichever larger
        'ira.roth_cents' => 50_000,                 // (solver takes the larger of epsilon vs ¼-room)
        'transfer.per_period_cents' => 2_500,       // ≥ $25
    ],

    // ── Tax-burden objective fill priority (§B.7 greedy order) ─────────────
    'tax_objective_priority' => ['match_capture', 'hsa', 'trad_401k', 'trad_ira'],

    // ── Trade-off one-liners (§C.3 — template-based, zero Claude for figures) ─
    // Tokens are filled server-side from engine outputs. NO dollar literals —
    // every {token} is substituted at render time from computed cents.
    'tradeoff_templates' => [
        'k401.roth_share_pct' => 'Option A: {a_pct}% Roth — Option B: {b_pct}% Roth. A keeps about {a_take_home}/paycheck more now; '
            .'B could mean roughly {b_fv_low}–{b_fv_high} more by age {age} under these assumptions (illustration).',
        'k401.deferral_pct' => 'Option A defers {a_pct}% — Option B defers {b_pct}%. The difference is about {delta_paycheck}/paycheck '
            .'now versus {delta_annual}/yr more invested (plus {match_delta}/yr employer match, if your plan allows).',
        'hsa.annual_election_cents' => 'Option A elects {a_amount}/yr to your HSA — Option B elects {b_amount}/yr. The difference is about '
            .'{delta_paycheck}/paycheck now, and HSA dollars avoid both income tax and FICA (if you are HSA-eligible).',
        'ira.traditional_cents' => 'Option A adds {a_amount} to your Traditional IRA — Option B adds {b_amount}. Traditional may lower this '
            .'year’s taxable income by roughly {delta_deduction} under these assumptions (illustration).',
        'ira.roth_cents' => 'Option A adds {a_amount} to your Roth IRA — Option B adds {b_amount}. Roth grows without a current-year '
            .'deduction; the trade-off is about {delta_paycheck}/paycheck now versus tax-free growth later (illustration).',
        'transfer.per_period_cents' => 'Option A sets aside {a_amount} every {period_label} — Option B sets aside {b_amount}. That is about '
            .'{delta_annual}/yr difference moved automatically to savings.',
    ],

    // ── Checklist step + benefit-line templates (§D.5 skeleton, token slots) ─
    // One group per knob (K1..K6). Each entry carries a 'directive' imperative
    // step, a 'confirm_ask' variant (rendered when the anchoring fact is not yet
    // confirmed — D9.2 fact-gating), and a 'benefit_line' with token slots filled
    // from attributeBenefits() output. NO dollar literals; figures come from the
    // engine at render time. Downstream materialization (14-07/14-08) fills tokens.
    'checklist_templates' => [
        'k1' => [
            'knob' => 'w4',
            'directive' => 'Contact your payroll department (or open your payroll portal’s W-4 form) and update your '
                .'filing status to {confirmed_status_label} and your dependents from {w4_deps} to {family_deps}.',
            'confirm_ask' => 'Confirm what filing status and number of dependents your current W-4 claims — check your '
                .'latest pay stub or payroll portal.',
            'benefit_line' => '≈ +{per_paycheck}/paycheck ({annual}/yr) in take-home.',
        ],
        'k2' => [
            'knob' => 'k401',
            'directive' => 'Log into your 401(k) portal and set your Roth split to {roth_pct}% Roth / {trad_pct}% '
                .'traditional (if your plan allows both).',
            'confirm_ask' => 'Confirm your current 401(k) Roth-vs-traditional split in your plan portal.',
            'benefit_line' => 'Adjusts this year’s taxable income by about {delta_tax}/yr; retirement impact roughly '
                .'{fv_low}–{fv_high} more by {age} (illustration).',
        ],
        'k3' => [
            'knob' => 'k401',
            'directive' => 'Log into your 401(k) portal and set your contribution to {pct}% of pay (if your plan allows).',
            'confirm_ask' => 'Confirm your current 401(k) contribution percentage in your plan portal.',
            'benefit_line' => 'Captures ≈ {match}/yr in employer match; changes take-home by about {delta_paycheck}/paycheck.',
        ],
        'k4' => [
            'knob' => 'hsa',
            'directive' => 'During open enrollment (or a qualifying life event), set your HSA payroll election to '
                .'{amount}/yr (if you are enrolled in an HSA-eligible plan).',
            'confirm_ask' => 'Confirm your health plan is HSA-eligible and your current HSA election.',
            'benefit_line' => 'HSA dollars avoid income tax and FICA; about {delta_paycheck}/paycheck difference.',
        ],
        'k5' => [
            'knob' => 'ira',
            'directive' => 'Contribute {trad}/{roth} to your Traditional/Roth IRA — as {n} transfers of {amount}.',
            'confirm_ask' => 'Confirm your year-to-date IRA contributions so we can compute your remaining room.',
            'benefit_line' => 'Traditional may lower taxable income by about {delta_deduction}/yr (illustration).',
        ],
        'k6' => [
            'knob' => 'transfer',
            'directive' => 'Set up an automatic transfer of {amount} every {period_label} from checking to savings.',
            'confirm_ask' => 'Confirm the account you would like the automatic transfer to draw from.',
            'benefit_line' => 'This automatic transfer sets aside {annual}/yr.',
        ],
    ],

    // [M5] NO 'readiness' key — ObjectiveReadinessService is the single source.
    // [M8] NO pay-frequency periods map — lives in optimization-objectives.php.
];
