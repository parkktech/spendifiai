import { useState } from 'react';
import { Heart, ChevronDown, ChevronUp, Calendar, FileText } from 'lucide-react';
import Badge from './Badge';
import type { CharitableGiving } from '@/types/spendifiai';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

function formatDate(dateStr: string): string {
  return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(
    new Date(dateStr + 'T00:00:00')
  );
}

interface CharitableGivingSectionProps {
  data: CharitableGiving;
}

export default function CharitableGivingSection({ data }: CharitableGivingSectionProps) {
  const [showAll, setShowAll] = useState(false);
  const [showRecent, setShowRecent] = useState(false);

  const displayRecipients = showAll ? data.top_recipients : data.top_recipients.slice(0, 5);

  if (data.transaction_count === 0) return null;

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
      {/* Header */}
      <div className="flex items-start justify-between mb-5">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-emerald-50 border border-emerald-200 flex items-center justify-center">
            <Heart size={20} className="text-emerald-600" />
          </div>
          <div>
            <h2 className="text-[15px] font-semibold text-sw-text">Charitable Giving</h2>
            <p className="text-xs text-sw-muted mt-0.5">
              {data.transaction_count} donation{data.transaction_count !== 1 ? 's' : ''} this period
            </p>
          </div>
        </div>
      </div>

      {/* Stat cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        <div className="rounded-xl bg-emerald-50/50 border border-emerald-200/50 px-3 py-2.5">
          <div className="text-[10px] text-emerald-700 font-medium uppercase tracking-wide">This Period</div>
          <div className="text-lg font-bold text-emerald-800 mt-0.5">{fmt.format(Number(data.period_total))}</div>
        </div>
        <div className="rounded-xl bg-emerald-50/50 border border-emerald-200/50 px-3 py-2.5">
          <div className="text-[10px] text-emerald-700 font-medium uppercase tracking-wide">Year to Date</div>
          <div className="text-lg font-bold text-emerald-800 mt-0.5">{fmt.format(Number(data.ytd_total))}</div>
        </div>
        <div className="rounded-xl bg-sw-surface border border-sw-border px-3 py-2.5">
          <div className="text-[10px] text-sw-muted font-medium uppercase tracking-wide">Donations</div>
          <div className="text-lg font-bold text-sw-text mt-0.5">{data.transaction_count}</div>
        </div>
        <div className="rounded-xl bg-blue-50/50 border border-blue-200/50 px-3 py-2.5">
          <div className="text-[10px] text-blue-700 font-medium uppercase tracking-wide">Est. Tax Savings</div>
          <div className="text-lg font-bold text-blue-800 mt-0.5">{fmt.format(Number(data.estimated_tax_savings))}</div>
          <div className="text-[9px] text-blue-600 mt-0.5">Schedule A deduction</div>
        </div>
      </div>

      {/* Top Recipients */}
      {data.top_recipients.length > 0 && (
        <div className="mb-4">
          <div className="flex items-center gap-2 mb-3">
            <span className="text-xs font-semibold text-sw-text">Top Recipients</span>
            <span className="text-[10px] text-sw-dim">by total donated</span>
          </div>
          <div className="space-y-1">
            {displayRecipients.map((r) => (
              <div
                key={r.recipient}
                className="flex items-center gap-3 py-2.5 px-3 rounded-xl hover:bg-sw-card-hover transition"
              >
                <div className="w-8 h-8 rounded-lg bg-emerald-50 border border-emerald-200 flex items-center justify-center shrink-0">
                  <Heart size={14} className="text-emerald-600" />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="text-[13px] font-medium text-sw-text truncate">{r.recipient}</div>
                  <div className="flex items-center gap-2 mt-0.5">
                    <span className="text-[11px] text-sw-dim">
                      {r.count} donation{r.count !== 1 ? 's' : ''}
                    </span>
                    {r.note && (
                      <>
                        <span className="text-sw-dim text-[11px]">·</span>
                        <span className="text-[11px] text-sw-muted truncate">{r.note}</span>
                      </>
                    )}
                  </div>
                </div>
                <div className="text-right shrink-0">
                  <div className="text-sm font-bold text-emerald-700">{fmt.format(Number(r.total))}</div>
                  <Badge variant="success">Tax Deductible</Badge>
                </div>
              </div>
            ))}
          </div>

          {data.top_recipients.length > 5 && (
            <button
              onClick={() => setShowAll(!showAll)}
              className="flex items-center gap-1 mt-2 px-3 py-1.5 text-xs text-sw-accent hover:text-sw-accent-hover transition"
            >
              {showAll ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
              {showAll ? 'Show less' : `Show all ${data.top_recipients.length} recipients`}
            </button>
          )}
        </div>
      )}

      {/* Recent Donations (collapsible) */}
      {data.recent_donations.length > 0 && (
        <div className="border-t border-sw-border pt-4">
          <button
            onClick={() => setShowRecent(!showRecent)}
            className="flex items-center gap-2 text-xs font-semibold text-sw-text hover:text-sw-accent transition w-full text-left"
          >
            <Calendar size={12} className="text-sw-muted" />
            Recent Donations
            <span className="text-[10px] text-sw-dim font-normal ml-1">({data.recent_donations.length})</span>
            <div className="ml-auto text-sw-dim">
              {showRecent ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
            </div>
          </button>

          {showRecent && (
            <div className="mt-3 space-y-1">
              {data.recent_donations.map((d) => (
                <div
                  key={d.id}
                  className="flex items-center gap-3 py-2 px-3 rounded-lg bg-sw-surface/50"
                >
                  <div className="flex-1 min-w-0">
                    <div className="text-[12px] font-medium text-sw-text truncate">{d.merchant}</div>
                    <div className="flex items-center gap-2 mt-0.5">
                      <span className="text-[10px] text-sw-dim">{formatDate(d.date)}</span>
                      {d.note && (
                        <>
                          <span className="text-sw-dim text-[10px]">·</span>
                          <span className="text-[10px] text-sw-muted flex items-center gap-1 truncate">
                            <FileText size={8} className="shrink-0" />
                            {d.note}
                          </span>
                        </>
                      )}
                    </div>
                  </div>
                  <div className="text-sm font-semibold text-sw-text shrink-0">
                    {fmt.format(Number(d.amount))}
                  </div>
                  {d.tax_deductible && <Badge variant="success">Tax</Badge>}
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
