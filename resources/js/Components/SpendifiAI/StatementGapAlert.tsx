import { useState, useMemo } from 'react';
import {
  AlertTriangle,
  X,
  CalendarX2,
  Upload,
  ChevronDown,
  ChevronUp,
  Info,
} from 'lucide-react';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { StatementGapResponse, StatementGap, StatementOverlap } from '@/types/spendifiai';

interface StatementGapAlertProps {
  onUploadStatement: (accountId?: number) => void;
}

export default function StatementGapAlert({ onUploadStatement }: StatementGapAlertProps) {
  const { data, loading, refresh } = useApi<StatementGapResponse>('/api/v1/statements/gaps');
  const { submit: dismissGap } = useApiPost('/api/v1/statements/gaps/dismiss');
  const [dismissing, setDismissing] = useState<string | null>(null);
  const [expanded, setExpanded] = useState(false);

  const gaps = data?.gaps || [];
  const overlaps = data?.overlaps || [];

  // Group gaps by account
  const gapsByAccount = useMemo(() => {
    const grouped = new Map<number, { name: string; gaps: StatementGap[] }>();
    for (const gap of gaps) {
      const existing = grouped.get(gap.account_id);
      if (existing) {
        existing.gaps.push(gap);
      } else {
        grouped.set(gap.account_id, { name: gap.account_name, gaps: [gap] });
      }
    }
    return grouped;
  }, [gaps]);

  if (loading || (gaps.length === 0 && overlaps.length === 0)) {
    return null;
  }

  const criticalGaps = gaps.filter(g => g.severity === 'critical');
  const warningGaps = gaps.filter(g => g.severity === 'warning');

  // Flatten all gaps for expand/collapse (show first 3 total)
  const allGapEntries = gaps;
  const visibleGaps = expanded ? allGapEntries : allGapEntries.slice(0, 3);
  const visibleGapKeys = new Set(visibleGaps.map(g => g.gap_key));
  const hasMore = allGapEntries.length > 3;

  const handleDismiss = async (gap: StatementGap) => {
    setDismissing(gap.gap_key);
    await dismissGap({ gap_key: gap.gap_key });
    setDismissing(null);
    refresh();
  };

  const formatOverlapDate = (dateStr: string) => {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  };

  return (
    <div className="rounded-2xl border border-amber-300/60 bg-gradient-to-br from-amber-50/80 via-orange-50/40 to-amber-50/60 p-5 mb-6">
      {/* Header */}
      <div className="flex items-start gap-3 mb-3">
        <div className="w-9 h-9 rounded-xl bg-amber-100 border border-amber-200 flex items-center justify-center shrink-0 mt-0.5">
          <CalendarX2 size={18} className="text-amber-600" />
        </div>
        <div className="flex-1 min-w-0">
          <h3 className="text-[15px] font-semibold text-sw-text tracking-tight">
            Missing Transaction Data
          </h3>
          <p className="text-xs text-sw-muted mt-0.5 leading-relaxed">
            {criticalGaps.length > 0 && (
              <span>
                <span className="font-semibold text-amber-700">
                  {criticalGaps.length} month{criticalGaps.length !== 1 ? 's' : ''}
                </span>
                {' '}with no transactions detected.{' '}
              </span>
            )}
            {warningGaps.length > 0 && (
              <span>
                {warningGaps.length} month{warningGaps.length !== 1 ? 's' : ''} with incomplete or low activity.{' '}
              </span>
            )}
            {gaps.length > 0 && <>Upload the missing statements to get complete financial analysis.</>}
            {gaps.length === 0 && overlaps.length > 0 && <>Some uploaded statements have overlapping date ranges.</>}
          </p>
        </div>
      </div>

      {/* Overlap warnings */}
      {overlaps.length > 0 && (
        <div className="mb-3 ml-12">
          <p className="text-[11px] font-semibold text-purple-600 mb-1.5 uppercase tracking-wide">
            Statement Overlaps
          </p>
          <div className="space-y-2">
            {overlaps.map((overlap: StatementOverlap, i: number) => (
              <div
                key={i}
                className="flex items-center gap-3 px-3.5 py-2.5 rounded-xl border bg-white/60 border-purple-100/80"
              >
                <Info size={14} className="text-purple-500 shrink-0" />
                <div className="flex-1 min-w-0">
                  <span className="text-[13px] font-semibold text-sw-text">
                    {overlap.account_name}
                  </span>
                  <span className="text-[11px] text-sw-muted ml-2">
                    {overlap.statements[0].file_name} and {overlap.statements[1].file_name} overlap{' '}
                    {formatOverlapDate(overlap.overlap_range.from)} – {formatOverlapDate(overlap.overlap_range.to)}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Gaps grouped by account */}
      {gaps.length > 0 && (
        <div className="space-y-3 ml-12">
          {Array.from(gapsByAccount.entries()).map(([accountId, { name, gaps: accountGaps }]) => {
            // Filter to only visible gaps for this account
            const visibleAccountGaps = accountGaps.filter(g => visibleGapKeys.has(g.gap_key));
            if (visibleAccountGaps.length === 0) return null;

            return (
              <div key={accountId}>
                {/* Account header (only show when multiple accounts) */}
                {gapsByAccount.size > 1 && (
                  <p className="text-[11px] font-semibold text-sw-text-secondary mb-1.5">
                    {name}
                    <span className="text-sw-dim font-normal ml-1.5">
                      {accountGaps.length} gap{accountGaps.length !== 1 ? 's' : ''}
                    </span>
                  </p>
                )}

                <div className="space-y-2">
                  {visibleAccountGaps.map((gap) => (
                    <div
                      key={gap.gap_key}
                      className={`flex items-center gap-3 px-3.5 py-2.5 rounded-xl border transition ${
                        gap.severity === 'critical'
                          ? 'bg-white/80 border-amber-200/80'
                          : 'bg-white/60 border-amber-100/80'
                      }`}
                    >
                      <AlertTriangle
                        size={14}
                        className={gap.severity === 'critical' ? 'text-amber-500 shrink-0' : 'text-amber-400 shrink-0'}
                      />

                      <div className="flex-1 min-w-0">
                        {gapsByAccount.size <= 1 && (
                          <span className="text-[13px] font-semibold text-sw-text">
                            {gap.month_label}
                          </span>
                        )}
                        {gapsByAccount.size > 1 && (
                          <span className="text-[13px] font-semibold text-sw-text">
                            {gap.month_label}
                          </span>
                        )}
                        <span className="text-[11px] text-sw-muted ml-2">
                          {gap.reason}
                        </span>
                      </div>

                      <button
                        onClick={() => onUploadStatement(gap.account_id)}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-100 text-amber-700 text-[11px] font-semibold hover:bg-amber-200 transition shrink-0"
                      >
                        <Upload size={11} />
                        Upload
                      </button>

                      <button
                        onClick={() => handleDismiss(gap)}
                        disabled={dismissing === gap.gap_key}
                        className="p-1.5 rounded-lg text-sw-dim hover:text-sw-text hover:bg-amber-100/60 transition shrink-0 disabled:opacity-50"
                        title="Dismiss — this data is correct"
                        aria-label={`Dismiss gap for ${gap.month_label}`}
                      >
                        <X size={13} />
                      </button>
                    </div>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Show more / less toggle */}
      {hasMore && (
        <button
          onClick={() => setExpanded(!expanded)}
          className="flex items-center gap-1.5 ml-12 mt-2 text-[11px] font-medium text-amber-600 hover:text-amber-800 transition"
        >
          {expanded ? (
            <>
              <ChevronUp size={12} />
              Show fewer
            </>
          ) : (
            <>
              <ChevronDown size={12} />
              Show {allGapEntries.length - 3} more
            </>
          )}
        </button>
      )}
    </div>
  );
}
