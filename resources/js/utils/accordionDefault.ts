/**
 * accordionDefault — "Add more documents" accordion tri-state default logic.
 *
 * Tri-state priority:
 *   1. User's manual preference (from localStorage) — always wins.
 *   2. All doc types have a ready doc → auto-collapse.
 *   3. Any doc type is missing → auto-expand (show remaining work).
 *
 * Extracted as a pure function so the logic is testable in isolation
 * (see tests/js/accordionDefault.test.mjs).
 */

export interface DocTypeStatusMin {
  has_ready_doc: boolean;
}

export type AccordionPreference = boolean | null;

/**
 * Compute the default expanded state for the "Add more documents" accordion.
 *
 * @param typeStatus   Per-type inventory from GET /api/v1/tax-vault/type-status.
 *                     Empty object when not yet loaded (loading state).
 * @param userPreference  Manual preference stored in localStorage (`true`/`false`),
 *                     or `null` when the user has not set a preference yet.
 * @returns `true` = expanded, `false` = collapsed
 */
export function computeAccordionDefault(
  typeStatus: Record<string, DocTypeStatusMin>,
  userPreference: AccordionPreference,
): boolean {
  // State 1: user's manual preference always wins over auto-defaults.
  if (userPreference !== null) {
    return userPreference;
  }

  const types = Object.values(typeStatus);

  // State 3: no data yet (loading) → expand so the upload UI is immediately visible.
  if (types.length === 0) {
    return true;
  }

  // State 2: all types have a ready doc → auto-collapse; any missing → expand.
  return !types.every((s) => s.has_ready_doc);
}
