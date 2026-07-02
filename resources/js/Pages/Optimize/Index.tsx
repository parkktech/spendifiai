/**
 * Optimize/Index — Phase 12-05 Task 2 (UI-01, UI-02, UI-03) + Phase 14-10 (SCEN-01).
 *
 * Four-stage Optimize My Income page:
 *   Stage 1 — Findings list (ranked by severity, "what we noticed" framing)
 *   Stage 2 — Guided interview (reuses P11 InterviewCard + doc_affordance)
 *   Stage 3 — Scenario comparison + choose + mix panel (D.1–D.4)
 *   Stage 4 — Collapsible report view (OptimizationReportView)
 *
 * Guards: auth.hasBankConnected → ConnectBankPrompt when false.
 *
 * DESIGN (Decision 6/7 / Decision 12 — born-premium, don't replace):
 *   - sw-* tokens only; Inter type stack; 4px/8px rhythm; shadow-sw-* cards
 *   - Applied: ui-ux-pro-max "Financial Dashboard — Data-Dense" for findings
 *   - Applied: soft-skill — 16px rhythm, leading-relaxed, focus states
 *   - All copy uses may/could/consider (UI-03 educational framing)
 *   - Inline disclaimer on every surface (non-globally-dismissable)
 */

import { useState, useEffect, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import {
  TrendingUp,
  AlertTriangle,
  AlertCircle,
  Info,
  ArrowRight,
  BarChart2,
  Brain,
  CheckCircle,
  ChevronDown,
  ChevronUp,
  Loader2,
  GitCompare,
} from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import ConnectBankPrompt from '@/Components/SpendifiAI/ConnectBankPrompt';
import InterviewCard from '@/Components/SpendifiAI/InterviewCard';
import OptimizationReportView from '@/Components/SpendifiAI/OptimizationReportView';
import ObjectiveReadinessPanel from '@/Components/SpendifiAI/ObjectiveReadinessPanel';
import ScenarioComparisonCards from '@/Components/SpendifiAI/ScenarioComparisonCards';
import ScenarioMixPanel from '@/Components/SpendifiAI/ScenarioMixPanel';
import OptimizationChecklistView from '@/Components/SpendifiAI/OptimizationChecklistView';
import { useApi } from '@/hooks/useApi';
import axios from 'axios';
import type { ObjectivesResponse, ScenariosResponse } from '@/types/spendifiai';

// ─── Types ────────────────────────────────────────────────────────────────────

/** D19: structured narration fields for report sections and executive summary. */
interface NarratorStructured {
  summary: string;
  bullets: string[];
}

interface ReportSection {
  section_key: string;
  title: string;
  section_type: string;
  findings: Array<{
    finding_id: number;
    finding_type: string;
    severity: 'high' | 'medium' | 'low' | string;
    description: string | null;
    /** D19: structured narration — {hook, detail, action_cue}. */
    narration_structured?: { hook: string; detail: string; action_cue: string } | null;
    docs_missing?: string[];
    docs_captured?: number[];
    band?: 'auto' | 'conditional' | 'specialist' | null;
  }>;
  narrator_prose: string | null;
  /** D19: structured section narration — prefer over narrator_prose when present. */
  narrator_structured: NarratorStructured | null;
  disclaimer: string | null;
}

interface ReportData {
  id: number;
  tax_year: number;
  is_stale: boolean;
  status: 'generating' | 'ready';
  sections: ReportSection[];
  executive_summary: string | null;
  /** D19: structured executive summary — prefer over executive_summary when present. */
  executive_summary_structured: NarratorStructured | null;
  rebuilt_at: string | null;
  stale_since: string | null;
}

interface ReportResponse {
  report: ReportData;
}

type ViewMode = 'findings' | 'interview' | 'scenarios' | 'report';

// ─── Narration truncation helper ─────────────────────────────────────────────

/** Return the first sentence of `text`, clamped at `maxChars` chars with ellipsis. */
function firstSentence(text: string, maxChars = 140): string {
  // Try to break on sentence-ending punctuation
  const sentenceEnd = text.search(/[.!?]\s/);
  const clip = sentenceEnd !== -1 && sentenceEnd + 1 <= maxChars
    ? text.slice(0, sentenceEnd + 1)   // keep the punctuation
    : text.slice(0, maxChars);
  return clip.length < text.length ? clip + '…' : clip;
}

// ─── Findings summary card ────────────────────────────────────────────────────

function FindingSummaryCard({
  section,
  expandedFindingId,
  setExpandedFindingId,
}: {
  section: ReportSection;
  expandedFindingId: number | null;
  setExpandedFindingId: (id: number | null) => void;
}) {
  const highFindings = section.findings.filter((f) => f.severity === 'high');
  const medFindings = section.findings.filter((f) => f.severity === 'medium');

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 p-5 space-y-3 card-lift">
      <div className="flex items-start gap-3">
        <div className="w-9 h-9 rounded-xl bg-sw-accent/10 ring-1 ring-sw-accent/20 flex items-center justify-center shrink-0">
          <BarChart2 size={16} className="text-sw-accent" />
        </div>
        <div className="min-w-0">
          <p className="text-[14px] font-semibold text-sw-text leading-snug">{section.title}</p>
          <p className="text-[11px] text-sw-dim mt-0.5">
            {section.findings.length} area{section.findings.length !== 1 ? 's' : ''} to consider
          </p>
        </div>
        <div className="flex items-center gap-1.5 shrink-0">
          {highFindings.length > 0 && (
            <Badge variant="danger">{highFindings.length} high</Badge>
          )}
          {medFindings.length > 0 && (
            <Badge variant="warning">{medFindings.length} review</Badge>
          )}
        </div>
      </div>

      {/* D19: Findings preview — hook from narration_structured (born-structured), or first-sentence clamp. */}
      {section.findings.slice(0, 3).map((finding) => {
        const isExpanded = expandedFindingId === finding.finding_id;
        // D19: prefer structured hook; fall back to description with clamp.
        const structured = finding.narration_structured ?? null;
        const hook = structured?.hook ?? null;
        const desc = finding.description ?? null;
        const displayText = hook ?? (desc ? firstSentence(desc) : null);
        const fullText = hook ?? desc;
        const needsExpand = !hook && desc !== null && firstSentence(desc) !== desc;

        return (
          <div key={finding.finding_id} className="pl-3 border-l-2 border-sw-border">
            {displayText ? (
              <>
                <p className="text-[12px] text-sw-text-secondary leading-relaxed">
                  {isExpanded && !hook ? fullText : displayText}
                </p>
                {/* D19: expand shows detail + action_cue from structured contract */}
                {isExpanded && structured && (
                  <div className="mt-1.5 space-y-1">
                    <p className="text-[11px] text-sw-muted leading-relaxed">{structured.detail}</p>
                    <p className="text-[11px] text-sw-dim italic">{structured.action_cue}</p>
                  </div>
                )}
                {(needsExpand || (structured?.detail)) && (
                  <button
                    onClick={() => setExpandedFindingId(isExpanded ? null : finding.finding_id)}
                    className="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-sw-muted hover:text-sw-text transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sw-accent/50 rounded"
                    aria-expanded={isExpanded}
                  >
                    {isExpanded ? (
                      <>Show less <ChevronUp size={12} /></>
                    ) : (
                      <>Read more <ChevronDown size={12} /></>
                    )}
                  </button>
                )}
              </>
            ) : (
              <p className="text-[12px] text-sw-dim italic">Analysis in progress...</p>
            )}
          </div>
        );
      })}
      {section.findings.length > 3 && (
        <p className="text-[11px] text-sw-dim pl-3">
          + {section.findings.length - 3} more in the full report
        </p>
      )}

      {/* D19: Narrator — compose from structured fields when present, fallback to prose clamp */}
      {(section.narrator_structured || section.narrator_prose) && (
        <div className="border-t border-sw-border/60 pt-3 space-y-1.5">
          {section.narrator_structured ? (
            <>
              <p className="text-[12px] text-sw-muted leading-relaxed">
                {section.narrator_structured.summary}
              </p>
              {section.narrator_structured.bullets.length > 0 && (
                <ul className="space-y-0.5 pl-3">
                  {section.narrator_structured.bullets.map((bullet, i) => (
                    <li key={i} className="text-[11px] text-sw-dim flex items-start gap-1.5">
                      <span className="mt-1.5 w-1 h-1 rounded-full bg-sw-dim shrink-0" />
                      {bullet}
                    </li>
                  ))}
                </ul>
              )}
            </>
          ) : (
            <p className="text-[12px] text-sw-muted leading-relaxed line-clamp-2">
              {section.narrator_prose}
            </p>
          )}
        </div>
      )}
    </div>
  );
}

// ─── Stage indicator ──────────────────────────────────────────────────────────

function StageIndicator({
  current,
  onSelect,
}: {
  current: ViewMode;
  onSelect: (m: ViewMode) => void;
}) {
  const stages: { key: ViewMode; label: string }[] = [
    { key: 'findings', label: 'Findings' },
    { key: 'interview', label: 'Interview' },
    { key: 'scenarios', label: 'Choices' },
    { key: 'report', label: 'Report' },
  ];
  const idx = stages.findIndex((s) => s.key === current);

  return (
    <nav aria-label="Optimize stages" className="flex items-center gap-0 rounded-xl border border-sw-border bg-sw-card overflow-hidden">
      {stages.map((stage, i) => (
        <button
          key={stage.key}
          onClick={() => onSelect(stage.key)}
          className={`flex-1 px-4 py-2.5 text-xs font-semibold transition-colors border-r border-sw-border last:border-r-0 ${
            i <= idx
              ? 'bg-sw-accent text-white'
              : 'text-sw-muted hover:text-sw-text hover:bg-sw-surface'
          }`}
        >
          {stage.label}
        </button>
      ))}
    </nav>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function OptimizeIndex() {
  const { auth } = usePage().props as unknown as {
    auth: { hasBankConnected: boolean; pendingOptimizationCount?: number };
  };

  const currentYear = new Date().getFullYear();
  const [viewMode, setViewMode] = useState<ViewMode>('findings');
  const [regenerating, setRegenerating] = useState(false);
  // Task 1: one-at-a-time expand for finding narrations (shared across all section cards)
  const [expandedFindingId, setExpandedFindingId] = useState<number | null>(null);
  // Task 3: 429 / rate-limit state — show cached data with gentle note
  const [rateLimited, setRateLimited] = useState(false);

  // Phase 14-10: Scenarios stage state
  const [enqueueing, setEnqueueing] = useState(false);
  const [enqueueError, setEnqueueError] = useState<string | null>(null);

  // Fetch objectives readiness (for scenarios stage)
  const {
    data: objectivesData,
    loading: objectivesLoading,
    refresh: refreshObjectives,
  } = useApi<ObjectivesResponse>(`/api/v1/optimizer/objectives/${currentYear}`, {
    enabled: auth.hasBankConnected && viewMode === 'scenarios',
  });

  // Fetch scenarios (for comparison cards)
  const {
    data: scenariosData,
    loading: scenariosLoading,
    refresh: refreshScenarios,
  } = useApi<ScenariosResponse>(`/api/v1/optimizer/scenarios/${currentYear}`, {
    enabled: auth.hasBankConnected && viewMode === 'scenarios',
  });

  const handleEnqueue = async () => {
    setEnqueueing(true);
    setEnqueueError(null);
    try {
      await axios.post(`/api/v1/optimizer/objectives/${currentYear}/enqueue`);
      refreshScenarios();
    } catch (err) {
      const msg =
        (err as { response?: { data?: { message?: string } } })?.response?.data?.message ??
        'Could not start scenario computation';
      setEnqueueError(msg);
    } finally {
      setEnqueueing(false);
    }
  };

  const handleChooseScenario = async (optionKey: string) => {
    try {
      await axios.post(`/api/v1/optimizer/scenarios/${currentYear}/choose`, {
        option_key: optionKey,
      });
      refreshScenarios();
      // Advance to report after choosing
      setTimeout(() => setViewMode('report'), 800);
    } catch {
      // ignore — user can retry
    }
  };

  // Fetch the report (includes sections/findings).
  // Data is cached in React state — does NOT refetch on tab switches.
  const {
    data: reportResponse,
    loading: reportLoading,
    error: reportError,
    refresh: refreshReport,
  } = useApi<ReportResponse>(`/api/v1/optimizer/report/${currentYear}`, {
    enabled: auth.hasBankConnected,
  });

  const report = reportResponse?.report ?? null;
  const allFindings = (report?.sections ?? []).flatMap((s) => s.findings);
  const highCount = allFindings.filter((f) => f.severity === 'high').length;
  const totalCount = allFindings.length;

  // Task 3: Poll for ready status while report is generating (stop once ready).
  // Polls every 8 seconds; stops automatically when status flips to 'ready'.
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    const isGenerating = report?.status === 'generating' || (!report && !reportLoading && !reportError);

    if (isGenerating && auth.hasBankConnected) {
      // Start polling if not already running
      if (!pollRef.current) {
        pollRef.current = setInterval(() => {
          refreshReport();
        }, 8000);
      }
    } else {
      // Report is ready (or errored) — stop polling
      if (pollRef.current) {
        clearInterval(pollRef.current);
        pollRef.current = null;
      }
    }

    return () => {
      if (pollRef.current) {
        clearInterval(pollRef.current);
        pollRef.current = null;
      }
    };
  }, [report?.status, reportLoading, reportError, auth.hasBankConnected, refreshReport]);

  const handleRegenerate = async () => {
    setRegenerating(true);
    setRateLimited(false);
    try {
      await axios.post(`/api/v1/optimizer/report/${currentYear}/regenerate`);
      // Polling loop (above) will pick up the ready state automatically
      setTimeout(() => {
        refreshReport();
        setRegenerating(false);
      }, 3000);
    } catch (err: unknown) {
      const status = (err as { response?: { status?: number } })?.response?.status;
      if (status === 429) {
        // Rate-limited on regenerate — show cached data with gentle note
        setRateLimited(true);
      }
      setRegenerating(false);
    }
  };

  // ── No bank ──────────────────────────────────────────────────────────────────
  if (!auth.hasBankConnected) {
    return (
      <AuthenticatedLayout
        header={
          <div>
            <h1 className="text-xl font-bold text-sw-text tracking-tight">Optimize My Income</h1>
            <p className="text-xs text-sw-dim mt-0.5">Discover potential tax opportunities — educational only</p>
          </div>
        }
      >
        <Head title="Optimize My Income" />
        <ConnectBankPrompt
          feature="income optimization"
          description="Link your bank account so we can analyze your income patterns and surface potential opportunities to review with a tax professional."
        />
      </AuthenticatedLayout>
    );
  }

  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-[28px] font-[800] text-sw-text tracking-[-0.03em] leading-tight">Optimize My Income</h1>
          <p className="text-xs text-sw-muted mt-0.5">Educational review — not tax advice</p>
        </div>
      }
    >
      <Head title="Optimize My Income" />

      {/* Page-level educational disclaimer (UI-03 — inline, non-globally-dismissable) */}
      <div className="mb-5 flex items-start gap-2.5 rounded-xl border border-sw-info/20 bg-sw-info-light/40 px-4 py-3">
        <Info size={14} className="text-sw-info shrink-0 mt-0.5" />
        <p className="text-[12px] text-sw-text-secondary leading-relaxed">
          <span className="font-semibold text-sw-text">Educational review only.</span>{' '}
          The analysis below may highlight areas worth discussing with a qualified tax professional.
          Nothing here constitutes tax advice, and any figures shown are estimates only. Consult a
          licensed professional before making financial decisions.
        </p>
      </div>

      {/* Stage navigator */}
      <div className="mb-5">
        <StageIndicator current={viewMode} onSelect={setViewMode} />
      </div>

      {/* Task 3: 429 / rate-limit gentle note (shows cached data, never hard error) */}
      {rateLimited && (
        <div className="mb-4 flex items-start gap-2 rounded-xl border border-sw-warning/30 bg-sw-warning/5 px-4 py-3">
          <Info size={13} className="text-sw-warning shrink-0 mt-0.5" />
          <p className="text-[12px] text-sw-text-secondary leading-relaxed">
            Refreshing shortly — the data below may be a moment behind.
            <button
              onClick={() => { setRateLimited(false); refreshReport(); }}
              className="ml-1.5 underline text-sw-accent hover:text-sw-accent-hover transition-colors"
            >
              Try now
            </button>
          </p>
        </div>
      )}

      {/* Summary stats (findings stage only) */}
      {viewMode === 'findings' && !reportLoading && report && totalCount > 0 && (
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-5 stagger-children">
          <div className="rounded-xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 p-3.5 card-lift">
            <p className="text-[11px] text-sw-muted font-medium">Total Findings</p>
            <p className="text-2xl font-bold text-sw-text mt-0.5 font-tabular">{totalCount}</p>
          </div>
          <div className="rounded-xl ring-1 ring-sw-danger/30 bg-sw-danger/5 shadow-sw-1 p-3.5 card-lift">
            <p className="text-[11px] text-sw-danger font-medium">High Priority</p>
            <p className="text-2xl font-bold text-sw-text mt-0.5 font-tabular">{highCount}</p>
          </div>
          <div className="rounded-xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 p-3.5 col-span-2 sm:col-span-1 card-lift">
            <p className="text-[11px] text-sw-muted font-medium">Tax Year</p>
            <p className="text-2xl font-bold text-sw-text mt-0.5 font-tabular">{currentYear}</p>
          </div>
        </div>
      )}

      {/* ── Stage 1: Findings ─────────────────────────────────────────────────── */}
      {viewMode === 'findings' && (
        <div className="space-y-4">
          <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-2">
              <TrendingUp size={16} className="text-sw-accent" />
              <h2 className="text-sm font-semibold text-sw-text">What We Noticed</h2>
            </div>
            <button
              onClick={() => setViewMode('interview')}
              className="inline-flex items-center gap-1.5 text-xs font-medium text-sw-accent hover:text-sw-accent-hover transition"
            >
              Start Income Review <ArrowRight size={13} />
            </button>
          </div>

          {/* Loading skeleton (§3.10 — replaces spinner) */}
          {reportLoading && (
            <div className="space-y-3 animate-pulse">
              {[1, 2, 3].map((i) => (
                <div key={i} className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 p-5">
                  <div className="flex items-start gap-3 mb-3">
                    <div className="w-9 h-9 rounded-xl bg-slate-200 shrink-0" />
                    <div className="flex-1 space-y-1.5">
                      <div className="h-4 bg-slate-200 rounded w-3/4" />
                      <div className="h-3 bg-slate-100 rounded w-1/2" />
                    </div>
                  </div>
                  <div className="space-y-2 pl-12">
                    <div className="h-3 bg-slate-100 rounded w-full" />
                    <div className="h-3 bg-slate-100 rounded w-4/5" />
                  </div>
                </div>
              ))}
            </div>
          )}

          {/* Error — but if we have cached data, show it instead of a hard error block.
               429 specifically means rate-limited; show the gentle rate-limit note above. */}
          {reportError && !report && (
            <div className="rounded-2xl border border-sw-danger/30 bg-sw-danger/5 p-6 text-center">
              <AlertTriangle size={22} className="mx-auto text-sw-danger mb-2" />
              <p className="text-sm text-sw-text">{reportError}</p>
            </div>
          )}

          {/* Generating state — born-premium §3.9 empty/waiting state */}
          {!reportLoading && !reportError && (!report || report.status === 'generating') && (
            <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-1 p-8 text-center space-y-3">
              <div className="w-12 h-12 mx-auto rounded-2xl bg-sw-accent/10 ring-1 ring-sw-accent/20 flex items-center justify-center">
                <Loader2 size={22} className="animate-spin text-sw-accent" />
              </div>
              <p className="text-sm font-semibold text-sw-text">Analyzing your financial data...</p>
              <p className="text-xs text-sw-muted max-w-xs mx-auto leading-relaxed">
                Your income optimization analysis may take a moment to prepare. This page updates automatically.
              </p>
            </div>
          )}

          {/* Findings list — show cached data even when there's a (non-fatal) error */}
          {!reportLoading && report && report.status === 'ready' && (
            <>
              {report.sections.length === 0 ? (
                <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-1 p-8 text-center">
                  <div className="w-12 h-12 mx-auto rounded-2xl bg-sw-success/10 ring-1 ring-sw-success/30 flex items-center justify-center mb-3">
                    <CheckCircle size={22} className="text-sw-success" />
                  </div>
                  <h3 className="text-[15px] font-semibold text-sw-text mb-1">No findings yet</h3>
                  <p className="text-xs text-sw-muted max-w-xs mx-auto leading-relaxed">
                    Connect your bank and complete the income review to see potential optimization areas.
                  </p>
                </div>
              ) : (
                <div className="space-y-3">
                  {/* Sort sections: high-priority findings first */}
                  {[...report.sections]
                    .sort((a, b) => {
                      const aHigh = a.findings.filter((f) => f.severity === 'high').length;
                      const bHigh = b.findings.filter((f) => f.severity === 'high').length;
                      return bHigh - aHigh;
                    })
                    .map((section) => (
                      <FindingSummaryCard
                        key={section.section_key}
                        section={section}
                        expandedFindingId={expandedFindingId}
                        setExpandedFindingId={setExpandedFindingId}
                      />
                    ))}
                  {/* CTA to start interview */}
                  <div className="rounded-2xl border border-sw-accent/20 bg-sw-accent/5 p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div className="flex items-start gap-3">
                      <Brain size={20} className="text-sw-accent shrink-0 mt-0.5" />
                      <div>
                        <p className="text-[14px] font-semibold text-sw-text">Ready to dig deeper?</p>
                        <p className="text-[12px] text-sw-muted leading-relaxed mt-0.5">
                          Answer a few guided questions and we may be able to refine these findings for your situation.
                        </p>
                      </div>
                    </div>
                    <button
                      onClick={() => setViewMode('interview')}
                      className="self-start shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition shadow-sm"
                    >
                      Start Interview <ArrowRight size={14} />
                    </button>
                  </div>
                </div>
              )}
            </>
          )}

          {/* Findings disclaimer (UI-03) */}
          <div className="flex items-start gap-2 rounded-xl border border-sw-border/60 bg-sw-surface px-4 py-3">
            <AlertCircle size={12} className="text-sw-dim shrink-0 mt-0.5" />
            <p className="text-[10px] text-sw-dim leading-relaxed">
              Findings are derived from transaction patterns and available documents. They may or may
              not apply to your specific tax situation. Consider consulting a qualified tax professional
              before acting on any of the above.
            </p>
          </div>
        </div>
      )}

      {/* ── Stage 2: Interview ────────────────────────────────────────────────── */}
      {viewMode === 'interview' && (
        <div className="space-y-4">
          <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-2">
              <Brain size={16} className="text-sw-info" />
              <h2 className="text-sm font-semibold text-sw-text">Income Review Interview</h2>
              <span className="text-[11px] text-sw-dim">one question at a time</span>
            </div>
            <button
              onClick={() => setViewMode('report')}
              className="inline-flex items-center gap-1.5 text-xs font-medium text-sw-accent hover:text-sw-accent-hover transition"
            >
              View Full Report <ArrowRight size={13} />
            </button>
          </div>

          {/* Reuse P11 InterviewCard unchanged */}
          <InterviewCard
            taxYear={currentYear}
            onAnswered={() => {
              // After each answer, we can auto-advance to report when complete
            }}
          />

          <div className="flex flex-col sm:flex-row items-center justify-center gap-3 pt-2">
            <button
              onClick={() => setViewMode('scenarios')}
              className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition shadow-sw-1"
            >
              <GitCompare size={14} /> See your options
            </button>
            <button
              onClick={() => setViewMode('report')}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-sw-border text-sm text-sw-muted hover:text-sw-text transition"
            >
              Skip to Report <ArrowRight size={14} />
            </button>
          </div>
        </div>
      )}

      {/* ── Stage 3: Scenarios — Choices ──────────────────────────────────────── */}
      {viewMode === 'scenarios' && (
        <div className="space-y-5">
          <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-2">
              <GitCompare size={16} className="text-sw-accent" />
              <h2 className="text-sm font-semibold text-sw-text">Your Optimization Options</h2>
            </div>
            <button
              onClick={() => setViewMode('report')}
              className="inline-flex items-center gap-1.5 text-xs font-medium text-sw-accent hover:text-sw-accent-hover transition"
            >
              Full Report <ArrowRight size={13} />
            </button>
          </div>

          {/* Objectives readiness */}
          {objectivesLoading && (
            <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-1 p-5 animate-pulse">
              <div className="h-4 w-40 bg-slate-200 rounded mb-3" />
              <div className="space-y-2">
                {[1, 2, 3].map((i) => <div key={i} className="h-12 bg-slate-100 rounded-xl" />)}
              </div>
            </div>
          )}
          {!objectivesLoading && objectivesData && (
            <ObjectiveReadinessPanel
              data={objectivesData}
              onEnqueue={handleEnqueue}
              enqueueing={enqueueing}
              enqueueError={enqueueError}
            />
          )}

          {/* Scenario comparison cards */}
          {scenariosLoading && (
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 animate-pulse">
              {[1, 2, 3].map((i) => (
                <div key={i} className="h-48 bg-slate-100 rounded-xl" />
              ))}
            </div>
          )}
          {!scenariosLoading && scenariosData && scenariosData.options.length > 0 && (
            <ScenarioComparisonCards
              data={scenariosData}
              onChoose={handleChooseScenario}
            />
          )}
          {!scenariosLoading && scenariosData && scenariosData.options.length === 0 && (
            <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-1 p-6 text-center">
              <GitCompare size={24} className="mx-auto text-sw-dim mb-2" />
              <p className="text-sm font-medium text-sw-text mb-1">Scenarios not yet computed</p>
              <p className="text-xs text-sw-muted max-w-xs mx-auto">
                Complete the readiness check above and click "See your optimization options" to generate scenario comparisons.
              </p>
            </div>
          )}

          {/* Mix panel */}
          <ScenarioMixPanel taxYear={currentYear} />

          {/* Checklist (materialized after choosing a scenario) */}
          {scenariosData?.chosen && (
            <OptimizationChecklistView
              taxYear={currentYear}
              hasBankConnected={auth.hasBankConnected}
            />
          )}
        </div>
      )}

      {/* ── Stage 4: Report ───────────────────────────────────────────────────── */}
      {viewMode === 'report' && (
        <div className="space-y-4">
          <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-2">
              <TrendingUp size={16} className="text-sw-accent" />
              <h2 className="text-sm font-semibold text-sw-text">Your Optimization Report</h2>
            </div>
          </div>

          {/* D7: chosen plan checklist mirror in the report stage */}
          {scenariosData?.chosen && (
            <OptimizationChecklistView
              taxYear={currentYear}
              hasBankConnected={auth.hasBankConnected}
            />
          )}

          <OptimizationReportView
            taxYear={currentYear}
            report={report}
            loading={reportLoading}
            error={reportError}
            onRegenerate={handleRegenerate}
            regenerating={regenerating}
          />
        </div>
      )}
    </AuthenticatedLayout>
  );
}
