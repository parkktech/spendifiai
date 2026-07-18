// Compact currency formatter with k suffix for thousands
export function formatCurrencyCompact(value: number): string {
  if (value >= 1000) return `$${(value / 1000).toFixed(1)}k`;
  return `$${value}`;
}
