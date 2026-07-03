<?php

/**
 * Change 4 (owner) — UNIT DISCIPLINE static gate for the checklist impact banner.
 *
 * The HeaderAggregateBanner in OptimizationChecklistView.tsx once rendered an ANNUAL
 * delta (−$4.4k/yr) directly above a "per paycheck" caption, reading as
 * "−$4.4k/yr per paycheck" — a mixed-unit lie.
 *
 * RULE: every rendered dollar figure in the banner carries its own explicit unit
 * token INLINE (/check, /yr, per paycheck, per year, or — for the retirement FV
 * illustration — "est. range" with the target-age tile header). No caption may
 * apply to a number with a different unit; standalone unit captions are prohibited.
 *
 * This test statically scans the HeaderAggregateBanner function source:
 *  1. Every line that renders a dollar figure (fmtAbs(...) or fmtCents(...) call in
 *     JSX) must have a unit token on the same line or within the next 3 lines
 *     (before/after pairs share one trailing unit: "$5,512 → $5,343 per paycheck").
 *  2. No JSX text node may consist solely of a unit token (standalone unit caption).
 *  3. The redesigned structure's unit tokens must all be present (red on revert).
 *
 * Scope: HeaderAggregateBanner only — per-item benefit lines build their units
 * inside buildBenefitLine() template strings, already unit-inline by construction.
 */

/** Extract the HeaderAggregateBanner function body from the component source. */
function bannerSourceLines(): array
{
    $path = base_path('resources/js/Components/SpendifiAI/OptimizationChecklistView.tsx');
    expect(file_exists($path))->toBeTrue("OptimizationChecklistView.tsx not found at {$path}");

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    $start = null;
    $end = null;
    foreach ($lines as $i => $line) {
        if ($start === null && str_contains($line, 'function HeaderAggregateBanner')) {
            $start = $i;

            continue;
        }
        // The banner function ends at the next section divider comment
        if ($start !== null && preg_match('/^\/\/ ─+/u', $line)) {
            $end = $i;
            break;
        }
    }

    expect($start)->not->toBeNull('HeaderAggregateBanner function not found in OptimizationChecklistView.tsx');

    return array_slice($lines, $start, ($end ?? count($lines)) - $start, true);
}

/** Unit tokens that satisfy the discipline rule when adjacent to a dollar figure. */
function bannerUnitTokens(): array
{
    return ['/check', '/yr', 'per paycheck', 'per year', 'est. range'];
}

test('Change 4: every rendered dollar figure in the banner has an adjacent unit token', function () {
    $body = bannerSourceLines();
    $tokens = bannerUnitTokens();
    $keys = array_keys($body);
    $violations = [];

    foreach ($keys as $idx => $lineNo) {
        $line = $body[$lineNo];

        // Only JSX-rendered dollar figures: an interpolated fmtAbs()/fmtCents() call.
        // Const declarations / plain logic lines are not rendered output.
        if (! preg_match('/\{[^}]*fmt(?:Abs|Cents)\(/', $line)) {
            continue;
        }

        // Window: same line + next 3 physical lines (before→after pairs share
        // one trailing unit token, e.g. "$5,512 → $5,343 per paycheck").
        $window = $line;
        for ($k = 1; $k <= 3; $k++) {
            $nextKey = $keys[$idx + $k] ?? null;
            if ($nextKey !== null) {
                $window .= "\n".$body[$nextKey];
            }
        }

        $hasUnit = false;
        foreach ($tokens as $token) {
            if (str_contains($window, $token)) {
                $hasUnit = true;
                break;
            }
        }

        if (! $hasUnit) {
            $violations[] = sprintf('OptimizationChecklistView.tsx:%d — %s', $lineNo + 1, trim($line));
        }
    }

    expect($violations)->toBeEmpty(
        "Dollar figures rendered without an adjacent unit token (/check, /yr, per paycheck, per year):\n"
        .implode("\n", $violations)
    );
});

test('Change 4: no standalone unit caption exists in the banner', function () {
    $body = bannerSourceLines();
    $violations = [];

    foreach ($body as $lineNo => $line) {
        // A <p> caption element whose ENTIRE content is a unit token, e.g.
        // <p className="...">per paycheck</p> — the mixed-unit caption pattern this
        // gate exists to kill (an annual delta rendered above a per-paycheck caption).
        // Inline <span>per paycheck</span> IN THE SAME paragraph as its number is the
        // correct pattern and is NOT flagged.
        if (preg_match('/<p\b[^>]*>\s*(?:per paycheck|per year|savings\/yr|contrib\/yr|federal\/yr|\/yr|\/check)\s*<\/p>/u', $line)) {
            $violations[] = sprintf('OptimizationChecklistView.tsx:%d — %s', $lineNo + 1, trim($line));
        }
    }

    expect($violations)->toBeEmpty(
        "Standalone unit captions found (units must be inline with their number):\n"
        .implode("\n", $violations)
    );
});

test('Change 4: the redesigned banner unit structure is present', function () {
    $source = implode("\n", bannerSourceLines());

    // Tile 1: before→after pair carries "per paycheck"; delta line carries both units.
    expect($source)->toContain('per paycheck');
    expect($source)->toContain('/check');

    // Tile 2: before→after pair carries "per year"; delta line carries /yr.
    expect($source)->toContain('per year');
    expect($source)->toContain('/yr');

    // Tile 3: retirement stays a range with the D9.7 assumptions line.
    expect($source)->toContain('est. range');
    expect($source)->toContain('not a guarantee');
});
