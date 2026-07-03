/**
 * findingTypeLabel — Fix 2: deterministic finding label for ready reports.
 *
 * Converts a snake_case `finding_type` to a human-readable category label.
 * Used when a finding's `description` is null and the report is not generating —
 * prevents the illegal "Analysis in progress..." string from rendering on a
 * completed report.
 *
 * D18 rule 2: output must not contain raw snake_case key paths or internal jargon.
 * All prefixes map to plain English category names.
 */

const FINDING_PREFIX_LABELS: Record<string, string> = {
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
 * Uses the first token (prefix) as the category key.  Unknown prefixes fall
 * back to "Tax area" — a safe, D18-compliant label for any future finding type.
 *
 * @example
 *   findingTypeLabel('retirement_after_tax_401k_opportunity') → 'Retirement planning'
 *   findingTypeLabel('conformance_name')                       → 'Profile consistency'
 *   findingTypeLabel('probe_deferral_gap')                    → 'Income analysis'
 */
export function findingTypeLabel(findingType: string): string {
  if (!findingType) return 'Finding';
  const prefix = findingType.split('_')[0].toLowerCase();
  return FINDING_PREFIX_LABELS[prefix] ?? 'Tax area';
}

/**
 * Return the display text for a finding description area.
 *
 * Fix 2 contract:
 *  - Non-null description → return it unchanged (isGenerating irrelevant)
 *  - Null + isGenerating=true  → 'Analysis in progress...' (the ONLY legal use)
 *  - Null + isGenerating=false → findingTypeLabel(findingType) — never lie
 *
 * @param description   The finding's `description` field (nullable from the API)
 * @param findingType   The finding's `finding_type` key
 * @param isGenerating  True only when report.status === 'generating'
 */
export function renderFindingDescription(
  description: string | null,
  findingType: string,
  isGenerating: boolean,
): string | null {
  if (description !== null && description !== undefined) return description;
  if (isGenerating) return 'Analysis in progress...';
  // Report is ready but description is null — deterministic fallback (Fix 2).
  return findingTypeLabel(findingType);
}
