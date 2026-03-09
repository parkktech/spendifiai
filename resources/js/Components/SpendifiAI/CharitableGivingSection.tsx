import { useState } from 'react';
import { Heart, ChevronDown, ChevronUp, Calendar, FileText, ExternalLink, Info } from 'lucide-react';
import { usePage } from '@inertiajs/react';
import Badge from './Badge';
import { formatDate } from '@/utils/formatDate';
import type { CharitableGiving } from '@/types/spendifiai';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

const categoryColors: Record<string, string> = {
  Religious: 'bg-purple-100 text-purple-700',
  Humanitarian: 'bg-amber-100 text-amber-700',
  Health: 'bg-rose-100 text-rose-700',
  Education: 'bg-blue-100 text-blue-700',
  Environment: 'bg-emerald-100 text-emerald-700',
  Community: 'bg-indigo-100 text-indigo-700',
  'Animal Welfare': 'bg-orange-100 text-orange-700',
};

interface CharitableGivingSectionProps {
  data: CharitableGiving;
}

export default function CharitableGivingSection({ data }: CharitableGivingSectionProps) {
  const tz = (usePage().props.auth as { timezone?: string }).timezone;
  const [showAll, setShowAll] = useState(false);
  const [showRecent, setShowRecent] = useState(false);
  const [showAllCharities, setShowAllCharities] = useState(false);

  const displayRecipients = showAll ? data.top_recipients : data.top_recipients.slice(0, 5);

  const hasNoDonations = data.transaction_count === 0 && Number(data.ytd_total) === 0;

  const CHARITY_PREVIEW_COUNT = 4;
  const allCharities = data.recommended_charities ?? [];
  const hasCharities = allCharities.length > 0;
  const displayedCharities = showAllCharities ? allCharities : allCharities.slice(0, CHARITY_PREVIEW_COUNT);
  const hasMoreCharities = allCharities.length > CHARITY_PREVIEW_COUNT;

  const charityGrid = hasCharities && (
    <div>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
        {displayedCharities.map((org) => (
          <div
            key={org.name}
            className="rounded-xl border border-sw-border bg-sw-surface/50 p-3.5 flex flex-col gap-2"
          >
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0 flex-1">
                <div className="text-[13px] font-semibold text-sw-text truncate">{org.name}</div>
                <span className={`inline-flex text-[9px] font-semibold px-1.5 py-0.5 rounded mt-1 ${categoryColors[org.category] ?? 'bg-gray-100 text-gray-600'}`}>
                  {org.category}
                </span>
              </div>
            </div>
            {org.description && (
              <p className="text-[11px] text-sw-muted leading-relaxed line-clamp-2">{org.description}</p>
            )}
            <div className="flex items-center justify-between mt-auto pt-1">
              {org.ein ? (
                <span className="text-[9px] text-sw-dim">EIN: {org.ein}</span>
              ) : <span />}
              {org.donate_url && (
                <a
                  href={org.donate_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-semibold transition"
                >
                  Donate <ExternalLink size={10} />
                </a>
              )}
            </div>
          </div>
        ))}
      </div>
      {hasMoreCharities && (
        <button
          onClick={() => setShowAllCharities(!showAllCharities)}
          className="flex items-center gap-1 mt-3 text-xs text-sw-accent hover:text-sw-accent-hover font-medium transition"
        >
          {showAllCharities ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
          {showAllCharities ? 'Show less' : `Show all ${allCharities.length} organizations`}
        </button>
      )}
    </div>
  );

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
              {hasNoDonations
                ? 'Make a difference — charitable donations are tax-deductible'
                : `${data.transaction_count} donation${data.transaction_count !== 1 ? 's' : ''} this period`}
            </p>
          </div>
        </div>
      </div>

      {/* Zero-state: no donations at all */}
      {hasNoDonations ? (
        <>
          {/* Tax info callout */}
          <div className="flex items-start gap-3 rounded-xl bg-blue-50/60 border border-blue-200/50 p-4 mb-5">
            <Info size={16} className="text-blue-600 mt-0.5 shrink-0" />
            <div>
              <div className="text-xs font-semibold text-blue-800">Tax Deduction Opportunity</div>
              <p className="text-[11px] text-blue-700 mt-0.5 leading-relaxed">
                Donations to 501(c)(3) organizations are deductible on Schedule A, reducing your taxable income.
                Keep records of all charitable contributions for tax time.
              </p>
            </div>
          </div>

          {/* Recommended charities */}
          {charityGrid && (
            <div>
              <div className="flex items-center gap-2 mb-3">
                <span className="text-xs font-semibold text-sw-text">Recommended Organizations</span>
                <span className="text-[10px] text-sw-dim">501(c)(3) verified</span>
              </div>
              {charityGrid}
            </div>
          )}
        </>
      ) : (
        <>
          {/* Stat cards */}
          <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-5">
            <div className="rounded-xl bg-emerald-50/50 border border-emerald-200/50 px-3 py-2.5">
              <div className="text-[10px] text-emerald-700 font-medium uppercase tracking-wide">This Period</div>
              <div className="text-lg font-bold text-emerald-800 mt-0.5">{fmt.format(Number(data.period_total))}</div>
            </div>
            <div className="rounded-xl bg-emerald-50/50 border border-emerald-200/50 px-3 py-2.5">
              <div className="text-[10px] text-emerald-700 font-medium uppercase tracking-wide">Year to Date</div>
              <div className="text-lg font-bold text-emerald-800 mt-0.5">{fmt.format(Number(data.ytd_total))}</div>
            </div>
            <div className="rounded-xl bg-sw-surface border border-sw-border px-3 py-2.5">
              <div className="text-[10px] text-sw-muted font-medium uppercase tracking-wide">This Period</div>
              <div className="text-lg font-bold text-sw-text mt-0.5">{data.transaction_count} <span className="text-xs font-normal text-sw-dim">donation{data.transaction_count !== 1 ? 's' : ''}</span></div>
            </div>
            <div className="rounded-xl bg-sw-surface border border-sw-border px-3 py-2.5">
              <div className="text-[10px] text-sw-muted font-medium uppercase tracking-wide">YTD Total</div>
              <div className="text-lg font-bold text-sw-text mt-0.5">{data.ytd_count} <span className="text-xs font-normal text-sw-dim">donation{data.ytd_count !== 1 ? 's' : ''}</span></div>
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
                          <span className="text-[10px] text-sw-dim">{formatDate(d.date, tz)}</span>
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

          {/* Recommended places to donate */}
          {charityGrid && (
            <div className="border-t border-sw-border pt-4 mt-4">
              <div className="flex items-center gap-2 mb-3">
                <Heart size={12} className="text-emerald-600" />
                <span className="text-xs font-semibold text-sw-text">Places to Donate</span>
                <span className="text-[10px] text-sw-dim font-normal">501(c)(3) verified</span>
              </div>
              {charityGrid}
            </div>
          )}
        </>
      )}
    </div>
  );
}
