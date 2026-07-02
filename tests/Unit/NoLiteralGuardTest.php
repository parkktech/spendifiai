<?php

/*
|--------------------------------------------------------------------------
| SCENARIOS-SPEC §F / §T.13 — No-literal grep guard (FLAG-08 pattern)
|--------------------------------------------------------------------------
| Every money/limit/rate threshold used by the SCN-01…SCN-07 scenario methods
| MUST trace to config (tax-rules.php or optimizer-scenarios.php). This test
| statically extracts each new method's BODY from TaxRulesEngineService.php,
| strips comments + string literals, and fails if any raw numeric threshold
| literal remains.
|
| Allowlisted structural constants (NOT IRS thresholds):
|   0, 1, 2  — min/max bounds and the FICA employee-half divisor
|   100      — the cents<->dollars converter and the percent (0–100) divisor
|
| Method signatures (with their `= 2026` default year) are excluded — only the
| body between the opening and closing brace is scanned.
*/

/**
 * Strip PHP block comments, line comments, and single/double quoted string
 * literals from source (so config-key braces/digits never confuse scanning).
 */
function stripCommentsAndStrings(string $src): string
{
    $src = preg_replace('/\/\*.*?\*\//s', '', $src);          // block comments + docblocks
    $src = preg_replace('/\/\/[^\n]*/', '', $src);            // line comments
    $src = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $src); // single-quoted strings
    $src = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $src); // double-quoted strings

    return $src;
}

/** Extract a method BODY (between the opening `{` and its matching `}`) by name. */
function extractMethodBody(string $strippedSrc, string $name): ?string
{
    $sig = strpos($strippedSrc, "function {$name}(");
    if ($sig === false) {
        return null;
    }

    $open = strpos($strippedSrc, '{', $sig);
    if ($open === false) {
        return null;
    }

    $depth = 0;
    $len = strlen($strippedSrc);
    for ($i = $open; $i < $len; $i++) {
        $ch = $strippedSrc[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($strippedSrc, $open + 1, $i - $open - 1);
            }
        }
    }

    return null;
}

test('SCN-01…SCN-07 method bodies contain no raw IRS/threshold literals', function () {
    $path = base_path('app/Services/TaxRulesEngineService.php');
    expect(file_exists($path))->toBeTrue();

    $stripped = stripCommentsAndStrings(file_get_contents($path));

    $methods = [
        'estimatePeriodWithholdingCents',
        'employeeFicaCents',
        'matchCaptureCents',
        'futureValueRangeCents',
        'projectedMagiCents',
        'acaCliffHeadroomCents',
        'computeScenarioOutcome',
    ];

    // Structural constants that are NOT IRS thresholds.
    $allowlist = ['0', '1', '2', '100'];

    $violations = [];

    foreach ($methods as $name) {
        $body = extractMethodBody($stripped, $name);

        expect($body)->not->toBeNull("Method {$name}() not found — the guard must not silently pass.");

        // Numeric literals that are NOT part of an identifier (e.g. the 401 in $trad401k
        // is preceded by a letter and correctly ignored).
        preg_match_all('/(?<![A-Za-z0-9_])\d[\d_]*(?:\.\d+)?(?![A-Za-z0-9_])/', $body, $matches);

        foreach ($matches[0] as $lit) {
            $normalized = str_replace('_', '', $lit);
            if (! in_array($normalized, $allowlist, true)) {
                $violations[] = "{$name}(): raw literal '{$lit}'";
            }
        }
    }

    expect($violations)->toBeEmpty(
        'No-literal guard failed — these numbers must come from config: '.PHP_EOL.implode(PHP_EOL, $violations)
    );
});

test('the guard actually finds all seven scenario methods', function () {
    $stripped = stripCommentsAndStrings(file_get_contents(base_path('app/Services/TaxRulesEngineService.php')));

    foreach ([
        'estimatePeriodWithholdingCents', 'employeeFicaCents', 'matchCaptureCents',
        'futureValueRangeCents', 'projectedMagiCents', 'acaCliffHeadroomCents', 'computeScenarioOutcome',
    ] as $name) {
        expect(extractMethodBody($stripped, $name))->not->toBeNull("Missing method body: {$name}");
    }
});
