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
 *   - Clarity pass: semantic zero-states, no "in progress" on ready reports,
 *     empty sections collapse to a muted footer row (Fix 1/2/3)
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
  BookOpen,
  Calendar,
  ShieldOff,
} from 'lucide-react';
import FileDropZone from './FileDropZone';
import Badge from './Badge';
import { renderFindingDescription } from '@/utils/findingTypeLabel';
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

/** A missing-document entry on the documents_missing section. */
interface SectionDocMissingEntry {
  document: string;
  finding_type: string;
  finding_id: number | null;
}

/** A refused-recommendation entry on the what_we_refused section. */
interface SectionRefusedEntry {
  category: string;
  what: string;
  why: string;
}

/** A glossary term on the glossary section. */
interface SectionGlossaryEntry {
  term: string;
  explanation: string;
  source?: string;
  note?: string;
}

/** A year-end awareness item (deadline-keyed finding row). */
interface SectionYearEndItem {
  finding_id: number | null;
  finding_type: string;
  description: string | null;
  lead_time_days: number | null;
  reversible: boolean;
}

interface ReportSection {
  section_key: string;
  title: string;
  section_type: 'topical' | 'wrapper' | 'glossary' | 'year_end' | 'chosen_plan' | string;
  findings: ReportFinding[];
  narrator_prose: string | null;
  /** D19: structured narration — prefer over narrator_prose when present. */
  narrator_structured: NarratorStructured | null;
  disclaimer: string | null;
  // Wrapper: documents_missing
  docs_missing?: SectionDocMissingEntry[];
  // Wrapper: what_we_refused
  refused_recommendations?: SectionRefusedEntry[];
  // Glossary section
  glossary_entries?: SectionGlossaryEntry[];
  // Year-end section
  dec_31_items?: SectionYearEndItem[];
  jan_15_items?: SectionYearEndItem[];
  april_items?: SectionYearEndItem[];
  dec_31_framing?: string;
  jan_15_framing?: string;
  april_framing?: string;
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
  /** Fix 3: true when is_stale=true or status=generating — triggers full overlay. */
  isRebuilding?: boolean;
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

// ─── Per-section zero-state SCALE (Fix 3) ────────────────────────────────────
// RED = has high findings, YELLOW = has medium (no high), GREEN = all low, ANALYZING = 0 findings

type SectionScale = 'RED' | 'YELLOW' | 'GREEN' | 'ANALYZING';

function sectionScale(findings: ReportFinding[]): SectionScale {
  if (findings.length === 0) return 'ANALYZING';
  if (findings.some((f) => f.severity === 'high')) return 'RED';
  if (findings.some((f) => f.severity === 'medium')) return 'YELLOW';
  return 'GREEN';
}

function ScaleBadge({ scale }: { scale: SectionScale }) {
  const map: Record<SectionScale, { variant: 'danger' | 'warning' | 'success' | 'info'; label: string }> = {
    RED:       { variant: 'danger',  label: 'RED'       },
    YELLOW:    { variant: 'warning', label: 'YELLOW'    },
    GREEN:     { variant: 'success', label: 'GREEN'     },
    ANALYZING: { variant: 'info',    label: 'ANALYZING' },
  };
  const { variant, label } = map[scale];
  return <Badge variant={variant}>{label}</Badge>;
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

// ─── Section empty classification (Fix 3) ────────────────────────────────────

/**
 * Determine if a section has no meaningful visible content and should be
 * collapsed into the muted footer row instead of rendered as a full card.
 */
function isSectionCollapsible(section: ReportSection): boolean {
  // Topical sections with findings: show as full card
  if ((section.section_type === 'topical' || section.section_type === 'wrapper') &&
      section.section_key !== 'documents_missing' &&
      section.section_key !== 'what_we_refused') {
    return section.findings.length === 0;
  }

  // documents_missing: collapse when no missing docs (handled as green banner instead)
  if (section.section_key === 'documents_missing') {
    return !(section.docs_missing && section.docs_missing.length > 0);
  }

  // what_we_refused: always has content from config; never collapse
  if (section.section_key === 'what_we_refused') {
    return !(section.refused_recommendations && section.refused_recommendations.length > 0);
  }

  // year_end: collapse when no calendar items
  if (section.section_type === 'year_end') {
    const hasItems = (
      (section.dec_31_items?.length ?? 0) > 0 ||
      (section.jan_15_items?.length ?? 0) > 0 ||
      (section.april_items?.length ?? 0) > 0
    );
    return !hasItems;
  }

  // Glossary: always collapse into the footer row (never show a count, show compactly elsewhere)
  if (section.section_type === 'glossary') {
    return true;
  }

  return false;
}

/**
 * Short label for a section in the collapsed footer row.
 */
function sectionShortLabel(section: ReportSection): string {
  if (section.section_key === 'year_end_awareness') return 'Year-End';
  if (section.section_key === 'what_we_refused')   return 'Did-Not-Recommend';
  if (section.section_key === 'documents_missing') return 'Documents';
  if (section.section_key === 'glossary')          return 'Glossary';
  return section.title;
}

/**
 * Per-section empty-state copy for the collapsed row tooltip/detail.
 */
function sectionEmptyCopy(section: ReportSection): string {
  if (section.section_key === 'year_end_awareness') {
    return 'Nothing seasonal right now — items appear as year-end approaches';
  }
  if (section.section_key === 'what_we_refused') {
    return 'Nothing was ruled out this cycle';
  }
  if (section.section_key === 'documents_missing') {
    return 'You\'ve provided everything we\'d ask for';
  }
  if (section.section_key === 'glossary') {
    return 'Reference terms available — see full glossary';
  }
  return 'Nothing to highlight in this area';
}

// ─── Finding row with optional docs_missing upload affordance ─────────────────

function FindingRow({
  finding,
  taxYear,
  isGenerating,
}: {
  finding: ReportFinding;
  taxYear: number;
  /** Fix 2: true only when report.status === 'generating'. */
  isGenerating: boolean;
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
      await axios.post('/api/v1/tax-vault/documents', form, {
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

  // Fix 2: use renderFindingDescription — never show "in progress" on a ready report.
  const displayText = renderFindingDescription(
    finding.description,
    finding.finding_type,
    isGenerating,
  );

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
      {displayText && (
        <p className={`text-[13px] leading-relaxed ${
          /* Fix 2: only dim + italic when genuinely generating; ready-report fallback is muted but not italic */
          isGenerating && !finding.description
            ? 'text-sw-dim italic'
            : 'text-sw-text-secondary'
        }`}>
          {displayText}
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

// ─── Section card (collapsible — for topical + needs_professional_review) ─────

function SectionCard({
  section,
  taxYear,
  defaultOpen = false,
  isGenerating = false,
}: {
  section: ReportSection;
  taxYear: number;
  defaultOpen?: boolean;
  isGenerating?: boolean;
}) {
  const [open, setOpen] = useState(defaultOpen);
  const highCount = section.findings.filter((f) => f.severity === 'high').length;
  const medCount = section.findings.filter((f) => f.severity === 'medium').length;
  const scale = sectionScale(section.findings);

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
          {/* Fix 3: per-section SCALE badge */}
          <ScaleBadge scale={scale} />
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
          {/* D19: Narrator */}
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
                <FindingRow
                  key={finding.finding_id}
                  finding={finding}
                  taxYear={taxYear}
                  isGenerating={isGenerating}
                />
              ))}
            </div>
          ) : (
            /* Fix 1: semantic zero-state for analytic sections with no findings */
            <div className="flex items-center gap-2 py-1">
              <CheckCircle size={14} className="text-sw-success shrink-0" />
              <p className="text-[12px] text-sw-success font-medium">
                Nothing notable found in this area — looking good
              </p>
            </div>
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

// ─── Documents Missing card (Fix 1 — semantic zero-state) ────────────────────

function DocumentsMissingCard({ section }: { section: ReportSection }) {
  const [open, setOpen] = useState(false);
  const missingDocs = section.docs_missing ?? [];
  const hasContent = missingDocs.length > 0;

  if (!hasContent) {
    // Fix 1: green success state — user has provided everything
    return (
      <div className="flex items-center gap-3 rounded-xl border border-sw-success/30 bg-sw-success-light/40 px-4 py-3">
        <CheckCircle size={16} className="text-sw-success shrink-0" />
        <div>
          <p className="text-[13px] font-semibold text-sw-success">
            You've provided everything we'd ask for ✓
          </p>
          <p className="text-[11px] text-sw-success/70 mt-0.5">
            Your vault has all the documents needed to support these findings.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 overflow-hidden">
      <button
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-sw-surface/50 transition-colors"
      >
        <div className="flex items-center gap-3 min-w-0">
          <div className="w-8 h-8 rounded-xl bg-sw-warning/10 ring-1 ring-sw-warning/20 flex items-center justify-center shrink-0">
            <FileText size={14} className="text-sw-warning" />
          </div>
          <div className="min-w-0">
            <p className="text-[14px] font-semibold text-sw-text truncate">{section.title}</p>
            <p className="text-[11px] text-sw-warning font-medium">
              {missingDocs.length} document type{missingDocs.length !== 1 ? 's' : ''} would strengthen your position
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <Badge variant="warning">{missingDocs.length} needed</Badge>
          {open ? <ChevronUp size={16} className="text-sw-dim" /> : <ChevronDown size={16} className="text-sw-dim" />}
        </div>
      </button>

      {open && (
        <div className="border-t border-sw-border/60 px-5 py-4 space-y-3">
          {section.narrator_prose && (
            <p className="text-[12px] text-sw-muted leading-relaxed">{section.narrator_prose}</p>
          )}
          <ul className="space-y-2">
            {missingDocs.map((entry, i) => (
              <li key={i} className="flex items-start gap-2 text-[12px] text-sw-text-secondary">
                <ArrowUpRight size={13} className="text-sw-warning shrink-0 mt-0.5" />
                <span>{entry.document.replace(/_/g, ' ')}</span>
              </li>
            ))}
          </ul>
          <div className="flex items-start gap-2 rounded-lg bg-sw-surface border border-sw-border/60 px-3.5 py-2.5">
            <Info size={12} className="text-sw-dim shrink-0 mt-0.5" />
            <p className="text-[10px] text-sw-dim leading-relaxed">
              {section.disclaimer ?? 'Uploading documents may help improve the completeness of your report. This is educational assistance only.'}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── What We Refused card (Fix 1 — render refused_recommendations) ────────────

function WhatWeRefusedCard({ section }: { section: ReportSection }) {
  const [open, setOpen] = useState(false);
  const refused = section.refused_recommendations ?? [];

  if (refused.length === 0) {
    // Fix 1: "Nothing was ruled out this cycle" — practically never occurs (config always has entries)
    return null;
  }

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 overflow-hidden">
      <button
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-sw-surface/50 transition-colors"
      >
        <div className="flex items-center gap-3 min-w-0">
          <div className="w-8 h-8 rounded-xl bg-sw-info/10 ring-1 ring-sw-info/20 flex items-center justify-center shrink-0">
            <ShieldOff size={14} className="text-sw-info" />
          </div>
          <div className="min-w-0">
            <p className="text-[14px] font-semibold text-sw-text truncate">{section.title}</p>
            <p className="text-[11px] text-sw-muted">
              {refused.length} categor{refused.length !== 1 ? 'ies' : 'y'} outside our scope — and why
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          {open ? <ChevronUp size={16} className="text-sw-dim" /> : <ChevronDown size={16} className="text-sw-dim" />}
        </div>
      </button>

      {open && (
        <div className="border-t border-sw-border/60 px-5 py-4 space-y-4">
          {section.narrator_prose && (
            <p className="text-[12px] text-sw-muted leading-relaxed">{section.narrator_prose}</p>
          )}
          <div className="space-y-3">
            {refused.map((entry, i) => (
              <div key={i} className="border-l-2 border-sw-info/40 pl-4 space-y-1">
                <p className="text-[13px] font-semibold text-sw-text">{entry.category}</p>
                <p className="text-[12px] text-sw-text-secondary leading-relaxed">
                  <span className="font-medium text-sw-muted">What: </span>{entry.what}
                </p>
                <p className="text-[12px] text-sw-muted leading-relaxed">
                  <span className="font-medium">Why: </span>{entry.why}
                </p>
              </div>
            ))}
          </div>
          <div className="flex items-start gap-2 rounded-lg bg-sw-surface border border-sw-border/60 px-3.5 py-2.5">
            <Info size={12} className="text-sw-dim shrink-0 mt-0.5" />
            <p className="text-[10px] text-sw-dim leading-relaxed">
              {section.disclaimer ?? 'SpendifiAI is an educational tool and does not provide tax, investment, or legal advice.'}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Year-End card (Fix 1 — semantic zero-state for empty seasonal section) ───

function YearEndSectionCard({ section }: { section: ReportSection }) {
  const [open, setOpen] = useState(false);

  const dec31Items = section.dec_31_items ?? [];
  const jan15Items = section.jan_15_items ?? [];
  const aprilItems = section.april_items ?? [];
  const hasItems = dec31Items.length > 0 || jan15Items.length > 0 || aprilItems.length > 0;

  if (!hasItems) {
    // Fix 1: "Nothing seasonal right now" — do not render a full card (handled by collapsed row)
    return null;
  }

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 overflow-hidden">
      <button
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-sw-surface/50 transition-colors"
      >
        <div className="flex items-center gap-3 min-w-0">
          <div className="w-8 h-8 rounded-xl bg-sw-warning/10 ring-1 ring-sw-warning/20 flex items-center justify-center shrink-0">
            <Calendar size={14} className="text-sw-warning" />
          </div>
          <div className="min-w-0">
            <p className="text-[14px] font-semibold text-sw-text truncate">{section.title}</p>
            <p className="text-[11px] text-sw-muted">
              {dec31Items.length + jan15Items.length + aprilItems.length} deadline-aware item{(dec31Items.length + jan15Items.length + aprilItems.length) !== 1 ? 's' : ''}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          {open ? <ChevronUp size={16} className="text-sw-dim" /> : <ChevronDown size={16} className="text-sw-dim" />}
        </div>
      </button>

      {open && (
        <div className="border-t border-sw-border/60 px-5 py-4 space-y-4">
          {section.narrator_prose && (
            <p className="text-[12px] text-sw-muted leading-relaxed">{section.narrator_prose}</p>
          )}

          {dec31Items.length > 0 && (
            <div className="space-y-2">
              <p className="text-[11px] font-semibold text-sw-text uppercase tracking-wide">
                {section.dec_31_framing ?? 'Commonly reviewed before year end'}
              </p>
              {dec31Items.map((item, i) => (
                <div key={i} className="border-l-2 border-sw-warning/40 pl-3 py-1">
                  <p className="text-[12px] text-sw-text-secondary leading-relaxed">
                    {item.description ?? item.finding_type.replace(/_/g, ' ')}
                  </p>
                  {item.lead_time_days && (
                    <p className="text-[11px] text-sw-dim mt-0.5">
                      Consider allowing {item.lead_time_days}+ days lead time
                    </p>
                  )}
                </div>
              ))}
            </div>
          )}

          {jan15Items.length > 0 && (
            <div className="space-y-2">
              <p className="text-[11px] font-semibold text-sw-text uppercase tracking-wide">
                {section.jan_15_framing ?? 'Commonly relevant in January'}
              </p>
              {jan15Items.map((item, i) => (
                <div key={i} className="border-l-2 border-sw-info/40 pl-3 py-1">
                  <p className="text-[12px] text-sw-text-secondary leading-relaxed">
                    {item.description ?? item.finding_type.replace(/_/g, ' ')}
                  </p>
                </div>
              ))}
            </div>
          )}

          {aprilItems.length > 0 && (
            <div className="space-y-2">
              <p className="text-[11px] font-semibold text-sw-text uppercase tracking-wide">
                {section.april_framing ?? 'Commonly relevant around the April filing window'}
              </p>
              {aprilItems.map((item, i) => (
                <div key={i} className="border-l-2 border-sw-accent/40 pl-3 py-1">
                  <p className="text-[12px] text-sw-text-secondary leading-relaxed">
                    {item.description ?? item.finding_type.replace(/_/g, ' ')}
                  </p>
                </div>
              ))}
            </div>
          )}

          <div className="flex items-start gap-2 rounded-lg bg-sw-surface border border-sw-border/60 px-3.5 py-2.5">
            <Info size={12} className="text-sw-dim shrink-0 mt-0.5" />
            <p className="text-[10px] text-sw-dim leading-relaxed">
              {section.disclaimer ?? 'These items are educational awareness only. Consult a tax professional for your specific deadlines and circumstances.'}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Glossary card (Fix 1 — never show count; always compact) ────────────────

function GlossaryCard({ section }: { section: ReportSection }) {
  const [open, setOpen] = useState(false);
  const entries = section.glossary_entries ?? [];

  if (entries.length === 0) return null;

  return (
    <div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 overflow-hidden">
      <button
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-sw-surface/50 transition-colors"
      >
        <div className="flex items-center gap-3 min-w-0">
          <div className="w-8 h-8 rounded-xl bg-sw-surface ring-1 ring-sw-border flex items-center justify-center shrink-0">
            <BookOpen size={14} className="text-sw-muted" />
          </div>
          <div className="min-w-0">
            <p className="text-[14px] font-semibold text-sw-text truncate">{section.title}</p>
            {/* Fix 1: NEVER show a count for the glossary */}
            <p className="text-[11px] text-sw-dim">
              Key terms referenced in this report — educational context only
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          {open ? <ChevronUp size={16} className="text-sw-dim" /> : <ChevronDown size={16} className="text-sw-dim" />}
        </div>
      </button>

      {open && (
        <div className="border-t border-sw-border/60 px-5 py-4 space-y-4">
          <div className="space-y-4">
            {entries.map((entry, i) => (
              <div key={i} className="space-y-1">
                <p className="text-[13px] font-semibold text-sw-text">{entry.term}</p>
                <p className="text-[12px] text-sw-text-secondary leading-relaxed">{entry.explanation}</p>
                {entry.source && (
                  <p className="text-[10px] text-sw-dim italic">Source: {entry.source}</p>
                )}
              </div>
            ))}
          </div>
          <div className="flex items-start gap-2 rounded-lg bg-sw-surface border border-sw-border/60 px-3.5 py-2.5">
            <Info size={12} className="text-sw-dim shrink-0 mt-0.5" />
            <p className="text-[10px] text-sw-dim leading-relaxed">
              {section.disclaimer ?? 'All glossary entries are educational information only and do not constitute tax advice.'}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Collapsed sections footer row (Fix 3) ───────────────────────────────────

/**
 * A single muted row that groups all empty/peripheral sections at the bottom
 * of the report instead of rendering them as full-height competing cards.
 *
 * "Nothing needed in these areas ✓ — Year-End · Glossary · Did-Not-Recommend"
 */
function CollapsedSectionsRow({ sections }: { sections: ReportSection[] }) {
  const [open, setOpen] = useState(false);

  if (sections.length === 0) return null;

  const labelParts = sections.map(sectionShortLabel).join(' · ');

  return (
    <div className="rounded-xl border border-sw-border/60 bg-sw-surface overflow-hidden">
      <button
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-sw-surface/80 transition"
      >
        <div className="flex items-center gap-2">
          <CheckCircle size={13} className="text-sw-success shrink-0" />
          <p className="text-[12px] text-sw-muted">
            <span className="font-medium text-sw-text-secondary">Nothing needed in these areas</span>
            <span className="mx-1.5 text-sw-border-strong">—</span>
            <span className="text-sw-dim">{labelParts}</span>
          </p>
        </div>
        <div className="flex items-center gap-1 shrink-0 ml-2">
          {open ? <ChevronUp size={13} className="text-sw-dim" /> : <ChevronDown size={13} className="text-sw-dim" />}
        </div>
      </button>

      {open && (
        <div className="border-t border-sw-border/60 px-4 py-3 space-y-2">
          {sections.map((section) => (
            <div key={section.section_key} className="flex items-start gap-2">
              <span className="text-sw-success shrink-0 mt-0.5">✓</span>
              <div>
                <p className="text-[12px] font-medium text-sw-text-secondary">{section.title}</p>
                <p className="text-[11px] text-sw-dim leading-relaxed">
                  {sectionEmptyCopy(section)}
                </p>
              </div>
            </div>
          ))}
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
  isRebuilding = false,
}: OptimizationReportViewProps) {
  // Download the report PDF
  const handleDownload = () => {
    window.open(`/api/v1/optimizer/report/${taxYear}/download`, '_blank');
  };

  // Fix 2: isGenerating is the report-level generating state (not isRebuilding which is the overlay)
  const isGenerating = !report || report.status === 'generating';

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
  // Stale-while-revalidate: saved sections ALWAYS render. Spinner only when nothing to show.
  const isFirstGeneration = !report || (report.sections.length === 0 && !error);

  if (isFirstGeneration) {
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

  // ── Ready — partition sections by type ───────────────────────────────────────

  const allSections = report.sections ?? [];

  // Separate special sections for targeted rendering
  const documentsMissingSection = allSections.find((s) => s.section_key === 'documents_missing');
  const whatWeRefusedSection    = allSections.find((s) => s.section_key === 'what_we_refused');
  const yearEndSection          = allSections.find((s) => s.section_type === 'year_end');
  const glossarySection         = allSections.find((s) => s.section_type === 'glossary');

  // Analytic sections (topical + needs_professional_review)
  const analyticSections = allSections.filter((s) =>
    s.section_type === 'topical' ||
    s.section_key === 'needs_professional_review'
  );

  // Fix 3: collect sections that should collapse into the muted footer row
  const collapsedSections: ReportSection[] = [];

  // Year-end goes to collapsed row when no items
  if (yearEndSection) {
    const hasItems = (
      (yearEndSection.dec_31_items?.length ?? 0) > 0 ||
      (yearEndSection.jan_15_items?.length ?? 0) > 0 ||
      (yearEndSection.april_items?.length ?? 0) > 0
    );
    if (!hasItems) collapsedSections.push(yearEndSection);
  }

  // Glossary always goes to collapsed row (shown via GlossaryCard below the collapsed row)
  // We do NOT put glossary in collapsedSections; it gets its own compact card.

  return (
    // Fix 3: relative wrapper for full overlay positioning
    <div className="space-y-4 relative">
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

      {/* D19: Executive summary */}
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
          <div className="flex items-start gap-2 pt-1">
            <Info size={11} className="text-sw-dim shrink-0 mt-0.5" />
            <p className="text-[10px] text-sw-dim leading-relaxed">
              This report may highlight potential areas of interest. Nothing here constitutes tax advice.
              Please consult a qualified tax professional before making any financial decisions.
            </p>
          </div>
        </div>
      )}

      {/* ── Analytic sections (topical + professional review) ── */}
      {analyticSections.length > 0 ? (
        <div className="space-y-3">
          {analyticSections.map((section, idx) => (
            <SectionCard
              key={section.section_key}
              section={section}
              taxYear={taxYear}
              defaultOpen={idx === 0}
              isGenerating={isGenerating}
            />
          ))}
        </div>
      ) : (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-8 text-center">
          <CheckCircle size={28} className="mx-auto text-sw-accent/50 mb-3" />
          <p className="text-sm text-sw-muted">No findings to report for {taxYear}.</p>
        </div>
      )}

      {/* ── Fix 1: Documents Missing — semantic zero-state ── */}
      {documentsMissingSection && (
        <DocumentsMissingCard section={documentsMissingSection} />
      )}

      {/* ── Fix 1: What We Refused — render refused list ── */}
      {whatWeRefusedSection && (
        <WhatWeRefusedCard section={whatWeRefusedSection} />
      )}

      {/* ── Fix 1: Year-End — only rendered when it has calendar items ── */}
      {yearEndSection && (
        <YearEndSectionCard section={yearEndSection} />
      )}

      {/* ── Fix 3: Collapsed sections footer row ── */}
      <CollapsedSectionsRow sections={collapsedSections} />

      {/* ── Glossary — compact reference, never count-based ── */}
      {glossarySection && (
        <GlossaryCard section={glossarySection} />
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

      {/* Fix 3: Full overlay — dims and blurs report content while a rebuild is running. */}
      {isRebuilding && (
        <div
          className="absolute inset-0 z-20 rounded-2xl flex items-center justify-center"
          style={{ backdropFilter: 'blur(3px)', background: 'rgba(255,255,255,0.82)' }}
          aria-live="polite"
          aria-label="Report is regenerating"
        >
          <div className="rounded-2xl border border-sw-accent/20 bg-white shadow-sw-3 px-7 py-7 text-center max-w-xs mx-4 space-y-3">
            <div className="w-12 h-12 mx-auto rounded-2xl bg-sw-accent/10 ring-1 ring-sw-accent/20 flex items-center justify-center animate-pulse">
              <Loader2 size={22} className="animate-spin text-sw-accent" />
            </div>
            <p className="text-[14px] font-semibold text-sw-text leading-snug">
              Report running
            </p>
            <p className="text-[12px] text-sw-muted leading-relaxed">
              We'll notify you when your updated report is ready, or check back in a few minutes.
            </p>
            <p className="text-[10px] text-sw-dim">
              This page updates automatically once complete.
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
