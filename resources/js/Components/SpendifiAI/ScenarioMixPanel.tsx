/**
 * ScenarioMixPanel — Phase 14-10 / D.3 compute round-trips
 *
 * Allows the user to adjust public knobs (W-4, 401k) and see the modeled
 * impact via POST /optimizer/scenarios/{year}/compute.
 *
 * The compute endpoint returns raw delta cents (NOT tiers) — this is the one
 * place in the scenarios stage where actual dollar figures appear, and they are
 * always labeled as "estimates" with the educational disclaimer.
 *
 * Design: born-premium §3.11, font-tabular for monetary values.
 * Educational framing: "may", "could", "estimates only".
 */

import { useState, useCallback } from 'react';
import axios from 'axios';
import { Calculator, Loader2, Info, RefreshCw } from 'lucide-react';
import type { ComputeScenarioResponse } from '@/types/spendifiai';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fmtDeltaCents(cents: number): string {
  if (cents === 0) return '—';
  const abs = Math.abs(cents);
  const dollars = abs / 100;
  const sign = cents > 0 ? '+' : '−';
  if (dollars >= 1000) {
    return `${sign}$${(dollars / 1000).toFixed(1)}k`;
  }
  return `${sign}$${dollars.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
}

function deltaColor(cents: number, metricKey: 'take_home' | 'federal_tax' | 'retirement'): string {
  if (cents === 0) return 'text-sw-dim';
  // Federal tax: positive delta = more tax = bad
  if (metricKey === 'federal_tax') {
    return cents < 0 ? 'text-sw-success' : 'text-sw-danger';
  }
  return cents > 0 ? 'text-sw-success' : 'text-sw-danger';
}

// ─── Slider Row ───────────────────────────────────────────────────────────────

function SliderRow({
  label,
  value,
  min,
  max,
  step,
  onChange,
  formatValue,
}: {
  label: string;
  value: number;
  min: number;
  max: number;
  step: number;
  onChange: (v: number) => void;
  formatValue: (v: number) => string;
}) {
  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between">
        <label className="text-[12px] font-medium text-sw-text">{label}</label>
        <span className="text-[12px] font-semibold font-tabular text-sw-accent">{formatValue(value)}</span>
      </div>
      <input
        type="range"
        min={min}
        max={max}
        step={step}
        value={value}
        onChange={(e) => onChange(Number(e.target.value))}
        className="w-full h-1.5 rounded-full bg-sw-border accent-sw-accent cursor-pointer"
      />
      <div className="flex items-center justify-between text-[10px] text-sw-dim">
        <span>{formatValue(min)}</span>
        <span>{formatValue(max)}</span>
      </div>
    </div>
  );
}

// ─── Result Row ───────────────────────────────────────────────────────────────

function ResultRow({
  label,
  deltaCents,
  metricKey,
}: {
  label: string;
  deltaCents: number;
  metricKey: 'take_home' | 'federal_tax' | 'retirement';
}) {
  return (
    <div className="flex items-center justify-between py-1 border-b border-sw-border/40 last:border-b-0">
      <span className="text-[12px] text-sw-muted">{label}</span>
      <span className={`text-[13px] font-[700] font-tabular leading-none ${deltaColor(deltaCents, metricKey)}`}>
        {fmtDeltaCents(deltaCents)}
        <span className="text-[10px] font-normal text-sw-dim ml-1">/yr est.</span>
      </span>
    </div>
  );
}

// ─── Main Component ───────────────────────────────────────────────────────────

interface Props {
  taxYear: number;
}

export default function ScenarioMixPanel({ taxYear }: Props) {
  const [deferralPct, setDeferralPct] = useState(6);
  const [rothSharePct, setRothSharePct] = useState(0);

  const [result, setResult] = useState<ComputeScenarioResponse | null>(null);
  const [computing, setComputing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const compute = useCallback(async () => {
    setComputing(true);
    setError(null);
    try {
      const res = await axios.post<ComputeScenarioResponse>(
        `/api/v1/optimizer/scenarios/${taxYear}/compute`,
        {
          knobs: {
            k401: {
              deferral_pct: deferralPct,
              roth_share_pct: rothSharePct,
            },
          },
        },
      );
      setResult(res.data);
    } catch (err) {
      const msg =
        (err as { response?: { data?: { message?: string } } })?.response?.data?.message ??
        'Could not compute scenario';
      setError(msg);
    } finally {
      setComputing(false);
    }
  }, [taxYear, deferralPct, rothSharePct]);

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 p-5">
      {/* Header */}
      <div className="flex items-center gap-2.5 mb-4">
        <div className="w-8 h-8 rounded-xl bg-sw-accent/10 ring-1 ring-sw-accent/20 flex items-center justify-center">
          <Calculator size={16} className="text-sw-accent" />
        </div>
        <div>
          <h3 className="text-[15px] font-semibold text-sw-text leading-tight">Mix & Model</h3>
          <p className="text-[11px] text-sw-muted">Adjust knobs to model what may happen</p>
        </div>
      </div>

      {/* Sliders */}
      <div className="space-y-4 mb-4">
        <SliderRow
          label="401(k) Deferral"
          value={deferralPct}
          min={0}
          max={23}
          step={0.5}
          onChange={setDeferralPct}
          formatValue={(v) => `${v}%`}
        />
        <SliderRow
          label="Roth Share"
          value={rothSharePct}
          min={0}
          max={100}
          step={5}
          onChange={setRothSharePct}
          formatValue={(v) => `${v}%`}
        />
      </div>

      {/* Compute button */}
      <button
        onClick={compute}
        disabled={computing}
        className="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 mb-4 shadow-sw-1"
      >
        {computing ? (
          <Loader2 size={14} className="animate-spin" />
        ) : (
          <RefreshCw size={14} />
        )}
        Model this mix
      </button>

      {/* Error state */}
      {error && (
        <div className="rounded-lg bg-red-50 ring-1 ring-red-200 px-3 py-2 mb-3">
          <p className="text-[11px] text-red-700">{error}</p>
        </div>
      )}

      {/* Results */}
      {result && (
        <div className="rounded-xl ring-1 ring-sw-border/60 bg-sw-surface/60 p-3.5">
          <p className="text-[10px] text-sw-dim uppercase tracking-widest font-semibold mb-2">
            Estimated annual impact
          </p>
          <ResultRow
            label="Take-home change"
            deltaCents={result.outcome.take_home}
            metricKey="take_home"
          />
          <ResultRow
            label="Federal tax change"
            deltaCents={result.outcome.federal_tax}
            metricKey="federal_tax"
          />
          <ResultRow
            label="Retirement contributions"
            deltaCents={result.outcome.retirement}
            metricKey="retirement"
          />
        </div>
      )}

      {/* Disclaimer */}
      <div className="flex items-start gap-1.5 mt-3">
        <Info size={10} className="text-sw-dim shrink-0 mt-0.5" />
        <p className="text-[10px] text-sw-dim leading-relaxed">
          Estimates are modeled from your interview answers and IRS tables. Results could differ. Not tax advice — consult a qualified professional.
        </p>
      </div>
    </div>
  );
}
