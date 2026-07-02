/**
 * Optimize/Index — Phase 12-05 Task 2 (UI-01, UI-02, UI-03).
 *
 * Three-stage Optimize My Income page:
 *   Stage 1 — Findings list (ranked by severity, "what we noticed" framing)
 *   Stage 2 — Guided interview (reuses P11 InterviewCard unchanged)
 *   Stage 3 — Collapsible report view (OptimizationReportView)
 *
 * Guards: auth.hasBankConnected → ConnectBankPrompt when false.
 *
 * DESIGN (Decision 6/7 — elevate, don't replace):
 *   - sw-* tokens only; Inter type stack; 4px/8px rhythm; shadow-sm cards
 *   - Applied: ui-ux-pro-max "Financial Dashboard — Data-Dense" for findings
 *   - Applied: soft-skill — 16px rhythm, leading-relaxed, focus states
 *   - All copy uses may/could/consider (UI-03 educational framing)
 *   - Inline disclaimer on every surface (non-globally-dismissable)
 *
 * SKILL USAGE:
 *   - ui-ux-pro-max query: "collapsible report sections layout" → Feature-Rich
 *     Showcase / Editorial Grid patterns applied (section cards with grid layout)
 *   - ui-ux-pro-max query: "tax dashboard report card priority severity" →
 *     Financial Dashboard style: Data-Dense, red/green severity indicators
 *   - soft-skill: 16px rhythm, shadow-sm depth, 1.6 leading for prose
 */

import { useState } from 'react';
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
  Loader2,
} from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import ConnectBankPrompt from '@/Components/SpendifiAI/ConnectBankPrompt';
import InterviewCard from '@/Components/SpendifiAI/InterviewCard';
import OptimizationReportView from '@/Components/SpendifiAI/OptimizationReportView';
import { useApi, useApiPost } from '@/hooks/useApi';
import axios from 'axios';

// ─── Types ────────────────────────────────────────────────────────────────────

interface ReportSection {
  section_key: string;
  title: string;
  section_type: string;
  findings: Array<{
    finding_id: number;
    finding_type: string;
    severity: 'high' | 'medium' | 'low' | string;
    description: string | null;
    docs_missing?: string[];
    docs_captured?: number[];
    band?: 'auto' | 'conditional' | 'specialist' | null;
  }>;
  narrator_prose: string | null;
  disclaimer: string | null;
}

interface ReportData {
  id: number;
  tax_year: number;
  is_stale: boolean;
  status: 'generating' | 'ready';
  sections: ReportSection[];
  executive_summary: string | null;
  rebuilt_at: string | null;
  stale_since: string | null;
}

interface ReportResponse {
  report: ReportData;
}

type ViewMode = 'findings' | 'interview' | 'report';

// ─── Findings summary card ────────────────────────────────────────────────────

function FindingSummaryCard({
  section,
}: {
  section: ReportSection;
}) {
  const highFindings = section.findings.filter((f) => f.severity === 'high');
  const medFindings = section.findings.filter((f) => f.severity === 'medium');

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card shadow-sm p-5 space-y-3">
      <div className="flex items-start gap-3">
        <div className="w-9 h-9 rounded-xl bg-sw-accent/10 border border-sw-accent/20 flex items-center justify-center shrink-0">
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

      {/* Findings preview list */}
      {section.findings.slice(0, 3).map((finding) => (
        <div key={finding.finding_id} className="pl-3 border-l-2 border-sw-border">
          {finding.description ? (
            <p className="text-[12px] text-sw-text-secondary leading-relaxed">{finding.description}</p>
          ) : (
            <p className="text-[12px] text-sw-dim italic">Analysis in progress...</p>
          )}
        </div>
      ))}
      {section.findings.length > 3 && (
        <p className="text-[11px] text-sw-dim pl-3">
          + {section.findings.length - 3} more in the full report
        </p>
      )}

      {/* Narrator prose */}
      {section.narrator_prose && (
        <p className="text-[12px] text-sw-muted leading-relaxed border-t border-sw-border/60 pt-3">
          {section.narrator_prose}
        </p>
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

  // Fetch the report (includes sections/findings)
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

  const handleRegenerate = async () => {
    setRegenerating(true);
    try {
      await axios.post(`/api/v1/optimizer/report/${currentYear}/regenerate`);
      // Poll refresh after a delay
      setTimeout(() => {
        refreshReport();
        setRegenerating(false);
      }, 3000);
    } catch {
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
          <h1 className="text-xl font-bold text-sw-text tracking-tight">Optimize My Income</h1>
          <p className="text-xs text-sw-dim mt-0.5">Educational review — not tax advice</p>
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

      {/* Summary stats (findings stage only) */}
      {viewMode === 'findings' && !reportLoading && report && totalCount > 0 && (
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-5">
          <div className="rounded-xl border border-sw-border bg-sw-card p-3.5">
            <p className="text-[11px] text-sw-dim uppercase tracking-wide">Total Findings</p>
            <p className="text-2xl font-bold text-sw-text mt-0.5">{totalCount}</p>
          </div>
          <div className="rounded-xl border border-sw-danger/30 bg-sw-danger/5 p-3.5">
            <p className="text-[11px] text-sw-danger uppercase tracking-wide font-medium">High Priority</p>
            <p className="text-2xl font-bold text-sw-text mt-0.5">{highCount}</p>
          </div>
          <div className="rounded-xl border border-sw-border bg-sw-card p-3.5 col-span-2 sm:col-span-1">
            <p className="text-[11px] text-sw-dim uppercase tracking-wide">Tax Year</p>
            <p className="text-2xl font-bold text-sw-text mt-0.5">{currentYear}</p>
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

          {/* Loading */}
          {reportLoading && (
            <div className="flex items-center justify-center py-12">
              <Loader2 size={22} className="animate-spin text-sw-accent" />
            </div>
          )}

          {/* Error */}
          {reportError && (
            <div className="rounded-2xl border border-sw-danger/30 bg-sw-danger/5 p-6 text-center">
              <AlertTriangle size={22} className="mx-auto text-sw-danger mb-2" />
              <p className="text-sm text-sw-text">{reportError}</p>
            </div>
          )}

          {/* Generating state */}
          {!reportLoading && !reportError && (!report || report.status === 'generating') && (
            <div className="rounded-2xl border border-sw-border bg-sw-card p-8 text-center space-y-3">
              <Loader2 size={24} className="mx-auto animate-spin text-sw-accent" />
              <p className="text-sm font-medium text-sw-text">Analyzing your financial data...</p>
              <p className="text-xs text-sw-muted">
                Your income optimization analysis may take a moment to prepare.
              </p>
            </div>
          )}

          {/* Findings list */}
          {!reportLoading && !reportError && report && report.status === 'ready' && (
            <>
              {report.sections.length === 0 ? (
                <div className="rounded-2xl border border-sw-border bg-sw-card p-8 text-center">
                  <CheckCircle size={28} className="mx-auto text-sw-accent/50 mb-3" />
                  <p className="text-sm font-medium text-sw-text mb-1">No findings yet</p>
                  <p className="text-xs text-sw-muted max-w-xs mx-auto">
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
                      <FindingSummaryCard key={section.section_key} section={section} />
                    ))}
                  {/* CTA to start interview */}
                  <div className="rounded-2xl border border-sw-accent/20 bg-sw-accent/5 p-5 flex items-center justify-between gap-4">
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
                      className="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition shadow-sm"
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

          <div className="flex justify-center pt-2">
            <button
              onClick={() => setViewMode('report')}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-sw-border text-sm text-sw-muted hover:text-sw-text transition"
            >
              Skip to Report <ArrowRight size={14} />
            </button>
          </div>
        </div>
      )}

      {/* ── Stage 3: Report ───────────────────────────────────────────────────── */}
      {viewMode === 'report' && (
        <div className="space-y-4">
          <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-2">
              <TrendingUp size={16} className="text-sw-accent" />
              <h2 className="text-sm font-semibold text-sw-text">Your Optimization Report</h2>
            </div>
          </div>

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
