<?php

/**
 * SAFE-03 Consolidation Gate — Payload-Exclusion Axis
 *
 * CLAIM: All report/finding dollar amounts originate from TaxRulesEngineService or config.
 * Claude narrates only — it never receives estimated_value_cents, net_cash_cost, tax_saved,
 * or cliff_bonus_value as array keys in any request payload.
 *
 * This test complements:
 *   - EstimatedValueGuardTest   → write-site axis (no assignment outside TaxRulesEngineService)
 *   - NoLiteralGuardTest        → literal-value axis (no raw IRS thresholds in service methods)
 *
 * What this test adds (payload-exclusion axis):
 *   Scans every Claude call-site service that builds an optimization/finding/report payload
 *   and asserts none of the excluded dollar field names appear as quoted array keys
 *   (i.e. 'field_name' =>) on a non-comment, non-docblock line.
 *
 * A quoted field name appearing merely as an array-read ($arr['field_name']) is intentionally
 * NOT a violation — only writing the field as an array key into a payload is forbidden.
 *
 * Excluded field set (from NarrationService docblock / OptimizationReportNarratorService docblock):
 *   estimated_value_cents  — primary finding value in cents
 *   net_cash_cost          — net cost after tax benefit
 *   tax_saved              — direct tax reduction amount
 *   cliff_bonus_value      — ACA cliff rescue value
 */

// ── Shared helpers ────────────────────────────────────────────────────────────

/**
 * Return the canonical set of dollar fields that must never appear as array
 * keys in any narration/report Claude payload.
 *
 * @return string[]
 */
function safe03ExcludedFields(): array
{
    return [
        'estimated_value_cents',
        'net_cash_cost',
        'tax_saved',
        'cliff_bonus_value',
    ];
}

/**
 * Check whether a source line should be skipped (comment or docblock line).
 *
 * After stripping leading whitespace, a line is a comment if it begins with
 * //, *, or # — matching the same discipline as EstimatedValueGuardTest.
 */
function safe03IsCommentLine(string $line): bool
{
    $trimmed = ltrim($line);

    return str_starts_with($trimmed, '//') ||
           str_starts_with($trimmed, '*') ||
           str_starts_with($trimmed, '#');
}

/**
 * Scan a file for any excluded dollar field name appearing as a quoted
 * array-key on a non-comment, non-docblock line.
 *
 * Detects: 'field_name' => ... or "field_name" => ...
 *
 * Does NOT detect: $arr['field_name'] (value-read only — not setting a payload key)
 * Does NOT detect: $model->field_name (Eloquent property access — not a payload key)
 *
 * @param  string[]  $excludedFields
 * @return string[]
 */
function safe03ScanPayloadExclusion(string $filePath, array $excludedFields): array
{
    $violations = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES);
    $relPath = str_replace(base_path('/'), '', $filePath);

    foreach ($lines as $lineNumber => $line) {
        if (safe03IsCommentLine($line)) {
            continue;
        }

        foreach ($excludedFields as $field) {
            // Match the field name as a quoted array KEY, immediately followed by =>
            // This catches 'field_name' => ... or "field_name" => ...
            // It does NOT match $arr['field_name'] because there the => is NOT directly after the closing quote
            if (preg_match('/[\'"]' . preg_quote($field, '/') . '[\'\"]\s*=>/', $line)) {
                $violations[] = sprintf(
                    '%s:%d — %s',
                    $relPath,
                    $lineNumber + 1,
                    trim($line)
                );
            }
        }
    }

    return $violations;
}

// ── Per-file payload-exclusion scans ─────────────────────────────────────────

/*
 * SAFE-03 scope: the three Claude call-site services that build optimization/finding/report
 * payloads in the v2.1 milestone surface.
 *
 * TaxDocumentExtractorService is excluded from this test because its payload is an extraction
 * schema (document → structured data), not an optimization/finding payload. Its safety is
 * covered by InjectionPenTest (schema-whitelist output validation, SAFE-07).
 */

test('SAFE-03 payload: NarrationService sends no dollar field to Claude', function () {
    $path = base_path('app/Services/NarrationService.php');
    expect(file_exists($path))->toBeTrue('NarrationService.php must exist');

    $violations = safe03ScanPayloadExclusion($path, safe03ExcludedFields());

    expect($violations)->toBeEmpty(
        'SAFE-03 payload violation in NarrationService — dollar field found as array key:' . PHP_EOL . implode(PHP_EOL, $violations)
    );
});

test('SAFE-03 payload: OptimizationReportNarratorService sends no dollar field to Claude', function () {
    $path = base_path('app/Services/OptimizationReportNarratorService.php');
    expect(file_exists($path))->toBeTrue('OptimizationReportNarratorService.php must exist');

    $violations = safe03ScanPayloadExclusion($path, safe03ExcludedFields());

    expect($violations)->toBeEmpty(
        'SAFE-03 payload violation in OptimizationReportNarratorService — dollar field found as array key:' . PHP_EOL . implode(PHP_EOL, $violations)
    );
});

test('SAFE-03 payload: InterviewOrchestratorService sends no dollar field to Claude', function () {
    $path = base_path('app/Services/InterviewOrchestratorService.php');
    expect(file_exists($path))->toBeTrue('InterviewOrchestratorService.php must exist');

    $violations = safe03ScanPayloadExclusion($path, safe03ExcludedFields());

    expect($violations)->toBeEmpty(
        'SAFE-03 payload violation in InterviewOrchestratorService — dollar field found as array key:' . PHP_EOL . implode(PHP_EOL, $violations)
    );
});

// ── Anchor assertions — sibling guards and dollar-math source must exist ──────

test('SAFE-03 anchor: EstimatedValueGuardTest exists (write-site axis)', function () {
    expect(file_exists(base_path('tests/Unit/EstimatedValueGuardTest.php')))->toBeTrue(
        'EstimatedValueGuardTest.php must exist — it guards the write-site axis'
    );
});

test('SAFE-03 anchor: NoLiteralGuardTest exists (literal-value axis)', function () {
    expect(file_exists(base_path('tests/Unit/NoLiteralGuardTest.php')))->toBeTrue(
        'NoLiteralGuardTest.php must exist — it guards the no-raw-literal axis'
    );
});

test('SAFE-03 anchor: TaxRulesEngineService exists (sole permitted dollar-math source)', function () {
    expect(file_exists(base_path('app/Services/TaxRulesEngineService.php')))->toBeTrue(
        'TaxRulesEngineService.php must exist — it is the sole permitted source of report dollar amounts'
    );
});
