<?php

/**
 * FramingReviewPinTest — SAFE-07 framing-review pin test.
 *
 * PURPOSE (research Pitfall 6: the test IS the artifact):
 *   This test file IS the SAFE-07 framing-review document. Each describe/it block
 *   names the module, its approved ceiling phrasing, and the liability it bounds.
 *   A green run certifies the liability-reframed copy is intact at every pinned location.
 *   Copy drift on any pin = RED build instead of silent legal exposure.
 *
 * WHAT IS PINNED (SAFE-07 requirement — enumerate every liability-reframed item):
 *
 *   1. MFS ceiling ("may be worth modeling with your preparer") — optimization-report.php
 *      Liability: Filing-status assertion. Ceiling: modeling framing only; no recommendation.
 *
 *   2. Mega-backdoor / employer-match gate ("if your plan allows") — optimizer-scenarios.php
 *      Liability: 401(k) in-service distribution assumption. Ceiling: plan-gated language.
 *      NOTE: Only optimizer-scenarios.php is pinned (the emitted copy).
 *      EmployerMatchGapDetector.php:81 occurrence is inside a // Treatment: comment;
 *      pinning a comment is vacuous (comment can drift while emitted copy stays correct).
 *
 *   3. Entity-analysis ceiling ("commonly considered at this level") — SignalProbeMatrix.php
 *      Liability: Business-entity recommendation without legal context. Ceiling: threshold
 *      framing only; no "you should form an LLC" assertion.
 *
 *   4. Commingling ceiling ("single most effective record in a hobby-loss review") — ComminglingMonitor.php
 *      Liability: Audit-strategy assertion. Ceiling: factual record-keeping statement only.
 *
 *   5. §121 planning ceiling ("depreciation recapture") — LifeEventTriggerDetector.php
 *      Liability: Home-sale gain exclusion assertion. Ceiling: naming the recapture risk only.
 *
 *   6. Anti-waste honesty guardrail part 1 — ChangeMonitor.php
 *      Liability: Presenting a deductible purchase as pure savings (D16 / binding constraint 4).
 *      Pin: "This only makes sense if you were planning to buy this anyway."
 *
 *   7. Anti-waste honesty guardrail part 2 — ChangeMonitor.php
 *      Pin: "Net cost to you is the purchase price minus your marginal tax rate applied to the deduction."
 *
 *   8. Never-surface solar (band=suppress) — tax-detection.php residential_solar_2026_primary_home
 *      Liability: Filing false §25D credit claim. Pin: rule_id present WITH band='suppress'.
 *
 *   9. Never-surface gambling (band=suppress) — tax-detection.php gambling_losses_fully_deductible
 *      Liability: Misstating 90%-limited deductibility as full deductibility. Pin: band='suppress'.
 *
 *  10. EV credit date-suppression (effective_end + status, NOT band) — tax-detection.php ev_credit_30d
 *      Liability: Surfacing the §30D credit as available for post-2025-09-30 purchases.
 *      Pin: effective_end='2025-09-30' AND status='expired'.
 *      CRITICAL: ev_credit_30d MUST stay band='conditional' — the conditional band feeds the
 *      retroactive amended-return scanner for qualifying pre-2025-10-01 EV purchases. Flipping
 *      it to band='suppress' to silence a red on this pin silently kills the retro scanner.
 *      This pin NEVER asserts band='suppress' on ev_credit_30d.
 *
 * TEST-THE-TEST EVIDENCE (mutation results in 13-01-SUMMARY.md):
 *   This test was verified RED-provable by:
 *   1. Temporarily softening the MFS ceiling phrase in optimization-report.php → RED.
 *   2. Reverting → GREEN.
 *   3. Temporarily changing ev_credit_30d effective_end to '2026-12-31' → RED.
 *   4. Reverting → GREEN.
 *
 * REFERENCES:
 *   REQUIREMENTS.md SAFE-07; 13-RESEARCH.md "Pinned Phrases to Test";
 *   13-01-PLAN.md Task 2; binding constraint 4 (anti-waste honesty guardrail).
 */

// ═══════════════════════════════════════════════════════════════════════════
// GROUP 1 — Liability-reframed ceiling phrasings in config arrays
// ═══════════════════════════════════════════════════════════════════════════

describe('SAFE-07 — MFS ceiling: config/optimization-report.php', function () {
    /**
     * Module: Filing-status MFS trade-off module.
     * Approved ceiling: "may be worth modeling with your preparer"
     * Liability bounded: Assertive MFS recommendation without tax-professional review.
     *
     * A developer who softens this to "you should file separately" or removes the
     * "preparer" qualifier creates direct filing-status liability. This pin makes
     * that drift a red build.
     */
    it('SAFE-07: "may be worth modeling with your preparer" is present in optimization-report.php', function () {
        $path = base_path('config/optimization-report.php');
        $content = file_get_contents($path);

        expect(file_exists($path))->toBeTrue('config/optimization-report.php must exist');
        expect(str_contains($content, 'may be worth modeling with your preparer'))->toBeTrue(
            'SAFE-07 — MFS ceiling drift detected: "may be worth modeling with your preparer" '.
            'must remain verbatim in config/optimization-report.php. '.
            'Softening this (e.g. "you should file separately") creates filing-status liability.'
        );
    });
});

describe('SAFE-07 — Mega-backdoor / employer-match gate: config/optimizer-scenarios.php', function () {
    /**
     * Module: 401(k) contribution and mega-backdoor Roth scenarios.
     * Approved ceiling: "if your plan allows"
     * Liability bounded: Assuming every 401(k) plan permits in-service distributions or
     * after-tax contributions — most do not. The gate is mandatory on every instruction
     * that depends on the plan permitting these features.
     *
     * Pinned to optimizer-scenarios.php only (the emitted instruction copy).
     * EmployerMatchGapDetector.php:81 has the phrase only in a // Treatment: comment;
     * comments are not emitted output and must not be pinned here.
     */
    it('SAFE-07: "if your plan allows" is present in optimizer-scenarios.php', function () {
        $path = base_path('config/optimizer-scenarios.php');
        $content = file_get_contents($path);

        expect(file_exists($path))->toBeTrue('config/optimizer-scenarios.php must exist');
        expect(str_contains($content, 'if your plan allows'))->toBeTrue(
            'SAFE-07 — Mega-backdoor gate drift detected: "if your plan allows" must remain '.
            'verbatim in config/optimizer-scenarios.php. Removing this gate creates 401(k) '.
            'plan-type liability by assuming in-service distribution capability.'
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// GROUP 2 — Liability-reframed ceiling phrasings in detector/scanner PHP files
// ═══════════════════════════════════════════════════════════════════════════

describe('SAFE-07 — Entity-analysis ceiling: app/Services/Detectors/SignalProbeMatrix.php', function () {
    /**
     * Module: Business-entity signal probe (S-corp / partnership / sole-prop threshold).
     * Approved ceiling: "commonly considered at this level"
     * Liability bounded: Recommending entity formation without legal/state context.
     * The ceiling uses the "commonly" qualifier and never says "you should form an entity."
     */
    it('SAFE-07: "commonly considered at this level" is present in SignalProbeMatrix.php', function () {
        $path = base_path('app/Services/Detectors/SignalProbeMatrix.php');
        $content = file_get_contents($path);

        expect(file_exists($path))->toBeTrue('SignalProbeMatrix.php must exist');
        expect(str_contains($content, 'commonly considered at this level'))->toBeTrue(
            'SAFE-07 — Entity-analysis ceiling drift: "commonly considered at this level" must '.
            'remain verbatim in SignalProbeMatrix.php. Changing to a recommendation form '.
            'creates unqualified legal-advice liability.'
        );
    });
});

describe('SAFE-07 — Commingling ceiling: app/Services/Detectors/ComminglingMonitor.php', function () {
    /**
     * Module: Business account commingling / hobby-loss risk detector.
     * Approved ceiling: "single most effective record in a hobby-loss review"
     * Liability bounded: Audit-strategy framing must be factual record-keeping advice only;
     * must not assert that a separate account guarantees hobby-loss protection.
     */
    it('SAFE-07: "single most effective record in a hobby-loss review" is present in ComminglingMonitor.php', function () {
        $path = base_path('app/Services/Detectors/ComminglingMonitor.php');
        $content = file_get_contents($path);

        expect(file_exists($path))->toBeTrue('ComminglingMonitor.php must exist');
        expect(str_contains($content, 'single most effective record in a hobby-loss review'))->toBeTrue(
            'SAFE-07 — Commingling ceiling drift: this audit-record phrasing must remain verbatim '.
            'in ComminglingMonitor.php. Strengthening it to "guarantees audit protection" crosses '.
            'into audit-strategy assertion liability.'
        );
    });
});

describe('SAFE-07 — §121 planning ceiling: app/Services/Scanners/LifeEventTriggerDetector.php', function () {
    /**
     * Module: Home-sale life-event trigger (§121 primary-home gain exclusion).
     * Approved ceiling: mentioning "depreciation recapture" (the risk) without asserting
     *   the exclusion applies or computing the gain.
     * Liability bounded: Home-office depreciation recapture on home sale is complex and
     * fact-specific; the ceiling names the risk without asserting outcomes.
     */
    it('SAFE-07: "depreciation recapture" is present in LifeEventTriggerDetector.php', function () {
        $path = base_path('app/Services/Scanners/LifeEventTriggerDetector.php');
        $content = file_get_contents($path);

        expect(file_exists($path))->toBeTrue('LifeEventTriggerDetector.php must exist');
        expect(str_contains($content, 'depreciation recapture'))->toBeTrue(
            'SAFE-07 — §121 ceiling drift: "depreciation recapture" must remain verbatim in '.
            'LifeEventTriggerDetector.php. Removing it removes disclosure of a material tax risk.'
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// GROUP 3 — Anti-waste honesty guardrail (D16 / binding constraint 4)
// ═══════════════════════════════════════════════════════════════════════════

describe('SAFE-07 — Anti-waste honesty guardrail: app/Services/ChangeMonitor.php', function () {
    /**
     * Module: Year-end purchase-timing items in ChangeMonitor.
     * Liability bounded: Presenting a deductible purchase as net-zero or "free"
     * savings violates the anti-waste honesty requirement (D16 / binding constraint 4).
     * The guardrail must appear verbatim on every purchase-timing item so the user
     * understands the full cash outlay before any tax savings are received.
     *
     * Both strings are from the same concatenated constant (ChangeMonitor.php line 693–695);
     * they are pinned separately so either substring's drift is independently caught.
     */
    it('SAFE-07: anti-waste guardrail part 1 is present in ChangeMonitor.php', function () {
        $path = base_path('app/Services/ChangeMonitor.php');
        $content = file_get_contents($path);

        expect(file_exists($path))->toBeTrue('ChangeMonitor.php must exist');
        expect(str_contains($content, 'This only makes sense if you were planning to buy this anyway'))->toBeTrue(
            'SAFE-07 — Anti-waste guardrail part 1 drift: the D16 honesty guardrail must remain '.
            'verbatim in ChangeMonitor.php. Removing or softening it allows purchase-timing items '.
            'to present a net-zero cost framing that violates binding constraint 4 (anti-waste).'
        );
    });

    it('SAFE-07: anti-waste guardrail part 2 is present in ChangeMonitor.php', function () {
        $path = base_path('app/Services/ChangeMonitor.php');
        $content = file_get_contents($path);

        expect(file_exists($path))->toBeTrue('ChangeMonitor.php must exist');
        expect(str_contains($content, 'Net cost to you is the purchase price minus your marginal tax rate applied to the deduction'))->toBeTrue(
            'SAFE-07 — Anti-waste guardrail part 2 drift: the D16 net-cost formula must remain '.
            'verbatim in ChangeMonitor.php. This sentence ensures the user sees the real out-of-pocket '.
            'cost of a purchase-timed deduction.'
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// GROUP 4 — Never-surface-as-available trio (tax-detection.php config)
// ═══════════════════════════════════════════════════════════════════════════

describe('SAFE-07 — Never-surface trio: config/tax-detection.php', function () {
    /**
     * The "never-surface-as-available" trio consists of three rule entries in
     * tax-detection.php that must NEVER be surfaced as currently available.
     * Each is suppressed by a specific mechanism; this test pins EACH by its
     * ACTUAL mechanism (not merely that "suppress" appears somewhere in the file).
     *
     * Solar and gambling: suppressed by band='suppress' (never surfaced at all).
     * EV credit: suppressed by date (effective_end='2025-09-30' + status='expired'),
     *   NOT by band — the band stays 'conditional' to feed the retroactive
     *   amended-return scanner for qualifying pre-2025-10-01 EV purchases.
     *
     * CRITICAL WARNING for ev_credit_30d:
     *   NEVER "fix" a red on the EV pin by flipping band to 'suppress'.
     *   That silently kills the retroactive amended-return scanner (a taxpayer
     *   who bought a qualifying EV before 2025-09-30 can still amend their return).
     *   If the config legitimately changes, update the assertion to track the
     *   new date/status mechanism — never the band.
     */

    // ── Residential solar 2026+ primary home ─────────────────────────────
    // §25D credit for 2026+ primary-home solar installs does not exist.
    // Surfacing it as available would cause users to file a false credit claim.
    it('SAFE-07: residential_solar_2026_primary_home has rule_id present in tax-detection.php rules', function () {
        expect(config('tax-detection.rules.residential_solar_2026_primary_home.rule_id'))
            ->toBe('residential_solar_2026_primary_home');
    });

    it('SAFE-07: residential_solar_2026_primary_home has band=suppress (never-surface)', function () {
        expect(config('tax-detection.rules.residential_solar_2026_primary_home.band'))
            ->toBe(
                'suppress',
                'SAFE-07 — Solar never-surface drift: residential_solar_2026_primary_home must have '.
                'band=suppress. Changing this may surface this non-existent credit as available, '.
                'causing users to file a false §25D claim.'
            );
    });

    // ── Gambling losses as fully deductible ───────────────────────────────
    // From 2026, only 90% of gambling losses are deductible (OBBBA §70250).
    // Presenting them as "fully deductible" is misinformation.
    it('SAFE-07: gambling_losses_fully_deductible has rule_id present in tax-detection.php rules', function () {
        expect(config('tax-detection.rules.gambling_losses_fully_deductible.rule_id'))
            ->toBe('gambling_losses_fully_deductible');
    });

    it('SAFE-07: gambling_losses_fully_deductible has band=suppress (never-surface)', function () {
        expect(config('tax-detection.rules.gambling_losses_fully_deductible.band'))
            ->toBe(
                'suppress',
                'SAFE-07 — Gambling never-surface drift: gambling_losses_fully_deductible must have '.
                'band=suppress. Changing this band may surface the pre-2026 fully-deductible form, '.
                'misstating the 90%-limited post-OBBBA deductibility.'
            );
    });

    // ── EV credit §30D date-suppression ───────────────────────────────────
    // The §30D EV credit window ended 2025-09-30 (OBBBA).
    // Suppressed by date (effective_end + status=expired), NOT by band.
    // The band stays 'conditional' to feed the retroactive amended-return scanner.
    //
    // CRITICAL: Do NOT change the band assertion below to expect 'suppress'.
    // The 'conditional' band is intentional and required for the retro scanner.
    it('SAFE-07: ev_credit_30d has rule_id present in tax-detection.php rules', function () {
        expect(config('tax-detection.rules.ev_credit_30d.rule_id'))
            ->toBe('ev_credit_30d');
    });

    it('SAFE-07: ev_credit_30d is date-gated by effective_end=2025-09-30 (not by band)', function () {
        expect(config('tax-detection.rules.ev_credit_30d.effective_end'))
            ->toBe(
                '2025-09-30',
                'SAFE-07 — EV credit date-suppression drift: ev_credit_30d.effective_end must be '.
                '2025-09-30 (the OBBBA sunset). Changing this may surface §30D for post-sunset purchases. '.
                'Never fix this red by setting band=suppress — that disables the retro amended-return scanner.'
            );
    });

    it('SAFE-07: ev_credit_30d has status=expired', function () {
        expect(config('tax-detection.rules.ev_credit_30d.status'))
            ->toBe(
                'expired',
                'SAFE-07 — EV credit status drift: ev_credit_30d.status must be "expired". '.
                'Setting it to "active" or "conditional" without the date gate re-surfaces the credit.'
            );
    });

    it('SAFE-07: ev_credit_30d band remains conditional (feeds retroactive amended-return scanner)', function () {
        // Belt-and-suspenders guard: band must stay 'conditional' for the retro scanner.
        // If this ever fails, verify the retroactive scanner before changing band.
        expect(config('tax-detection.rules.ev_credit_30d.band'))
            ->toBe(
                'conditional',
                'SAFE-07 — EV credit band guard: ev_credit_30d.band must remain "conditional" to feed '.
                'the retroactive amended-return scanner for qualifying pre-2025-10-01 EV purchases. '.
                'If this must change, verify the retroactive scanner is still functional first.'
            );
    });
});
