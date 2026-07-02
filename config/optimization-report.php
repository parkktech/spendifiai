<?php

/**
 * Optimization Report configuration (RPT-01/03/06/07/08).
 *
 * STATIC CONTENT ONLY — no business-logic code here.
 * Numbers that appear here are educational constants (offsets, rule facts),
 * NOT user-specific computed values. User-specific dollar figures come
 * exclusively from OptimizationFinding records written by TaxRulesEngineService.
 *
 * Approved glossary lines carry the annotation:
 * [default ruling — owner review pending]
 */
return [

    // ── D17 Template-first finding narration (SCN-03 / template-first) ────
    // Deterministic, zero-Claude narration copy keyed by finding_type. When a
    // finding's finding_type has an entry here, NarrationService::narrateFinding()
    // renders the string via token substitution and returns WITHOUT any HTTP call.
    // Only genuinely bespoke finding_types fall through to the (budget-gated,
    // Haiku) Claude path. Tokens: {value} (formatted estimated_value figure at
    // render time — NO dollar literal here), {severity}. Educational framing only
    // ("may"/"could"/"consider") — banned assertive phrases must never appear
    // (enforced by BannedPhraseTemplatesTest / SAFE-01).
    'finding_narration_templates' => [
        'income_discrepancy' => 'Your reported income and your bank deposits may differ enough to be worth '
            .'a closer look before you file. Consider discussing this with a tax professional.',
        'withholding' => 'Your current federal withholding may not line up with your expected tax for the year, '
            .'which could affect your refund or balance due. Consider reviewing this with a tax professional.',
        'qbi' => 'You may be able to explore the qualified business income deduction based on your income mix. '
            .'Consider discussing whether it could apply to your situation with a tax professional.',
        'refundable_credit' => 'You may qualify to review one or more refundable credits based on your household. '
            .'Consider confirming eligibility with a tax professional.',
        'benefit_gap' => 'One or more workplace benefits may be worth reviewing to see whether you could be '
            .'leaving value on the table. Consider exploring your options with your employer or a professional.',
        'estimated_tax' => 'Your estimated tax payments may be worth reviewing so you can avoid a surprise at '
            .'filing time. Consider discussing your payment schedule with a tax professional.',
    ],

    // ── Section Definitions (RPT-01: 4 topical sections) ──────────────────

    'sections' => [
        [
            'section_key' => 'deductions',
            'title' => 'Deductions & Business Expenses',
            'description' => 'Commonly missed or underutilized deductions based on your transaction patterns and profile.',
            'icon' => 'receipt',
            'order' => 1,
        ],
        [
            'section_key' => 'taxes',
            'title' => 'Tax Reduction Opportunities',
            'description' => 'Areas where your tax situation may benefit from a professional review.',
            'icon' => 'trending-down',
            'order' => 2,
        ],
        [
            'section_key' => 'filings',
            'title' => 'Filing & Compliance',
            'description' => 'Filing considerations and compliance areas worth discussing with a professional.',
            'icon' => 'file-text',
            'order' => 3,
        ],
        [
            'section_key' => 'retirement_401k',
            'title' => 'Retirement & Benefits',
            'description' => 'Retirement contribution opportunities and employer benefits that may be worth exploring.',
            'icon' => 'piggy-bank',
            'order' => 4,
        ],
    ],

    // ── Finding Type → Section Mapping ───────────────────────────────────

    'finding_type_to_section' => [
        // Deductions & Business Expenses
        'deduction_probe' => 'deductions',
        'deductible_saas' => 'deductions',
        'deduction_education' => 'deductions',
        'routing_rule' => 'deductions',
        'self_employment' => 'deductions',
        'category_library' => 'deductions',

        // Tax Reduction Opportunities
        'withholding' => 'taxes',
        'qbi' => 'taxes',
        'aca_awareness' => 'taxes',
        'refundable_credit' => 'taxes',
        'income_discrepancy' => 'taxes',
        'benefit_gap' => 'taxes',
        'benefit_education' => 'taxes',
        'estimated_tax' => 'taxes',
        'penalty_prevention' => 'taxes',

        // Filing & Compliance
        'filing_status' => 'filings',
        'conformance' => 'filings',
        'entity' => 'filings',
        'equity' => 'filings',
        'audit_risk' => 'filings',
        'commingling' => 'filings',
        'time_critical' => 'filings',
        'battery_question' => 'filings',

        // Retirement & Benefits
        'retirement' => 'retirement_401k',
        'hsa_advanced' => 'retirement_401k',
        'education' => 'retirement_401k',
    ],

    // Default section for unmapped finding types
    'default_section' => 'deductions',

    // ── Decision 13: Report Staleness Policy ─────────────────────────────
    //
    // freshness_days: data-churn events (OptimizationProfileBuilt from scheduled
    // jobs / bank syncs) do NOT stale a report that was rebuilt within this
    // many days — unless a material change is also detected.
    //
    // material_change.*: percentage thresholds used to detect a "material change"
    // in the user's financial picture between the current profile aggregates and
    // the built_against snapshot stored at the time of report generation.
    //
    // Rationale (owner, 2026-07-02): user actions take 2-4 weeks to appear in
    // transactions/paystubs — early churn regeneration burns without signal.
    // The material-change detector catches when actions actually land.

    'freshness_days' => 30,

    'material_change' => [
        // Income change (bank_deposit_total / w2_wages) percentage threshold.
        // A shift of ≥ income_pct % compared to the built_against snapshot
        // triggers staleness even within the freshness window.
        'income_pct' => 5,

        // Savings/contributions change percentage threshold.
        // Covers sum of traditional_401k_ytd + roth_401k_ytd + ira_ytd + hsa_ytd.
        'savings_pct' => 10,
    ],

    // ── Severity Ordering for Ranking (RPT-01: severity tier then value DESC) ─

    'severity_order' => [
        'high' => 0,
        'medium' => 1,
        'low' => 2,
    ],

    // Bands that route to "needs professional review" wrapper section (RPT-06)
    'specialist_bands' => ['specialist'],

    // ── Per-Section Disclaimer Text (RPT-03) ─────────────────────────────

    'section_disclaimer' => 'This section presents educational information about tax topics that may be relevant to your situation. Nothing here constitutes tax advice. Tax laws are complex and vary by circumstance — please discuss these items with a qualified tax professional before taking any action.',

    'report_disclaimer' => 'This report is educational material only and does not constitute tax, legal, or financial advice. The findings shown reflect patterns in your connected data and general educational information about tax topics. SpendifiAI is not a licensed tax advisor. Always consult a qualified tax professional (CPA, EA, or tax attorney) before making any tax-related decisions.',

    // ── RPT-07: Educational Glossary Lines ───────────────────────────────
    // Approved content only. Lines carry "[default ruling — owner review pending]"
    // where the liability screen approved an educational reframe.

    'glossary' => [
        [
            'term' => '0% Long-Term Capital Gains Bracket',
            'explanation' => 'Long-term capital gains (assets held more than one year) may be taxed at 0% for taxpayers whose income falls below certain thresholds. The specific thresholds are adjusted annually by the IRS. Understanding which bracket you may fall into is something worth discussing with a tax professional. [default ruling — owner review pending]',
            'source' => 'IRC §1(h); Rev. Proc. 2025-32',
            'note' => 'Educational bracket-awareness only — no sell/rebuy language.',
        ],
        [
            'term' => 'Tax-Loss Harvesting — Key Facts',
            'explanation' => 'Investment losses can generally offset capital gains dollar for dollar. If losses exceed gains, up to $3,000 of net loss per year may offset ordinary income ($1,500 if married filing separately). The wash-sale rule (IRC §1091) disallows a loss if you buy a "substantially identical" security within 30 days before or after the sale. The specifics of how these rules apply to your situation are worth reviewing with a tax professional. [default ruling — owner review pending]',
            'source' => 'IRC §1211, §1091',
            'note' => 'Glossary facts only — not a detector or alert.',
        ],
        [
            'term' => 'Account-Type Taxation Basics',
            'explanation' => 'Different types of investment accounts are taxed differently. Traditional pre-tax accounts (traditional 401(k), traditional IRA) allow a deduction when money goes in but the withdrawals are taxed as ordinary income. Roth accounts use after-tax contributions but qualified withdrawals are generally tax-free. Taxable brokerage accounts have no special tax treatment on contributions but only realized gains are taxed. Understanding how each account type is taxed — not what to hold in each — is educational context worth discussing with a professional. [default ruling — owner review pending]',
            'source' => 'IRC §401(k), §408A, general tax law',
            'note' => 'Account taxation facts only — no asset-class or placement advice.',
        ],
        [
            'term' => 'Stepped-Up Cost Basis at Death',
            'explanation' => 'Under current law (IRC §1014), assets inherited from a decedent generally receive a new cost basis equal to the fair market value at the date of death. This can eliminate unrealized capital gains that accumulated during the decedent\'s lifetime. The rules around stepped-up basis, estate planning, and how this interacts with other planning tools are complex and should be reviewed with an estate planning attorney or tax professional. [default ruling — owner review pending]',
            'source' => 'IRC §1014',
            'note' => 'Glossary line only — no leverage or estate strategy content.',
        ],
        [
            'term' => 'Married Filing Separately (MFS)',
            'explanation' => 'Most married couples pay less tax filing jointly, but there are situations where filing separately may be worth modeling — for example, when one spouse has large medical expenses, significant student loans under income-driven repayment plans, or other specific circumstances. The trade-offs are complex and state-law dependent. MFS may be worth modeling with your preparer. [default ruling — owner review pending]',
            'source' => 'IRC §1(d), §63',
            'note' => 'Educational ceiling line only — no filing-status assertion or community-property analysis.',
        ],
        [
            'term' => 'Tax Avoidance vs. Tax Evasion',
            'explanation' => 'Tax avoidance is the legal use of the tax code to reduce your tax liability — taking deductions and credits you are entitled to, structuring transactions under the rules as written. The landmark case Gregory v. Helvering (1935) established that taxpayers may arrange their affairs to minimize taxes within the law. Tax evasion is illegal — it involves misrepresenting facts, concealing income, or falsifying records (§7201, a felony). Even legal tax avoidance can fail under the "killer doctrines": economic substance (§7701(o), requires real business purpose and economic effect), substance-over-form, step-transaction, and sham-transaction doctrines. A series of technically legal steps that have no purpose other than reducing taxes may be recharacterized by the IRS. "Borderline" arrangements are not a viable product tier — they are specialist territory requiring professional evaluation.',
            'source' => 'Gregory v. Helvering, 293 U.S. 465 (1935); IRC §7201, §7701(o)',
            'note' => 'PB-v1 §9 verbatim-worthy explainer. Also feeds P13 framing review reference.',
        ],
        [
            'term' => 'Non-Qualified Deferred Compensation (NQDC)',
            'explanation' => 'NQDC plans allow certain employees to defer compensation beyond qualified-plan limits. Unlike 401(k) plans, NQDC is an unsecured promise from your employer — if the company becomes insolvent, deferred amounts may be at risk. The decision to participate involves weighing your employer\'s financial stability, your anticipated tax rates in the deferral vs. distribution years, and the terms of the plan. This is a decision worth discussing with a tax and financial professional.',
            'source' => 'IRC §409A',
            'note' => 'Employer-credit-risk warning mandatory per INTEGRATION-MAP §5 W2-11.',
        ],
        [
            'term' => '1031 Like-Kind Exchange',
            'explanation' => 'IRC §1031 allows deferral of capital gains tax when you exchange certain investment or business property for "like-kind" property of equal or greater value. The rules are strict: you must identify replacement property within 45 days and close within 180 days. Not all property types qualify (personal-use property does not). 1031 exchanges are complex transactions that require careful planning and qualified intermediaries — always consult a tax professional well in advance of any sale.',
            'source' => 'IRC §1031',
        ],
        [
            'term' => 'Qualified Opportunity Zones (QOZ)',
            'explanation' => 'Capital gains rolled into a Qualified Opportunity Fund (QOF) within 180 days may defer and potentially reduce the original gain, and appreciation in the QOF held 10+ years may be excluded. Opportunity Zone investments carry their own risks (illiquidity, business risk). Pre-2027 QOF investors should be aware of mandatory gain recognition rules that may apply by end of 2026. Consult a tax professional regarding your specific situation.',
            'source' => 'IRC §1400Z-1, §1400Z-2',
        ],
        [
            'term' => 'NIIT (Net Investment Income Tax)',
            'explanation' => 'A 3.8% tax applies to the lesser of net investment income or the excess of modified AGI over certain thresholds ($200K single / $250K MFJ for 2025). This can increase the effective tax rate on investment income for higher earners. Understanding where you stand relative to these thresholds is worth discussing with a tax professional.',
            'source' => 'IRC §1411',
        ],
        [
            'term' => 'Cost Basis Methods for Investments',
            'explanation' => 'For securities, you may choose different methods to identify which shares are sold (FIFO, average cost, specific identification/HIFO). The method can significantly affect when and how much gain or loss you recognize. The choice of method must generally be made before or at the time of sale. Consult a tax professional about which method is most appropriate for your situation.',
            'source' => 'IRC §1012; Treasury Reg. §1.1012-1',
        ],
    ],

    // ── RPT-05: Pro-Review Export — Static Defensibility Ratings ─────────
    // Maps finding band to a static rating label for the professional packet.
    // Ratings are STATIC config values — never computed from user data or Claude output.
    //
    //   solid            — well-established, IRS-accepted treatment; low controversy
    //   fact-dependent   — correct treatment depends on specific taxpayer facts
    //   frequently-abused — area subject to frequent IRS scrutiny / listed-transaction risk

    'defensibility_ratings' => [
        'auto' => 'solid',
        'conditional' => 'fact-dependent',
        'specialist' => 'frequently-abused',
        'suppress' => 'fact-dependent',   // Should not appear in export; fallback label
        'hard_block' => 'frequently-abused', // Should not appear in export; fallback label
    ],

    // RPT-05: Persistent disclaimer for every pro-review export packet (PDF and email).
    // Must appear on every page of the exported document.

    'pro_review_disclaimer' => 'EDUCATIONAL MATERIAL ONLY — NOT TAX ADVICE. This packet was prepared from information self-asserted by the user and has not been independently verified. It does not constitute tax, legal, or financial advice and may not be relied upon as such. The legal citations included are educational references only. Tax laws are complex and fact-specific; the items herein require evaluation by a qualified tax professional (CPA, EA, or tax attorney) with knowledge of your complete financial situation. SpendifiAI is not a licensed tax advisor.',

    // RPT-06: What We Refused and Why ──────────────────────────────────
    // WHAT + WHY only — NEVER HOW these schemes work.

    'refused_recommendations' => [
        [
            'category' => 'Securities & Investment Advice',
            'what' => 'Specific securities transaction recommendations (0% LTCG sell/rebuy strategies, tax-loss-harvesting alerts, asset location advice, direct indexing, muni/Treasury selection)',
            'why' => 'These activities constitute investment advice requiring RIA registration under the Investment Advisers Act. SpendifiAI is not a registered investment adviser. We provide educational information about how accounts and tax rates work, but we do not advise on what to buy, sell, or hold.',
            'blocked_items' => ['W2-7', 'W2-9', 'IV-1', 'IV-2', 'IV-3', 'CR-2', 'CR-3', 'CR-4'],
        ],
        [
            'category' => 'Abusive Tax Schemes (IRS Dirty Dozen)',
            'what' => 'Micro-captive insurance arrangements (831(b)), syndicated conservation easements, offshore structures / FBAR-FATCA concealment, Malta pension arrangements and abusive foreign trusts, nonprofit-as-personal-shelter, corporation sole and "pure trust" packages, "start a ministry" structures, PPLI and offshore crypto-IRA pitches',
            'why' => 'These are listed transactions or abusive schemes on the IRS Dirty Dozen list. They carry substantial civil penalties, potential criminal exposure, and in many cases have been consistently struck down by courts. SpendifiAI detects signals that may relate to these schemes only to educate and refuse — we never recommend or assist with them.',
            'blocked_items' => ['SAFE-06'],
        ],
        [
            'category' => 'Crypto Non-Reporting and Structuring',
            'what' => 'Guidance on not reporting cryptocurrency transactions, cash structuring strategies designed to stay below reporting thresholds',
            'why' => 'These are illegal under federal law. Willful failure to report income is tax evasion (IRC §7201). Cash structuring to evade bank reporting requirements is a federal crime (31 U.S.C. §5324). SpendifiAI refuses to assist with any such strategies.',
            'blocked_items' => ['SAFE-06'],
        ],
        [
            'category' => 'ISO AMT Modeling',
            'what' => 'Automated AMT crossover modeling for incentive stock option exercises',
            'why' => 'Computing a user\'s specific tax liability is practice before the IRS requiring a PTIN. Modeling ISO/AMT interactions involves projecting tax liability and is professional tax work. We note that ISO exercises may have AMT implications and encourage users to consult a professional before exercising.',
            'blocked_items' => ['W2-5 ISO'],
        ],
        [
            'category' => 'Entity Formation Recommendations',
            'what' => 'Specific entity formation recommendations (e.g., "you should elect S-corporation status")',
            'why' => 'Entity decisions carry irreversible consequences (60-month lock-in for S-corp elections) and interact with state law, reasonable compensation rules, and other factors that require professional evaluation. We surface educational thresholds and encourage professional review but do not make specific recommendations.',
            'blocked_items' => ['SH-11'],
        ],
        [
            'category' => 'Leverage and Estate Strategies',
            'what' => 'Buy-borrow-die strategies, upstream gifting for basis step-up, borrowing against cryptocurrency holdings as a tax strategy',
            'why' => 'These strategies require securities advice (leverage), estate planning attorney involvement (upstream gifting), or involve thin lines between legal tax planning and problematic arrangements. SpendifiAI provides educational glossary information where approved but does not advise on these strategies.',
            'blocked_items' => ['RE-5', 'IV-8', 'CR-9'],
        ],
        [
            'category' => 'State Tax Items',
            'what' => 'State-specific tax advice, multi-state residency/domicile planning, state credit optimization, PTET strategies',
            'why' => 'SpendifiAI focuses on federal tax education. State tax laws vary significantly by jurisdiction and require state-specific expertise. These items are deferred to our future STATE-01 capability.',
            'blocked_items' => ['STATE-01'],
        ],
    ],

    // ── RPT-08: Year-End Awareness — Finding Categories ──────────────────
    // Dec-31 items framed as "commonly reviewed before year end"
    // Jan-15 / April items surfaced informatively
    // NO countdown/pressure copy — educational surfacing only

    'year_end_awareness' => [
        'section_title' => 'Year-End Tax Awareness',
        'section_intro' => 'The following tax topics are commonly reviewed before year end based on your detected findings. This is educational awareness only — tax decisions depend on your complete financial picture and should be made with professional guidance.',
        'disclaimer' => 'These items are surfaced for educational awareness. Dates mentioned reflect general rules; consult a tax professional for your specific deadlines and circumstances. SpendifiAI does not provide deadline guarantees.',
        'dec_31_framing' => 'commonly reviewed before year end',
        'jan_15_framing' => 'commonly relevant in January',
        'april_framing' => 'commonly relevant around the April filing window',
        // Dec-31 type topics (from findings' deadline metadata where deadline month = 12)
        'dec_31_topics' => [
            'Roth conversion considerations during lower-income years',
            'Charitable contribution timing and donor-advised fund transfers',
            'Retirement contribution maximization (solo 401(k) establishment deadline)',
            'FSA spend-down for healthcare flexible spending accounts',
            'Annual gift exclusion transfers',
            'Placing business equipment "in service" for depreciation timing',
            'QCD (qualified charitable distributions) for those 70½+',
            'RMD (required minimum distribution) compliance for those subject to RMDs',
        ],
        // State-layer items are suppressed per INTEGRATION-MAP (STATE-01 deferral)
    ],

];
