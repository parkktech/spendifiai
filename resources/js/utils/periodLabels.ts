import type { PeriodMeta } from '@/types/spendifiai';

export interface PeriodLabels {
  incomeLabel: string;
  surplusLabel: string;
  deficitLabel: string;
  amountSuffix: string;
  timeDescriptor: string;
  waterfallSubtitle: string;
  periodAdjective: string;
  dateRange: string;
  isSingleMonth: boolean;
  isAvgMode: boolean;
}

/** Format a date string (YYYY-MM-DD) as "Jan 1" or "Jan 1, 2025" if year differs from reference */
function fmtDate(dateStr: string, refYear?: number): string {
  const d = new Date(dateStr + 'T00:00:00');
  const month = d.toLocaleString('en-US', { month: 'short' });
  const day = d.getDate();
  if (refYear !== undefined && d.getFullYear() !== refYear) {
    return `${month} ${day}, ${d.getFullYear()}`;
  }
  return `${month} ${day}`;
}

/** Build a readable date range like "Jan 1 – Feb 15" or "Mar 1, 2025 – Mar 6, 2026" */
function buildDateRange(start: string, end: string): string {
  const startDate = new Date(start + 'T00:00:00');
  const endDate = new Date(end + 'T00:00:00');
  const sameYear = startDate.getFullYear() === endDate.getFullYear();
  if (sameYear) {
    return `${fmtDate(start)} – ${fmtDate(end)}`;
  }
  return `${fmtDate(start, -1)} – ${fmtDate(end, -1)}`;
}

export const DEFAULT_PERIOD_LABELS: PeriodLabels = {
  incomeLabel: 'Monthly Income',
  surplusLabel: 'Monthly Surplus',
  deficitLabel: 'Monthly Deficit',
  amountSuffix: '/mo',
  timeDescriptor: 'this month',
  waterfallSubtitle: 'Where every dollar goes this month',
  periodAdjective: 'Monthly',
  dateRange: '',
  isSingleMonth: true,
  isAvgMode: false,
};

/**
 * Generate period-aware labels for dashboard sections.
 * @param isCustomRange – true only when user manually picks a custom date range
 */
export function getPeriodLabels(period: PeriodMeta, isCustomRange = false): PeriodLabels {
  const { months, avg_mode } = period;
  const range = buildDateRange(period.start, period.end);
  // Only append date range for custom ranges
  const rangeTag = isCustomRange ? ` (${range})` : '';

  // Single month (This Month / Last Month / short custom range)
  if (months <= 1) {
    if (isCustomRange) {
      return {
        incomeLabel: `Income (${range})`,
        surplusLabel: `Surplus (${range})`,
        deficitLabel: `Deficit (${range})`,
        amountSuffix: '',
        timeDescriptor: `from ${range}`,
        waterfallSubtitle: `Where every dollar goes (${range})`,
        periodAdjective: 'Total',
        dateRange: range,
        isSingleMonth: true,
        isAvgMode: false,
      };
    }
    return { ...DEFAULT_PERIOD_LABELS, dateRange: range };
  }

  // Average mode (any multi-month period viewed as monthly averages)
  if (avg_mode === 'monthly_avg') {
    return {
      incomeLabel: `Avg Monthly Income${rangeTag}`,
      surplusLabel: `Avg Monthly Surplus${rangeTag}`,
      deficitLabel: `Avg Monthly Deficit${rangeTag}`,
      amountSuffix: '/mo avg',
      timeDescriptor: 'on average per month',
      waterfallSubtitle: `Where every dollar typically goes per month${rangeTag}`,
      periodAdjective: 'Monthly Avg',
      dateRange: range,
      isSingleMonth: false,
      isAvgMode: true,
    };
  }

  // 12+ months → annual labels (checked before YTD so full-year ranges get "Annual")
  if (months >= 12) {
    return {
      incomeLabel: `Annual Income${rangeTag}`,
      surplusLabel: `Annual Surplus${rangeTag}`,
      deficitLabel: `Annual Deficit${rangeTag}`,
      amountSuffix: '/yr',
      timeDescriptor: 'over the past year',
      waterfallSubtitle: `Where every dollar goes this year${rangeTag}`,
      periodAdjective: 'Annual',
      dateRange: range,
      isSingleMonth: false,
      isAvgMode: false,
    };
  }

  // YTD detection: starts Jan 1 of the current year, less than 12 months
  const start = new Date(period.start + 'T00:00:00');
  const now = new Date();
  const isYTD = !isCustomRange
    && start.getMonth() === 0 && start.getDate() === 1
    && start.getFullYear() === now.getFullYear();

  if (isYTD) {
    return {
      incomeLabel: 'YTD Income',
      surplusLabel: 'YTD Surplus',
      deficitLabel: 'YTD Deficit',
      amountSuffix: ' (YTD)',
      timeDescriptor: 'year to date',
      waterfallSubtitle: 'Where your dollars go year to date',
      periodAdjective: 'YTD',
      dateRange: range,
      isSingleMonth: false,
      isAvgMode: false,
    };
  }

  // Multi-month totals (3, 6, or custom)
  if (isCustomRange) {
    return {
      incomeLabel: `Total Income (${range})`,
      surplusLabel: `Total Surplus (${range})`,
      deficitLabel: `Total Deficit (${range})`,
      amountSuffix: ' (total)',
      timeDescriptor: `from ${range}`,
      waterfallSubtitle: `Where every dollar goes (${range})`,
      periodAdjective: `${months}-Month`,
      dateRange: range,
      isSingleMonth: false,
      isAvgMode: false,
    };
  }

  return {
    incomeLabel: `${months}-Month Income`,
    surplusLabel: `${months}-Month Surplus`,
    deficitLabel: `${months}-Month Deficit`,
    amountSuffix: ' (total)',
    timeDescriptor: `over ${months} months`,
    waterfallSubtitle: `Where every dollar goes over ${months} months`,
    periodAdjective: `${months}-Month`,
    dateRange: range,
    isSingleMonth: false,
    isAvgMode: false,
  };
}
