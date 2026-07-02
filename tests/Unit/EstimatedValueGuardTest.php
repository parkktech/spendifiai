<?php

/**
 * SAFE-03 Grep-Gate: estimated_value_cents must ONLY be assigned
 * inside TaxRulesEngineService.php.
 *
 * This test scans all PHP files under app/ and fails if any file OTHER than
 * TaxRulesEngineService.php contains an assignment to estimated_value_cents.
 *
 * Excluded from the scan:
 *  - app/Services/TaxRulesEngineService.php (the sole permitted writer)
 *  - Tests themselves (test assertions that read the field are fine)
 *  - Migration files (ADD COLUMN SQL is not an "assignment" in PHP)
 *
 * What counts as a forbidden assignment:
 *  - 'estimated_value_cents' => <value>   (array key write in updateOrCreate/fill)
 *  - $model->estimated_value_cents = ...  (direct property write)
 *  - ->fill(['estimated_value_cents' => ... (fill call)
 *  - ::create(['estimated_value_cents' => ... (static create call)
 *
 * Lines that are pure comments (leading whitespace + //) are excluded.
 */
test('SAFE-03: estimated_value_cents only assigned in TaxRulesEngineService', function () {
    $appDir = base_path('app');
    $permitted = base_path('app/Services/TaxRulesEngineService.php');

    $violations = [];

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($appDir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getRealPath();

        // Only TaxRulesEngineService is permitted to assign this field
        if ($path === $permitted) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $lineNumber => $line) {
            // Skip pure comment lines (leading whitespace + // or #)
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '*')) {
                continue;
            }

            // Detect assignments to estimated_value_cents
            // Pattern: the identifier followed by assignment operators or array-key context
            if (preg_match('/estimated_value_cents\s*(?:=>|=(?!=))/', $line)) {
                $violations[] = sprintf('%s:%d — %s', str_replace(base_path('/'), '', $path), $lineNumber + 1, trim($line));
            }
        }
    }

    expect($violations)->toBeEmpty(
        'SAFE-03 violation: estimated_value_cents assigned outside TaxRulesEngineService: '.PHP_EOL.implode(PHP_EOL, $violations)
    );
});

test('SAFE-03: TaxRulesEngineService.php itself exists', function () {
    $path = base_path('app/Services/TaxRulesEngineService.php');
    expect(file_exists($path))->toBeTrue();
});
