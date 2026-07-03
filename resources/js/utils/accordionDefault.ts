/**
 * accordionDefault — "Add more documents" accordion tri-state default logic.
 *
 * Tri-state priority:
 *   1. User's manual preference (from localStorage) — always wins.
 *   2. All doc types are covered (ready doc OR user-excluded) → auto-collapse.
 *   3. Any doc type is missing and not excluded → auto-expand (show remaining work).
 *
 * "Not applicable" exclusions count as covered (Item 1): a user who marks
 * "HSA Statement — not applicable" should not see the accordion pinned open
 * just because they have no HSA document.
 *
 * Extracted as a pure function so the logic is testable in isolation
 * (see tests/js/accordionDefault.test.mjs).
 */

export interface DocTypeStatusMin {
  has_ready_doc: boolean;
  /** true when the user has marked this type as "Not applicable" (Item 1). */
  is_excluded?: boolean;
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

  // State 2: all types are "covered" (ready doc OR user-excluded) → auto-collapse.
  // Excluded types count as covered — the user has explicitly said they don't apply.
  return !types.every((s) => s.has_ready_doc || s.is_excluded === true);
}
