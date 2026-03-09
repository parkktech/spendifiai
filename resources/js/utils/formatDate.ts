/**
 * Centralized date formatting utilities.
 * All functions accept an optional IANA timezone (e.g. "America/Phoenix").
 * Dates are parsed carefully: date-only strings get "T00:00:00" appended
 * to avoid UTC-shift issues; ISO datetimes pass through as-is.
 */

function parseDate(dateStr: string): Date {
  // If it looks like a date-only string (YYYY-MM-DD), append T00:00:00
  // to prevent the Date constructor from interpreting it as UTC midnight
  if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
    return new Date(dateStr + 'T00:00:00');
  }
  return new Date(dateStr);
}

/** "Mar 7, 2026" */
export function formatDate(dateStr: string, tz?: string): string {
  const d = parseDate(dateStr);
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    ...(tz ? { timeZone: tz } : {}),
  }).format(d);
}

/** "Mar 7" */
export function formatDateShort(dateStr: string, tz?: string): string {
  const d = parseDate(dateStr);
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    ...(tz ? { timeZone: tz } : {}),
  }).format(d);
}

/** "Mar 7, 2026, 3:30 PM" */
export function formatDateTime(dateStr: string, tz?: string): string {
  const d = parseDate(dateStr);
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    ...(tz ? { timeZone: tz } : {}),
  }).format(d);
}
