/**
 * OptimizationReportView — Phase 12-05 Task 2 (UI-02, UI-03).
 *
 * Renders the sectioned, collapsible optimization report fed by
 * GET /api/v1/optimizer/report/{year}.
 *
 * Features:
 *   - Collapsible sections (topical / wrapper / year_end)
 *   - Per-section educational disclaimer (UI-03, non-globally-dismissable)
 *   - Generating/regenerate/download states (RPT-02)
 *   - DOC-05 in-flow upload control for findings with docs_missing
 *
 * DESIGN (Decision 6/7 — elevate, don't replace):
 *   - sw-* tokens only; Inter type stack; 4px/8px spacing rhythm
 *   - Applied: ui-ux-pro-max "Financial Dashboard — Data-Dense" card approach
 *   - Applied: soft-skill — shadow-sm card depth, 16px rhythm, 1.6 leading
 *   - Preserves brand; no palette swaps
 */

import { useState } from 'react';
import {
  ChevronDown,
  ChevronUp,
  RefreshCw,
  Download,
  Loader2,
  AlertCircle,
  Info,
  CheckCircle,
  FileText,
  Clock,
  AlertTriangle,
  ArrowUpRight,
} from 'lucide-react';
import FileDropZone from './FileDropZone';
import Badge from './Badge';
import axios from 'axios';

// ─── Types ───────────────────────────────────────────────────────────────────

interface ReportFinding {
  finding_id: number;
  finding_type: string;
  severity: 'high' | 'medium' | 'low' | string;
  description: string | null;
  docs_missing?: string[];
  docs_captured?: number[];
  band?: 'auto' | 'conditional' | 'specialist' | null;
}

/** D19 structured narration fields for report sections. */
interface NarratorStructured {
  summary: string;
  bullets: string[];
}

/** D19 structured executive summary. */
interface ExecutiveSummaryStructured {
  summary: string;
  bullets: string[];
}

interface ReportSection {
  section_key: string;
  title: string;
  section_type: 'topical' | 'wrapper' | 'glossary' | 'year_end' | string;
  findings: ReportFinding[];
  narrator_prose: string | null;
  /** D19: structured narration — prefer over narrator_prose when present. */
  narrator_structured: NarratorStructured | null;
  disclaimer: string | null;
}

interface OptimizationReportData {
  id: number;
  tax_year: number;
  is_stale: boolean;
  status: 'generating' | 'ready';
  sections: ReportSection[];
  executive_summary: string | null;
  /** D19: structured executive summary — prefer over executive_summary when present. */
  executive_summary_structured: ExecutiveSummaryStructured | null;
  rebuilt_at: string | null;
  stale_since: string | null;
}

interface OptimizationReportViewProps {
  taxYear: number;
  report: OptimizationReportData | null;
  loading: boolean;
  error: string | null;
  onRegenerate: () => void;
  regenerating: boolean;
}

// ─── Severity helpers ─────────────────────────────────────────────────────────

function severityBadgeVariant(severity: string): 'danger' | 'warning' | 'info' | 'neutral' {
  if (severity === 'high') return 'danger';
  if (severity === 'medium') return 'warning';
  if (severity === 'low') return 'info';
  return 'neutral';
}

function severityLabel(severity: string): string {
  if (severity === 'high') return 'High Priority';
  if (severity === 'medium') return 'Worth Reviewing';
  if (severity === 'low') return 'Low Impact';
  return severity;
}

function formatRebuiltAt(iso: string | null): string {
  if (!iso) return 'Not yet generated';
  const d = new Date(iso);
  return d.toLocaleDateString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

// ─── Finding row with optional docs_missing upload affordance ─────────────────

function FindingRow({
  finding,
  taxYear,
}: {
  finding: ReportFinding;
  taxYear: number;
}) {
  const [uploadOpen, setUploadOpen] = useState(false);
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const [uploaded, setUploaded] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const hasMissingDocs = finding.docs_missing && finding.docs_missing.length > 0;

  const handleUpload = async () => {
    if (!uploadFile) return;
    setUploading(true);
    setUploadError(null);
    try {
      const form = new FormData();
      form.append('file', uploadFile);
      form.append('tax_year', String(taxYear));
      // Use the existing vault upload endpoint
      await axios.post('/api/v1/documents', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setUploaded(true);
      setUploadOpen(false);
    } catch {
      setUploadError('Upload failed. Please try again.');
    } finally {
      setUploading(false);
    }
  };

  return (
    <div className="border-l-2 pl-4 py-2 space-y-1.5" style={{
      borderColor: finding.severity === 'high' ? 'var(--color-sw-danger)' :
        finding.severity === 'medium' ? 'var(--color-sw-warning)' : 'var(--color-sw-accent)',
    }}>
      <div className="flex items-start gap-2 flex-wrap">
        <Badge variant={severityBadgeVariant(finding.severity)}>
          {severityLabel(finding.severity)}
        </Badge>
        {finding.band && (
          <Badge variant={finding.band === 'auto' ? 'success' : finding.band === 'conditional' ? 'warning' : 'info'}>
            {finding.band === 'auto' ? 'High Confidence' : finding.band === 'conditional' ? 'Needs Review' : 'Specialist'}
          </Badge>
        )}
        {uploaded && (
          <Badge variant="success"><CheckCircle size={10} /> Uploaded</Badge>
        )}
      </div>
      {finding.description ? (
        <p className="text-[13px] text-sw-text-secondary leading-relaxed">
          {finding.description}
        </p>
      ) : (
        <p className="text-[13px] text-sw-dim italic">
          Analysis in progress...
        </p>
      )}
      {/* DOC-05: docs_missing in-flow upload */}
      {hasMissingDocs && !uploaded && (
        <div className="mt-2">
          <button
            onClick={() => setUploadOpen(!uploadOpen)}
            className="inline-flex items-center gap-1.5 text-[11px] text-sw-accent hover:text-sw-accent-hover font-medium transition"
          >
            <ArrowUpRight size={12} />
            Upload {finding.docs_missing!.map((d) => d.replace(/_/g, ' ')).join(' or ')} to strengthen this finding
          </button>
          {uploadOpen && (
            <div className="mt-2 p-3 rounded-xl border border-sw-border bg-sw-surface space-y-2">
              <p className="text-[11px] text-sw-muted">
                Uploading the document may help improve the accuracy of this finding.
                <span className="text-sw-dim"> (Educational purposes only — not tax advice.)</span>
              </p>
              <FileDropZone
                onFileSelect={setUploadFile}
                selectedFile={uploadFile}
                onClear={() => setUploadFile(null)}
                acceptedExtensions={['.pdf', '.png', '.jpg', '.jpeg']}
                acceptedMimes={['application/pdf', 'image/png', 'image/jpeg']}
              />
              {uploadError && (
                <p className="text-[11px] text-sw-danger flex items-center gap-1">
                  <AlertCircle size={11} /> {uploadError}
                </p>
              )}
              <button
                onClick={handleUpload}
                disabled={!uploadFile || uploading}
                className="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {uploading ? <Loader2 size={12} className="animate-spin" /> : <Download size={12} />}
                {uploading ? 'Uploading...' : 'Upload document'}
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ─── Section card (collapsible) ───────────────────────────────────────────────

function SectionCard({
  section,
  taxYear,
  defaultOpen = false,
}: {
  section: ReportSection;
  taxYear: number;
  defaultOpen?: boolean;
}) {
  const [open, setOpen] = useState(defaultOpen);
  const highCount = section.findings.filter((f) => f.severity === 'high').length;
  const medCount = section.findings.filter((f) => f.severity === 'medium').length;

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 overflow-hidden">
      {/* Section header */}
      <button
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-sw-surface/50 transition-colors"
      >
        <div className="flex items-center gap-3 min-w-0">
          <div className="w-8 h-8 rounded-xl bg-sw-accent/10 ring-1 ring-sw-accent/20 flex items-center justify-center shrink-0">
            <FileText size={14} className="text-sw-accent" />
          </div>
          <div className="min-w-0">
            <p className="text-[14px] font-semibold text-sw-text truncate">{section.title}</p>
            <p className="text-[11px] text-sw-dim">
              {section.findings.length} finding{section.findings.length !== 1 ? 's' : ''}
              {highCount > 0 && <span className="ml-1 text-sw-danger font-medium">{highCount} high priority</span>}
              {highCount === 0 && medCount > 0 && <span className="ml-1 text-sw-warning font-medium">{medCount} worth reviewing</span>}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          {highCount > 0 && (
            <span className="w-5 h-5 rounded-full bg-sw-danger flex items-center justify-center text-[10px] text-white font-bold">
              {highCount}
            </span>
          )}
          {open ? <ChevronUp size={16} className="text-sw-dim" /> : <ChevronDown size={16} className="text-sw-dim" />}
        </div>
      </button>

      {/* Section body */}
      {open && (
        <div className="border-t border-sw-border/60 px-5 py-4 space-y-4">
          {/* D19: Narrator — compose from structured fields when present, fallback to prose clamp */}
          {section.narrator_structured ? (
            <div className="space-y-2">
              <p className="text-[13px] text-sw-text-secondary leading-relaxed">
                {section.narrator_structured.summary}
              </p>
              {section.narrator_structured.bullets.length > 0 && (
                <ul className="space-y-1 pl-3">
                  {section.narrator_structured.bullets.map((bullet, i) => (
                    <li key={i} className="text-[12px] text-sw-muted flex items-start gap-1.5">
                      <span className="mt-1.5 w-1 h-1 rounded-full bg-sw-muted shrink-0" />
                      {bullet}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ) : section.narrator_prose ? (
            <p className="text-[13px] text-sw-text-secondary leading-relaxed line-clamp-3">
              {section.narrator_prose}
            </p>
          ) : null}

          {/* Findings list */}
          {section.findings.length > 0 ? (
            <div className="space-y-3">
              {section.findings.map((finding) => (
                <FindingRow key={finding.finding_id} finding={finding} taxYear={taxYear} />
              ))}
            </div>
          ) : (
            <p className="text-[12px] text-sw-dim italic">No findings in this section.</p>
          )}

          {/* Per-section educational disclaimer (UI-03 — non-globally-dismissable) */}
          <div className="flex items-start gap-2 rounded-lg bg-sw-surface border border-sw-border/60 px-3.5 py-2.5 mt-2">
            <Info size={12} className="text-sw-dim shrink-0 mt-0.5" />
            <p className="text-[10px] text-sw-dim leading-relaxed">
              {section.disclaimer ??
                'This analysis may highlight potential areas to discuss with a tax professional. Nothing here constitutes tax advice. Consider consulting a qualified professional before making financial decisions.'}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function OptimizationReportView({
  taxYear,
  report,
  loading,
  error,
  onRegenerate,
  regenerating,
}: OptimizationReportViewProps) {
  // Download the report PDF
  const handleDownload = () => {
    window.open(`/api/v1/optimizer/report/${taxYear}/download`, '_blank');
  };

  // ── Loading ──────────────────────────────────────────────────────────────────
  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center py-16 gap-3">
        <Loader2 size={28} className="animate-spin text-sw-accent" />
        <p className="text-sm text-sw-muted">Loading your optimization report...</p>
      </div>
    );
  }

  // ── Error ────────────────────────────────────────────────────────────────────
  if (error) {
    return (
      <div className="rounded-2xl border border-sw-danger/30 bg-sw-danger/5 p-8 text-center">
        <AlertTriangle size={28} className="mx-auto text-sw-danger mb-3" />
        <p className="text-sm text-sw-text mb-4">{error}</p>
        <button
          onClick={onRegenerate}
          className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition"
        >
          <RefreshCw size={14} /> Try Again
        </button>
      </div>
    );
  }

  // ── Generating / empty ───────────────────────────────────────────────────────
  // Stale-while-revalidate: saved sections ALWAYS render (owner: "we need to be
  // saving these results"). Spinner only when there is genuinely nothing to show;
  // a stale-but-populated report renders with the is_stale refresh chip instead.
  const isGenerating = !report || (report.sections.length === 0 && !error);

  if (isGenerating) {
    return (
      <div className="rounded-2xl border border-sw-border bg-sw-card p-10 text-center space-y-4">
        <div className="w-14 h-14 mx-auto rounded-2xl bg-sw-accent/10 border border-sw-accent/20 flex items-center justify-center">
          <Loader2 size={24} className="animate-spin text-sw-accent" />
        </div>
        <div>
          <h3 className="text-[15px] font-semibold text-sw-text mb-1">Generating your report</h3>
          <p className="text-[13px] text-sw-muted max-w-sm mx-auto leading-relaxed">
            Your personalized income optimization report may take a moment to prepare.
            It will appear here automatically when ready.
          </p>
        </div>
        {/* Page-level educational disclaimer */}
        <div className="flex items-start gap-2 rounded-lg bg-sw-surface border border-sw-border/60 px-4 py-3 max-w-md mx-auto text-left">
          <Info size={12} className="text-sw-dim shrink-0 mt-0.5" />
          <p className="text-[10px] text-sw-dim leading-relaxed">
            This report is for educational purposes and may help you identify areas to discuss with a qualified
            tax professional. It is not tax advice and does not guarantee any tax outcome.
          </p>
        </div>
        <div className="flex items-center justify-center gap-3 pt-2">
          <button
            onClick={onRegenerate}
            disabled={regenerating}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-sw-border text-sm text-sw-muted hover:text-sw-text transition disabled:opacity-50"
          >
            {regenerating ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}
            {regenerating ? 'Requesting...' : 'Request now'}
          </button>
        </div>
      </div>
    );
  }

  // ── Ready ────────────────────────────────────────────────────────────────────
  const sections = report.sections ?? [];

  return (
    <div className="space-y-4">
      {/* Report header bar */}
      <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 px-5 py-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div>
          <p className="text-[13px] font-semibold text-sw-text">
            {taxYear} Income Optimization Report
          </p>
          <div className="flex items-center gap-2 mt-0.5 text-[11px] text-sw-dim">
            <Clock size={11} />
            <span>Last built: {formatRebuiltAt(report.rebuilt_at)}</span>
            {report.is_stale && (
              <Badge variant="warning"><AlertCircle size={9} /> Stale</Badge>
            )}
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <button
            onClick={onRegenerate}
            disabled={regenerating}
            className="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg border border-sw-border text-xs text-sw-muted hover:text-sw-text hover:border-sw-border-strong transition disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {regenerating ? <Loader2 size={12} className="animate-spin" /> : <RefreshCw size={12} />}
            {regenerating ? 'Rebuilding...' : 'Rebuild'}
          </button>
          <button
            onClick={handleDownload}
            className="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition"
          >
            <Download size={12} />
            Download PDF
          </button>
        </div>
      </div>

      {/* D19: Executive summary — compose from structured when present, fallback to prose clamp */}
      {(report.executive_summary_structured || report.executive_summary) && (
        <div className="rounded-2xl border border-sw-accent/20 bg-sw-accent/5 px-5 py-4 space-y-2">
          <p className="text-[11px] font-semibold text-sw-accent uppercase tracking-wide">Overview</p>
          {report.executive_summary_structured ? (
            <div className="space-y-2">
              <p className="text-[13px] text-sw-text-secondary leading-relaxed">
                {report.executive_summary_structured.summary}
              </p>
              {report.executive_summary_structured.bullets.length > 0 && (
                <ul className="space-y-1 pl-3">
                  {report.executive_summary_structured.bullets.map((bullet, i) => (
                    <li key={i} className="text-[12px] text-sw-muted flex items-start gap-1.5">
                      <span className="mt-1.5 w-1 h-1 rounded-full bg-sw-muted shrink-0" />
                      {bullet}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ) : (
            <p className="text-[13px] text-sw-text-secondary leading-relaxed line-clamp-3">
              {report.executive_summary}
            </p>
          )}
          {/* Page-level disclaimer (UI-03) */}
          <div className="flex items-start gap-2 pt-1">
            <Info size={11} className="text-sw-dim shrink-0 mt-0.5" />
            <p className="text-[10px] text-sw-dim leading-relaxed">
              This report may highlight potential areas of interest. Nothing here constitutes tax advice.
              Please consult a qualified tax professional before making any financial decisions.
            </p>
          </div>
        </div>
      )}

      {/* Sections */}
      {sections.length === 0 ? (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-8 text-center">
          <CheckCircle size={28} className="mx-auto text-sw-accent/50 mb-3" />
          <p className="text-sm text-sw-muted">No findings to report for {taxYear}.</p>
        </div>
      ) : (
        <div className="space-y-3">
          {sections.map((section, idx) => (
            <SectionCard
              key={section.section_key}
              section={section}
              taxYear={taxYear}
              defaultOpen={idx === 0}
            />
          ))}
        </div>
      )}

      {/* Bottom-level page disclaimer (UI-03 — persistent, non-globally-dismissable) */}
      <div className="flex items-start gap-2 rounded-xl border border-sw-border/60 bg-sw-surface px-4 py-3">
        <Info size={12} className="text-sw-dim shrink-0 mt-0.5" />
        <p className="text-[10px] text-sw-dim leading-relaxed">
          SpendifiAI income optimization is for educational purposes only. Findings may or may not apply
          to your specific tax situation. Consider consulting a qualified, licensed tax professional before
          making any decisions based on this report. SpendifiAI does not provide tax, legal, or accounting advice.
        </p>
      </div>
    </div>
  );
}
