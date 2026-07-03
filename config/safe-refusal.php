<?php

/**
 * SAFE-06 Hard-Block Refusal Configuration
 *
 * This file drives HardBlockRefusalService::check() — a zero-AI keyword/phrase detector
 * that runs BEFORE any Claude call on the escape-hatch and chat routes.
 *
 * Design rules:
 *   - Phrases are multi-word n-grams only (Pitfall 5: bare words cause false positives).
 *   - Education copy is what/why only — NEVER how to implement. Sourced from the
 *     refused_recommendations lineage in config/optimization-report.php.
 *   - No code logic, business rules, or dollar figures here — static content only.
 *   - All phrases are lowercase; HardBlockRefusalService lowercases input before matching.
 *
 * SAFE-06 cluster list (IRS Dirty Dozen + related criminal-statute schemes):
 *   1. 831(b) Micro-Captive Insurance
 *   2. Syndicated / Façade Conservation Easement
 *   3. Offshore Concealment / FBAR-FATCA
 *   4. Malta Pension / Abusive Foreign Trusts
 *   5. Nonprofit-as-Personal-Shelter (§4958)
 *   6. Corporation Sole / Pure Trust Packages
 *   7. "Start a Ministry" Tax Structures
 *   8. Crypto Non-Reporting
 *   9. Cash Structuring / Smurfing
 *   10. PPLI / Offshore Crypto-IRA
 *   11. Body Modification Deduction Probes (Hess-style)
 */

return [

    // ── SAFE-06 Detection Clusters ────────────────────────────────────────────
    //
    // Each cluster: {category, phrases[], education}
    //   category  — human-readable label used in the structured refusal response
    //   phrases   — lowercase n-gram triggers; ANY match returns a refusal
    //   education — what/why copy (never how); sourced from refused_recommendations why-text
    //
    'clusters' => [

        // ── 1. 831(b) Micro-Captive Insurance ────────────────────────────────
        [
            'category' => '831(b) Micro-Captive Insurance',
            'phrases' => [
                '831b',
                '831(b)',
                'micro-captive',
                'microcaptive',
                'micro captive',
                'captive insurance',
            ],
            'education' => 'Micro-captive insurance arrangements using the 831(b) tax election appear '
                .'on the IRS Dirty Dozen list of abusive tax schemes. These structures involve '
                .'a business owner paying premiums to a related "captive" insurer that may not '
                .'bear genuine insurance risk. The IRS has challenged them consistently — '
                .'participants face substantial civil penalties and, in some cases, criminal '
                .'exposure. SpendifiAI can describe what this arrangement is and why the IRS '
                .'challenges it, but cannot assist with implementing or evaluating it. '
                .'For questions in this area, a licensed tax professional is the appropriate resource.',
        ],

        // ── 2. Syndicated / Façade Conservation Easement ─────────────────────
        [
            'category' => 'Syndicated Conservation Easement',
            'phrases' => [
                'conservation easement',
                'syndicated easement',
                'facade easement',
                'façade easement',
                'historic facade easement',
                'syndicated conservation',
            ],
            'education' => 'Syndicated and façade conservation easements appear on the IRS Dirty Dozen '
                .'list of abusive tax schemes. In these transactions, promoters sell interests in '
                .'land or historic buildings, obtain inflated appraisals, and generate deductions '
                .'that far exceed the actual economic contribution. Courts have disallowed these '
                .'deductions in the vast majority of cases, and participants face substantial civil '
                .'penalties. SpendifiAI can describe what this arrangement is and why the IRS '
                .'challenges it, but cannot assist with implementing or evaluating it. '
                .'For questions in this area, a licensed tax professional is the appropriate resource.',
        ],

        // ── 3. Offshore Concealment / FBAR-FATCA ─────────────────────────────
        [
            'category' => 'Offshore Concealment / FBAR-FATCA',
            'phrases' => [
                'offshore account',
                'offshore concealment',
                'offshore bank account',
                'fbar failure',
                'fbar violation',
                'not file fbar',
                "don't file fbar",
                'dont file fbar',
                'avoid fatca',
                'fatca conceal',
                'conceal foreign account',
                'conceal a foreign account',
                'hide foreign account',
                'hide offshore',
                'unreported foreign account',
                'unreported offshore',
                'undisclosed foreign',
            ],
            'education' => 'Offshore structures designed to conceal assets or income from the IRS appear '
                .'on the IRS Dirty Dozen list of abusive schemes. Failure to report foreign financial '
                .'accounts (FBAR, FinCEN Form 114) or foreign assets (FATCA, Form 8938) is a '
                .'federal violation carrying civil penalties up to 50% of the account balance per '
                .'year and, for willful violations, criminal exposure including imprisonment. '
                .'SpendifiAI can describe what these reporting requirements are and why they exist, '
                .'but cannot assist with concealment strategies. '
                .'For questions in this area, a licensed tax professional is the appropriate resource.',
        ],

        // ── 4. Malta Pension / Abusive Foreign Trusts ─────────────────────────
        [
            'category' => 'Malta Pension and Abusive Foreign Trusts',
            'phrases' => [
                'malta pension',
                'abusive foreign trust',
                'foreign trust shelter',
                'offshore trust shelter',
                'offshore trust strategy',
                'foreign trust tax avoidance',
            ],
            'education' => 'Malta pension arrangements and abusive foreign trust schemes appear on the '
                .'IRS Dirty Dozen list. These arrangements involve using foreign pension plans or '
                .'trust structures under tax treaties in ways the IRS considers abusive — '
                .'typically by claiming treaty benefits for income that would otherwise be taxable '
                .'under U.S. law, or by placing assets in trusts designed primarily to avoid '
                .'U.S. tax rather than for legitimate estate or business purposes. '
                .'SpendifiAI can describe what these arrangements are and why the IRS challenges '
                .'them, but cannot assist with implementing or evaluating them. '
                .'For questions in this area, a licensed tax professional is the appropriate resource.',
        ],

        // ── 5. Nonprofit-as-Personal-Shelter / §4958 ──────────────────────────
        [
            'category' => 'Nonprofit-as-Personal-Shelter (Section 4958)',
            'phrases' => [
                'nonprofit shelter',
                'use a nonprofit shelter',
                'nonprofit as shelter',
                'nonprofit as a shelter',
                'church as shelter',
                'church as a tax shelter',
                'run personal expenses through',
                'personal expenses through my church',
                'personal expenses through a church',
                'personal expenses through a nonprofit',
                'personal expenses through the church',
                'use a nonprofit to shelter',
                'section 4958',
                'section 4958 exploit',
                'exploit a nonprofit',
            ],
            'education' => 'Using a nonprofit organization or church as a personal tax shelter '
                .'— for example, by routing personal expenses through the entity or taking '
                .'excessive compensation — constitutes "private inurement" prohibited by '
                .'IRC §501(c)(3) and can result in "excess benefit transactions" subject to '
                .'substantial excise taxes under IRC §4958. The organization can lose its '
                .'tax-exempt status. SpendifiAI can describe what these rules are and why they '
                .'exist, but cannot assist with using nonprofit status as a personal shelter. '
                .'For questions in this area, a licensed tax professional is the appropriate resource.',
        ],

        // ── 6. Corporation Sole / Pure Trust Packages ─────────────────────────
        [
            'category' => 'Corporation Sole and Pure Trust Packages',
            'phrases' => [
                'corporation sole',
                'pure trust',
                'pure trust organization',
                'constitutional trust',
                'common law trust shelter',
                'sovereignty trust',
            ],
            'education' => 'Corporation sole and "pure trust" packages are promoter-marketed schemes '
                .'that claim to eliminate taxes by placing income and assets into structures '
                .'asserted to be outside IRS jurisdiction. These claims are without legal basis — '
                .'courts have consistently held that these structures do not shield income from '
                .'U.S. taxation, and participants face back taxes, penalties, and potential '
                .'criminal charges for tax evasion. SpendifiAI can describe what these '
                .'arrangements are and why they fail legally, but cannot assist with implementing '
                .'or evaluating them. '
                .'For questions in this area, a licensed tax professional is the appropriate resource.',
        ],

        // ── 7. "Start a Ministry" Tax Structures ──────────────────────────────
        [
            'category' => '"Start a Ministry" Tax Structures',
            'phrases' => [
                'start a ministry',
                'form a ministry',
                'set up a ministry',
                'create a ministry',
                'establish a ministry',
                'ministry tax shelter',
                'ministry shelter',
                'ministry to avoid taxes',
                'ministry to reduce taxes',
                'start my own ministry',
            ],
            'education' => 'Promoters sometimes advise people to establish a "ministry" or religious '
                .'organization as a vehicle to shelter personal income or expenses from taxation. '
                .'While legitimate religious organizations have genuine tax-exempt status, '
                .'sham ministries formed primarily for tax avoidance — without real religious '
                .'activity — do not qualify and expose participants to back taxes, penalties, '
                .'and potential criminal liability. SpendifiAI can describe how legitimate '
                .'religious organizations interact with tax law and why sham ministries are '
                .'challenged, but cannot assist with using such structures as shelters. '
                .'For questions in this area, a licensed tax professional is the appropriate resource.',
        ],

        // ── 8. Crypto Non-Reporting ───────────────────────────────────────────
        [
            'category' => 'Crypto Non-Reporting',
            'phrases' => [
                'hide crypto',
                "don't report crypto",
                'do not report crypto',
                'not report crypto',
                'conceal crypto',
                'unreported crypto',
                'crypto tax evasion',
                'crypto non-reporting',
                'hide cryptocurrency',
                'not report cryptocurrency',
                'conceal cryptocurrency',
                'unreported cryptocurrency',
                'avoid reporting crypto',
                'avoid reporting cryptocurrency',
            ],
            'education' => 'Willful failure to report cryptocurrency transactions or income is tax evasion '
                .'under IRC §7201, a federal felony carrying penalties of up to $250,000 and five '
                .'years imprisonment. The IRS requires reporting of all crypto transactions that '
                .'result in gain or income — including exchange trades, staking rewards, and '
                .'payments received in crypto. SpendifiAI can describe what the reporting '
                .'requirements are and why they exist, but cannot assist with non-reporting '
                .'or concealment strategies. '
                .'For questions in this area, a licensed tax professional is the appropriate resource.',
        ],

        // ── 9. Cash Structuring / Smurfing ────────────────────────────────────
        [
            'category' => 'Cash Structuring and Smurfing',
            'phrases' => [
                'cash structuring',
                'structuring cash',
                'smurfing',
                'structure cash deposits',
                'structure deposits',
                'break up deposits',
                'keep deposits below',
                'split deposits to avoid',
                'below $10,000 to avoid',
                'under 10000 to avoid',
                'structuring deposits',
                'structuring transactions',
            ],
            'education' => 'Cash structuring — deliberately breaking up cash transactions to stay below '
                .'Currency Transaction Report (CTR) thresholds — is a federal crime under '
                .'31 U.S.C. §5324, regardless of whether the underlying income is otherwise '
                .'legal. The offense carries civil forfeiture and criminal penalties of up to '
                .'5 years imprisonment. SpendifiAI can describe what structuring is and why it '
                .'is illegal, but cannot assist with structuring strategies. '
                .'For questions in this area, a licensed tax professional is the appropriate resource.',
        ],

        // ── 10. PPLI / Offshore Crypto-IRA ───────────────────────────────────
        [
            'category' => 'PPLI and Offshore Crypto-IRA',
            'phrases' => [
                'ppli',
                'private placement life insurance',
                'offshore ira',
                'offshore crypto ira',
                'offshore crypto-ira',
                'offshore retirement account',
                'private placement variable annuity',
                'ppva',
                'offshore life insurance policy',
                'life insurance policy offshore',
            ],
            'education' => 'Private Placement Life Insurance (PPLI) and offshore crypto-IRA arrangements '
                .'are marketed as ways to place investments — including cryptocurrency — inside '
                .'insurance wrappers or offshore retirement accounts to defer or eliminate U.S. tax. '
                .'The IRS has identified these arrangements as potentially abusive when the primary '
                .'purpose is tax avoidance rather than genuine insurance or retirement savings. '
                .'SpendifiAI can describe what these arrangements are and why the IRS scrutinizes '
                .'them, but cannot assist with implementing them. '
                .'For questions in this area, a licensed tax professional is the appropriate resource.',
        ],

        // ── 11. Body Modification Deduction Probes (Hess-style) ───────────────
        [
            'category' => 'Body Modification Deduction Probes',
            'phrases' => [
                'body modification deduction',
                'body mod deduction',
                'deduct body modification',
                'deduct body mod',
                'tattoo business expense',
                'tattoo tax deduction',
                'cosmetic surgery business expense',
                'cosmetic surgery deduction',
                'hess v.',
                'hess v commissioner',
                'hess case tax',
                'body modification tax',
                'body modification business expense',
            ],
            'education' => 'Claims that body modifications (tattoos, piercings, cosmetic procedures) '
                .'qualify as tax-deductible medical expenses or business expenses have been '
                .'consistently rejected by courts. The Hess v. Commissioner case and similar '
                .'litigation affirm that cosmetic body modifications undertaken for personal '
                .'reasons do not meet the IRC §213 medical expense standard or the ordinary-and-necessary '
                .'business expense standard of IRC §162. SpendifiAI can describe why these '
                .'deductions are generally unavailable, but cannot assist with claiming them. '
                .'For questions in this area, a licensed tax professional is the appropriate resource.',
        ],

    ],

    // ── Best-Effort Disclaimer ────────────────────────────────────────────────
    //
    // Rendered ONCE per session (not per detection). Verbatim per SAFE-06.
    //
    'best_effort_disclaimer' => 'SpendifiAI monitors for abusive-scheme signals as a best-effort '
        .'safeguard. This does not guarantee detection of every harmful prompt or scheme. '
        .'Our refusal list focuses on the IRS Dirty Dozen and related criminal-statute schemes; '
        .'edge cases or novel schemes may not be detected. When in doubt, consult a licensed '
        .'tax professional.',

    // ── Anti-Waste Principle ──────────────────────────────────────────────────
    //
    // Canonical statement per SAFE-06 owner mandate. The live pinned copy lives in
    // ChangeMonitor.php:693 and year-end config items; this key is the config-level
    // source of truth. No output may present spending solely to create a deduction
    // as savings.
    //
    'anti_waste_principle' => 'No SpendifiAI output may present spending solely to create a '
        .'deduction as a net savings strategy. A purchase that costs $10,000 in the 24% '
        .'bracket saves approximately $2,400 in tax and costs approximately $7,600 in net '
        .'cash — the transaction is a net cost, not a savings. Any output involving '
        .'spending must pair the estimated tax reduction with the out-of-pocket cost '
        .'so the user can evaluate the true economic impact.',

];
