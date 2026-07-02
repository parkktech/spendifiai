/**
 * ActionCenterWidget — Phase 14-10 (ACT-01 / ACT-05)
 *
 * Consumes GET /api/v1/optimizer/action-center and renders four item groups:
 *   1. Stage-0 onboarding prerequisites (disappear once the prerequisite is met)
 *   2. Optimization checklist actions with engine benefit lines (cents → dollars)
 *   3. Change-detected monitor prompts (income shift, year-end, bonus lead-time)
 *   4. Calendar events approaching their alert window
 *
 * Empty state (ACT-05): when is_empty === true, renders a §3.9 premium achievement
 * moment — "You're fully optimized for now — we're watching for changes."
 *
 * Checklist item PATCH uses optimistic UI — the item disappears immediately and
 * reverts on API failure.
 *
 * Design: born-premium §3.11 recipe (shadow-sw-1, card-lift, stagger-children,
 * font-tabular for monetary figures).
 */

import { useState, useCallback } from 'react';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import {
  Circle,
  ArrowRight,
  Sparkles,
  AlertTriangle,
  Calendar,
  TrendingUp,
  Shield,
  Zap,
  RefreshCw,
} from 'lucide-react';
import { useApi } from '@/hooks/useApi';
import type { ActionCenterResponse, ActionCenterChecklistItem, ChecklistBenefitParams } from '@/types/spendifiai';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fmtCents(cents: number | undefined): string {
  if (!cents || cents === 0) return '';
  const dollars = Math.abs(cents) / 100;
  if (dollars >= 1000) {
    return `$${(dollars / 1000).toFixed(1)}k`;
  }
  return `$${dollars.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
}

/**
 * Derive the most prominent benefit figure from a checklist item's benefit_line_params.
 * Returns a short readable string like "+$89/check" or "+$1,040/yr".
 * All values are integer cents in the params object.
 */
function deriveBenefitLine(knob: string, params: ChecklistBenefitParams | null): string | null {
  if (!params) return null;

  switch (knob) {
    case 'k1': {
      const perPaycheck = params.per_paycheck;
      const annual = params.annual;
      if (perPaycheck && perPaycheck !== 0) {
        const sign = perPaycheck > 0 ? '+' : '';
        return `${sign}${fmtCents(perPaycheck)}/paycheck`;
      }
      if (annual && annual !== 0) {
        const sign = annual > 0 ? '+' : '';
        return `${sign}${fmtCents(annual)}/yr take-home`;
      }
      return null;
    }
    case 'k2': {
      const deltaTax = params.delta_tax;
      if (deltaTax && deltaTax !== 0) {
        // Negative delta_tax = tax savings
        const savings = -deltaTax;
        if (savings > 0) return `~${fmtCents(savings)}/yr tax savings`;
      }
      return null;
    }
    case 'k3': {
      const match = params.match;
      const deltaAnnual = params.delta_annual;
      if (match && match > 0) return `+${fmtCents(match)}/yr employer match`;
      if (deltaAnnual && deltaAnnual !== 0) {
        const sign = deltaAnnual > 0 ? '+' : '';
        return `${sign}${fmtCents(deltaAnnual)}/yr take-home`;
      }
      return null;
    }
    case 'k4': {
      const deltaPaycheck = params.delta_paycheck;
      if (deltaPaycheck && deltaPaycheck !== 0) {
        const sign = deltaPaycheck > 0 ? '+' : '';
        return `${sign}${fmtCents(deltaPaycheck)}/paycheck`;
      }
      const amount = params.amount;
      if (amount && amount > 0) return `~${fmtCents(amount)}/yr HSA election`;
      return null;
    }
    case 'k5': {
      const deduction = params.delta_deduction;
      if (deduction && deduction > 0) return `~${fmtCents(deduction)}/yr deduction`;
      return null;
    }
    case 'k6': {
      const amount = params.amount;
      const periodLabel = params.period_label;
      if (amount && amount > 0) {
        return `+${fmtCents(amount)}${periodLabel ? ' ' + periodLabel : '/period'} auto-transfer`;
      }
      return null;
    }
    default:
      return null;
  }
}

/** Human-readable knob label for the action card title. */
function knobTitle(knob: string, stepKey: string): string {
  const titles: Record<string, string> = {
    k1: 'Align W-4 withholding',
    k2: 'Optimize Roth / Traditional split',
    k3: 'Maximize 401(k) employer match',
    k4: 'Elect HSA contributions',
    k5: 'Contribute to IRA',
    k6: 'Set up auto-transfer',
  };
  return titles[knob] ?? stepKey.replace(/_/g, ' ');
}

// ─── Skeleton ─────────────────────────────────────────────────────────────────

function ActionCenterSkeleton() {
  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 p-5 animate-pulse">
      <div className="h-5 w-36 bg-slate-200 rounded mb-4" />
      <div className="space-y-3">
        {[1, 2].map((i) => (
          <div key={i} className="h-14 bg-slate-100 rounded-xl" />
        ))}
      </div>
    </div>
  );
}

// ─── Achievement Empty State (ACT-05 / §3.9) ─────────────────────────────────

function AchievementEmptyState() {
  return (
    <div className="flex flex-col items-center justify-center py-10 px-6 text-center">
      <div className="w-12 h-12 rounded-2xl bg-sw-success/10 ring-1 ring-sw-success/30 flex items-center justify-center mb-4">
        <Shield size={22} className="text-sw-success" />
      </div>
      <h3 className="text-[15px] font-semibold text-sw-text mb-1">You're fully optimized for now</h3>
      <p className="text-sm text-sw-muted max-w-xs">
        We're watching for income shifts, deadline windows, and new opportunities — you'll see them here.
      </p>
    </div>
  );
}

// ─── Stage-0 Items ────────────────────────────────────────────────────────────

function Stage0Section({ items }: { items: ActionCenterResponse['stage0_items'] }) {
  if (items.length === 0) return null;

  const iconMap: Record<string, React.ReactNode> = {
    upload_paystub: <TrendingUp size={14} className="text-sw-accent" />,
    link_bank: <Zap size={14} className="text-sw-accent" />,
    link_credit_cards: <Zap size={14} className="text-sw-accent" />,
    link_email: <Zap size={14} className="text-sw-accent" />,
    do_interview: <Sparkles size={14} className="text-sw-accent" />,
  };

  return (
    <div className="mb-5">
      <p className="text-[11px] font-semibold uppercase tracking-widest text-sw-muted mb-2">
        Get Started
      </p>
      <div className="space-y-2">
        {items.map((item) => (
          <Link
            key={item.key}
            href={item.cta_url}
            className="group flex items-center gap-3 rounded-xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 p-3.5 card-lift"
          >
            <div className="w-7 h-7 rounded-lg bg-sw-accent/10 ring-1 ring-sw-accent/20 flex items-center justify-center shrink-0">
              {iconMap[item.key] ?? <Zap size={14} className="text-sw-accent" />}
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-[13px] font-semibold text-sw-text leading-tight">{item.title}</p>
              <p className="text-xs text-sw-muted mt-0.5 leading-snug">{item.description}</p>
            </div>
            <ArrowRight size={14} className="text-sw-dim group-hover:text-sw-accent transition-colors shrink-0" />
          </Link>
        ))}
      </div>
    </div>
  );
}

// ─── Checklist Item Card ──────────────────────────────────────────────────────

function ChecklistItemCard({
  item,
  onDone,
  isDoneOptimistic,
}: {
  item: ActionCenterChecklistItem;
  onDone: (id: number) => void;
  isDoneOptimistic: boolean;
}) {
  const benefitLine = deriveBenefitLine(item.knob, item.benefit_line_params);
  const title = knobTitle(item.knob, item.step_key);
  const isConfirmAsk = item.kind === 'confirm_ask';

  if (isDoneOptimistic) return null;

  return (
    <div className="group flex items-start gap-3 rounded-xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 p-3.5 card-lift">
      {/* Done toggle */}
      <button
        onClick={() => onDone(item.id)}
        className="mt-0.5 w-5 h-5 flex items-center justify-center rounded-full ring-1 ring-sw-border hover:ring-sw-success hover:bg-sw-success/10 transition-all shrink-0"
        aria-label={`Mark "${title}" complete`}
      >
        <Circle size={13} className="text-sw-dim group-hover:text-sw-success transition-colors" />
      </button>

      <div className="flex-1 min-w-0">
        <div className="flex items-start justify-between gap-2">
          <p className="text-[13px] font-semibold text-sw-text leading-tight">{title}</p>
          {benefitLine && (
            <span className="text-[12px] font-[700] text-sw-success font-tabular shrink-0 leading-tight">
              {benefitLine}
            </span>
          )}
        </div>
        {isConfirmAsk && (
          <p className="text-[11px] text-sw-muted mt-0.5">
            Confirm your facts in the interview to unlock this step
          </p>
        )}
        {item.due_date && (
          <p className="text-[11px] text-sw-dim mt-1 flex items-center gap-1">
            <Calendar size={10} />
            Due {new Date(item.due_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
          </p>
        )}
      </div>
    </div>
  );
}

// ─── Monitor Prompts ──────────────────────────────────────────────────────────

function MonitorPromptsSection({ items }: { items: ActionCenterResponse['monitor_prompts'] }) {
  if (items.length === 0) return null;

  const severityStyles: Record<string, string> = {
    high: 'bg-red-50 ring-red-200 text-red-800',
    medium: 'bg-amber-50 ring-amber-200 text-amber-800',
    low: 'bg-blue-50 ring-blue-200 text-blue-800',
  };

  return (
    <div className="mt-4">
      <p className="text-[11px] font-semibold uppercase tracking-widest text-sw-muted mb-2">
        Changes Detected
      </p>
      <div className="space-y-2">
        {items.map((prompt) => (
          <div
            key={prompt.id}
            className={`flex items-start gap-3 rounded-xl ring-1 p-3.5 ${severityStyles[prompt.severity] ?? severityStyles.low}`}
          >
            <AlertTriangle size={14} className="mt-0.5 shrink-0" />
            <div className="flex-1 min-w-0">
              <p className="text-[13px] font-semibold leading-tight">{prompt.description ?? prompt.finding_key}</p>
              {prompt.deadline && (
                <p className="text-[11px] opacity-70 mt-0.5">
                  Deadline: {new Date(prompt.deadline).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                </p>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ─── Calendar Items ───────────────────────────────────────────────────────────

function CalendarItemsSection({ items }: { items: ActionCenterResponse['calendar_items'] }) {
  if (items.length === 0) return null;

  const eventLabels: Record<string, string> = {
    year_end_hsa: 'HSA election deadline',
    bonus_window: 'Bonus lead-time window',
    ira_deadline: 'IRA contribution deadline',
    open_enrollment: 'Open enrollment period',
  };

  return (
    <div className="mt-4">
      <p className="text-[11px] font-semibold uppercase tracking-widest text-sw-muted mb-2">
        Coming Up
      </p>
      <div className="space-y-2">
        {items.map((event) => (
          <div
            key={event.id}
            className="flex items-center gap-3 rounded-xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 p-3.5"
          >
            <div className="w-7 h-7 rounded-lg bg-violet-50 ring-1 ring-violet-200 flex items-center justify-center shrink-0">
              <Calendar size={14} className="text-violet-600" />
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-[13px] font-semibold text-sw-text leading-tight">
                {eventLabels[event.event_type] ?? event.event_type.replace(/_/g, ' ')}
              </p>
              {event.due_date && (
                <p className="text-[11px] text-sw-muted mt-0.5">
                  {new Date(event.due_date).toLocaleDateString('en-US', { month: 'long', day: 'numeric' })}
                </p>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ─── Main Component ───────────────────────────────────────────────────────────

interface Props {
  hasBankConnected: boolean;
}

export default function ActionCenterWidget({ hasBankConnected }: Props) {
  const { data, loading, error, refresh } = useApi<ActionCenterResponse>('/api/v1/optimizer/action-center', {
    enabled: hasBankConnected,
  });

  // Optimistic checklist done state
  const [doneIds, setDoneIds] = useState<Set<number>>(new Set());

  const handleDone = useCallback(
    async (id: number) => {
      // Optimistic update
      setDoneIds((prev) => new Set([...prev, id]));

      try {
        await axios.patch(`/api/v1/optimizer/checklist/items/${id}`, { done: true });
        // Silently refresh in background to sync server state
        refresh();
      } catch {
        // Revert optimistic update on failure
        setDoneIds((prev) => {
          const next = new Set(prev);
          next.delete(id);
          return next;
        });
      }
    },
    [refresh],
  );

  if (!hasBankConnected) return null;
  if (loading) return <ActionCenterSkeleton />;

  // Error state — show minimal retry
  if (error || !data) {
    return (
      <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-1 p-5">
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-[15px] font-semibold text-sw-text">Action Center</h2>
        </div>
        <div className="text-center py-4">
          <p className="text-sm text-sw-muted mb-3">Could not load action items</p>
          <button
            onClick={refresh}
            className="inline-flex items-center gap-2 text-xs text-sw-accent hover:text-sw-accent-hover transition"
          >
            <RefreshCw size={12} /> Retry
          </button>
        </div>
      </div>
    );
  }

  const visibleChecklistItems = data.checklist_items.filter((item) => !doneIds.has(item.id));

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 p-5 stagger-children">
      {/* Header */}
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-xl bg-sw-accent/10 ring-1 ring-sw-accent/20 flex items-center justify-center">
            <Zap size={16} className="text-sw-accent" />
          </div>
          <div>
            <h2 className="text-[15px] font-semibold text-sw-text leading-tight">Action Center</h2>
            {data.total_open > 0 && (
              <p className="text-[11px] text-sw-muted">
                {data.total_open} item{data.total_open !== 1 ? 's' : ''} waiting
              </p>
            )}
          </div>
        </div>
        <Link
          href="/optimize"
          className="text-xs text-sw-accent hover:text-sw-accent-hover transition font-medium"
        >
          View All
        </Link>
      </div>

      {/* Achievement empty state (ACT-05) */}
      {data.is_empty && <AchievementEmptyState />}

      {/* Stage-0 prerequisites */}
      {!data.is_empty && <Stage0Section items={data.stage0_items} />}

      {/* Optimization checklist actions */}
      {!data.is_empty && visibleChecklistItems.length > 0 && (
        <div className="mb-4">
          <p className="text-[11px] font-semibold uppercase tracking-widest text-sw-muted mb-2">
            Your Optimization Checklist
          </p>
          <div className="space-y-2">
            {visibleChecklistItems.slice(0, 4).map((item) => (
              <ChecklistItemCard
                key={item.id}
                item={item}
                onDone={handleDone}
                isDoneOptimistic={doneIds.has(item.id)}
              />
            ))}
            {visibleChecklistItems.length > 4 && (
              <Link
                href="/optimize"
                className="flex items-center justify-center gap-1.5 text-xs text-sw-accent hover:text-sw-accent-hover transition py-2"
              >
                +{visibleChecklistItems.length - 4} more actions
                <ArrowRight size={12} />
              </Link>
            )}
          </div>
        </div>
      )}

      {/* Monitor prompts */}
      {!data.is_empty && <MonitorPromptsSection items={data.monitor_prompts} />}

      {/* Calendar items */}
      {!data.is_empty && <CalendarItemsSection items={data.calendar_items} />}
    </div>
  );
}
