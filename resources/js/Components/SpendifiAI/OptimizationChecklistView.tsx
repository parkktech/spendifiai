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

import { useState, useCallback, useEffect, useRef } from 'react';
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
  ArrowLeft,
  ListChecks,
  Check,
  Target,
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
      // DISPLAY LAW (Addition 7): every rendered percentage rounds to the nearest whole percent.
      const toRounded = Math.round(to);
      const fromStr = (from !== undefined && from !== null) ? ` from ${Math.round(from)}%` : '';
      const rothStr = (rothShare !== undefined && rothShare !== null && rothShare > 0)
        ? `, with ${rothShare}% designated as Roth`
        : '';
      return `Tell HR or your payroll portal: change your 401(k) deferral${fromStr} to ${toRounded}%${rothStr}.`;
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

/**
 * Formats a cents value with optional "k" abbreviation and no sign (for display values).
 * Used in the before/after tiles where the sign context is the arrow.
 */
function fmtAbs(cents: number | undefined | null): string {
  if (!cents && cents !== 0) return '—';
  const abs = Math.abs(cents) / 100;
  if (abs >= 1000) return `$${(abs / 1000).toFixed(1)}k`;
  return `$${abs.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
}

/**
 * BEFORE → AFTER banner — Change 1 + Change 4 (unit discipline) + DELTA-CONSISTENCY.
 *
 * Both "before" and "after" take-home values are MODELLED via the same estimator so
 * that the delta (perCheckDelta) is honest. If the user's actual paystub take-home
 * differs materially from the model, a one-line context note is shown.
 *
 * UNIT DISCIPLINE RULE (Change 4): every rendered dollar figure carries its own explicit
 * unit token INLINE (/check, /yr, per paycheck, per year). No caption may apply to a
 * number with a different unit; standalone unit captions are prohibited.
 * Enforced by tests/Unit/BannerUnitDisciplineTest.php (static source gate).
 *
 * Falls back gracefully to delta-only display when baseline_absolute values are absent
 * (e.g. checklist items materialized before this change).
 */
function HeaderAggregateBanner({ params }: { params: ChecklistBenefitParams | null }) {
  if (!params?.header_aggregate) return null;
  const agg = params.header_aggregate;

  // Take-home: per-paycheck BEFORE → AFTER (both modelled — DELTA-CONSISTENCY LAW)
  const baselinePP = agg.baseline_per_period_take_home_cents;
  const chosenPP = agg.chosen_per_period_take_home_cents;
  const takeHomeDelta = agg.take_home_annual_delta_cents;
  const hasTHAbsolute = baselinePP !== undefined && baselinePP !== null && chosenPP !== undefined && chosenPP !== null;
  // Per-paycheck delta, derived from the same engine absolutes (unit-matched secondary line)
  const perCheckDelta = hasTHAbsolute ? (chosenPP! - baselinePP!) : 0;
  // Observed paycheck context note
  const observedPP = agg.observed_per_period_take_home_cents;
  const modelDiffers = agg.model_differs_from_observed === true && observedPP != null;

  // Tax: annual BEFORE → AFTER
  const baselineTax = agg.baseline_federal_tax_annual_cents;
  const chosenTax = agg.chosen_federal_tax_annual_cents;
  const taxDelta = agg.federal_tax_annual_delta_cents; // negative = savings
  const hasTaxAbsolute = baselineTax !== undefined && chosenTax !== undefined;

  // Retirement: FV range BEFORE → AFTER
  const baseFv = agg.baseline_retirement_fv;
  const chsnFv = agg.chosen_retirement_fv;
  const retirementAge = agg.retirement_target_age;
  const hasFvRange = baseFv !== null && baseFv !== undefined && chsnFv !== null && chsnFv !== undefined
    && (baseFv.low_cents > 0 || chsnFv.low_cents > 0);
  // Fall back to contribution delta if FV range not available
  const retirementContribDelta = agg.retirement_contributions_delta_cents;

  return (
    <div className="rounded-2xl ring-1 ring-sw-accent/30 bg-sw-accent/5 p-4 mb-4">
      <p className="text-[11px] font-semibold uppercase tracking-widest text-sw-accent mb-3">
        Your optimization plan — estimated impact
      </p>
      <div className="grid grid-cols-3 gap-2">

        {/* Tile 1: Bring home — unit discipline: line 1 per-paycheck est., line 2 delta */}
        <div className="rounded-xl bg-white/60 ring-1 ring-sw-border/40 p-2.5 text-center">
          <p className="text-[9px] font-semibold uppercase tracking-wider text-sw-muted mb-1.5">Bring home</p>
          {hasTHAbsolute ? (
            <>
              <p className="text-[13px] font-semibold text-sw-text font-tabular leading-tight">
                <span className="font-normal text-sw-text-secondary">{fmtAbs(baselinePP)}</span>
                <span className="mx-0.5 text-sw-muted font-normal">→</span>
                {fmtAbs(chosenPP)}{' '}
                <span className="text-[9px] font-normal text-sw-muted">est. per paycheck</span>
              </p>
              <p className={`text-[12px] font-[700] font-tabular tracking-[-0.02em] leading-none mt-1.5 ${perCheckDelta > 0 ? 'text-sw-success' : perCheckDelta < 0 ? 'text-sw-danger' : 'text-sw-muted'}`}>
                {fmtCents(perCheckDelta)}/check est.
                <span className="mx-1 text-sw-dim font-normal">·</span>
                {fmtCents(takeHomeDelta)}/yr
              </p>
            </>
          ) : (
            <p className={`text-[20px] font-[800] font-tabular tracking-[-0.03em] leading-none ${takeHomeDelta > 0 ? 'text-sw-success' : takeHomeDelta < 0 ? 'text-sw-danger' : 'text-sw-muted'}`}>
              {fmtCents(takeHomeDelta)}<span className="text-[10px] font-medium">/yr</span>
            </p>
          )}
        </div>

        {/* Tile 2: Tax — unit discipline: line 1 per year, line 2 annual delta labeled */}
        <div className="rounded-xl bg-white/60 ring-1 ring-sw-border/40 p-2.5 text-center">
          <p className="text-[9px] font-semibold uppercase tracking-wider text-sw-muted mb-1.5">Est. federal tax</p>
          {hasTaxAbsolute ? (
            <>
              <p className="text-[13px] font-semibold text-sw-text font-tabular leading-tight">
                <span className="font-normal text-sw-text-secondary">{fmtAbs(baselineTax)}</span>
                <span className="mx-0.5 text-sw-muted font-normal">→</span>
                {fmtAbs(chosenTax)}{' '}
                <span className="text-[9px] font-normal text-sw-muted">per year</span>
              </p>
              <p className={`text-[12px] font-[700] font-tabular tracking-[-0.02em] leading-none mt-1.5 ${taxDelta < 0 ? 'text-sw-success' : taxDelta > 0 ? 'text-sw-danger' : 'text-sw-muted'}`}>
                {taxDelta !== 0 ? <>{taxDelta < 0 ? '−' : '+'}{fmtAbs(Math.abs(taxDelta))}/yr</> : '—'}
              </p>
            </>
          ) : (
            <p className={`text-[20px] font-[800] font-tabular tracking-[-0.03em] leading-none ${(-taxDelta) > 0 ? 'text-sw-success' : (-taxDelta) < 0 ? 'text-sw-danger' : 'text-sw-muted'}`}>
              {taxDelta !== 0 ? <>{taxDelta < 0 ? '+' : '−'}{fmtAbs(Math.abs(taxDelta))}<span className="text-[10px] font-medium">/yr</span></> : '—'}
            </p>
          )}
        </div>

        {/* Tile 3: Retirement — range + assumptions (unchanged per Change 4) */}
        <div className="rounded-xl bg-white/60 ring-1 ring-sw-border/40 p-2.5 text-center">
          <p className="text-[9px] font-semibold uppercase tracking-wider text-sw-muted mb-1.5">
            {retirementAge ? `At age ${retirementAge}` : 'Retirement'}
          </p>
          {hasFvRange ? (
            <>
              <p className="text-[9px] text-sw-text-secondary font-tabular leading-none">
                ~{fmtAbs(baseFv!.low_cents)}–{fmtAbs(baseFv!.high_cents)}{' '}
                <span className="text-sw-muted">est. range</span>
              </p>
              <p className="text-[9px] text-sw-muted mx-0.5">→</p>
              <p className="text-[11px] font-semibold text-sw-success font-tabular leading-none">
                ~{fmtAbs(chsnFv!.low_cents)}–{fmtAbs(chsnFv!.high_cents)}{' '}
                <span className="text-[9px] font-normal text-sw-muted">est. range</span>
              </p>
            </>
          ) : (
            <p className={`text-[20px] font-[800] font-tabular tracking-[-0.03em] leading-none ${retirementContribDelta > 0 ? 'text-sw-success' : retirementContribDelta < 0 ? 'text-sw-danger' : 'text-sw-muted'}`}>
              {fmtCents(retirementContribDelta)}<span className="text-[10px] font-medium">/yr</span>
            </p>
          )}
        </div>

      </div>

      {/* D9.7 illustration assumptions line — always shown when FV range is present */}
      {hasFvRange && chsnFv?.assumptions && chsnFv.assumptions.length > 0 && (
        <p className="text-[9px] text-sw-dim mt-2 text-center leading-relaxed">
          <span className="font-medium">Illustration only — not a guarantee.</span>{' '}
          {`${(chsnFv.growth_rate_low * 100).toFixed(0)}%–${(chsnFv.growth_rate_high * 100).toFixed(0)}% annual growth assumed over ${chsnFv.horizon_years} years. Actual results vary.`}
        </p>
      )}

      {/* Observed paycheck context note (DELTA-CONSISTENCY: model ≠ paystub actual) */}
      {modelDiffers && observedPP != null && (
        <p className="text-[9px] text-sw-muted mt-1.5 text-center leading-relaxed">
          <span className="font-medium">Note:</span>{' '}
          Your actual paycheck is ~{fmtAbs(observedPP)}/check. Deltas above compare like-for-like estimates — both sides use the same withholding model.
        </p>
      )}

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

  // k2 with FV range → illustration badge.
  // Use explicit >0 check to avoid falsy-render "0" when fv_low=0 (no horizon or zero delta).
  const hasIllustration = item.knob === 'k2'
    && (item.benefit_line_params?.fv_low ?? 0) > 0
    && (item.benefit_line_params?.fv_high ?? 0) > 0;
  const fvLow = item.benefit_line_params?.fv_low;
  const fvHigh = item.benefit_line_params?.fv_high;
  const retirementAge = item.benefit_line_params?.age;
  const retirementRangesEqualRothChanged = item.benefit_line_params?.retirement_ranges_equal_roth_changed === true;

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
        {/* Exact instruction with concrete numbers (owner mandate).
            Change 2: matches title font size/weight; prefixed with "TODO: " */}
        {instruction && !isDone && (
          <p className="text-[13px] font-semibold text-sw-text leading-tight mt-1">
            <span className="text-sw-accent">TODO: </span>{instruction}
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
        {/* Retirement equal-range note: same contribution amount, just different timing of tax */}
        {item.knob === 'k2' && !isDone && retirementRangesEqualRothChanged && !hasIllustration && (
          <p className="text-[10px] text-sw-muted mt-1 leading-relaxed">
            <Info size={8} className="inline mr-0.5 text-sw-dim" />
            Traditional vs Roth changes <em>when</em> you pay tax — not the projected balance.
          </p>
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
  /** Addition 8: callback to navigate back to Choices stage ("Change plan →" / Back button). */
  onBackToChoices?: () => void;
}

// ─── What-if calculator (owner request 2026-07-06) ───────────────────────────

interface SimulateResult {
  contribution: {
    annual_cents: number;
    legal_max_cents: number;
    pct_of_max: number;
    payroll_deferral_pct: number;
    per_check_cents: number;
    roth_share_pct: number;
    bonus_included: boolean;
  };
  bring_home: {
    before_per_check_cents: number;
    after_per_check_cents: number;
    delta_per_check_cents: number;
    delta_annual_cents: number;
  };
  federal_tax: {
    before_annual_cents: number;
    after_annual_cents: number;
    delta_annual_cents: number;
  };
  retirement: {
    before_fv: { low_cents: number; high_cents: number } | null;
    after_fv: { low_cents: number; high_cents: number } | null;
    target_age: number | null;
    horizon_years: number;
    employer_match_before_cents: number;
    employer_match_after_cents: number;
  };
  clamps: string[];
  disclaimer: string;
}

/**
 * ScenarioPlayground — interactive 401(k) what-if calculator.
 *
 * SEMANTICS (owner-mandated): the contribution slider is a percentage of the
 * IRS LEGAL MAXIMUM (402(g) limit incl. catch-ups) — 100% = "contribute the
 * full legally allowed amount", bonus included when the plan defers from bonus
 * checks. The server translates to a payroll percentage ("tell HR X%").
 *
 * Opens at the user's CURRENT position (empty first call returns it).
 * Debounced 350ms; pure engine math server-side — no AI calls.
 * UNIT DISCIPLINE: every dollar figure carries its inline unit.
 */
function ScenarioPlayground({ taxYear }: { taxYear: number }) {
  const [expanded, setExpanded] = useState(false);
  const [pctOfMax, setPctOfMax] = useState<number | null>(null);
  const [rothShare, setRothShare] = useState<number | null>(null);
  const [result, setResult] = useState<SimulateResult | null>(null);
  const [loading, setLoading] = useState(false);
  const [simError, setSimError] = useState(false);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const seededRef = useRef(false);

  const simulate = useCallback(async (payload: Record<string, number>) => {
    setLoading(true);
    setSimError(false);
    try {
      const res = await axios.post<SimulateResult>(
        `/api/v1/optimizer/scenarios/${taxYear}/simulate`,
        payload,
      );
      setResult(res.data);
      // First call (empty payload) seeds the controls at the current position.
      if (!seededRef.current) {
        seededRef.current = true;
        setPctOfMax(res.data.contribution.pct_of_max);
        setRothShare(res.data.contribution.roth_share_pct);
      }
    } catch {
      setSimError(true);
    } finally {
      setLoading(false);
    }
  }, [taxYear]);

  // Seed on first expand
  useEffect(() => {
    if (expanded && !seededRef.current) void simulate({});
  }, [expanded, simulate]);

  // Debounced re-simulate on control changes (after seeding)
  useEffect(() => {
    if (!seededRef.current || pctOfMax === null || rothShare === null) return;
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      void simulate({ contribution_pct_of_max: pctOfMax, roth_share_pct: rothShare });
    }, 350);
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [pctOfMax, rothShare, simulate]);

  const fmtDollars = (cents: number) => `$${(cents / 100).toLocaleString('en-US', { maximumFractionDigits: 0 })}`;
  const fmtK = (cents: number) => {
    const abs = Math.abs(cents) / 100;
    return abs >= 1000 ? `$${(abs / 1000).toFixed(1)}k` : `$${abs.toLocaleString('en-US', { maximumFractionDigits: 0 })}`;
  };

  const matchDropped = result
    && result.retirement.employer_match_after_cents < result.retirement.employer_match_before_cents;
  const capped = result?.clamps.includes('401k_annual_limit') ?? false;

  return (
    <div className="rounded-2xl ring-1 ring-sw-border bg-sw-card shadow-sw-1 mb-4 overflow-hidden">
      <button
        onClick={() => setExpanded((e) => !e)}
        className="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-sw-surface/60 transition"
      >
        <span className="flex items-center gap-2 text-[13px] font-semibold text-sw-text">
          <Sparkles size={14} className="text-sw-accent" />
          Try your own mix
        </span>
        <span className="text-[11px] text-sw-muted">
          {expanded ? 'Hide' : 'Adjust your 401(k) and watch the numbers move'}
        </span>
      </button>

      {expanded && (
        <div className="px-4 pb-4 border-t border-sw-border/60 pt-3">
          {simError && (
            <p className="text-[12px] text-sw-danger mb-2">Could not compute — try again in a moment.</p>
          )}

          {/* Control 1: contribution as % of the IRS legal max */}
          <div className="mb-3">
            <div className="flex items-center justify-between mb-1">
              <label className="text-[12px] font-medium text-sw-text-secondary">
                401(k) contribution — % of the IRS max
                {result && (
                  <span className="text-sw-muted font-normal"> ({fmtDollars(result.contribution.legal_max_cents)} limit/yr)</span>
                )}
              </label>
              <div className="flex items-center gap-1">
                <input
                  type="number"
                  min={0}
                  max={100}
                  value={pctOfMax ?? ''}
                  onChange={(e) => setPctOfMax(Math.min(100, Math.max(0, Number(e.target.value))))}
                  className="w-14 text-right text-[12px] font-tabular rounded border border-sw-border px-1 py-0.5 bg-sw-card text-sw-text"
                  aria-label="Contribution percent of IRS maximum"
                />
                <span className="text-[12px] text-sw-muted">%</span>
              </div>
            </div>
            <input
              type="range"
              min={0}
              max={100}
              step={1}
              value={pctOfMax ?? 0}
              onChange={(e) => setPctOfMax(Number(e.target.value))}
              className="w-full accent-sw-accent"
              aria-label="Contribution percent of IRS maximum slider"
            />
            {result && (
              <p className="text-[11px] text-sw-muted mt-0.5 font-tabular">
                = {fmtDollars(result.contribution.annual_cents)}/yr
                {' · '}{result.contribution.payroll_deferral_pct}% of each paycheck
                {' · '}{fmtDollars(result.contribution.per_check_cents)} per check
                {result.contribution.bonus_included ? ' · bonus checks included' : ''}
                {capped ? ' · capped at the IRS annual limit' : ''}
              </p>
            )}
          </div>

          {/* Control 2: Roth share */}
          <div className="mb-4">
            <div className="flex items-center justify-between mb-1">
              <label className="text-[12px] font-medium text-sw-text-secondary">
                Roth share <span className="text-sw-muted font-normal">(rest goes to Traditional)</span>
              </label>
              <div className="flex items-center gap-1">
                <input
                  type="number"
                  min={0}
                  max={100}
                  value={rothShare ?? ''}
                  onChange={(e) => setRothShare(Math.min(100, Math.max(0, Math.round(Number(e.target.value)))))}
                  className="w-14 text-right text-[12px] font-tabular rounded border border-sw-border px-1 py-0.5 bg-sw-card text-sw-text"
                  aria-label="Roth share percent"
                />
                <span className="text-[12px] text-sw-muted">%</span>
              </div>
            </div>
            <input
              type="range"
              min={0}
              max={100}
              step={5}
              value={rothShare ?? 0}
              onChange={(e) => setRothShare(Number(e.target.value))}
              className="w-full accent-sw-accent"
              aria-label="Roth share percent slider"
            />
          </div>

          {/* Live outcome tiles */}
          {result && (
            <div className={`grid grid-cols-3 gap-2 transition-opacity ${loading ? 'opacity-50' : 'opacity-100'}`}>
              <div className="rounded-xl bg-sw-surface ring-1 ring-sw-border/40 p-2.5 text-center">
                <p className="text-[9px] font-semibold uppercase tracking-wider text-sw-muted mb-1">Bring home</p>
                <p className="text-[13px] font-semibold text-sw-text font-tabular leading-tight">
                  {fmtK(result.bring_home.after_per_check_cents)}{' '}
                  <span className="text-[9px] font-normal text-sw-muted">est. per paycheck</span>
                </p>
                <p className={`text-[11px] font-[700] font-tabular mt-1 ${result.bring_home.delta_per_check_cents > 0 ? 'text-sw-success' : result.bring_home.delta_per_check_cents < 0 ? 'text-sw-danger' : 'text-sw-muted'}`}>
                  {fmtCents(result.bring_home.delta_per_check_cents)}/check est.
                </p>
              </div>
              <div className="rounded-xl bg-sw-surface ring-1 ring-sw-border/40 p-2.5 text-center">
                <p className="text-[9px] font-semibold uppercase tracking-wider text-sw-muted mb-1">Est. federal tax</p>
                <p className="text-[13px] font-semibold text-sw-text font-tabular leading-tight">
                  {fmtK(result.federal_tax.after_annual_cents)}{' '}
                  <span className="text-[9px] font-normal text-sw-muted">per year</span>
                </p>
                <p className={`text-[11px] font-[700] font-tabular mt-1 ${result.federal_tax.delta_annual_cents < 0 ? 'text-sw-success' : result.federal_tax.delta_annual_cents > 0 ? 'text-sw-danger' : 'text-sw-muted'}`}>
                  {fmtCents(result.federal_tax.delta_annual_cents)}/yr
                </p>
              </div>
              <div className="rounded-xl bg-sw-surface ring-1 ring-sw-border/40 p-2.5 text-center">
                <p className="text-[9px] font-semibold uppercase tracking-wider text-sw-muted mb-1">
                  {result.retirement.target_age ? `At age ${result.retirement.target_age}` : 'Retirement'}
                </p>
                {result.retirement.after_fv ? (
                  <p className="text-[11px] font-semibold text-sw-text font-tabular leading-tight">
                    ~{fmtK(result.retirement.after_fv.low_cents)}–{fmtK(result.retirement.after_fv.high_cents)}{' '}
                    <span className="text-[9px] font-normal text-sw-muted">est. range</span>
                  </p>
                ) : (
                  <p className="text-[11px] text-sw-muted">—</p>
                )}
                <p className={`text-[11px] font-tabular mt-1 ${matchDropped ? 'text-sw-danger font-[700]' : 'text-sw-muted'}`}>
                  match {fmtK(result.retirement.employer_match_after_cents)}/yr
                </p>
              </div>
            </div>
          )}

          {matchDropped && result && (
            <p className="text-[11px] text-sw-danger mt-2 flex items-center gap-1">
              <AlertCircle size={11} className="shrink-0" />
              This level gives up {fmtK(result.retirement.employer_match_before_cents - result.retirement.employer_match_after_cents)}/yr of free employer match.
            </p>
          )}

          <p className="text-[10px] text-sw-dim mt-3">
            Estimates only — this does not change your plan. Consider reviewing with a tax professional.
          </p>
        </div>
      )}
    </div>
  );
}

export default function OptimizationChecklistView({
  taxYear,
  hasBankConnected,
  scenariosData,
  onChoose,
  onNavigateToChoices,
  onBackToChoices,
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

  // Chosen plan label: from API response or from scenariosData (inline choose path).
  const chosenOptionLabel = data?.chosen_option_label ?? null;

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 p-5">

      {/* Addition 8: Back button — top-left, ghost/tertiary style */}
      {onBackToChoices && (
        <button
          onClick={onBackToChoices}
          className="inline-flex items-center gap-1 text-[11px] text-sw-muted hover:text-sw-accent transition mb-3"
        >
          <ArrowLeft size={12} />
          Back to your options
        </button>
      )}

      {/* Addition 6: Chosen plan header — "YOUR PLAN: X" eyebrow + Change plan link */}
      {chosenOptionLabel && (
        <div className="flex items-start justify-between gap-2 mb-3">
          <div className="min-w-0">
            <p className="text-[9px] font-semibold uppercase tracking-widest text-sw-muted leading-none mb-0.5">
              Your plan
            </p>
            <div className="flex items-center gap-1.5">
              <Target size={13} className="text-sw-accent shrink-0" />
              <p className="text-[14px] font-bold text-sw-text leading-tight truncate">
                {chosenOptionLabel}
              </p>
            </div>
          </div>
          {onBackToChoices && (
            <button
              onClick={onBackToChoices}
              className="shrink-0 inline-flex items-center gap-1 text-[11px] font-medium text-sw-accent hover:text-sw-accent-hover transition"
            >
              Change plan <ArrowRight size={11} />
            </button>
          )}
        </div>
      )}

      {/* Header aggregate banner */}
      {headerRow && <HeaderAggregateBanner params={headerRow.benefit_line_params} />}

      {/* What-if calculator (owner request 2026-07-06) */}
      <ScenarioPlayground taxYear={taxYear} />

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
