/**
 * Optimize/Index — ONE-JOURNEY consolidation (14-10 corrective).
 *
 * Single top-to-bottom journey (no peer tabs for sub-stages):
 *   Overview stage (conditional inner steps):
 *     1. Upload (DocumentUploadFlow) — only if action-center has upload_paystub item
 *     2. Reveal ("What your paystub told us") — ProposalConfirmCard list, only if proposals exist
 *     3. Doc follow-ups — other stage0 connectivity items as inline prompts
 *     4. Gap questions ("A few questions we couldn't answer from your documents")
 *        — InterviewCard inline, only if do_interview in stage0
 *     5. Findings list (ranked by severity)
 *     6. CTA to Choices
 *   Choices stage — ScenarioComparisonCards + MixPanel (was 'scenarios')
 *   Checklist stage — OptimizationChecklistView (standalone)
 *   Report stage — OptimizationReportView (accessible via nav)
 *
 * Progress indicators: Overview | Choices | Checklist | Report
 * Interview tab REMOVED — absorbed inline into Overview as a journey step.
 * Interview machinery (endpoints, InterviewCard, DB) is UNCHANGED.
 *
 * Guards: auth.hasBankConnected → ConnectBankPrompt when false.
 *
 * DESIGN (Decision 6/7 / Decision 12 — born-premium, elevate not replace):
 *   - sw-* tokens only; Inter type stack; 4px/8px rhythm; shadow-sw-* cards
 *   - Applied: ui-ux-pro-max "Financial Dashboard — Data-Dense"
 *   - Applied: soft-skill — 16px rhythm, leading-relaxed, focus states
 *   - All copy uses may/could/consider (UI-03 educational framing)
 *   - Inline disclaimer on every surface (non-globally-dismissable)
 */

import { useState, useEffect, useRef, useCallback } from 'react';
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
  Upload,
  Sparkles,
  RefreshCw,
  Link2,
  ChevronRight,
  Plus,
} from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import ConnectBankPrompt from '@/Components/SpendifiAI/ConnectBankPrompt';
import InterviewCard from '@/Components/SpendifiAI/InterviewCard';
import OptimizationReportView from '@/Components/SpendifiAI/OptimizationReportView';
import ObjectiveReadinessPanel from '@/Components/SpendifiAI/ObjectiveReadinessPanel';
import ScenarioComparisonCards from '@/Components/SpendifiAI/ScenarioComparisonCards';
import ScenarioMixPanel from '@/Components/SpendifiAI/ScenarioMixPanel';
import OptimizationChecklistView from '@/Components/SpendifiAI/OptimizationChecklistView';
import DocumentUploadFlow from '@/Components/SpendifiAI/DocumentUploadFlow';
import ProposalConfirmCard from '@/Components/SpendifiAI/ProposalConfirmCard';
import { useApi } from '@/hooks/useApi';
import axios from 'axios';
import { computeAccordionDefault } from '@/utils/accordionDefault';
import type {
  ObjectivesResponse,
  ScenariosResponse,
  ActionCenterResponse,
  ActionCenterStage0Item,
  DurableFactsResponse,
  UserTaxFactView,
} from '@/types/spendifiai';

// ─── Type-status shape (mirrors DocumentUploadFlow.DocTypeStatus) ─────────────

interface DocTypeStatus {
  has_ready_doc: boolean;
  latest_uploaded_at: string | null;
  ready_count: number;
  extracted_fields_count: number;
}

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

type ViewMode = 'overview' | 'choices' | 'checklist' | 'report';

// ─── Narration truncation helper ─────────────────────────────────────────────

/** Return the first sentence of `text`, clamped at `maxChars` chars with ellipsis. */
function firstSentence(text: string, maxChars = 140): string {
  const sentenceEnd = text.search(/[.!?]\s/);
  const clip = sentenceEnd !== -1 && sentenceEnd + 1 <= maxChars
    ? text.slice(0, sentenceEnd + 1)
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

      {/* D19: Findings preview — hook from narration_structured, or first-sentence clamp. */}
      {section.findings.slice(0, 3).map((finding) => {
        const isExpanded = expandedFindingId === finding.finding_id;
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

      {/* D19: Narrator — structured fields when present, fallback to prose clamp */}
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

// ─── Stage indicator (progress bar — not peer tabs) ───────────────────────────

function StageIndicator({
  current,
  onSelect,
}: {
  current: ViewMode;
  onSelect: (m: ViewMode) => void;
}) {
  const stages: { key: ViewMode; label: string }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'choices', label: 'Choices' },
    { key: 'checklist', label: 'Checklist' },
    { key: 'report', label: 'Report' },
  ];
  const idx = stages.findIndex((s) => s.key === current);

  return (
    <nav aria-label="Optimize journey stages" className="flex items-center gap-0 rounded-xl border border-sw-border bg-sw-card overflow-hidden">
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

// ─── Doc follow-up inline card ────────────────────────────────────────────────

/** Inline prompt for connectivity/prerequisite items that aren't document uploads. */
function DocFollowUpCard({ item }: { item: ActionCenterStage0Item }) {
  const iconMap: Record<string, React.ReactNode> = {
    link_bank: <Link2 size={14} className="text-sw-accent" />,
    link_credit_cards: <Link2 size={14} className="text-sw-accent" />,
    link_email: <Link2 size={14} className="text-sw-accent" />,
  };

  return (
    <a
      href={item.cta_url}
      className="flex items-start gap-3 p-4 rounded-xl border border-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 hover:border-sw-accent/40 hover:shadow-sw-1 transition group"
    >
      <div className="w-8 h-8 rounded-lg bg-sw-accent/10 ring-1 ring-sw-accent/20 flex items-center justify-center shrink-0 mt-0.5">
        {iconMap[item.key] ?? <ChevronRight size={14} className="text-sw-accent" />}
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-[13px] font-semibold text-sw-text leading-snug">{item.title}</p>
        <p className="text-[11px] text-sw-muted leading-relaxed mt-0.5">{item.description}</p>
      </div>
      <ArrowRight size={13} className="text-sw-dim group-hover:text-sw-accent transition shrink-0 mt-1" />
    </a>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function OptimizeIndex() {
  const { auth } = usePage().props as unknown as {
    auth: {
      hasBankConnected: boolean;
      pendingOptimizationCount?: number;
      user?: { id: number };
    };
  };

  const currentYear = new Date().getFullYear();

  // ── localStorage key for the "Add more documents" accordion preference ─────────
  // Scoped to the user so different users on the same browser don't share state.
  const accordionStorageKey = `sw_upload_accordion_${auth.user?.id ?? 'anon'}`;

  // ── Type-status inventory (fetched at the page level for accordion default) ───
  const [typeStatus, setTypeStatus] = useState<Record<string, DocTypeStatus>>({});

  // ── "Add more documents" accordion — Fix-3 tri-state ───────────────────────
  //
  // Priority:
  //   1. User's manual preference (localStorage key) — wins over auto-defaults.
  //   2. All doc types populated → auto-collapse.
  //   3. Any type missing → auto-expand (show remaining work).
  //
  // On first mount we read the localStorage preference (null if unset) and
  // compute the initial state from the persisted preference + current typeStatus
  // (empty on mount = loading state → expand until we know otherwise).
  const readStoredPreference = (): boolean | null => {
    try {
      const raw = localStorage.getItem(accordionStorageKey);
      if (raw === 'true') return true;
      if (raw === 'false') return false;
    } catch {
      // localStorage unavailable (private mode, etc.) — fall through to auto logic
    }
    return null;
  };

  const [addMoreExpanded, setAddMoreExpanded] = useState<boolean>(() =>
    computeAccordionDefault({}, readStoredPreference()),
  );

  // ── Sync accordion default when type-status loads ────────────────────────────
  // If the user has NOT set a manual preference, recompute and auto-collapse
  // once we discover all types are populated. This handles the case where the
  // user finishes uploading all types in the current session.
  useEffect(() => {
    if (Object.keys(typeStatus).length === 0) return; // still loading
    const pref = readStoredPreference();
    if (pref !== null) return; // user's explicit choice wins — don't override
    setAddMoreExpanded(computeAccordionDefault(typeStatus, null));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [typeStatus]);

  // ── Toggle handler — persists the user's manual choice to localStorage ────────
  const handleAccordionToggle = () => {
    setAddMoreExpanded((prev) => {
      const next = !prev;
      try {
        localStorage.setItem(accordionStorageKey, String(next));
      } catch {
        // localStorage unavailable — still update in-memory state
      }
      return next;
    });
  };

  const [viewMode, setViewMode] = useState<ViewMode>('overview');
  const [regenerating, setRegenerating] = useState(false);
  // Task 1: one-at-a-time expand for finding narrations (shared across all section cards)
  const [expandedFindingId, setExpandedFindingId] = useState<number | null>(null);
  // 429 / rate-limit state — show cached data with gentle note
  const [rateLimited, setRateLimited] = useState(false);
  // Dismissed proposal IDs (rejected locally)
  const [dismissedProposalIds, setDismissedProposalIds] = useState<Set<number>>(new Set());

  // Phase 14-10: Scenarios/Choices stage state
  const [enqueueing, setEnqueueing] = useState(false);
  const [enqueueError, setEnqueueError] = useState<string | null>(null);

  // Action center — stage0 items tell us what the user still needs to do
  const {
    data: actionCenterData,
    refresh: refreshActionCenter,
  } = useApi<ActionCenterResponse>('/api/v1/optimizer/action-center', {
    enabled: auth.hasBankConnected,
  });

  // Facts API — proposals (unconfirmed extractions from uploaded documents)
  const {
    data: factsData,
    loading: factsLoading,
    refresh: refreshFacts,
  } = useApi<DurableFactsResponse>('/api/v1/optimizer/facts', {
    enabled: auth.hasBankConnected,
  });

  // ── Type-status fetch (page-level, for accordion default) ────────────────────
  // Also fetched inside DocumentUploadFlow on mount; both are cheap and cached.
  const fetchTypeStatus = useCallback(() => {
    if (!auth.hasBankConnected) return;
    axios.get<{ types: Record<string, DocTypeStatus> }>('/api/v1/tax-vault/type-status')
      .then((res) => setTypeStatus(res.data.types ?? {}))
      .catch(() => { /* silently ignore — accordion falls back to expanded */ });
  }, [auth.hasBankConnected]);

  useEffect(() => { fetchTypeStatus(); }, [fetchTypeStatus]);

  // Derive journey state from action-center + facts
  const stage0Items = actionCenterData?.stage0_items ?? [];
  const needsDocUpload = stage0Items.some((i) => i.key === 'upload_paystub');
  const needsInterview = stage0Items.some((i) => i.key === 'do_interview');
  const followUpItems = stage0Items.filter(
    (i) => i.key !== 'upload_paystub' && i.key !== 'do_interview'
  );
  const proposals = (factsData?.proposals ?? []).filter(
    (p) => !dismissedProposalIds.has(p.id)
  );
  const hasPendingProposals = proposals.length > 0;

  const handleUploadComplete = useCallback(() => {
    // Give extraction job a moment, then refresh facts + action center + type-status
    setTimeout(() => {
      refreshFacts();
      refreshActionCenter();
      fetchTypeStatus();
    }, 4000);
  }, [refreshFacts, refreshActionCenter, fetchTypeStatus]);

  const handleProposalConfirmed = useCallback((id: number) => {
    setTimeout(() => refreshFacts(), 500);
  }, [refreshFacts]);

  const handleProposalRejected = useCallback((id: number) => {
    setDismissedProposalIds((prev) => new Set(prev).add(id));
  }, []);

  // Fetch objectives readiness (for choices stage)
  const {
    data: objectivesData,
    loading: objectivesLoading,
    refresh: refreshObjectives,
  } = useApi<ObjectivesResponse>(`/api/v1/optimizer/objectives/${currentYear}`, {
    enabled: auth.hasBankConnected && viewMode === 'choices',
  });

  // Fetch scenarios (for comparison cards)
  const {
    data: scenariosData,
    loading: scenariosLoading,
    refresh: refreshScenarios,
  } = useApi<ScenariosResponse>(`/api/v1/optimizer/scenarios/${currentYear}`, {
    enabled: auth.hasBankConnected && viewMode === 'choices',
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
      // Advance to checklist after choosing
      setTimeout(() => setViewMode('checklist'), 800);
    } catch {
      // ignore — user can retry
    }
  };

  // Fetch the report (includes sections/findings).
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

  // Poll for ready status while report is generating (stop once ready).
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    const isGenerating = report?.status === 'generating' || (!report && !reportLoading && !reportError);

    if (isGenerating && auth.hasBankConnected) {
      if (!pollRef.current) {
        pollRef.current = setInterval(() => {
          refreshReport();
        }, 8000);
      }
    } else {
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
      setTimeout(() => {
        refreshReport();
        setRegenerating(false);
      }, 3000);
    } catch (err: unknown) {
      const status = (err as { response?: { status?: number } })?.response?.status;
      if (status === 429) {
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

      {/* Journey progress indicator */}
      <div className="mb-5">
        <StageIndicator current={viewMode} onSelect={setViewMode} />
      </div>

      {/* 429 / rate-limit gentle note */}
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

      {/* ── Overview stage ────────────────────────────────────────────────────── */}
      {viewMode === 'overview' && (
        <div className="space-y-6">

          {/* ── 1. Upload section — ALWAYS present (Fix 4: never disappear) ── */}
          {needsDocUpload ? (
            /* Upload hero — shown when paystub is missing/stale */
            <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 p-5 space-y-4">
              <div className="flex items-start gap-3">
                <div className="w-9 h-9 rounded-xl bg-sw-accent/10 ring-1 ring-sw-accent/20 flex items-center justify-center shrink-0">
                  <Upload size={16} className="text-sw-accent" />
                </div>
                <div>
                  <p className="text-[15px] font-semibold text-sw-text leading-snug">Upload a recent pay stub</p>
                  <p className="text-[12px] text-sw-muted leading-relaxed mt-0.5">
                    A pay stub unlocks withholding analysis and retirement contribution calibration.
                    It only asks for things it doesn't yet have.
                  </p>
                </div>
              </div>
              <DocumentUploadFlow onComplete={handleUploadComplete} />
            </div>
          ) : (
            /* "Add more documents" accordion — Fix 3: expanded by default while any
               doc type lacks a ready doc; auto-collapses when all types filled;
               user's manual collapse/expand persists via localStorage. */
            <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 overflow-hidden">
              <button
                onClick={handleAccordionToggle}
                className="w-full flex items-center justify-between px-5 py-3.5 text-left hover:bg-sw-surface/40 transition group"
                aria-expanded={addMoreExpanded}
              >
                <div className="flex items-center gap-2.5">
                  <div className="w-7 h-7 rounded-lg bg-sw-accent/10 flex items-center justify-center shrink-0">
                    <Plus size={13} className="text-sw-accent" />
                  </div>
                  <span className="text-[13px] font-semibold text-sw-text">Add more documents</span>
                  <span className="text-[11px] text-sw-dim">W-2, benefits guide, retirement statement…</span>
                </div>
                {addMoreExpanded
                  ? <ChevronUp size={14} className="text-sw-dim" />
                  : <ChevronDown size={14} className="text-sw-dim" />}
              </button>
              {addMoreExpanded && (
                <div className="px-5 pb-5 pt-1 border-t border-sw-border/60">
                  <DocumentUploadFlow onComplete={handleUploadComplete} />
                </div>
              )}
            </div>
          )}

          {/* ── 2. Reveal — "What your paystub told us" (only if proposals exist) ── */}
          {(hasPendingProposals || factsLoading) && (
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Sparkles size={15} className="text-sw-info" />
                  <h2 className="text-[14px] font-semibold text-sw-text">What your paystub told us</h2>
                </div>
                <button
                  onClick={() => refreshFacts()}
                  disabled={factsLoading}
                  className="inline-flex items-center gap-1.5 text-[11px] text-sw-muted hover:text-sw-text transition"
                >
                  <RefreshCw size={12} className={factsLoading ? 'animate-spin' : ''} />
                  Refresh
                </button>
              </div>

              <div className="rounded-xl border border-sw-success/20 bg-sw-success-light/40 px-4 py-2.5 flex items-center gap-2">
                <CheckCircle size={13} className="text-sw-success shrink-0" />
                <p className="text-[12px] text-sw-success font-medium">
                  Review and confirm each suggestion to save it to your profile.
                </p>
              </div>

              {/* Group disclaimer — one per section (not per card). UI-03 educational framing. */}
              <div className="flex items-start gap-1.5 rounded-xl border border-sw-border/60 bg-sw-surface px-3.5 py-2.5">
                <Info size={11} className="text-sw-dim shrink-0 mt-0.5" />
                <p className="text-[10px] text-sw-dim leading-relaxed">
                  These values were extracted from your uploaded document by AI and may need verification.
                  Confirming adds them to your profile — it does not constitute tax filing or advice.
                  Nothing is saved until you click Confirm.
                </p>
              </div>

              {factsLoading && (
                <div className="rounded-xl border border-sw-border bg-sw-card p-5 flex items-center gap-3 animate-pulse">
                  <Loader2 size={16} className="animate-spin text-sw-dim shrink-0" />
                  <p className="text-[12px] text-sw-muted">Loading suggestions...</p>
                </div>
              )}

              {proposals.map((fact: UserTaxFactView) => (
                <ProposalConfirmCard
                  key={fact.id}
                  fact={fact}
                  onConfirmed={handleProposalConfirmed}
                  onRejected={handleProposalRejected}
                />
              ))}
            </div>
          )}

          {/* ── 3. Doc follow-up items — connectivity prerequisites inline ── */}
          {followUpItems.length > 0 && (
            <div className="space-y-2">
              <p className="text-[12px] font-semibold text-sw-muted uppercase tracking-wide px-1">
                Connect more data sources
              </p>
              {followUpItems.map((item) => (
                <DocFollowUpCard key={item.key} item={item} />
              ))}
            </div>
          )}

          {/* ── 4. Gap questions — InterviewCard inline (D18 anatomy preserved) ── */}
          {needsInterview && (
            <div className="space-y-3">
              <div className="flex items-center gap-2">
                <Brain size={16} className="text-sw-info" />
                <h2 className="text-[14px] font-semibold text-sw-text">
                  A few questions we couldn't answer from your documents
                </h2>
              </div>
              <p className="text-[12px] text-sw-muted leading-relaxed">
                These are asked once, tier-ordered by what matters most to your situation.
                Answers refine your optimization analysis.
              </p>

              {/* Reuse P11 InterviewCard unchanged — D18 anatomy preserved */}
              <InterviewCard
                taxYear={currentYear}
                onAnswered={() => {
                  // On each answer refresh action center to track do_interview completion
                  refreshActionCenter();
                }}
              />
            </div>
          )}

          {/* ── 5. Findings list ── */}
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <TrendingUp size={16} className="text-sw-accent" />
                <h2 className="text-sm font-semibold text-sw-text">What We Noticed</h2>
              </div>
              <button
                onClick={() => setViewMode('choices')}
                className="inline-flex items-center gap-1.5 text-xs font-medium text-sw-accent hover:text-sw-accent-hover transition"
              >
                See your options <ArrowRight size={13} />
              </button>
            </div>

            {/* Summary stats */}
            {!reportLoading && report && totalCount > 0 && (
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 stagger-children">
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

            {/* Loading skeleton */}
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

            {/* Error — show cached data when available */}
            {reportError && !report && (
              <div className="rounded-2xl border border-sw-danger/30 bg-sw-danger/5 p-6 text-center">
                <AlertTriangle size={22} className="mx-auto text-sw-danger mb-2" />
                <p className="text-sm text-sw-text">{reportError}</p>
              </div>
            )}

            {/* Generating state */}
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

            {/* Findings list — show cached data even on non-fatal error */}
            {!reportLoading && report && report.status === 'ready' && (
              <>
                {report.sections.length === 0 ? (
                  <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-1 p-8 text-center">
                    <div className="w-12 h-12 mx-auto rounded-2xl bg-sw-success/10 ring-1 ring-sw-success/30 flex items-center justify-center mb-3">
                      <CheckCircle size={22} className="text-sw-success" />
                    </div>
                    <h3 className="text-[15px] font-semibold text-sw-text mb-1">No findings yet</h3>
                    <p className="text-xs text-sw-muted max-w-xs mx-auto leading-relaxed">
                      Connect your bank and complete the steps above to see potential optimization areas.
                    </p>
                  </div>
                ) : (
                  <div className="space-y-3">
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

                    {/* CTA to Choices */}
                    <div className="rounded-2xl border border-sw-accent/20 bg-sw-accent/5 p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                      <div className="flex items-start gap-3">
                        <GitCompare size={20} className="text-sw-accent shrink-0 mt-0.5" />
                        <div>
                          <p className="text-[14px] font-semibold text-sw-text">Ready to see your options?</p>
                          <p className="text-[12px] text-sw-muted leading-relaxed mt-0.5">
                            Compare optimization scenarios and choose the path that fits your situation.
                          </p>
                        </div>
                      </div>
                      <button
                        onClick={() => setViewMode('choices')}
                        className="self-start shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition shadow-sm"
                      >
                        See your options <ArrowRight size={14} />
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
        </div>
      )}

      {/* ── Choices stage (was scenarios) ─────────────────────────────────────── */}
      {viewMode === 'choices' && (
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

          {/* After choosing: link to Checklist */}
          {scenariosData?.chosen && (
            <div className="rounded-2xl border border-sw-success/20 bg-sw-success-light/40 p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <div className="flex items-start gap-3">
                <CheckCircle size={20} className="text-sw-success shrink-0 mt-0.5" />
                <div>
                  <p className="text-[14px] font-semibold text-sw-text">Scenario chosen — view your action checklist</p>
                  <p className="text-[12px] text-sw-muted mt-0.5">Step-by-step actions to implement your chosen plan.</p>
                </div>
              </div>
              <button
                onClick={() => setViewMode('checklist')}
                className="self-start shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-sw-success text-white text-sm font-semibold hover:bg-emerald-700 transition shadow-sm"
              >
                View Checklist <ArrowRight size={14} />
              </button>
            </div>
          )}
        </div>
      )}

      {/* ── Checklist stage (standalone) ──────────────────────────────────────── */}
      {viewMode === 'checklist' && (
        <div className="space-y-5">
          <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-2">
              <CheckCircle size={16} className="text-sw-success" />
              <h2 className="text-sm font-semibold text-sw-text">Optimization Checklist</h2>
            </div>
            <button
              onClick={() => setViewMode('report')}
              className="inline-flex items-center gap-1.5 text-xs font-medium text-sw-accent hover:text-sw-accent-hover transition"
            >
              Full Report <ArrowRight size={13} />
            </button>
          </div>

          <OptimizationChecklistView
            taxYear={currentYear}
            hasBankConnected={auth.hasBankConnected}
          />
        </div>
      )}

      {/* ── Report stage ──────────────────────────────────────────────────────── */}
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
