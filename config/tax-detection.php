<?php

// Source: D1 (versioned-rule schema), D2 (materiality gates in config — not service code),
//         CONTEXT.md decisions, INTEGRATION-MAP consolidated table.
//
// Design note (10-03 precedent distinction):
//   CrossSourceReviewService tolerances are CLASS CONSTANTS because they are business-logic
//   thresholds driven by statistical gap analysis.
//   Materiality gates and band cutpoints live HERE because the v2.1 spec explicitly mandates
//   them in config so they can be updated without touching service code.
//
// All cent values are integer cents; all rates are floats [0..1].

return [

    // ── Materiality Gates (D2 / FLAG-08) ─────────────────────────────────────
    // Read by TaxRulesEngineService::passesMaterialityGate() — NEVER hardcode in service code.
    'materiality' => [
        'single_txn_auto_floor_cents' => 10_000,   // $100  — below this, never interrogate
        'recurring_pattern_annual_cents' => 50_000,   // $500/yr — recurring pattern gate
        'single_txn_interrogate_cents' => 100_000,  // $1,000 — always interrogate single txn
        'address_match_always' => true,     // address-matched transactions always interrogated
        'loan_servicer_always' => true,     // loan-servicer transactions always interrogated
    ],

    // ── Confidence Band Cutpoints (INT-07 / FLAG-06) ─────────────────────────
    // Mirror the confidence structure in config/spendifiai.php for consistency.
    // high-confidence → pre-fill "suggested — confirm" (one-tap confirm/undo, never auto-classify)
    // conditional    → standard multiple-choice interview question
    // specialist     → route to professional-review sentinel
    'confidence' => [
        'suggested_confirm_threshold' => 0.85,  // auto|conditional band: pre-fill + one-tap confirm
        'conditional_threshold' => 0.60,  // conditional band: standard interview Q
        'specialist_threshold' => 0.40,  // specialist band: route to pro-review
    ],

    // ── Durable-Fact Carry-Forward Timing (STORE-01 / D3) ────────────────────
    'facts' => [
        'reconfirm_months' => 12,   // stable-volatility facts prompt a one-tap "still true?" reconfirm
        // after N months; permanent facts never re-asked; annual facts
        // expire with the tax year
    ],

    // ── Staleness Threshold for last_verified Dates (TAX-09) ─────────────────
    // A rule whose last_verified date is more than this many days ago is flagged stale.
    'staleness_days' => 90,

    // ── Guided Interview Config (INT-01/03 / D5) ─────────────────────────────
    // initial_cap: maximum questions asked in the first pass of the interview.
    // Capped to prevent overwhelming the user; gated probes may add more later.
    'interview' => [
        'initial_cap' => 10,
    ],

    // ── Onboarding Retroactive History Depth (FLAG-12) ───────────────────────
    // How many months back the retroactive scanner reaches on first profile build.
    // Range: 12–36 months (36 is the full Plaid historical limit).
    'onboarding_history_months' => 36,

    // ── Document Request Labels (feeds docs_missing jsonb on OptimizationFinding) ─
    // Keyed by doc_id (snake_case slug); value is the user-facing label shown in the
    // "you'll be asked to upload X" affordance. P12 wires vault upload; P11 records the need.
    'doc_request_labels' => [
        'mileage_log' => 'Mileage log (contemporaneous)',
        'vehicle_purchase_date' => 'Vehicle purchase date and registration',
        'sponsorship_agreement' => 'Written sponsorship agreement',
        'market_rate_memo' => 'Market-rate comparable memo (sponsorship / Augusta)',
        'rx_letter' => 'Physician prescription or recommendation letter',
        'contractor_invoices' => 'Contractor invoices and improvement receipts',
        'gallons_log' => 'Off-road fuel gallons log (for fuel tax credit)',
        'hdhp_enrollment_proof' => 'HDHP enrollment confirmation from insurer',
        'solar_invoice' => 'Solar installer invoice (for §25D retro scanner)',
        'ev_purchase_docs' => 'EV purchase agreement and MSRP confirmation (for §30D retro)',
        'home_office_measurement' => 'Home office square footage measurement and floor plan',
        'loan_statements' => 'Loan servicer statements (balance, interest paid)',
        'donation_receipts' => 'Charitable contribution receipts and written acknowledgments',
        '1099_contractor' => '1099-NEC from contractor payments',
        'lease_agreement' => 'Lease agreement (vehicle or property)',
        'section_179_election' => 'Signed §179 election statement',
        'business_use_log' => 'Listed-property business-use log (% qualified use)',
        'esop_or_equity_grant' => 'Equity grant agreement or ESOP notice',
        '83b_election' => '83(b) election filing receipt (postmark proof)',
        'qsbs_issuance_cert' => 'QSBS original issuance certificate (§1202 qualification)',
        'ira_contribution_record' => 'IRA contribution records (Roth + Traditional combined)',
        'hsa_contribution_record' => 'HSA contribution records (payroll + personal)',
        'prior_year_return' => 'Prior-year federal tax return (Form 1040)',
        'w2_box_12' => 'W-2 with Box 12 codes (pre-tax benefits, excess salary deferral)',
    ],

    // ── Versioned Rule Registry (TAX-09 / D1) ────────────────────────────────
    // Every Phase 11 detector rule carries the canonical TD-v2Δ §9 schema:
    //   rule_id, authority, effective_start, effective_end, phaseouts (MAGI-keyed),
    //   inflation_adjusted, source_url, last_verified, status, band, cap_cents (where applicable)
    //
    // Status enum:  verified | needs_review | expired | expired_pending_extension
    // Band enum:    auto | conditional | specialist | suppress | hard_block
    //
    // Suppress/hard_block rules NEVER render a finding; Phase 13 SAFE-06 plugs into this list.
    // TaxRulesEngineService::validateRule() is the single enforcement point.
    'rules' => [

        // ── OBBBA: Tips Deduction (IRC §224; 2025–2028) ──────────────────────
        'tips_deduction' => [
            'rule_id' => 'tips_deduction',
            'authority' => 'IRC §224 (OBBBA §70101)',
            'effective_start' => '2025-01-01',
            'effective_end' => '2028-12-31',
            'phaseouts' => [
                'magi_single' => 150_000,
                'magi_mfj' => 300_000,
            ],
            'cap_cents' => 2_500_000,   // $25,000 cap per return
            'inflation_adjusted' => false,
            'source_url' => 'https://www.congress.gov/bill/119th-congress/house-bill/1',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'auto',      // tip income visible in bank data
        ],

        // ── OBBBA: Overtime Deduction (IRC §225; 2025–2028) ──────────────────
        // W-2 box codes TP/TT required for eligibility; conditional band
        'ot_deduction' => [
            'rule_id' => 'ot_deduction',
            'authority' => 'IRC §225 (OBBBA §70102)',
            'effective_start' => '2025-01-01',
            'effective_end' => '2028-12-31',
            'phaseouts' => [
                'magi_single' => 150_000,
                'magi_mfj' => 300_000,
            ],
            'cap_cents' => 2_500_000,   // $25,000 MFJ cap; $12,500 single [ASSUMED] — see tax-rules.php
            'inflation_adjusted' => false,
            'source_url' => 'https://www.congress.gov/bill/119th-congress/house-bill/1',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional', // W-2 box code TP/TT verification required
        ],

        // ── OBBBA: Senior Deduction (IRC §226; 2025–2028) ────────────────────
        'senior_deduction' => [
            'rule_id' => 'senior_deduction',
            'authority' => 'IRC §226 (OBBBA §70103)',
            'effective_start' => '2025-01-01',
            'effective_end' => '2028-12-31',
            'phaseouts' => [
                'magi_single' => 75_000,
                'magi_mfj' => 150_000,
            ],
            'cap_cents' => 600_000,     // $6,000 per qualifying person
            'inflation_adjusted' => false,
            'source_url' => 'https://www.congress.gov/bill/119th-congress/house-bill/1',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional', // age 65+ gate required
        ],

        // ── OBBBA: Auto-Loan Interest Deduction (IRC §163(h); 2025–2028) ─────
        // US-assembled vehicles purchased on or after 2025-01-01 only
        'auto_loan_interest' => [
            'rule_id' => 'auto_loan_interest',
            'authority' => 'IRC §163(h)(4)(D) (OBBBA §70104)',
            'effective_start' => '2025-01-01',
            'effective_end' => '2028-12-31',
            'phaseouts' => [],
            'cap_cents' => 1_000_000,   // $10,000 per return
            'inflation_adjusted' => false,
            'source_url' => 'https://www.congress.gov/bill/119th-congress/house-bill/1',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional', // US-assembled + purchase-date gate required
        ],

        // ── OBBBA: SALT Cap (IRC §164(b)(6); 2025–2029) ──────────────────────
        'salt_deduction_cap' => [
            'rule_id' => 'salt_deduction_cap',
            'authority' => 'IRC §164(b)(6) (OBBBA §70105 — $40K cap)',
            'effective_start' => '2025-01-01',
            'effective_end' => '2029-12-31',
            'phaseouts' => [],           // phases toward $10K above ~$500K MAGI (TBD in regs)
            'cap_cents' => 4_000_000,   // $40,000
            'inflation_adjusted' => false,
            'source_url' => 'https://www.congress.gov/bill/119th-congress/house-bill/1',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'auto',
        ],

        // ── QOF: Mandatory Recognition (end-2026 for pre-2027 holders) ────────
        'qof_recognition' => [
            'rule_id' => 'qof_recognition',
            'authority' => 'IRC §1400Z-2 (OBBBA removes deferral post-2026)',
            'effective_start' => '2018-01-01',
            'effective_end' => '2026-12-31',
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/newsroom/opportunity-zones',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'specialist', // pro review required for gain recognition timing
        ],

        // ── §25D Residential Clean Energy Credit (Expired 2025-12-31) ─────────
        // Primary-home federal solar credit expired — amendment-scanner only for pre-2026 installs
        // NEVER surface as "available" for 2026+ installs; validateRule() returns suppressed=true
        'residential_energy_credit_25d' => [
            'rule_id' => 'residential_energy_credit_25d',
            'authority' => 'IRC §25D',
            'effective_start' => '2022-01-01',
            'effective_end' => '2025-12-31',  // expired — amendment scanner only
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/credits-deductions/residential-clean-energy-credit',
            'last_verified' => '2026-07-01',
            'status' => 'expired',      // past-window amended-return scanner only
            'band' => 'conditional',  // eligible only for pre-2026 installs
        ],

        // ── §30D EV Credit (Expired pre-Oct-2025 window) ─────────────────────
        // EV purchases before 2025-10-01 only — past-window retro scanner
        // NEVER surface as "currently available"
        'ev_credit_30d' => [
            'rule_id' => 'ev_credit_30d',
            'authority' => 'IRC §30D (OBBBA ends credit 2025-09-30)',
            'effective_start' => '2023-01-01',
            'effective_end' => '2025-09-30',  // pre-Oct-2025 purchases only
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/credits-deductions/credits-for-new-electric-vehicles',
            'last_verified' => '2026-07-01',
            'status' => 'expired',      // date-gated past-window only
            'band' => 'conditional',  // retro scanner for qualifying pre-2025-10-01 purchases
        ],

        // ── Residential Solar 2026+ Primary Home — SUPPRESS (never-surface trio) ─
        // Federal §25D credit for 2026+ primary-home solar installs DOES NOT EXIST.
        // Surfacing it as available = direct user harm (filing false credit).
        // This entry exists solely so SAFE-06 (Phase 13) can reference it explicitly.
        'residential_solar_2026_primary_home' => [
            'rule_id' => 'residential_solar_2026_primary_home',
            'authority' => 'IRC §25D (expired; primary-home 2026+ installs not eligible)',
            'effective_start' => '2026-01-01',
            'effective_end' => null,           // no end — permanently inapplicable
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/credits-deductions/residential-clean-energy-credit',
            'last_verified' => '2026-07-01',
            'status' => 'expired',
            'band' => 'suppress',     // NEVER surface as available — never-surface trio
        ],

        // ── Gambling Losses as Fully Deductible — SUPPRESS (never-surface trio) ─
        // From 2026, only 90% of gambling losses are deductible (OBBBA §70250).
        // Break-even bettors owe tax on 10% phantom income.
        // Presenting losses as "fully deductible" = misinformation.
        'gambling_losses_fully_deductible' => [
            'rule_id' => 'gambling_losses_fully_deductible',
            'authority' => 'IRC §165(d) as amended (OBBBA §70250 — 90% limit from 2026)',
            'effective_start' => '2026-01-01',
            'effective_end' => null,           // ongoing — gambling losses never fully deductible again
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.congress.gov/bill/119th-congress/house-bill/1',
            'last_verified' => '2026-07-01',
            'status' => 'expired',      // the "fully deductible" form of this rule is dead
            'band' => 'suppress',     // NEVER surface as fully deductible — never-surface trio
        ],

    ],

];
