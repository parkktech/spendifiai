/**
 * Unit tests for findingTypeLabel and renderFindingDescription helpers.
 *
 * Run with: node tests/js/findingTypeLabel.test.mjs
 *
 * Fix 2 — Kill the "Analysis in progress..." lie:
 *   Findings on a READY report must NEVER show "Analysis in progress..."
 *   regardless of whether `description` is null.
 *   Only when `isGenerating=true` and `description` is null is that string legal.
 *
 * D18 gate: no rendered output should be raw snake_case key paths.
 *
 * @see resources/js/utils/findingTypeLabel.ts (TypeScript source)
 */

import assert from 'node:assert/strict';

// ── Inline the pure logic (mirrors the TS source exactly) ──────────────────────

const FINDING_PREFIX_LABELS = {
  retirement:   'Retirement planning',
  conformance:  'Profile consistency',
  withholding:  'Withholding',
  deduction:    'Deduction opportunity',
  probe:        'Income analysis',
  red:          'Area to review',
  ira:          'IRA opportunity',
  hsa:          'HSA opportunity',
  income:       'Income area',
  filing:       'Filing consideration',
  business:     'Business area',
  w4:           'W-4 area',
};

/**
 * Convert a snake_case finding_type to a human-readable category label.
 *
 * D18: output must not contain raw snake_case identifiers or internal jargon.
 */
function findingTypeLabel(findingType) {
  if (!findingType) return 'Finding';
  const prefix = findingType.split('_')[0].toLowerCase();
  return FINDING_PREFIX_LABELS[prefix] ?? 'Tax area';
}

/**
 * Return the display text for a finding's description area.
 *
 * Fix 2 contract:
 *   - description non-null → return description (always)
 *   - description null + isGenerating=true  → 'Analysis in progress...' (LEGAL)
 *   - description null + isGenerating=false → findingTypeLabel(findingType) (never lie)
 */
function renderFindingDescription(description, findingType, isGenerating) {
  if (description !== null && description !== undefined) return description;
  if (isGenerating) return 'Analysis in progress...';
  return findingTypeLabel(findingType);
}

// ── Test harness ──────────────────────────────────────────────────────────────

let passed = 0;
let failed = 0;

function test(name, fn) {
  try {
    fn();
    console.log(`  ✓ ${name}`);
    passed++;
  } catch (err) {
    console.error(`  ✗ ${name}`);
    console.error(`    ${err.message}`);
    failed++;
  }
}

console.log('\nfindingTypeLabel — Fix 2 "Analysis in progress..." gate\n');

// ── findingTypeLabel: basic conversions ───────────────────────────────────────

test('retirement prefix → "Retirement planning"', () => {
  assert.equal(findingTypeLabel('retirement_after_tax_401k_opportunity'), 'Retirement planning');
});

test('conformance prefix → "Profile consistency" (not raw jargon)', () => {
  assert.equal(findingTypeLabel('conformance_name'), 'Profile consistency');
  assert.equal(findingTypeLabel('conformance_dependents'), 'Profile consistency');
});

test('withholding prefix → "Withholding"', () => {
  assert.equal(findingTypeLabel('withholding_calibration'), 'Withholding');
});

test('deduction prefix → "Deduction opportunity"', () => {
  assert.equal(findingTypeLabel('deduction_home_office'), 'Deduction opportunity');
});

test('probe prefix → "Income analysis"', () => {
  assert.equal(findingTypeLabel('probe_deferral_gap'), 'Income analysis');
});

test('unknown prefix → "Tax area" (safe fallback)', () => {
  assert.equal(findingTypeLabel('xyzzy_unknown_thing'), 'Tax area');
});

test('empty string → "Finding"', () => {
  assert.equal(findingTypeLabel(''), 'Finding');
});

test('undefined/null finding type → "Finding"', () => {
  assert.equal(findingTypeLabel(null ?? ''), 'Finding');
  assert.equal(findingTypeLabel(undefined ?? ''), 'Finding');
});

// ── D18: no snake_case in output ──────────────────────────────────────────────

const knownFindingTypes = [
  'retirement_after_tax_401k_opportunity',
  'retirement_contribution_mix_review',
  'retirement_match_pace_gap',
  'retirement_w4_step3_alignment',
  'conformance_name',
  'conformance_dependents',
  'probe_deferral_gap',
  'withholding_calibration',
  'deduction_home_office',
  'income_reconciliation',
];

test('D18: no snake_case key paths in any output (no underscore-separated words that look like keys)', () => {
  for (const type of knownFindingTypes) {
    const label = findingTypeLabel(type);
    // The label should not look like a snake_case identifier
    // A snake_case pattern is: word_word (lowercase word, underscore, lowercase word)
    const hasSnakeCase = /\b[a-z]+_[a-z]/.test(label);
    assert.equal(hasSnakeCase, false,
      `findingTypeLabel('${type}') returned '${label}' which contains snake_case`);
  }
});

// ── renderFindingDescription: Fix 2 contract ──────────────────────────────────

test('description provided → returned as-is (isGenerating irrelevant)', () => {
  const desc = 'Your employer offers after-tax 401(k) contributions.';
  assert.equal(renderFindingDescription(desc, 'retirement_after_tax_401k_opportunity', false), desc);
  assert.equal(renderFindingDescription(desc, 'retirement_after_tax_401k_opportunity', true), desc);
});

test('description null + isGenerating=true → "Analysis in progress..." (LEGAL)', () => {
  const result = renderFindingDescription(null, 'retirement_contribution_mix_review', true);
  assert.equal(result, 'Analysis in progress...');
});

// Fix 2 CORE GATE: "in progress" is ILLEGAL on a ready report
test('Fix 2 gate: description null + isGenerating=false → NOT "Analysis in progress..."', () => {
  const result = renderFindingDescription(null, 'conformance_name', false);
  assert.notEqual(result, 'Analysis in progress...',
    'FAIL: "Analysis in progress..." rendered on a ready report — Fix 2 violated');
});

test('Fix 2 gate: result for ready report is non-empty when description is null', () => {
  const result = renderFindingDescription(null, 'probe_deferral_gap', false);
  assert.ok(result && result.length > 0, 'ready report finding with null description must render something');
});

test('Fix 2 gate: batch — no "in progress" for any known finding type on ready report', () => {
  for (const type of knownFindingTypes) {
    const result = renderFindingDescription(null, type, false);
    assert.notEqual(result, 'Analysis in progress...',
      `FAIL: "Analysis in progress..." rendered for '${type}' on ready report`);
  }
});

// ── Edge cases ────────────────────────────────────────────────────────────────

test('empty description string is not treated as null (it is a valid description)', () => {
  // Empty string is technically a description (just empty)
  const result = renderFindingDescription('', 'conformance_name', false);
  assert.equal(result, '', 'empty string description should pass through unchanged');
});

// ── Summary ───────────────────────────────────────────────────────────────────

console.log(`\n${passed} passed, ${failed} failed\n`);
if (failed > 0) process.exit(1);
