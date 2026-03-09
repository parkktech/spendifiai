export const US_TIMEZONES = [
  { value: 'America/New_York', label: 'Eastern Time (ET)' },
  { value: 'America/Chicago', label: 'Central Time (CT)' },
  { value: 'America/Denver', label: 'Mountain Time (MT)' },
  { value: 'America/Phoenix', label: 'Arizona (no DST)' },
  { value: 'America/Los_Angeles', label: 'Pacific Time (PT)' },
  { value: 'America/Anchorage', label: 'Alaska Time (AKT)' },
  { value: 'Pacific/Honolulu', label: 'Hawaii Time (HT)' },
];

const usValues = new Set(US_TIMEZONES.map((tz) => tz.value));

export function getAllTimezones(): { value: string; label: string }[] {
  try {
    const all = Intl.supportedValuesOf('timeZone');
    const others = all
      .filter((tz) => !usValues.has(tz))
      .map((tz) => ({ value: tz, label: tz.replace(/_/g, ' ') }));
    return [...US_TIMEZONES, ...others];
  } catch {
    // Fallback for older browsers
    return US_TIMEZONES;
  }
}
