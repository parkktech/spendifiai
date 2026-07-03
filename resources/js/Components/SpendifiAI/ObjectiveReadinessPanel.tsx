/**
 * ObjectiveReadinessPanel — Phase 14-10 / D.2 Scenarios stage
 *
 * Renders the three scenario objectives (take_home, tax_burden, retirement)
 * as readiness chips and shows missing-fact counts.
 *
 * D23 (done-for-you): no "See your options" button; no dead-end text.
 * - When ALL ready: scenarios are already auto-computed above by ScenarioController.
 * - When NOT ready: Index.tsx auto-fires POST /enqueue-gaps on stage load and renders
 *   an inline InterviewCard below this panel. The footer says so, never a dead-end.
 *
 * Design: born-premium §3.11 recipe, stagger-children.
 * Educational framing: "may", "could", "consider" — no assertive language.
 */

import {
  CheckCircle2,
  Circle,
  TrendingUp,
  ShieldMinus,
  PiggyBank,
  AlertCircle,
  MessageSquare,
} from 'lucide-react';
import type { ObjectivesResponse, ObjectiveReadiness } from '@/types/spendifiai';

// ─── Helpers ──────────────────────────────────────────────────────────────────

const OBJECTIVE_META: Record<string, { label: string; icon: React.ReactNode; description: string }> = {
  take_home: {
    label: 'Maximize Take-Home',
    icon: <TrendingUp size={15} className="text-sw-success" />,
    description: 'W-4 alignment and 401(k) optimization to increase each paycheck',
  },
  tax_burden: {
    label: 'Reduce Tax Burden',
    icon: <ShieldMinus size={15} className="text-violet-600" />,
    description: 'HSA elections, IRA contributions, and deduction opportunities',
  },
  retirement: {
    label: 'Build Retirement',
    icon: <PiggyBank size={15} className="text-blue-600" />,
    description: 'Employer match capture and Roth/Traditional balance',
  },
};

// ─── Readiness Chip ───────────────────────────────────────────────────────────

function ReadinessChip({
  objectiveKey,
  readiness,
}: {
  objectiveKey: string;
  readiness: ObjectiveReadiness;
}) {
  const meta = OBJECTIVE_META[objectiveKey] ?? {
    label: readiness.label,
    icon: <Circle size={15} className="text-sw-muted" />,
    description: '',
  };

  return (
    <div
      className={`group rounded-xl ring-1 p-3.5 transition-all ${
        readiness.ready
          ? 'ring-sw-success/40 bg-sw-success/5 card-lift'
          : 'ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 card-lift'
      }`}
    >
      <div className="flex items-start gap-2.5">
        <div
          className={`w-7 h-7 rounded-lg flex items-center justify-center shrink-0 ring-1 ${
            readiness.ready
              ? 'bg-sw-success/10 ring-sw-success/30'
              : 'bg-slate-50 ring-sw-border'
          }`}
        >
          {readiness.ready ? (
            <CheckCircle2 size={14} className="text-sw-success" />
          ) : (
            meta.icon
          )}
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex items-center justify-between gap-2">
            <p className="text-[13px] font-semibold text-sw-text leading-tight">{meta.label}</p>
            {readiness.ready ? (
              <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-sw-success/15 text-sw-success ring-1 ring-sw-success/30">
                Ready
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-700 ring-1 ring-amber-200">
                {readiness.questions_to_unlock} to unlock
              </span>
            )}
          </div>
          <p className="text-[11px] text-sw-muted mt-0.5 leading-snug">{meta.description}</p>
          {!readiness.ready && readiness.blocking.length > 0 && (
            <div className="mt-1.5 space-y-0.5">
              {readiness.blocking.slice(0, 2).map((b) => (
                <p key={b.fact_key} className="text-[10px] text-sw-dim flex items-center gap-1">
                  <AlertCircle size={9} className="shrink-0 text-amber-500" />
                  {b.label}
                </p>
              ))}
              {readiness.blocking.length > 2 && (
                <p className="text-[10px] text-sw-dim pl-3.5">
                  +{readiness.blocking.length - 2} more
                </p>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ─── Main Component ───────────────────────────────────────────────────────────

interface Props {
  data: ObjectivesResponse;
  /** Fix 5: when provided, the "unlock" footer row becomes a clickable CTA. */
  onUnlockClick?: () => void;
}

export default function ObjectiveReadinessPanel({ data, onUnlockClick }: Props) {
  const objectives = data.objectives;
  const allReady = Object.values(objectives).every((o) => o.ready);
  const readyCount = Object.values(objectives).filter((o) => o.ready).length;
  const totalCount = Object.keys(objectives).length;

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 p-5">
      {/* Header */}
      <div className="flex items-start justify-between gap-3 mb-4">
        <div>
          <h3 className="text-[15px] font-semibold text-sw-text leading-tight">Scenario Readiness</h3>
          <p className="text-[12px] text-sw-muted mt-0.5">
            {readyCount} of {totalCount} objectives ready to model
          </p>
        </div>
        {allReady && (
          <span className="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-full bg-sw-success/15 text-sw-success ring-1 ring-sw-success/30">
            <CheckCircle2 size={11} />
            All ready
          </span>
        )}
      </div>

      {/* Objective chips */}
      <div className="space-y-2.5 stagger-children mb-4">
        {Object.entries(objectives).map(([key, readiness]) => (
          <ReadinessChip key={key} objectiveKey={key} readiness={readiness} />
        ))}
      </div>

      {/* D23 footer: no dead-end. Fix 5: unlock row is clickable when onUnlockClick provided. */}
      <div className="pt-3 border-t border-sw-border/60">
        {allReady ? (
          <p className="text-[10px] text-sw-dim text-center">
            Educational estimates only — consider reviewing with a tax professional
          </p>
        ) : onUnlockClick ? (
          /* Fix 5: prominent, clickable unlock cue */
          <button
            onClick={onUnlockClick}
            className="w-full flex items-center justify-center gap-2 py-1.5 rounded-lg hover:bg-sw-accent/5 transition group focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sw-accent/50"
          >
            <MessageSquare size={14} className="text-sw-accent shrink-0 group-hover:scale-110 transition-transform" />
            <span className="text-[13px] font-semibold text-sw-accent">
              Answer the questions below to unlock your options
            </span>
          </button>
        ) : (
          <p className="text-[11px] text-sw-muted flex items-center justify-center gap-1.5">
            <MessageSquare size={12} className="text-sw-accent shrink-0" />
            A few quick questions below will unlock your options
          </p>
        )}
      </div>
    </div>
  );
}
