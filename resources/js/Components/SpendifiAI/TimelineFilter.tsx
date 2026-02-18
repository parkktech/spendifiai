import { useState, useMemo } from 'react';
import { Calendar, BarChart3, TrendingUp } from 'lucide-react';
import type { TimelinePeriod, PeriodMeta } from '@/types/spendifiai';

interface TimelineFilterProps {
  onPeriodChange: (start: string | null, end: string | null, avgMode: 'total' | 'monthly_avg') => void;
  onDisplayModeChange?: (mode: 'dollars' | 'percent') => void;
  currentPeriod?: PeriodMeta;
}

function formatDate(d: Date): string {
  return d.toISOString().split('T')[0];
}

function startOfMonth(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), 1);
}

function endOfMonth(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth() + 1, 0);
}

function subtractMonths(d: Date, months: number): Date {
  const result = new Date(d);
  result.setMonth(result.getMonth() - months);
  return result;
}

const PRESETS: { key: TimelinePeriod; label: string; shortLabel: string }[] = [
  { key: 'this_month', label: 'This Month', shortLabel: 'This Mo' },
  { key: 'last_month', label: 'Last Month', shortLabel: 'Last Mo' },
  { key: 'last_3_months', label: '3 Months', shortLabel: '3 Mo' },
  { key: 'last_6_months', label: '6 Months', shortLabel: '6 Mo' },
  { key: 'last_year', label: '1 Year', shortLabel: '1 Yr' },
  { key: 'ytd', label: 'Year to Date', shortLabel: 'YTD' },
  { key: 'custom', label: 'Custom', shortLabel: 'Custom' },
];

export default function TimelineFilter({ onPeriodChange, onDisplayModeChange, currentPeriod }: TimelineFilterProps) {
  const [activePeriod, setActivePeriod] = useState<TimelinePeriod>('this_month');
  const [customStart, setCustomStart] = useState('');
  const [customEnd, setCustomEnd] = useState('');
  const [avgMode, setAvgMode] = useState<'total' | 'monthly_avg'>('total');
  const [displayMode, setDisplayMode] = useState<'dollars' | 'percent'>('dollars');

  const isMultiMonth = useMemo(() => {
    return !['this_month', 'last_month'].includes(activePeriod);
  }, [activePeriod]);

  const getPresetDates = (preset: TimelinePeriod): [string | null, string | null] => {
    const now = new Date();

    switch (preset) {
      case 'this_month':
        return [null, null]; // Default behavior
      case 'last_month': {
        const start = startOfMonth(subtractMonths(now, 1));
        const end = endOfMonth(subtractMonths(now, 1));
        return [formatDate(start), formatDate(end)];
      }
      case 'last_3_months': {
        const start = startOfMonth(subtractMonths(now, 3));
        return [formatDate(start), formatDate(now)];
      }
      case 'last_6_months': {
        const start = startOfMonth(subtractMonths(now, 6));
        return [formatDate(start), formatDate(now)];
      }
      case 'last_year': {
        const start = startOfMonth(subtractMonths(now, 12));
        return [formatDate(start), formatDate(now)];
      }
      case 'ytd': {
        const start = new Date(now.getFullYear(), 0, 1);
        return [formatDate(start), formatDate(now)];
      }
      case 'custom':
        return [customStart || null, customEnd || null];
    }
  };

  const handlePresetClick = (preset: TimelinePeriod) => {
    setActivePeriod(preset);
    if (preset !== 'custom') {
      const [start, end] = getPresetDates(preset);
      // Reset avg mode to total when switching to single-month period
      const newAvgMode = ['this_month', 'last_month'].includes(preset) ? 'total' : avgMode;
      setAvgMode(newAvgMode);
      onPeriodChange(start, end, newAvgMode);
    }
  };

  const handleCustomApply = () => {
    if (customStart && customEnd) {
      onPeriodChange(customStart, customEnd, avgMode);
    }
  };

  const handleAvgModeToggle = () => {
    const newMode = avgMode === 'total' ? 'monthly_avg' : 'total';
    setAvgMode(newMode);
    const [start, end] = getPresetDates(activePeriod);
    onPeriodChange(start, end, newMode);
  };

  const periodLabel = useMemo(() => {
    if (!currentPeriod) return '';
    const start = new Date(currentPeriod.start + 'T00:00:00');
    const end = new Date(currentPeriod.end + 'T00:00:00');
    const opts: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric', year: 'numeric' };
    return `${start.toLocaleDateString('en-US', opts)} — ${end.toLocaleDateString('en-US', opts)}`;
  }, [currentPeriod]);

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card p-4">
      <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3">
        {/* Icon + label */}
        <div className="flex items-center gap-2 shrink-0">
          <div className="w-8 h-8 rounded-lg bg-sw-accent-light border border-sw-accent-muted flex items-center justify-center">
            <Calendar size={16} className="text-sw-accent" />
          </div>
          <span className="text-xs font-medium text-sw-muted hidden sm:inline">Period</span>
        </div>

        {/* Preset pills */}
        <div className="flex flex-wrap items-center gap-1.5">
          {PRESETS.map(({ key, label, shortLabel }) => (
            <button
              key={key}
              onClick={() => handlePresetClick(key)}
              className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${
                activePeriod === key
                  ? 'bg-sw-accent text-white shadow-sm'
                  : 'bg-sw-surface text-sw-muted hover:bg-sw-card-hover hover:text-sw-text'
              }`}
            >
              <span className="hidden sm:inline">{label}</span>
              <span className="sm:hidden">{shortLabel}</span>
            </button>
          ))}
        </div>

        {/* Display toggles */}
        <div className="flex items-center gap-2 ml-auto shrink-0">
          {/* $ / % toggle — always visible */}
          <div className="flex items-center rounded-lg bg-sw-surface p-0.5" title="Toggle between dollar amounts and percentages of income">
            <button
              onClick={() => { setDisplayMode('dollars'); onDisplayModeChange?.('dollars'); }}
              className={`px-2.5 py-1 rounded text-xs font-bold transition-all ${
                displayMode === 'dollars'
                  ? 'bg-sw-accent text-white shadow-sm'
                  : 'text-sw-muted hover:text-sw-text'
              }`}
            >
              $
            </button>
            <button
              onClick={() => { setDisplayMode('percent'); onDisplayModeChange?.('percent'); }}
              className={`px-2.5 py-1 rounded text-xs font-bold transition-all ${
                displayMode === 'percent'
                  ? 'bg-sw-accent text-white shadow-sm'
                  : 'text-sw-muted hover:text-sw-text'
              }`}
            >
              %
            </button>
          </div>

          {/* Avg mode toggle — only for multi-month ranges */}
          {isMultiMonth && (
            <button
              onClick={handleAvgModeToggle}
              className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${
                avgMode === 'total'
                  ? 'bg-sw-surface text-sw-text'
                  : 'bg-sw-info/10 text-sw-info border border-sw-info/20'
              }`}
              title={avgMode === 'total' ? 'Showing totals — click for monthly averages' : 'Showing monthly averages — click for totals'}
            >
              {avgMode === 'total' ? (
                <>
                  <BarChart3 size={12} />
                  <span>Totals</span>
                </>
              ) : (
                <>
                  <TrendingUp size={12} />
                  <span>Mo. Avg</span>
                </>
              )}
            </button>
          )}
        </div>
      </div>

      {/* Custom date range inputs */}
      {activePeriod === 'custom' && (
        <div className="flex flex-wrap items-center gap-3 mt-3 pt-3 border-t border-sw-border">
          <div className="flex items-center gap-2">
            <label className="text-xs text-sw-muted">From</label>
            <input
              type="date"
              value={customStart}
              onChange={(e) => setCustomStart(e.target.value)}
              className="px-3 py-1.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-xs focus:outline-none focus:border-sw-accent transition"
            />
          </div>
          <div className="flex items-center gap-2">
            <label className="text-xs text-sw-muted">To</label>
            <input
              type="date"
              value={customEnd}
              onChange={(e) => setCustomEnd(e.target.value)}
              className="px-3 py-1.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-xs focus:outline-none focus:border-sw-accent transition"
            />
          </div>
          <button
            onClick={handleCustomApply}
            disabled={!customStart || !customEnd}
            className="px-4 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition disabled:opacity-40"
          >
            Apply
          </button>
        </div>
      )}

      {/* Period summary */}
      {periodLabel && currentPeriod?.is_custom && (
        <div className="mt-2 text-[11px] text-sw-dim">
          Showing data for {periodLabel}
          {currentPeriod.months > 1 && ` (${currentPeriod.months} months)`}
        </div>
      )}
    </div>
  );
}
