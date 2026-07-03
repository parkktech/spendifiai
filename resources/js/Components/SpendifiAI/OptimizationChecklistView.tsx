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

import { useState, useCallback } from 'react';
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
  Sparkles,
  ArrowRight,
  ListChecks,
  Check,
} from 'lucide-react';
import type { ChecklistGatedFact } from '@/types/spendifiai';
import Badge from './Badge';
import { useApi } from '@/hooks/useApi';
import type { OptimizationChecklistResponse, OptimizationChecklistItemView, ChecklistBenefitParams, ScenariosResponse } from '@/types/spendifiai';

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

/**
 * Exact imperative instruction with concrete numbers for each knob (owner mandate).
 * Returns null when key parameters are missing (falls back to generic title).
 */
function knobInstruction(knob: string, params: ChecklistBenefitParams | null): string | null {
  if (!params) return null;
  switch (knob) {
    case 'k2': {
      const roth = params.roth_pct ?? 0;
      const trad = params.trad_pct ?? 100;
      const fromRoth = params.from_roth_pct;
      if (fromRoth !== undefined && fromRoth !== null) {
        if (roth === 0) {
          return `Tell HR or your payroll portal: change your 401(k) contributions to 100% Traditional, 0% Roth (currently ${fromRoth}% Roth).`;
        }
        if (trad === 0) {
          return `Tell HR or your payroll portal: change your 401(k) contributions to 100% Roth, 0% Traditional (currently ${fromRoth}% Roth).`;
        }
        return `Tell HR or your payroll portal: change your 401(k) to ${trad}% Traditional / ${roth}% Roth (currently ${fromRoth}% Roth).`;
      }
      return `Tell HR or your payroll portal: change your 401(k) to ${trad}% Traditional / ${roth}% Roth.`;
    }
    case 'k3': {
      const to = params.pct;
      const from = params.from_pct;
      const rothShare = params.roth_share_pct;
      if (!to) return null;
      const fromStr = (from !== undefined && from !== null) ? ` from ${from}%` : '';
      const rothStr = (rothShare !== undefined && rothShare !== null && rothShare > 0)
        ? `, with ${rothShare}% designated as Roth`
        : '';
      return `Tell HR or your payroll portal: change your 401(k) deferral${fromStr} to ${to}%${rothStr}.`;
    }
    case 'k4': {
      const amt = params.amount;
      if (!amt || amt <= 0) return null;
      return `Elect $${Math.round(Number(amt) / 100).toLocaleString('en-US')}/yr in HSA contributions through your benefits portal or payroll.`;
    }
    case 'k5': {
      const n = params.n ?? 26;
      const perPeriod = params.amount;
      if (!perPeriod || perPeriod <= 0) return null;
      const perPeriodDollars = Math.round(Number(perPeriod) / 100);
      return `Set up an automatic $${perPeriodDollars.toLocaleString('en-US')}/${n === 12 ? 'month' : n === 26 ? 'biweekly' : n === 24 ? 'semi-monthly' : 'period'} transfer to an IRA (Fidelity, Vanguard, or Schwab — takes ~10 min to open).`;
    }
    case 'k6': {
      const amt = params.amount;
      const label = params.period_label ?? 'period';
      if (!amt || amt <= 0) return null;
      const dollars = Math.round(Number(amt) / 100);
      return `Set up an automatic $${dollars.toLocaleString('en-US')}/${label} transfer to your savings account.`;
    }
    default:
      return null;
  }
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

/**
 * Morning polish Item 3: Inline confirm button for a single gated fact.
 * POSTs to /api/v1/optimizer/facts/{fact_id}/supersede to confirm the value.
 * On success, calls onConfirmed() so the parent can refetch and re-render.
 */
function GatedFactConfirmRow({
  fact,
  onConfirmed,
}: {
  fact: ChecklistGatedFact;
  onConfirmed: () => void;
}) {
  const [confirming, setConfirming] = useState(false);
  const [confirmed, setConfirmed] = useState(false);

  const handleConfirm = useCallback(async () => {
    if (!fact.fact_id || confirming || confirmed) return;
    setConfirming(true);
    try {
      // Use the supersede endpoint: POST the existing value as a user_edit confirmation
      await axios.post(`/api/v1/optimizer/facts/${fact.fact_id}/supersede`, {
        answer: fact.display_value ?? '0',
      });
      setConfirmed(true);
      onConfirmed();
    } catch {
      // Silently log — user can retry
    } finally {
      setConfirming(false);
    }
  }, [fact, confirming, confirmed, onConfirmed]);

  return (
    <div className="flex items-center gap-2 mt-1.5 flex-wrap">
      <span className="text-[11px] text-amber-700">
        <span className="font-medium">{fact.label}</span>
        {fact.display_value && (
          <> <span className="font-semibold">{fact.display_value}</span></>
        )}
      </span>
      {fact.fact_id && (
        <button
          onClick={handleConfirm}
          disabled={confirming || confirmed}
          className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-amber-100 border border-amber-300 text-amber-800 text-[10px] font-semibold hover:bg-amber-200 transition disabled:opacity-50"
        >
          {confirmed ? (
            <><Check size={9} /> Confirmed</>
          ) : confirming ? (
            <Loader2 size={9} className="animate-spin" />
          ) : (
            'Confirm'
          )}
        </button>
      )}
    </div>
  );
}

function ChecklistCard({
  item,
  onToggle,
  optimisticDone,
  onGatedFactConfirmed,
}: {
  item: OptimizationChecklistItemView;
  onToggle: (id: number, done: boolean) => void;
  optimisticDone: boolean;
  onGatedFactConfirmed?: () => void;
}) {
  const isDirective = item.kind === 'directive';
  const benefitLine = buildBenefitLine(item.knob, item.benefit_line_params);
  const title = knobTitle(item.knob);
  const instruction = isDirective ? knobInstruction(item.knob, item.benefit_line_params) : null;
  const isDone = optimisticDone || item.done;
  const gatedFacts = item.gated_facts ?? [];

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
        {/* Exact instruction with concrete numbers (owner mandate) */}
        {instruction && !isDone && (
          <p className="text-[12px] text-sw-text-secondary leading-relaxed mt-1.5">
            {instruction}
          </p>
        )}
        {/* Benefit amount — §3.11 born-premium: text-[22px] font-[800] */}
        {benefitLine && !isDone && (
          <p className="text-[22px] font-[800] text-sw-success font-tabular leading-none tracking-[-0.03em] mt-1">
            {benefitLine}
          </p>
        )}

        {/* Morning polish Item 3: confirm-ask framing — named blockers with inline confirm */}
        {!isDirective && !isDone && (
          <div className="mt-1">
            {gatedFacts.length > 0 ? (
              /* Named blockers: "Activate by confirming: [label] [value] [Confirm]" */
              <div className="space-y-0.5">
                <p className="text-[10px] font-semibold uppercase tracking-wider text-amber-700/70 flex items-center gap-1">
                  <AlertCircle size={9} className="shrink-0" />
                  Activate by confirming:
                </p>
                {gatedFacts.map((fact) => (
                  <GatedFactConfirmRow
                    key={fact.fact_key}
                    fact={fact}
                    onConfirmed={onGatedFactConfirmed ?? (() => {})}
                  />
                ))}
              </div>
            ) : (
              /* Fallback when gated_facts is empty (all anchors unresolvable) */
              <p className="text-[11px] text-amber-700 flex items-center gap-1">
                <AlertCircle size={10} className="shrink-0" />
                Confirm your facts in the interview to activate this step
              </p>
            )}
          </div>
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
  /** Scenarios data passed from parent to power the inline choose CTA on the empty state. */
  scenariosData?: ScenariosResponse | null;
  /** Callback to choose a scenario (fires POST /choose and advances to checklist). */
  onChoose?: (optionKey: string) => Promise<void>;
  /** Callback to navigate back to the Choices stage when no options are computed. */
  onNavigateToChoices?: () => void;
}

export default function OptimizationChecklistView({
  taxYear,
  hasBankConnected,
  scenariosData,
  onChoose,
  onNavigateToChoices,
}: Props) {
  const { data, loading, error, refresh } = useApi<OptimizationChecklistResponse>(
    `/api/v1/optimizer/checklist/${taxYear}`,
    { enabled: hasBankConnected },
  );

  // Optimistic done states
  const [optimisticDone, setOptimisticDone] = useState<Map<number, boolean>>(new Map());
  // Inline choose loading — prevents double-click 429s on the empty-state option cards
  const [inlineChoosingKey, setInlineChoosingKey] = useState<string | null>(null);

  const handleInlineChoose = async (optionKey: string) => {
    if (inlineChoosingKey !== null) return; // idempotency guard
    setInlineChoosingKey(optionKey);
    try {
      await onChoose?.(optionKey);
    } finally {
      setInlineChoosingKey(null);
    }
  };

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
    // Case 1: user chose a scenario and it produced zero items → already optimal
    if (data.already_optimal) {
      return (
        <div className="rounded-2xl ring-1 ring-sw-success/30 bg-gradient-to-b from-sw-success/5 to-white shadow-sw-1 p-6 text-center">
          <div className="w-12 h-12 mx-auto rounded-2xl bg-sw-success/10 ring-1 ring-sw-success/30 flex items-center justify-center mb-3">
            <Sparkles size={22} className="text-sw-success" />
          </div>
          <h3 className="text-[14px] font-semibold text-sw-text mb-1">
            You&apos;re already optimized
          </h3>
          <p className="text-xs text-sw-muted max-w-xs mx-auto leading-relaxed">
            Your current settings match the chosen plan — no payroll changes needed.
            We&apos;ll continue monitoring and surface any new opportunities here.
          </p>
        </div>
      );
    }

    // Case 2: scenario options computed but none chosen → inline compact choose cards
    const options = scenariosData?.options ?? [];
    if (options.length > 0 && onChoose) {
      return (
        <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-1 p-5">
          <div className="flex items-center gap-2 mb-4">
            <ListChecks size={15} className="text-sw-accent" />
            <h3 className="text-[13px] font-semibold text-sw-text">
              Choose a plan to build your checklist
            </h3>
          </div>
          <div className="space-y-2">
            {options.map((opt) => {
              const isChoosing = inlineChoosingKey === opt.key;
              const isDisabled = inlineChoosingKey !== null;
              return (
                <button
                  key={opt.key}
                  onClick={() => handleInlineChoose(opt.key)}
                  disabled={isDisabled}
                  className="w-full text-left rounded-xl ring-1 ring-sw-border/70 bg-white hover:ring-sw-accent/40 hover:bg-sw-accent-light/30 transition-all p-3.5 group disabled:opacity-60 disabled:cursor-not-allowed"
                >
                  <div className="flex items-center justify-between">
                    <span className="text-[13px] font-medium text-sw-text group-hover:text-sw-accent transition">
                      {opt.label}
                    </span>
                    <span className="inline-flex items-center gap-1 text-[11px] font-medium text-sw-accent">
                      {isChoosing
                        ? <Loader2 size={11} className="animate-spin" />
                        : <><span className="opacity-0 group-hover:opacity-100 transition">Choose</span> <ArrowRight size={11} className="opacity-0 group-hover:opacity-100 transition" /></>
                      }
                    </span>
                  </div>
                  <p className="text-[11px] text-sw-muted mt-0.5">
                    {opt.outcome.take_home === 'positive_large' || opt.outcome.take_home === 'positive_medium'
                      ? 'Take-home increase expected'
                      : opt.outcome.take_home === 'positive_small'
                        ? 'Modest take-home gain expected'
                        : opt.outcome.retirement === 'positive_large' || opt.outcome.retirement === 'positive_medium'
                          ? 'Retirement boost expected'
                          : 'Tailored for this objective'}
                  </p>
                </button>
              );
            })}
          </div>
        </div>
      );
    }

    // Case 3: no options computed yet → link to Choices stage
    return (
      <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-1 p-6 text-center">
        <div className="w-11 h-11 mx-auto rounded-2xl bg-sw-accent/10 ring-1 ring-sw-accent/20 flex items-center justify-center mb-3">
          <ListChecks size={20} className="text-sw-accent" />
        </div>
        <h3 className="text-[14px] font-semibold text-sw-text mb-1">No plan selected yet</h3>
        <p className="text-xs text-sw-muted max-w-xs mx-auto leading-relaxed mb-4">
          Complete the interview and choose a scenario to generate your personalized checklist.
        </p>
        {onNavigateToChoices && (
          <button
            onClick={onNavigateToChoices}
            className="inline-flex items-center gap-1.5 text-xs font-medium text-sw-accent hover:text-sw-accent-hover transition"
          >
            Go to Choices <ArrowRight size={12} />
          </button>
        )}
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
            onGatedFactConfirmed={refresh} // Item 3: refetch on inline confirm
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
