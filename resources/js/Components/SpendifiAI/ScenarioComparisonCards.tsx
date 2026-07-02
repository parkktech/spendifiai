/**
 * ScenarioComparisonCards — Phase 14-10 / D.3 Scenarios stage
 *
 * Renders the 3-up (A/B/Balanced) scenario comparison grid.
 *
 * Three-metric block: uses delta TIERS (not raw cents — SAFE-03 compliance).
 * Knob-diff rows: shows diverging W-4 / 401(k) knob values between options.
 * Guard chips: "Illustration" badge on long-horizon FV ranges.
 * Choose CTA: triggers ConfirmDialog before POSTing /choose.
 *
 * Design: born-premium §3.11 action-card recipe.
 * Educational framing: "may", "could", "consider" — no assertive language.
 */

import { useState } from 'react';
import {
  TrendingUp,
  TrendingDown,
  Minus,
  ArrowRight,
  CheckCircle2,
  Loader2,
  Info,
} from 'lucide-react';
import Badge from './Badge';
import ConfirmDialog from './ConfirmDialog';
import type { ScenariosResponse, ScenarioOption, DeltaTier, PublicKnobs } from '@/types/spendifiai';

// ─── Delta Tier Renderers ──────────────────────────────────────────────────────

interface TierDisplay {
  icon: React.ReactNode;
  label: string;
  colorClass: string;
}

function tierDisplay(tier: DeltaTier, metricKey: 'take_home' | 'federal_tax' | 'retirement'): TierDisplay {
  // For federal_tax: positive tier = tax REDUCTION = good
  // For take_home: positive tier = more take-home = good
  // For retirement: positive tier = more retirement savings = good

  const isGoodTier = (t: DeltaTier) =>
    t === 'positive_large' || t === 'positive_medium' || t === 'positive_small';
  const isBadTier = (t: DeltaTier) =>
    t === 'negative_large' || t === 'negative_medium' || t === 'negative_small';

  // Federal tax: "positive" means federal tax goes UP (bad for take-home); "negative" = tax decreases = good
  const effectivelyGood =
    metricKey === 'federal_tax'
      ? isBadTier(tier) // negative federal tax delta = tax savings
      : isGoodTier(tier);

  const icon = effectivelyGood ? (
    <TrendingUp size={13} />
  ) : isBadTier(metricKey === 'federal_tax' ? opposeTier(tier) : tier) ? (
    <TrendingDown size={13} />
  ) : (
    <Minus size={13} />
  );

  const label = tierLabel(tier, metricKey);
  const colorClass = tier === 'neutral'
    ? 'text-sw-muted'
    : effectivelyGood
      ? 'text-sw-success'
      : 'text-sw-danger';

  return { icon, label, colorClass };
}

function opposeTier(tier: DeltaTier): DeltaTier {
  switch (tier) {
    case 'positive_large': return 'negative_large';
    case 'positive_medium': return 'negative_medium';
    case 'positive_small': return 'negative_small';
    case 'negative_large': return 'positive_large';
    case 'negative_medium': return 'positive_medium';
    case 'negative_small': return 'positive_small';
    default: return 'neutral';
  }
}

function tierLabel(tier: DeltaTier, metricKey: 'take_home' | 'federal_tax' | 'retirement'): string {
  const magnitude: Record<string, string> = {
    positive_large: 'significant',
    positive_medium: 'moderate',
    positive_small: 'slight',
    neutral: 'no change',
    negative_small: 'slight decrease',
    negative_medium: 'moderate decrease',
    negative_large: 'significant decrease',
  };

  const direction =
    tier === 'neutral'
      ? 'no change'
      : tier.startsWith('positive')
        ? `${magnitude[tier]} increase`
        : `${magnitude[tier]}`;

  if (metricKey === 'federal_tax') {
    // For federal tax, positive = more tax (bad), negative = less tax (good)
    if (tier === 'neutral') return 'unchanged';
    if (tier.startsWith('positive')) return `${magnitude[tier]} increase`;
    return `${magnitude[tier].replace('decrease', '').trim()} reduction`;
  }

  return direction;
}

// ─── Metric Labels ────────────────────────────────────────────────────────────

const METRIC_LABELS: Record<string, string> = {
  take_home: 'Take-Home',
  federal_tax: 'Federal Tax',
  retirement: 'Retirement',
};

// ─── Knob Diff Row ────────────────────────────────────────────────────────────

function findDivergingKnobs(options: ScenarioOption[]): string[] {
  if (options.length < 2) return [];

  const diverging: string[] = [];

  // Compare W-4 filing_status
  const filingStatuses = options.map((o) => o.knobs.w4.filing_status);
  if (new Set(filingStatuses).size > 1) diverging.push('w4_filing');

  // Compare W-4 dependents_under_17
  const deps17 = options.map((o) => o.knobs.w4.dependents_under_17);
  if (new Set(deps17).size > 1) diverging.push('w4_deps17');

  // Compare 401k deferral_pct
  const deferral = options.map((o) => o.knobs.k401.deferral_pct);
  if (new Set(deferral).size > 1) diverging.push('k401_deferral');

  // Compare 401k roth_share_pct
  const roth = options.map((o) => o.knobs.k401.roth_share_pct);
  if (new Set(roth).size > 1) diverging.push('k401_roth');

  return diverging;
}

function KnobDiffSection({
  options,
  divergingKnobs,
}: {
  options: ScenarioOption[];
  divergingKnobs: string[];
}) {
  if (divergingKnobs.length === 0) return null;

  const knobValue = (knobs: PublicKnobs, key: string): string => {
    switch (key) {
      case 'w4_filing': return knobs.w4.filing_status ?? '—';
      case 'w4_deps17': return `${knobs.w4.dependents_under_17} dep.`;
      case 'k401_deferral': return `${knobs.k401.deferral_pct}%`;
      case 'k401_roth': return `${knobs.k401.roth_share_pct}% Roth`;
      default: return '—';
    }
  };

  const knobLabel: Record<string, string> = {
    w4_filing: 'Filing Status',
    w4_deps17: 'Dependents (<17)',
    k401_deferral: '401(k) Deferral',
    k401_roth: 'Roth Share',
  };

  return (
    <div className="mt-3 border-t border-sw-border/40 pt-3">
      <p className="text-[10px] text-sw-dim uppercase tracking-widest mb-2 font-semibold">Key differences</p>
      <div className="space-y-1.5">
        {divergingKnobs.slice(0, 3).map((key) => (
          <div key={key} className="flex items-center gap-1 text-[10px] text-sw-muted">
            <span className="w-24 shrink-0">{knobLabel[key]}</span>
            {options.map((opt, i) => (
              <span
                key={opt.key}
                className={`flex-1 text-center font-tabular font-semibold ${
                  i === 0 ? 'text-sw-accent' : i === 1 ? 'text-violet-700' : 'text-emerald-700'
                }`}
              >
                {knobValue(opt.knobs, key)}
              </span>
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}

// ─── Single Scenario Card ─────────────────────────────────────────────────────

function ScenarioCard({
  option,
  index,
  isChosen,
  onChoose,
  choosing,
}: {
  option: ScenarioOption;
  index: number;
  isChosen: boolean;
  onChoose: () => void;
  choosing: boolean;
}) {
  const accentColors = [
    'ring-sw-accent/30 bg-sw-accent/5',
    'ring-violet-200 bg-violet-50/50',
    'ring-emerald-200 bg-emerald-50/50',
  ];
  const headerColors = [
    'text-sw-accent',
    'text-violet-700',
    'text-emerald-700',
  ];

  const metricKeys: Array<'take_home' | 'federal_tax' | 'retirement'> = [
    'take_home',
    'federal_tax',
    'retirement',
  ];

  return (
    <div
      className={`group rounded-xl ring-1 p-4 transition-all relative ${
        isChosen
          ? 'ring-sw-success/50 bg-sw-success/5 shadow-sw-2'
          : `${accentColors[index] ?? accentColors[0]} shadow-sw-1 card-lift`
      }`}
    >
      {isChosen && (
        <div className="absolute -top-2 left-1/2 -translate-x-1/2">
          <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-sw-success text-white shadow-sw-1">
            <CheckCircle2 size={9} /> Chosen
          </span>
        </div>
      )}

      {/* Option header */}
      <div className="mb-3">
        <p className={`text-[13px] font-[700] leading-tight ${headerColors[index] ?? headerColors[0]}`}>
          {option.label}
        </p>
        {option.badges && option.badges.length > 0 && (
          <div className="flex flex-wrap gap-1 mt-1">
            {option.badges.map((b) => (
              <Badge key={b} variant="info">{b}</Badge>
            ))}
          </div>
        )}
      </div>

      {/* Three-metric block */}
      <div className="space-y-1.5 mb-3">
        {metricKeys.map((metric) => {
          const tier = option.outcome[metric];
          const display = tierDisplay(tier, metric);
          return (
            <div key={metric} className="flex items-center justify-between gap-2">
              <span className="text-[11px] text-sw-muted">{METRIC_LABELS[metric]}</span>
              <span className={`flex items-center gap-1 text-[11px] font-semibold ${display.colorClass}`}>
                {display.icon}
                {display.label}
              </span>
            </div>
          );
        })}
      </div>

      {/* 401k deferral display */}
      <div className="rounded-lg bg-slate-50/80 ring-1 ring-sw-border/40 p-2 mb-3">
        <div className="flex items-center justify-between text-[11px]">
          <span className="text-sw-dim">401(k) deferral</span>
          <span className="font-tabular font-semibold text-sw-text">
            {option.knobs.k401.deferral_pct}%
          </span>
        </div>
        {option.knobs.k401.roth_share_pct > 0 && (
          <div className="flex items-center justify-between text-[11px] mt-0.5">
            <span className="text-sw-dim">Roth share</span>
            <span className="font-tabular font-semibold text-sw-text">
              {option.knobs.k401.roth_share_pct}%
            </span>
          </div>
        )}
      </div>

      {/* Illustration guard chip */}
      {option.outcome.retirement === 'positive_large' && (
        <div className="mb-3">
          <Badge variant="info">
            <Info size={9} className="inline mr-0.5" />
            Illustration — long-horizon FV range
          </Badge>
        </div>
      )}

      {/* Choose CTA */}
      {!isChosen && (
        <button
          onClick={onChoose}
          disabled={choosing}
          className={`w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-xs font-semibold transition border ${
            index === 0
              ? 'border-sw-accent/30 text-sw-accent hover:bg-sw-accent hover:text-white'
              : index === 1
                ? 'border-violet-300 text-violet-700 hover:bg-violet-600 hover:text-white'
                : 'border-emerald-300 text-emerald-700 hover:bg-emerald-600 hover:text-white'
          } disabled:opacity-50`}
        >
          {choosing ? <Loader2 size={12} className="animate-spin" /> : <ArrowRight size={12} />}
          Choose this plan
        </button>
      )}
    </div>
  );
}

// ─── Main Component ───────────────────────────────────────────────────────────

interface Props {
  data: ScenariosResponse;
  onChoose: (optionKey: string) => Promise<void>;
}

export default function ScenarioComparisonCards({ data, onChoose }: Props) {
  const [pendingKey, setPendingKey] = useState<string | null>(null);
  const [choosingKey, setChoosingKey] = useState<string | null>(null);

  const divergingKnobs = findDivergingKnobs(data.options);

  const handleChooseConfirm = async () => {
    if (!pendingKey) return;
    setChoosingKey(pendingKey);
    setPendingKey(null);
    await onChoose(pendingKey);
    setChoosingKey(null);
  };

  return (
    <div className="space-y-4">
      {/* Disclaimer */}
      <div className="flex items-start gap-2 rounded-xl ring-1 ring-sw-info/20 bg-sw-info-light/30 px-3.5 py-2.5">
        <Info size={13} className="text-sw-info shrink-0 mt-0.5" />
        <p className="text-[11px] text-sw-text-secondary leading-relaxed">
          Scenarios show directional estimates only. Dollar amounts are modeled from your interview answers and may differ from actual results. Consult a tax professional before acting.
        </p>
      </div>

      {/* 3-up comparison grid */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        {data.options.map((option, i) => (
          <ScenarioCard
            key={option.key}
            option={option}
            index={i}
            isChosen={data.chosen?.option_key === option.key}
            onChoose={() => setPendingKey(option.key)}
            choosing={choosingKey === option.key}
          />
        ))}
      </div>

      {/* Knob diff section spanning full width */}
      {data.options.length > 1 && (
        <div className="rounded-xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 p-3.5">
          <KnobDiffSection options={data.options} divergingKnobs={divergingKnobs} />
          {divergingKnobs.length === 0 && (
            <p className="text-[11px] text-sw-muted text-center py-2">
              All options use the same settings for your situation
            </p>
          )}
        </div>
      )}

      {/* Choose confirmation dialog */}
      {pendingKey && (
        <ConfirmDialog
          open={!!pendingKey}
          title="Choose this plan"
          message={`This will materialize your ${
            data.options.find((o) => o.key === pendingKey)?.label ?? 'chosen'
          } optimization plan into a step-by-step checklist. You can revisit and re-choose at any time.`}
          confirmText="Yes, create my checklist"
          variant="default"
          onConfirm={handleChooseConfirm}
          onCancel={() => setPendingKey(null)}
        />
      )}
    </div>
  );
}
