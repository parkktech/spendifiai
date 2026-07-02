/**
 * OptimizationChecklistView — Phase 14-10 / D.5 Scenarios stage
 *
 * Renders the materialized optimization checklist from
 * GET /api/v1/optimizer/checklist/{year}.
 *
 * D9.2 fact-gate:
 *   - 'directive' items: rendered as actionable steps with benefit lines
 *   - 'confirm_ask' items: rendered as confirmation asks — "confirm this first"
 *
 * Header aggregate: displays the sum take-home / tax / retirement deltas
 * from the header row's benefit_line_params.header_aggregate (integer cents → dollars).
 *
 * Benefit lines: per-knob summary figure from benefit_line_params (cents → dollars).
 * All monetary figures use font-tabular and are labeled "estimate".
 *
 * Illustration badge (§3.11): persistent sw-info Badge on k2's FV range — never plain text.
 *
 * Design: born-premium §3.11 action-card recipe; stagger-children; shadow-sw-1.
 * Educational framing: "may", "could", "consider" — no assertive language.
 */

import { useState } from 'react';
import axios from 'axios';
import {
  CheckCircle2,
  Circle,
  AlertCircle,
  Info,
  TrendingUp,
  ShieldMinus,
  PiggyBank,
  RefreshCw,
  Loader2,
} from 'lucide-react';
import Badge from './Badge';
import { useApi } from '@/hooks/useApi';
import type { OptimizationChecklistResponse, OptimizationChecklistItemView, ChecklistBenefitParams } from '@/types/spendifiai';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fmtCents(cents: number | undefined, sign = true): string {
  if (!cents || cents === 0) return '—';
  const abs = Math.abs(cents) / 100;
  const prefix = sign ? (cents > 0 ? '+' : '−') : '';
  if (abs >= 1000) return `${prefix}$${(abs / 1000).toFixed(1)}k`;
  return `${prefix}$${abs.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
}

/** Per-knob benefit summary line. */
function buildBenefitLine(knob: string, params: ChecklistBenefitParams | null): string | null {
  if (!params) return null;
  switch (knob) {
    case 'k1': {
      const pp = params.per_paycheck;
      const ann = params.annual;
      if (pp && pp !== 0) return `${fmtCents(pp)}/paycheck est.`;
      if (ann && ann !== 0) return `${fmtCents(ann)}/yr take-home est.`;
      return null;
    }
    case 'k2': {
      const dt = params.delta_tax;
      if (dt && dt !== 0) {
        const savings = -dt;
        if (savings > 0) return `~${fmtCents(savings, false)}/yr tax savings est.`;
      }
      return null;
    }
    case 'k3': {
      const m = params.match;
      const da = params.delta_annual;
      if (m && m > 0) return `+${fmtCents(m, false)}/yr employer match`;
      if (da && da !== 0) return `${fmtCents(da)}/yr take-home est.`;
      return null;
    }
    case 'k4': {
      const dp = params.delta_paycheck;
      if (dp && dp !== 0) return `${fmtCents(dp)}/paycheck est.`;
      return null;
    }
    case 'k5': {
      const dd = params.delta_deduction;
      if (dd && dd > 0) return `~${fmtCents(dd, false)}/yr deduction est.`;
      return null;
    }
    case 'k6': {
      const amt = params.amount;
      const label = params.period_label;
      if (amt && amt > 0) return `+${fmtCents(amt, false)}${label ? ' ' + label : '/period'} auto-transfer`;
      return null;
    }
    default:
      return null;
  }
}

/** Per-knob description for directive step. */
function knobTitle(knob: string): string {
  const titles: Record<string, string> = {
    k1: 'Update your W-4 withholding',
    k2: 'Adjust your Roth / Traditional mix',
    k3: 'Increase your 401(k) deferral',
    k4: 'Elect HSA contributions',
    k5: 'Open or contribute to an IRA',
    k6: 'Set up an automatic transfer',
  };
  return titles[knob] ?? knob;
}

function knobIcon(knob: string): React.ReactNode {
  switch (knob) {
    case 'k1': return <TrendingUp size={14} className="text-sw-accent" />;
    case 'k2': return <ShieldMinus size={14} className="text-violet-600" />;
    case 'k3': return <PiggyBank size={14} className="text-blue-600" />;
    case 'k4': return <ShieldMinus size={14} className="text-emerald-600" />;
    case 'k5': return <PiggyBank size={14} className="text-amber-600" />;
    case 'k6': return <TrendingUp size={14} className="text-sw-success" />;
    default: return <Circle size={14} className="text-sw-muted" />;
  }
}

// ─── Header Aggregate Banner ──────────────────────────────────────────────────

function HeaderAggregateBanner({ params }: { params: ChecklistBenefitParams | null }) {
  if (!params?.header_aggregate) return null;
  const agg = params.header_aggregate;
  const takeHome = agg.take_home_annual_delta_cents;
  const taxSavings = -(agg.federal_tax_annual_delta_cents); // negative = savings
  const retirement = agg.retirement_contributions_delta_cents;

  return (
    <div className="rounded-2xl ring-1 ring-sw-accent/30 bg-sw-accent/5 p-4 mb-4">
      <p className="text-[11px] font-semibold uppercase tracking-widest text-sw-accent mb-2">
        Your optimization plan — estimated annual impact
      </p>
      <div className="grid grid-cols-3 gap-3">
        <div className="text-center">
          <p className={`text-[22px] font-[800] font-tabular tracking-[-0.03em] leading-none ${takeHome > 0 ? 'text-sw-success' : takeHome < 0 ? 'text-sw-danger' : 'text-sw-muted'}`}>
            {fmtCents(takeHome)}
          </p>
          <p className="text-[10px] text-sw-muted mt-0.5">take-home</p>
        </div>
        <div className="text-center">
          <p className={`text-[22px] font-[800] font-tabular tracking-[-0.03em] leading-none ${taxSavings > 0 ? 'text-sw-success' : taxSavings < 0 ? 'text-sw-danger' : 'text-sw-muted'}`}>
            {taxSavings !== 0 ? `${taxSavings > 0 ? '+' : '−'}${fmtCents(Math.abs(taxSavings), false)}` : '—'}
          </p>
          <p className="text-[10px] text-sw-muted mt-0.5">tax savings</p>
        </div>
        <div className="text-center">
          <p className={`text-[22px] font-[800] font-tabular tracking-[-0.03em] leading-none ${retirement > 0 ? 'text-sw-success' : retirement < 0 ? 'text-sw-danger' : 'text-sw-muted'}`}>
            {fmtCents(retirement)}
          </p>
          <p className="text-[10px] text-sw-muted mt-0.5">retirement</p>
        </div>
      </div>
      <p className="text-[10px] text-sw-dim mt-2 text-center">
        Estimates only. Results could differ. Consider reviewing with a tax professional.
      </p>
    </div>
  );
}

// ─── Checklist Item Card ──────────────────────────────────────────────────────

function ChecklistCard({
  item,
  onToggle,
  optimisticDone,
}: {
  item: OptimizationChecklistItemView;
  onToggle: (id: number, done: boolean) => void;
  optimisticDone: boolean;
}) {
  const isDirective = item.kind === 'directive';
  const benefitLine = buildBenefitLine(item.knob, item.benefit_line_params);
  const title = knobTitle(item.knob);
  const isDone = optimisticDone || item.done;

  // k2 with FV range → illustration badge
  const hasIllustration = item.knob === 'k2' && item.benefit_line_params?.fv_low && item.benefit_line_params?.fv_high;
  const fvLow = item.benefit_line_params?.fv_low;
  const fvHigh = item.benefit_line_params?.fv_high;
  const retirementAge = item.benefit_line_params?.age;

  return (
    <div
      className={`group flex items-start gap-3 rounded-xl ring-1 p-3.5 transition-all ${
        isDone
          ? 'ring-sw-success/30 bg-sw-success/5'
          : isDirective
            ? 'ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 card-lift'
            : 'ring-amber-200 bg-amber-50/50 shadow-sw-1'
      }`}
    >
      {/* Done toggle */}
      <button
        onClick={() => onToggle(item.id, !isDone)}
        className={`mt-0.5 w-5 h-5 flex items-center justify-center rounded-full ring-1 transition-all shrink-0 ${
          isDone
            ? 'ring-sw-success bg-sw-success'
            : 'ring-sw-border hover:ring-sw-success hover:bg-sw-success/10'
        }`}
        aria-label={`${isDone ? 'Unmark' : 'Mark'} "${title}" ${isDone ? 'incomplete' : 'complete'}`}
      >
        {isDone ? (
          <CheckCircle2 size={12} className="text-white" />
        ) : (
          <Circle size={11} className="text-sw-dim group-hover:text-sw-success transition-colors" />
        )}
      </button>

      <div className="flex-1 min-w-0">
        {/* Icon + title */}
        <div className="flex items-center gap-1.5 min-w-0 mb-0.5">
          <div className="w-5 h-5 rounded-md bg-slate-50 ring-1 ring-sw-border/60 flex items-center justify-center shrink-0">
            {knobIcon(item.knob)}
          </div>
          <p className={`text-[13px] font-semibold leading-tight ${isDone ? 'text-sw-muted line-through' : 'text-sw-text'}`}>
            {title}
          </p>
        </div>
        {/* Benefit amount — §3.11 born-premium: text-[22px] font-[800] */}
        {benefitLine && !isDone && (
          <p className="text-[22px] font-[800] text-sw-success font-tabular leading-none tracking-[-0.03em] mt-1">
            {benefitLine}
          </p>
        )}

        {/* Confirm-ask framing */}
        {!isDirective && !isDone && (
          <p className="text-[11px] text-amber-700 mt-1 flex items-center gap-1">
            <AlertCircle size={10} className="shrink-0" />
            Confirm your facts in the interview to activate this step
          </p>
        )}

        {/* k2 illustration badge (long-horizon FV range) */}
        {hasIllustration && !isDone && fvLow && fvHigh && (
          <div className="mt-1.5">
            <Badge variant="info">
              <Info size={9} className="inline mr-0.5" />
              Illustration: {fmtCents(fvLow, false)}–{fmtCents(fvHigh, false)} projected at age {retirementAge ?? 65}
            </Badge>
          </div>
        )}
      </div>
    </div>
  );
}

// ─── Skeleton ─────────────────────────────────────────────────────────────────

function ChecklistSkeleton() {
  return (
    <div className="space-y-2 animate-pulse">
      {[1, 2, 3].map((i) => (
        <div key={i} className="h-14 rounded-xl bg-slate-100 ring-1 ring-sw-border/40" />
      ))}
    </div>
  );
}

// ─── Main Component ───────────────────────────────────────────────────────────

interface Props {
  taxYear: number;
  hasBankConnected: boolean;
}

export default function OptimizationChecklistView({ taxYear, hasBankConnected }: Props) {
  const { data, loading, error, refresh } = useApi<OptimizationChecklistResponse>(
    `/api/v1/optimizer/checklist/${taxYear}`,
    { enabled: hasBankConnected },
  );

  // Optimistic done states
  const [optimisticDone, setOptimisticDone] = useState<Map<number, boolean>>(new Map());

  const handleToggle = async (id: number, done: boolean) => {
    setOptimisticDone((prev) => new Map(prev).set(id, done));
    try {
      await axios.patch(`/api/v1/optimizer/checklist/items/${id}`, { done });
      refresh();
    } catch {
      // Revert
      setOptimisticDone((prev) => {
        const next = new Map(prev);
        next.delete(id);
        return next;
      });
    }
  };

  if (!hasBankConnected) return null;
  if (loading) return <ChecklistSkeleton />;

  if (error || !data) {
    return (
      <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-1 p-5 text-center">
        <p className="text-sm text-sw-muted mb-2">{error ?? 'Could not load checklist'}</p>
        <button
          onClick={refresh}
          className="inline-flex items-center gap-1.5 text-xs text-sw-accent hover:text-sw-accent-hover transition"
        >
          <RefreshCw size={12} /> Retry
        </button>
      </div>
    );
  }

  // Separate header row from action items
  const headerRow = data.items.find((item) => item.knob === 'header');
  const actionItems = data.items.filter((item) => item.knob !== 'header');

  const completedCount = actionItems.filter((item) => {
    const opt = optimisticDone.get(item.id);
    return opt !== undefined ? opt : item.done;
  }).length;
  const totalCount = actionItems.length;

  if (totalCount === 0) {
    return (
      <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-1 p-6 text-center">
        <div className="w-11 h-11 mx-auto rounded-2xl bg-sw-success/10 ring-1 ring-sw-success/30 flex items-center justify-center mb-3">
          <CheckCircle2 size={20} className="text-sw-success" />
        </div>
        <h3 className="text-[14px] font-semibold text-sw-text mb-1">No checklist yet</h3>
        <p className="text-xs text-sw-muted max-w-xs mx-auto leading-relaxed">
          Choose a scenario above to generate your personalized optimization checklist.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 p-5">
      {/* Header aggregate banner */}
      {headerRow && <HeaderAggregateBanner params={headerRow.benefit_line_params} />}

      {/* Progress */}
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-[14px] font-semibold text-sw-text">Your Action Checklist</h3>
        <span className="text-[12px] text-sw-muted font-tabular">
          {completedCount}/{totalCount} done
        </span>
      </div>

      {completedCount > 0 && (
        <div className="w-full h-1.5 rounded-full bg-sw-border mb-4 overflow-hidden">
          <div
            className="h-full rounded-full bg-sw-success transition-all duration-500"
            style={{ width: `${(completedCount / totalCount) * 100}%` }}
          />
        </div>
      )}

      {/* Action items */}
      <div className="space-y-2 stagger-children">
        {actionItems.map((item) => (
          <ChecklistCard
            key={item.id}
            item={item}
            onToggle={handleToggle}
            optimisticDone={optimisticDone.get(item.id) ?? item.done}
          />
        ))}
      </div>

      {/* Disclaimer */}
      <div className="flex items-start gap-1.5 mt-4 pt-3 border-t border-sw-border/40">
        <Info size={10} className="text-sw-dim shrink-0 mt-0.5" />
        <p className="text-[10px] text-sw-dim leading-relaxed">
          Steps are educational estimates derived from your interview answers. Consult a qualified tax professional before making changes to withholding, retirement contributions, or other elections.
        </p>
      </div>
    </div>
  );
}
