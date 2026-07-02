/**
 * AiUsagePanel — Phase 14-10 / D17 AI Cost Discipline
 *
 * Displays per-purpose AI usage counters for admin oversight.
 * Reads from GET /api/admin/ai-usage (admin-only endpoint).
 *
 * Design: born-premium §3.11, shadow-sw-1, font-tabular for counts,
 * stagger-children for mini bar charts.
 */

import { useEffect, useState } from 'react';
import axios from 'axios';
import { Sparkles, AlertTriangle } from 'lucide-react';
import type { AiUsageResponse, AiUsagePurpose } from '@/types/spendifiai';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function purposeLabel(purpose: string): string {
  const labels: Record<string, string> = {
    narration: 'Narration',
    wording: 'Wording / Copy',
    extraction: 'Document Extraction',
    categorization: 'Transaction Categorization',
    savings_analysis: 'Savings Analysis',
    alternative_suggestions: 'Alternatives',
    statement_parsing: 'Statement Parsing',
  };
  return labels[purpose] ?? purpose.replace(/_/g, ' ');
}

// ─── Mini Sparkline Bar ───────────────────────────────────────────────────────

function SparkBars({ days, budget }: { days: AiUsagePurpose['days']; budget: number | null }) {
  if (days.length === 0) return <p className="text-[11px] text-sw-dim">No data</p>;

  const maxCount = Math.max(...days.map((d) => d.count), 1);
  const displayDays = days.slice(-7);

  return (
    <div className="flex items-end gap-0.5 h-8">
      {displayDays.map((d) => {
        const heightPct = (d.count / maxCount) * 100;
        const isOverBudget = budget !== null && d.count > budget;
        return (
          <div
            key={d.date}
            title={`${d.date}: ${d.count} call${d.count !== 1 ? 's' : ''}`}
            className={`flex-1 rounded-sm min-h-[2px] transition-all ${
              isOverBudget ? 'bg-red-400' : 'bg-sw-accent/60'
            }`}
            style={{ height: `${Math.max(heightPct, 4)}%` }}
          />
        );
      })}
    </div>
  );
}

// ─── Purpose Row ─────────────────────────────────────────────────────────────

function PurposeRow({ purpose, usage }: { purpose: string; usage: AiUsagePurpose }) {
  const todayCount = usage.days.find((d) => d.date === new Date().toISOString().slice(0, 10))?.count ?? 0;
  const totalWeek = usage.days.slice(-7).reduce((sum, d) => sum + d.count, 0);
  const isOverBudget = usage.daily_budget !== null && todayCount > usage.daily_budget;

  return (
    <div className="group rounded-xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 p-3.5 card-lift">
      <div className="flex items-start justify-between gap-3 mb-2">
        <div>
          <p className="text-[13px] font-semibold text-sw-text leading-tight">{purposeLabel(purpose)}</p>
          <p className="text-[10px] text-sw-dim mt-0.5 font-mono">{usage.model}</p>
        </div>
        <div className="text-right shrink-0">
          <p className={`text-[15px] font-[700] font-tabular leading-none ${isOverBudget ? 'text-red-600' : 'text-sw-text'}`}>
            {todayCount}
          </p>
          <p className="text-[10px] text-sw-dim">today</p>
        </div>
      </div>

      <SparkBars days={usage.days} budget={usage.daily_budget} />

      <div className="flex items-center justify-between mt-1.5">
        <p className="text-[10px] text-sw-dim">
          <span className="font-tabular font-semibold text-sw-text">{totalWeek}</span> this week
        </p>
        {usage.daily_budget !== null && (
          <p className="text-[10px] text-sw-dim">
            budget: <span className="font-tabular">{usage.daily_budget}/day</span>
          </p>
        )}
      </div>

      {isOverBudget && (
        <div className="flex items-center gap-1.5 mt-1.5 rounded-lg bg-red-50 ring-1 ring-red-200 px-2 py-1">
          <AlertTriangle size={10} className="text-red-600 shrink-0" />
          <p className="text-[10px] text-red-700 font-semibold">Over daily budget</p>
        </div>
      )}
    </div>
  );
}

// ─── Skeleton ─────────────────────────────────────────────────────────────────

function AiUsageSkeleton() {
  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 p-5 animate-pulse">
      <div className="h-5 w-32 bg-slate-200 rounded mb-4" />
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        {[1, 2, 3, 4].map((i) => (
          <div key={i} className="h-24 bg-slate-100 rounded-xl" />
        ))}
      </div>
    </div>
  );
}

// ─── Main Component ───────────────────────────────────────────────────────────

export default function AiUsagePanel() {
  const [data, setData] = useState<AiUsageResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    axios
      .get<AiUsageResponse>('/api/admin/ai-usage')
      .then((res) => {
        setData(res.data);
        setLoading(false);
      })
      .catch((err) => {
        setError(err?.response?.data?.message ?? 'Failed to load AI usage');
        setLoading(false);
      });
  }, []);

  if (loading) return <AiUsageSkeleton />;

  if (error || !data) {
    return (
      <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-1 p-5">
        <p className="text-sm text-sw-muted">{error ?? 'No AI usage data'}</p>
      </div>
    );
  }

  const purposes = Object.entries(data.usage);

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 p-5">
      {/* Header */}
      <div className="flex items-center gap-2.5 mb-4">
        <div className="w-8 h-8 rounded-xl bg-violet-50 ring-1 ring-violet-200 flex items-center justify-center">
          <Sparkles size={16} className="text-violet-600" />
        </div>
        <div>
          <h2 className="text-[15px] font-semibold text-sw-text leading-tight">AI Usage</h2>
          <p className="text-[11px] text-sw-muted">Last {data.window_days} days · Today: {data.today}</p>
        </div>
      </div>

      {purposes.length === 0 ? (
        <p className="text-sm text-sw-muted py-4 text-center">No AI calls recorded in this window.</p>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 stagger-children">
          {purposes.map(([purpose, usage]) => (
            <PurposeRow key={purpose} purpose={purpose} usage={usage} />
          ))}
        </div>
      )}
    </div>
  );
}
