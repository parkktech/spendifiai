/**
 * Unit tests for the "Add more documents" accordion tri-state default logic.
 *
 * Run with: node tests/js/accordionDefault.test.mjs
 *
 * No dependencies beyond Node.js built-ins — pure logic test.
 *
 * Tri-state priority:
 *   1. User's manual preference (localStorage) — always wins over auto-defaults.
 *   2. All doc types have a ready doc → auto-collapse.
 *   3. Any doc type is missing → auto-expand (show remaining work).
 *
 * @see resources/js/utils/accordionDefault.ts (TypeScript source)
 */

import assert from 'node:assert/strict';

// ── Inline the pure logic (mirrors the TS source exactly) ──────────────────────

/**
 * Compute the default expanded state for the "Add more documents" accordion.
 *
 * @param {Record<string, {has_ready_doc: boolean}>} typeStatus — per-type inventory
 * @param {boolean | null} userPreference — stored in localStorage, or null if unset
 * @returns {boolean} true = expanded, false = collapsed
 */
function computeAccordionDefault(typeStatus, userPreference) {
  // State 1: user's manual preference always wins
  if (userPreference !== null) {
    return userPreference;
  }

  const types = Object.values(typeStatus);

  // State 3: no data yet (loading) → expand to show the upload UI
  if (types.length === 0) {
    return true;
  }

  // State 2: all types have a ready doc → auto-collapse; else expand
  return !types.every((s) => s.has_ready_doc);
}

// ── Test cases ─────────────────────────────────────────────────────────────────

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

console.log('\naccordionDefault — tri-state logic\n');

// ── State 1: user preference wins ────────────────────────────────────────────

test('user preference true → expanded (all types filled)', () => {
  const status = { paystub: { has_ready_doc: true }, w2: { has_ready_doc: true } };
  assert.equal(computeAccordionDefault(status, true), true,
    'user pinned open must stay open even if all types are filled');
});

test('user preference false → collapsed (some types missing)', () => {
  const status = { paystub: { has_ready_doc: true }, w2: { has_ready_doc: false } };
  assert.equal(computeAccordionDefault(status, false), false,
    'user pinned closed must stay closed even if some types are missing');
});

test('user preference false overrides empty typeStatus (loading state)', () => {
  assert.equal(computeAccordionDefault({}, false), false,
    'user preference false wins over loading-state default (open)');
});

// ── State 2: auto-collapse when all types filled ──────────────────────────────

test('all types filled → auto-collapse (null preference)', () => {
  const status = {
    paystub:              { has_ready_doc: true },
    w2:                   { has_ready_doc: true },
    benefits_guide:       { has_ready_doc: true },
    hsa_statement:        { has_ready_doc: true },
    retirement_statement: { has_ready_doc: true },
    medical_receipt:      { has_ready_doc: true },
    other:                { has_ready_doc: true },
  };
  assert.equal(computeAccordionDefault(status, null), false,
    'all 7 types filled → should auto-collapse');
});

test('even one type missing → auto-expand (null preference)', () => {
  const status = {
    paystub:        { has_ready_doc: true },
    w2:             { has_ready_doc: true },
    benefits_guide: { has_ready_doc: false }, // missing
  };
  assert.equal(computeAccordionDefault(status, null), true,
    'one missing type → should auto-expand');
});

// ── State 3: loading (empty typeStatus) → expand ─────────────────────────────

test('no typeStatus data yet (loading) → expand', () => {
  assert.equal(computeAccordionDefault({}, null), true,
    'no data loaded yet → default to expanded so the upload UI is visible');
});

test('single type, not ready → expand', () => {
  assert.equal(computeAccordionDefault({ paystub: { has_ready_doc: false } }, null), true);
});

test('single type, ready → collapse', () => {
  assert.equal(computeAccordionDefault({ paystub: { has_ready_doc: true } }, null), false);
});

// ── Edge cases ────────────────────────────────────────────────────────────────

test('user preference null is not confused with false', () => {
  const status = { paystub: { has_ready_doc: true } };
  // null → use auto logic → all filled → collapse
  assert.equal(computeAccordionDefault(status, null), false);
  // false → user pinned closed
  assert.equal(computeAccordionDefault(status, false), false);
  // true → user pinned open despite all filled
  assert.equal(computeAccordionDefault(status, true), true);
});

// ── Summary ───────────────────────────────────────────────────────────────────

console.log(`\n${passed} passed, ${failed} failed\n`);
if (failed > 0) process.exit(1);
