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

    // ── ACA Cliff Constants (FLAG-22, TAX-08 equivalent) ─────────────────────
    // FPL 400% thresholds for premium credit cliff (2026, continental US).
    // Used by AcaCliffMonitor to gate cliff proximity warnings.
    // Verify annually: healthcare.gov FPL charts published each fall.
    'aca_cliff_cents' => [
        'single_2026' => 6_260_000,    // ~$62,600 single (continental US, 2026)
        'family4_2026' => 12_860_000,  // ~$128,600 family-4 (continental US, 2026)
    ],

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

    // ── Withholding Gap Floor (FLAG-03) ──────────────────────────────────────
    // The minimum gap (estimated_tax - detected_withholding) that triggers a finding.
    // Read by WithholdingGapDetector — NEVER hardcode in service code.
    'withholding' => [
        'gap_floor_cents' => 50_000,  // $500 — matches TD-v1 §11.5 / INTEGRATION-MAP config table
    ],

    // ── Deduction Probe Thresholds (FLAG-05) ─────────────────────────────────
    // Probes fire only when their data prerequisite is verified AND materiality passes.
    // Meal deduction rate (50% of business meals allowable under §274).
    'deduction' => [
        'meal_rate' => 0.50,
        'electronics_min_cents' => 50_000,  // $500 — minimum qualifying §179 eligible purchase
    ],

    // ── Audit Risk Thresholds (FLAG-15) ──────────────────────────────────────
    // Score inputs: each factor adds 1 point; score >= threshold triggers a finding.
    'audit_risk' => [
        'score_threshold' => 2,    // at least 2 risk factors to emit (avoids false positives)
        // Charitable outlier: contributions > charitable_pct_of_income % of income
        'charitable_outlier_pct' => 0.20, // 20% of income is a documented audit trigger
        // SE perpetual losses: how many consecutive loss years before flagging
        'se_loss_years_threshold' => 2,
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

        // ── FLAG-02: Filing Status Mismatch ──────────────────────────────────
        // SURFACED never ASSERTED — detector compares snapshot filing_status vs
        // UserFinancialProfile.tax_filing_status. Any mismatch → educational finding.
        'filing_status_mismatch' => [
            'rule_id' => 'filing_status_mismatch',
            'authority' => 'IRS Publication 501; IRC §1(a)–(d)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/pub/irs-pdf/p501.pdf',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',  // professional review required to confirm
        ],

        // ── FLAG-03: Withholding Gap ──────────────────────────────────────────
        // Fires when engine-computed estimated_tax − detected_withholding exceeds
        // config('tax-detection.withholding.gap_floor_cents') ($500 default).
        // Dollar magnitude is TaxRulesEngineService-only; detector emits no dollar.
        'withholding_gap' => [
            'rule_id' => 'withholding_gap',
            'authority' => 'IRC §3402; IRS Publication 505',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/pub/irs-pdf/p505.pdf',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',  // withholding review requires professional or W-4 update
        ],

        // ── FLAG-04: Employer Match Gap ───────────────────────────────────────
        // Fires when durable facts show employer match_pct > 0 and user contribution
        // is below the match threshold. "if your plan allows" framing mandatory.
        'employer_match_gap' => [
            'rule_id' => 'employer_match_gap',
            'authority' => 'IRC §401(k)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/retirement-plans/401k-plans',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'auto',  // unclaimed match is the highest-return action for W-2 workers
        ],

        // ── FLAG-05: Deduction Probes (5 probes) ─────────────────────────────
        // Each probe is prerequisite-gated. Merchant-pattern enrichment (FLAG-10)
        // lands in 11-07 — these probes fire on profile/entity/fact prerequisites only.

        'deduction_home_office' => [
            'rule_id' => 'deduction_home_office',
            'authority' => 'IRC §280A; Rev. Proc. 2013-13 (simplified $5/sqft, $1,500 cap)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'cap_cents' => 150_000,   // $1,500 simplified method cap; regular method has no cap
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p587',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',  // sq footage and exclusive-use test required
        ],

        'deduction_vehicle' => [
            'rule_id' => 'deduction_vehicle',
            'authority' => 'IRC §179; Rev. Proc. 2025-33 (standard mileage 72.5¢/mi 2026)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => true,  // mileage rate adjusted annually
            'source_url' => 'https://www.irs.gov/tax-professionals/standard-mileage-rates',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',  // business-use % and method election required
        ],

        'deduction_electronics' => [
            'rule_id' => 'deduction_electronics',
            'authority' => 'IRC §179; IRC §168(k) (bonus depreciation)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p946',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',  // listed-property business-use % > 50% required
        ],

        'deduction_pet' => [
            'rule_id' => 'deduction_pet',
            'authority' => 'IRC §162 (guard-dog/working-animal); IRC §170 (*Van Dusen* foster)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p526',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'specialist',   // gray-area: question + pro routing only (never assert)
        ],

        'deduction_meals' => [
            'rule_id' => 'deduction_meals',
            'authority' => 'IRC §274 (50% business meals); Notice 2021-63 (restaurant exception expired)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p463',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',  // business purpose and documentation required
        ],

        // ── FLAG-14: Commingling Monitor ──────────────────────────────────────
        // Locked wording: "Business owners commonly keep a separate account..."
        // Warn-and-educate ONLY. NEVER "you qualify as a business."
        'commingling_detected' => [
            'rule_id' => 'commingling_detected',
            'authority' => 'IRS hobby-loss 9-factor test; IRC §183',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/taxtopics/tc307',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',  // education/awareness — separate-account recommendation
        ],

        // ── FLAG-15: Audit Risk Score ─────────────────────────────────────────
        // Locked protective framing: "returns with patterns like [X] commonly receive
        // additional IRS scrutiny — here is the documentation that typically resolves it."
        // NEVER accusations, NEVER numeric audit probability.
        'audit_risk_score' => [
            'rule_id' => 'audit_risk_score',
            'authority' => 'IRS audit selection patterns; DIF score research; §183 hobby-loss criteria',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/businesses/small-businesses-self-employed/irs-audit-rates',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',  // protective-framing finding with documentation checklist
        ],

        // ── FLAG-28: Profile-vs-Reality Conformance (3 planes) ───────────────
        // D13 (LOCKED): both directions per plane; every mismatch = OptimizationFinding
        // + educational question ("Your paystub appears to show X while profile says Y").
        // NEVER auto-write to UserFinancialProfile; profile updates via user-confirm only.

        'conformance_filing_status' => [
            'rule_id' => 'conformance_filing_status',
            'authority' => 'IRS Publication 501; IRC §1 (filing status rules)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/pub/irs-pdf/p501.pdf',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'conformance_ira_hsa' => [
            'rule_id' => 'conformance_ira_hsa',
            'authority' => 'IRC §408 (IRA); IRC §223 (HSA); Rev. Proc. 2025-32 (limits)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => true,
            'source_url' => 'https://www.irs.gov/retirement-plans/ira-faqs',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'conformance_checkbox' => [
            'rule_id' => 'conformance_checkbox',
            'authority' => 'IRS Schedule E (rental), Form 2441 (childcare), IRC §221 (student loans)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/forms-instructions',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        // ── FLAG-10: Category Library Rules (Plan 11-07) ─────────────────────

        'category_vehicle' => [
            'rule_id' => 'category_vehicle',
            'authority' => 'IRC §179; Rev. Proc. 2025-33 (72.5¢/mi standard mileage); IRC §274',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => true,
            'source_url' => 'https://www.irs.gov/tax-professionals/standard-mileage-rates',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'category_solar' => [
            'rule_id' => 'category_solar',
            'authority' => 'IRC §25D (expired 2025-12-31 for primary home); MACRS 5-yr for rental',
            'effective_start' => '2022-01-01',
            'effective_end' => '2025-12-31',
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/credits-deductions/residential-clean-energy-credit',
            'last_verified' => '2026-07-01',
            'status' => 'expired',     // retro-scanner only for pre-2026 installs
            'band' => 'conditional',
        ],

        'category_pool_spa' => [
            'rule_id' => 'category_pool_spa',
            'authority' => 'IRC §168 (15-yr land improvement); IRC §213 (medical, 7.5% AGI); IRC §121 (basis)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p946',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'specialist',   // gray-area: always routes to pro review
        ],

        'category_landscaping' => [
            'rule_id' => 'category_landscaping',
            'authority' => 'IRC §168 (15-yr land improvement); IRC §121 (basis); IRC §162 (business)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p946',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'category_home_improvement' => [
            'rule_id' => 'category_home_improvement',
            'authority' => 'IRC §179; IRC §168 (depreciation); IRC §121 (home basis)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p946',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'category_medical' => [
            'rule_id' => 'category_medical',
            'authority' => 'IRC §223 (HSA); IRC §213 (medical deduction, 7.5% AGI floor)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p502',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        // ── FLAG-10 Gap Closure: travel_cluster, rv_boat, masters_14_day ─────────

        'category_travel_cluster' => [
            'rule_id' => 'category_travel_cluster',
            'authority' => 'IRC §162 (ordinary/necessary business travel); Rev. Proc. 2025-33 (per-diem); IRC §274(m)(3)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p463',
            'last_verified' => '2026-07-02',
            'status' => 'verified',
            'band' => 'conditional',  // primarily-business test required
        ],

        'category_rv_boat' => [
            'rule_id' => 'category_rv_boat',
            'authority' => 'IRC §163(h)(4)(A) (RV/boat as qualified second home); IRC §163(h)(3) ($750K debt cap)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p936',
            'last_verified' => '2026-07-02',
            'status' => 'verified',
            'band' => 'conditional',  // qualified-residence test required
        ],

        'category_masters_14_day' => [
            'rule_id' => 'category_masters_14_day',
            'authority' => 'IRC §280A(g) (14-day personal-residence rental exclusion — "Augusta Rule")',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p527',
            'last_verified' => '2026-07-02',
            'status' => 'verified',
            'band' => 'conditional',  // days-rented question gates eligibility
        ],

        // ── FLAG-07: Deductible SaaS Sweep ───────────────────────────────────

        'deductible_saas_sweep' => [
            'rule_id' => 'deductible_saas_sweep',
            'authority' => 'IRC §162 (ordinary and necessary business expenses)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p535',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',  // business-use prerequisite required
        ],

        // ── FLAG-11: Recurring Payee Sweep modules ────────────────────────────

        'recurring_payee_worker_classification' => [
            'rule_id' => 'recurring_payee_worker_classification',
            'authority' => 'IRC §3401 (FICA); Rev. Rul. 87-41 (20-factor); IRC §1402 (SE income)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/taxtopics/tc762',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',  // warn-and-educate; classification decision is facts-and-circumstances
        ],

        'recurring_payee_childcare' => [
            'rule_id' => 'recurring_payee_childcare',
            'authority' => 'IRC §21 (Child and Dependent Care Credit); IRC §129 (Dependent Care FSA)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p503',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'recurring_payee_tuition_loans' => [
            'rule_id' => 'recurring_payee_tuition_loans',
            'authority' => 'IRC §25A (AOTC/LLC); IRC §127 (employer §127); IRC §221 (student loan $2,500)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p970',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'recurring_payee_charitable' => [
            'rule_id' => 'recurring_payee_charitable',
            'authority' => 'IRC §170 (charitable); Rev. Proc. 2023-34 (DAF); IRC §170(e) (appreciated property)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p526',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'recurring_payee_storage_coworking' => [
            'rule_id' => 'recurring_payee_storage_coworking',
            'authority' => 'IRC §162 (ordinary and necessary business expenses)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p535',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'recurring_payee_se_health_insurance' => [
            'rule_id' => 'recurring_payee_se_health_insurance',
            'authority' => 'IRC §162(l) (SE health insurance 100% deduction); IRC §105 (HRA)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p535',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        // ── FLAG-12: Retroactive Scanners ─────────────────────────────────────

        'retroactive_missed_credit_25d' => [
            'rule_id' => 'retroactive_missed_credit_25d',
            'authority' => 'IRC §25D; IRC §6511 (3-year amended return window)',
            'effective_start' => '2022-01-01',
            'effective_end' => '2025-12-31',
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/credits-deductions/residential-clean-energy-credit',
            'last_verified' => '2026-07-01',
            'status' => 'expired',
            'band' => 'conditional',
        ],

        'retroactive_ev_credit_30d' => [
            'rule_id' => 'retroactive_ev_credit_30d',
            'authority' => 'IRC §30D; IRC §6511 (3-year amended return window)',
            'effective_start' => '2023-01-01',
            'effective_end' => '2025-09-30',
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/credits-deductions/credits-for-new-electric-vehicles',
            'last_verified' => '2026-07-01',
            'status' => 'expired',
            'band' => 'conditional',
        ],

        'retroactive_basis_reconstruction' => [
            'rule_id' => 'retroactive_basis_reconstruction',
            'authority' => 'IRC §1011 (adjusted basis); IRC §121 (gain exclusion basis); IRC §168 (rental)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p551',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        // ── FLAG-18: Safe-Harbor Benchmark (REFRAMED per D10) ─────────────────
        // NEVER "your estimated taxes" — penalty-avoidance benchmark ONLY.
        // Arithmetic: prior-year liability × 100%/110% + detected IRS payments only.

        'safe_harbor_benchmark' => [
            'rule_id' => 'safe_harbor_benchmark',
            'authority' => 'IRC §6654(d)(1) (safe-harbor from underpayment penalty); IRS Publication 505',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/pub/irs-pdf/p505.pdf',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',  // penalty-avoidance benchmark, not tax bill
        ],

        // ── FLAG-26: Penalty Prevention Sweep ────────────────────────────────

        'penalty_excess_ira_contribution' => [
            'rule_id' => 'penalty_excess_ira_contribution',
            'authority' => 'IRC §4973 (6% excise tax on excess IRA contributions); IRC §408(d)(4)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/retirement-plans/ira-faqs',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'penalty_excess_hsa_contribution' => [
            'rule_id' => 'penalty_excess_hsa_contribution',
            'authority' => 'IRC §4973(d) (6% excise on excess HSA contributions); IRC §223(f)(3)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p969',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'penalty_roth_income_limit' => [
            'rule_id' => 'penalty_roth_income_limit',
            'authority' => 'IRC §408A(c)(3) (Roth MAGI limits); IRC §408(d)(6) (recharacterization)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => true,
            'source_url' => 'https://www.irs.gov/retirement-plans/roth-iras',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'penalty_hsa_medicare' => [
            'rule_id' => 'penalty_hsa_medicare',
            'authority' => 'IRC §223(b)(7) (HSA ineligibility on Medicare enrollment); IRS Publication 969',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p969',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        // Sweep 4: 1099-K / deposit mismatch awareness (educational only)
        // From 2025+: Form 1099-K threshold lowered to $600 (down from $20K/200 txns).
        // Detects third-party payment platform inflows that may trigger 1099-K reporting.
        'penalty_1099k_mismatch' => [
            'rule_id' => 'penalty_1099k_mismatch',
            'authority' => 'IRC §6050W (1099-K reporting); IRS Notice 2023-74; IRS Notice 2024-85',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'threshold_cents' => 60_000,   // $600 aggregate threshold per platform (2025+ IRS rules)
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/businesses/understanding-your-form-1099-k',
            'last_verified' => '2026-07-02',
            'status' => 'verified',
            'band' => 'conditional',  // educational awareness only
        ],

        // ── FLAG-27: Life-Event Trigger Detector ──────────────────────────────

        'life_event_payroll_stop' => [
            'rule_id' => 'life_event_payroll_stop',
            'authority' => 'IRC §1401 (SE tax); IRC §6654 (estimated tax); IRC §162(l); IRC §401(a)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/businesses/small-businesses-self-employed/self-employment-tax',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'life_event_new_mortgage' => [
            'rule_id' => 'life_event_new_mortgage',
            'authority' => 'IRC §163(h) (home mortgage interest); IRC §164 (property taxes, $10K SALT); IRC §121',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p936',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'life_event_marketplace_premium' => [
            'rule_id' => 'life_event_marketplace_premium',
            'authority' => 'IRC §36B (Premium Tax Credit); IRS Form 8962; IRS Form 1095-A',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/affordable-care-act/individuals-and-families',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        // ── FLAG-27 Gap Closure: escrow inflow + annual battery ──────────────

        'life_event_escrow_inflow' => [
            'rule_id' => 'life_event_escrow_inflow',
            'authority' => 'IRC §121 (primary-home gain exclusion, $250K/$500K MFJ); IRC §1250 (depreciation recapture)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p523',
            'last_verified' => '2026-07-02',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        // ── FLAG-20: W-2 Benefit Arbitrage ───────────────────────────────────
        // HSA gap, ESPP participation-education, NQDC education, mega-backdoor.
        // ESPP: participation-education only — ban "free money"/"guaranteed return".
        // NQDC: employer-credit-risk warning mandatory. Mega-backdoor: "if your plan allows".

        'hsa_contribution_gap' => [
            'rule_id' => 'hsa_contribution_gap',
            'authority' => 'IRC §223 (HSA); IRS Publication 969',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'caps_cents' => [
                'self_only' => 432_000,   // 2026 HSA limit, self-only (~$4,320) — verify annually
                'family' => 860_000,      // 2026 HSA limit, family (~$8,600) — verify annually
            ],
            'inflation_adjusted' => true,
            'source_url' => 'https://www.irs.gov/publications/p969',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'auto',  // HSA is triple-tax-advantaged; gap is high-value action
        ],

        'espp_participation_education' => [
            'rule_id' => 'espp_participation_education',
            'authority' => 'IRC §423 (qualified ESPP); IRC §83 (disqualifying disposition income)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/pub/irs-pdf/p525.pdf',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            // BINDING: participation-education only — never "free money" / "guaranteed return"
            // BINDING: no disposition modeling (qualifying vs. disqualifying)
            'band' => 'conditional',
        ],

        'nqdc_employer_credit_risk' => [
            'rule_id' => 'nqdc_employer_credit_risk',
            'authority' => 'IRC §409A (NQDC requirements and 20% excise tax on violations)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/pub/irs-pdf/n2007-34.pdf',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            // BINDING: employer-credit-risk warning mandatory in every NQDC finding
            'band' => 'specialist',
        ],

        // ── FLAG-21: Public-Sector Retirement ────────────────────────────────
        'ps_457b_limits' => [
            'rule_id' => 'ps_457b_limits',
            'authority' => 'IRC §457(b); IRC §457(b)(3) (3-yr catch-up doubles the limit)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'annual_limit_cents' => 2_450_000,  // ~$24,500 457(b)/403(b) separate limit 2026
            'catchup_limit_cents' => 4_900_000, // 3-yr pre-retirement catch-up: doubles the limit
            'inflation_adjusted' => true,
            'source_url' => 'https://www.irs.gov/retirement-plans/irc-457b-deferred-compensation-plans',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            // BINDING: non-governmental 457(b) creditor-risk caveat BEFORE any 457(b) content
            'band' => 'conditional',
        ],

        // ── FLAG-25: Reimbursement Beats Deduction ────────────────────────────
        'reimbursement_accountable_plan' => [
            'rule_id' => 'reimbursement_accountable_plan',
            'authority' => 'IRC §62(c) (accountable plan); Reg. §1.62-2; IRC §62(a)(2)(A) (suspended 2018-2025)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/pub/irs-pdf/p463.pdf',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            // BINDING: W-2 employee work expenses → "consider asking employer" NOT "deduct this"
            'band' => 'conditional',
        ],

        // ── FLAG-24: IRA→HSA Qualified Funding Distribution ──────────────────
        // Probe-level finding (no ruleId needed; gates enforce prerequisites).
        // Testing-period caveat is MANDATORY in all QFD findings.

        // Battery answers don't need a rule_id in the tax rules registry
        // (they're stored as UserTaxFacts, not OptimizationFindings).

        // ── FLAG-22: ACA Subsidy Cliff Monitor ───────────────────────────────
        // 400%-FPL thresholds for premium credit cliff (2026, continental US)
        // 2B.1 binding: MAGI-management BEFORE Trad-vs-Roth for marketplace enrollees
        // Awareness only: never compute a subsidy or clawback amount

        'aca_cliff_awareness' => [
            'rule_id' => 'aca_cliff_awareness',
            'authority' => 'IRC §36B(b)(3)(A) (400% FPL cliff); OBBBA (removes excess-APTC repayment cap post-2025)',
            'effective_start' => '2026-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'fpl_400pct_single_cents' => 6_260_000,   // ~$62,600 single (continental US 2026)
            'fpl_400pct_family4_cents' => 12_860_000,  // ~$128,600 family-4 (continental US 2026)
            'inflation_adjusted' => true,
            'source_url' => 'https://www.irs.gov/affordable-care-act/individuals-and-families/premium-tax-credit-the-basics',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            // BINDING T-11-08-02: finding must never present user-specific subsidy/clawback
            'band' => 'conditional',
        ],

        // ── FLAG-23: Refundable Credit Scanner ───────────────────────────────
        // "may be eligible" framing only (never "you qualify").
        // Saver's Match date-gated to 2027 (SECURE 2.0 §103).
        // State credits → STATE-01 deferred (not surfaced here).

        'refundable_credit_eitc' => [
            'rule_id' => 'refundable_credit_eitc',
            'authority' => 'IRC §32 (EITC); §32(i) (investment income disqualifier ~$11,950/2026)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [
                'investment_income_limit_cents' => 1_195_000, // ~$11,950 2026; verify annually
            ],
            'inflation_adjusted' => true,
            'source_url' => 'https://www.irs.gov/credits-deductions/individuals/earned-income-tax-credit',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            // BINDING: investment-income-limit caveat mandatory in every EITC finding
            'band' => 'conditional',
        ],

        'refundable_credit_ctc' => [
            'rule_id' => 'refundable_credit_ctc',
            'authority' => 'IRC §24 (CTC, $2,000/child); §24(d) (ACTC refundable up to $1,700)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [
                'magi_single' => 200_000,  // phaseout starts ($200K single)
                'magi_mfj' => 400_000,     // phaseout starts ($400K MFJ)
            ],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/credits-deductions/individuals/child-tax-credit',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'refundable_credit_savers' => [
            'rule_id' => 'refundable_credit_savers',
            'authority' => 'IRC §25B (Saver\'s Credit 10%/20%/50% of $2K); SECURE 2.0 §103 (Saver\'s Match, eff. 2027)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [
                'magi_single_max' => 23_000,  // ~2026 approximate; verify annually
                'magi_mfj_max' => 46_000,
            ],
            'savers_match_effective_year' => 2027,  // TAX-09 date gate
            'inflation_adjusted' => true,
            'source_url' => 'https://www.irs.gov/retirement-plans/plan-participant-employee/retirement-savings-contributions-savers-credit',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            // BINDING TAX-09: Saver's Match content date-gated to 2027
            'band' => 'conditional',
        ],

        // ── FLAG-17: Signal→Question→Strategy Probe Matrix ───────────────────
        // Probes fire only on verified prerequisite signals (FLAG-05 pattern).
        // Rule entries used for harness validation; probes with ruleId=null skip validation.

        'probe_deferral_gap' => [
            'rule_id' => 'probe_deferral_gap',
            'authority' => 'IRC §401(k); IRC §402(g) (annual deferral limit)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => true,  // limits adjust annually
            'source_url' => 'https://www.irs.gov/retirement-plans/401k-plans',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'auto',
        ],

        'probe_se_income' => [
            'rule_id' => 'probe_se_income',
            'authority' => 'IRC §1401 (SE tax); IRC §162(l); IRC §280A; IRC §199A',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/businesses/small-businesses-self-employed/self-employment-tax',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'probe_solo_401k' => [
            'rule_id' => 'probe_solo_401k',
            'authority' => 'IRC §401(k); IRC §415(c) (~$72,000 DC limit 2026); IRS Publication 560',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'cap_cents' => 7_200_000,   // ~$72,000 total DC limit (415(c)) — verify annually
            'inflation_adjusted' => true,
            'source_url' => 'https://www.irs.gov/retirement-plans/one-participant-401k-plans',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'conditional',
        ],

        'probe_entity_analysis' => [
            'rule_id' => 'probe_entity_analysis',
            'authority' => 'IRC §1361 (S-corp election); IRC §1402 (SE tax); 60-month lock (§1362(d))',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/businesses/small-businesses-self-employed/s-corporations',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            // BINDING: 60-month lock warning leads; no recommendation form (D10)
            'band' => 'conditional',
        ],

        'probe_qbi_high_income' => [
            'rule_id' => 'probe_qbi_high_income',
            'authority' => 'IRC §199A; Rev. Proc. 2019-38 (QBI safe harbor)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [
                'magi_single' => 201_750,  // 2026 phaseout start (single)
                'magi_mfj' => 403_500,     // 2026 phaseout start (MFJ)
            ],
            'inflation_adjusted' => true,
            'source_url' => 'https://www.irs.gov/newsroom/qualified-business-income-deduction',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            // BINDING D11: specialist sentinel only — no W-2/UBIA implementation
            'band' => 'specialist',
        ],

        // ── FLAG-16: Time-Critical Alarms ────────────────────────────────────
        // All alarms: band=time_critical → severity=critical; professional framing mandatory

        'alarm_83b_election' => [
            'rule_id' => 'alarm_83b_election',
            'authority' => 'IRC §83(b); Reg. §1.83-2 (30-day election period, non-waivable)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p525',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'time_critical',  // highest severity; 30-day hard deadline
        ],

        'alarm_qsbs_eligibility' => [
            'rule_id' => 'alarm_qsbs_eligibility',
            'authority' => 'IRC §1202 (QSBS, $15M cap, $75M gross-asset test); IRC §1244 (ordinary loss)',
            'effective_start' => '2025-01-01',
            'effective_end' => null,
            'phaseouts' => [],
            'cap_cents' => 1_500_000_000,  // $15M per taxpayer per issuer
            'inflation_adjusted' => false,
            'source_url' => 'https://www.irs.gov/publications/p550',
            'last_verified' => '2026-07-01',
            'status' => 'verified',
            'band' => 'time_critical',  // eligibility determined at formation
        ],

    ],

    // ── Retroactive Scanner Range Config (FLAG-12) ───────────────────────────
    // §25D amended-return credit range — RANGE with uncertainty framing (never a promise).
    // See RetroactiveScanner::scanMissedCredits() treatment wording.
    'retroactive' => [
        '25d_range_low_dollars' => 10000,  // $10,000 — low end of commonly-observed §25D recoveries
        '25d_range_high_dollars' => 20000,  // $20,000 — high end of commonly-observed §25D recoveries
    ],

];
