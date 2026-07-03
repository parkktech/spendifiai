/**
 * DocumentUploadFlow — Phase 12-05 Task 3 + owner UX fix cluster (Fix 2, 3, 4 surface)
 *                      + Item 1: "Not applicable" per-type exclusion.
 *
 * Multi-step document upload wizard:
 *   Step 1 — Choose document type (financial / substantiation categories from 12-01)
 *             Green-check inventory badges overlay each type that already has a ready doc.
 *             "Not applicable" action per card → muted check "Not applicable — marked by you",
 *             one-click reversible.
 *   Step 2 — Drop/select file (FileDropZone; pdf/png/jpg/jpeg accepted)
 *   Step 3 — Processing indicator (polling document status until ready/failed)
 *   Step 4 — Done: explicit outcome ("Parsed — N fields extracted") or honest failure copy.
 *             Duplicate upload → "Already on file" state (not an error).
 *
 * Fix 2: type grid overlays green check + "Received ✓ · date" for types with ready docs.
 * Fix 3: HTTP 409 duplicate_of response → rendered as "Already on file" (success, not error).
 * Item 1: excluded types show muted "Not applicable — marked by you" state; count as populated
 *         for the accordion tri-state; the parent receives onExclusionsChange when they toggle.
 *
 * DESIGN (Decision 6/7 — elevate, don't replace):
 *   sw-* tokens; Inter; shadow-sm cards; 4px/8px rhythm
 */

import { useState, useEffect } from 'react';
import {
  ChevronRight,
  CheckCircle,
  Loader2,
  AlertCircle,
  Upload,
  FileText,
  Check,
  MinusCircle,
} from 'lucide-react';
import FileDropZone from './FileDropZone';
import axios from 'axios';

// ─── Document type catalogue ───────────────────────────────────────────────────

// paystub is not excludable — it is always required by Stage-0.
const DOC_TYPES: { value: string; label: string; description: string; excludable: boolean }[] = [
  { value: 'paystub', label: 'Pay Stub', description: 'Recent paycheck stub with YTD figures', excludable: false },
  { value: 'w2', label: 'W-2', description: 'Annual wage and tax statement', excludable: true },
  { value: 'benefits_guide', label: 'Benefits Guide', description: 'Employer benefits enrollment document', excludable: true },
  { value: 'hsa_statement', label: 'HSA Statement', description: 'Health Savings Account statement', excludable: true },
  { value: 'retirement_statement', label: 'Retirement Statement', description: '401(k) / IRA account statement', excludable: true },
  { value: 'medical_receipt', label: 'Medical Receipt', description: 'Out-of-pocket medical expense receipt', excludable: true },
  { value: 'other', label: 'Other', description: 'Any other financial document', excludable: true },
];

// ─── Types ─────────────────────────────────────────────────────────────────────

interface DocTypeStatus {
  has_ready_doc: boolean;
  latest_uploaded_at: string | null;
  ready_count: number;
  extracted_fields_count: number;
  is_excluded: boolean;
}

interface TypeStatusResponse {
  types: Record<string, DocTypeStatus>;
  exclusions: string[];
}

interface DuplicateDocInfo {
  id: number;
  category_label: string | null;
  created_at: string | null;
}

type FlowStep = 'type' | 'upload' | 'processing' | 'done';
type DoneVariant = 'success' | 'duplicate' | 'failed' | 'processing_timeout';

export interface DocumentUploadFlowProps {
  /** Called after upload completes — parent should refresh proposals. */
  onComplete?: (documentId: number) => void;
  /** Compact mode for inline use (hides step labels). */
  compact?: boolean;
  /** Called when a type's exclusion state changes — parent should refresh typeStatus. */
  onExclusionsChange?: (exclusions: string[]) => void;
}

// ─── Helpers ───────────────────────────────────────────────────────────────────

function formatDate(iso: string | null): string {
  if (!iso) return '';
  try {
    return new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  } catch {
    return '';
  }
}

export default function DocumentUploadFlow({ onComplete, compact = false, onExclusionsChange }: DocumentUploadFlowProps) {
  const [step, setStep] = useState<FlowStep>('type');
  const [docType, setDocType] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [docId, setDocId] = useState<number | null>(null);

  // Fix 2: type inventory for the type-picker grid (includes is_excluded per Item 1)
  const [typeStatus, setTypeStatus] = useState<Record<string, DocTypeStatus>>({});
  const [typeStatusLoaded, setTypeStatusLoaded] = useState(false);
  // Optimistic exclusion toggle (local state mirrors server response immediately)
  const [togglingExclusion, setTogglingExclusion] = useState<string | null>(null);

  // Done step state
  const [doneVariant, setDoneVariant] = useState<DoneVariant>('success');
  const [extractedFieldsCount, setExtractedFieldsCount] = useState<number | null>(null);
  const [duplicateDoc, setDuplicateDoc] = useState<DuplicateDocInfo | null>(null);

  // Fetch type inventory on mount (for the green-check grid + exclusion state)
  useEffect(() => {
    axios.get<TypeStatusResponse>('/api/v1/tax-vault/type-status')
      .then((res) => {
        setTypeStatus(res.data.types ?? {});
        setTypeStatusLoaded(true);
      })
      .catch(() => {
        // Silently ignore — the grid works without inventory badges
        setTypeStatusLoaded(true);
      });
  }, []);

  // ── "Not applicable" exclusion toggle (Item 1) ────────────────────────────────
  const handleToggleExclusion = async (type: string, exclude: boolean) => {
    setTogglingExclusion(type);
    // Optimistic update
    setTypeStatus((prev) => ({
      ...prev,
      [type]: { ...(prev[type] ?? { has_ready_doc: false, latest_uploaded_at: null, ready_count: 0, extracted_fields_count: 0 }), is_excluded: exclude },
    }));
    try {
      const res = await axios.post<{ exclusions: string[] }>('/api/v1/tax-vault/type-exclusions', {
        type,
        excluded: exclude,
      });
      // Sync server state back into typeStatus (source of truth)
      const exclusions = res.data.exclusions ?? [];
      setTypeStatus((prev) => {
        const updated = { ...prev };
        Object.keys(updated).forEach((k) => {
          updated[k] = { ...updated[k], is_excluded: exclusions.includes(k) };
        });
        return updated;
      });
      onExclusionsChange?.(exclusions);
    } catch {
      // Revert optimistic update on error
      setTypeStatus((prev) => ({
        ...prev,
        [type]: { ...(prev[type] ?? { has_ready_doc: false, latest_uploaded_at: null, ready_count: 0, extracted_fields_count: 0 }), is_excluded: !exclude },
      }));
    } finally {
      setTogglingExclusion(null);
    }
  };

  // ── Step 1: Document type ──────────────────────────────────────────────────────
  const handleTypeSelect = (type: string) => {
    setDocType(type);
    setStep('upload');
  };

  // ── Step 2/3: Upload ───────────────────────────────────────────────────────────
  const handleUpload = async () => {
    if (!file || !docType) return;
    setUploading(true);
    setError(null);
    setStep('processing');

    try {
      const form = new FormData();
      form.append('file', file);
      form.append('document_type', docType);

      const res = await axios.post<{
        document?: { id: number };
        id?: number;
        status?: string;
        extracted_data?: { fields?: Record<string, unknown> } | null;
      }>(
        '/api/v1/tax-vault/documents',
        form,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      );

      const id = res.data.document?.id ?? res.data.id ?? 0;
      setDocId(id);

      // Poll the document status until ready/failed (up to ~30s)
      await pollDocumentStatus(id);
      onComplete?.(id);
    } catch (err: unknown) {
      // Fix 3: HTTP 409 = duplicate — treat as "Already on file", not an error
      const axiosErr = err as { response?: { status?: number; data?: { message?: string; duplicate_of?: DuplicateDocInfo } } };
      if (axiosErr.response?.status === 409) {
        const dupOf = axiosErr.response.data?.duplicate_of ?? null;
        setDuplicateDoc(dupOf);
        setDoneVariant('duplicate');
        setStep('done');
        // Still call onComplete so the parent refreshes proposals/facts
        if (dupOf?.id) onComplete?.(dupOf.id);
      } else {
        const message =
          axiosErr.response?.data?.message ?? 'Upload failed. Please try again.';
        setError(message);
        setStep('upload');
      }
    } finally {
      setUploading(false);
    }
  };

  /**
   * Poll GET /api/v1/tax-vault/documents/{id} until status is 'ready' or 'failed'.
   * Timeout after ~30s → show processing_timeout done variant.
   */
  const pollDocumentStatus = async (id: number): Promise<void> => {
    const maxAttempts = 15;
    const intervalMs = 2000;

    for (let i = 0; i < maxAttempts; i++) {
      await new Promise((r) => setTimeout(r, intervalMs));
      try {
        const res = await axios.get<{
          status?: string;
          extracted_data?: { fields?: Record<string, unknown> } | null;
        }>(`/api/v1/tax-vault/documents/${id}`);

        const status = res.data?.status;
        if (status === 'ready' || status === 'failed') {
          const fieldsObj = res.data.extracted_data?.fields ?? {};
          const count = Object.values(fieldsObj).filter((f) => {
            if (typeof f === 'object' && f !== null) {
              return (f as Record<string, unknown>).value != null;
            }
            return f != null;
          }).length;

          setExtractedFieldsCount(count);
          setDoneVariant(status === 'ready' ? 'success' : 'failed');
          setStep('done');
          return;
        }
      } catch {
        // Transient error — continue polling
      }
    }

    // Timed out
    setDoneVariant('processing_timeout');
    setStep('done');
  };

  const handleReset = () => {
    setStep('type');
    setDocType('');
    setFile(null);
    setError(null);
    setDocId(null);
    setDoneVariant('success');
    setExtractedFieldsCount(null);
    setDuplicateDoc(null);
    // Refresh type inventory after a successful upload (includes is_excluded state)
    axios.get<TypeStatusResponse>('/api/v1/tax-vault/type-status')
      .then((res) => {
        setTypeStatus(res.data.types ?? {});
        if (res.data.exclusions) {
          onExclusionsChange?.(res.data.exclusions);
        }
      })
      .catch(() => {});
  };

  // ─── Step indicators ───────────────────────────────────────────────────────────
  const stepLabels: { key: FlowStep; label: string }[] = [
    { key: 'type', label: 'Choose type' },
    { key: 'upload', label: 'Upload' },
    { key: 'processing', label: 'Processing' },
    { key: 'done', label: 'Done' },
  ];
  const stepIdx = stepLabels.findIndex((s) => s.key === step);

  return (
    <div className="space-y-4">
      {/* Step indicator */}
      {!compact && (
        <div className="flex items-center gap-2 min-w-0 overflow-hidden">
          {stepLabels.map((s, i) => (
            <div key={s.key} className="flex items-center gap-2 shrink-0">
              <div className={`w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold transition-colors shrink-0 ${
                i < stepIdx
                  ? 'bg-sw-success text-white'
                  : i === stepIdx
                    ? 'bg-sw-accent text-white'
                    : 'bg-sw-border text-sw-dim'
              }`}>
                {i < stepIdx ? <CheckCircle size={12} /> : i + 1}
              </div>
              <span className={`text-[11px] ${i === stepIdx ? 'inline text-sw-text font-medium' : 'hidden sm:inline text-sw-dim'}`}>{s.label}</span>
              {i < stepLabels.length - 1 && <ChevronRight size={12} className="text-sw-dim mx-0.5 sm:mx-1 shrink-0" />}
            </div>
          ))}
        </div>
      )}

      {/* ── Step 1: Type selection (Fix 2: green-check + Item 1: not-applicable) ── */}
      {step === 'type' && (
        <div className="space-y-2">
          <p className="text-[12px] text-sw-muted mb-3">
            What type of document would you like to upload?
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
            {DOC_TYPES.map((dt) => {
              const status = typeStatus[dt.value];
              const hasReady = status?.has_ready_doc === true;
              const isExcluded = status?.is_excluded === true;
              const date = hasReady && status.latest_uploaded_at
                ? formatDate(status.latest_uploaded_at)
                : null;
              const count = status?.ready_count ?? 0;
              const isToggling = togglingExclusion === dt.value;

              // Excluded state: muted "not applicable" card — still clickable to upload if needed
              if (isExcluded) {
                return (
                  <div
                    key={dt.value}
                    className="text-left p-3.5 rounded-xl border border-sw-border/50 bg-sw-surface/40 relative"
                  >
                    <div className="flex items-start gap-2.5">
                      <MinusCircle size={16} className="text-sw-dim shrink-0 mt-0.5" />
                      <div className="min-w-0 flex-1">
                        <p className="text-[13px] font-semibold text-sw-muted">{dt.label}</p>
                        <p className="text-[11px] text-sw-dim leading-snug mt-0.5">Not applicable — marked by you</p>
                      </div>
                      <div className="flex items-center gap-1.5 shrink-0">
                        <button
                          onClick={() => handleToggleExclusion(dt.value, false)}
                          disabled={isToggling}
                          className="text-[10px] text-sw-accent hover:text-sw-accent-hover underline-offset-2 hover:underline disabled:opacity-50 transition-colors"
                          title="Remove 'Not applicable' — restore this document type"
                        >
                          {isToggling ? 'Updating…' : 'Undo'}
                        </button>
                        <span className="text-sw-border text-[10px]">·</span>
                        <button
                          onClick={() => handleTypeSelect(dt.value)}
                          className="text-[10px] text-sw-muted hover:text-sw-text underline-offset-2 hover:underline transition-colors"
                          title="Upload this type anyway"
                        >
                          Upload anyway
                        </button>
                      </div>
                    </div>
                  </div>
                );
              }

              return (
                <div key={dt.value} className="relative group">
                  <button
                    onClick={() => handleTypeSelect(dt.value)}
                    className={`w-full text-left p-3.5 rounded-xl border transition ${
                      hasReady
                        ? 'border-sw-success/40 bg-sw-success-light/30 hover:border-sw-success/60 hover:bg-sw-success-light/50'
                        : 'border-sw-border bg-sw-card hover:border-sw-accent hover:bg-sw-accent/5'
                    }`}
                  >
                    <div className="flex items-start gap-2.5">
                      {hasReady ? (
                        <div className="w-7 h-7 rounded-full bg-sw-success flex items-center justify-center shrink-0 mt-0.5">
                          <Check size={13} className="text-white" />
                        </div>
                      ) : (
                        <FileText size={16} className="text-sw-muted group-hover:text-sw-accent shrink-0 mt-0.5 transition-colors" />
                      )}
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-1.5">
                          <p className="text-[13px] font-semibold text-sw-text">{dt.label}</p>
                          {count > 1 && (
                            <span className="text-[10px] text-sw-success font-semibold bg-sw-success/10 px-1.5 py-0.5 rounded-full">
                              ×{count}
                            </span>
                          )}
                        </div>
                        {hasReady && date ? (
                          <p className="text-[11px] text-sw-success leading-snug mt-0.5">
                            Received ✓ · {date}
                          </p>
                        ) : (
                          <p className="text-[11px] text-sw-dim leading-snug mt-0.5">{dt.description}</p>
                        )}
                      </div>
                    </div>
                  </button>
                  {/* "Not applicable" action — visible on hover for excludable types */}
                  {dt.excludable && !hasReady && (
                    <button
                      onClick={(e) => { e.stopPropagation(); handleToggleExclusion(dt.value, true); }}
                      disabled={isToggling}
                      className="absolute top-2 right-2 text-[10px] text-sw-dim hover:text-sw-muted opacity-0 group-hover:opacity-100 transition-opacity disabled:opacity-50 px-1.5 py-0.5 rounded bg-sw-surface border border-sw-border"
                      title="Mark as not applicable — you don't have this type of document"
                    >
                      {isToggling ? '…' : 'Not applicable'}
                    </button>
                  )}
                </div>
              );
            })}
          </div>
          {!typeStatusLoaded && (
            <div className="flex items-center gap-1.5 px-1">
              <Loader2 size={11} className="animate-spin text-sw-dim" />
              <span className="text-[10px] text-sw-dim">Loading inventory…</span>
            </div>
          )}
        </div>
      )}

      {/* ── Step 2: File upload ──────────────────────────────────────────────── */}
      {step === 'upload' && (
        <div className="space-y-3">
          <div className="flex items-center gap-2">
            <span className="text-[12px] text-sw-muted">Document type:</span>
            <span className="text-[12px] font-semibold text-sw-text">
              {DOC_TYPES.find((d) => d.value === docType)?.label ?? docType}
            </span>
            <button
              onClick={() => setStep('type')}
              className="text-[11px] text-sw-accent hover:text-sw-accent-hover ml-1"
            >
              Change
            </button>
          </div>

          <FileDropZone
            onFileSelect={setFile}
            selectedFile={file}
            onClear={() => setFile(null)}
            acceptedExtensions={['.pdf', '.png', '.jpg', '.jpeg']}
            acceptedMimes={['application/pdf', 'image/png', 'image/jpeg']}
          />

          {error && (
            <p className="text-[11px] text-sw-danger flex items-center gap-1.5">
              <AlertCircle size={11} /> {error}
            </p>
          )}

          <button
            onClick={handleUpload}
            disabled={!file || uploading}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed shadow-sm"
          >
            {uploading ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />}
            {uploading ? 'Uploading...' : 'Upload Document'}
          </button>
        </div>
      )}

      {/* ── Step 3: Processing ───────────────────────────────────────────────── */}
      {step === 'processing' && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-8 text-center space-y-3">
          <Loader2 size={28} className="mx-auto animate-spin text-sw-accent" />
          <p className="text-sm font-medium text-sw-text">Extracting information…</p>
          <p className="text-xs text-sw-muted">
            AI is reading your document. This takes a moment.
          </p>
        </div>
      )}

      {/* ── Step 4: Done ─────────────────────────────────────────────────────── */}
      {step === 'done' && (
        <div className={`rounded-2xl border p-6 text-center space-y-3 ${
          doneVariant === 'failed'
            ? 'border-sw-danger/30 bg-sw-danger/5'
            : 'border-sw-success/30 bg-sw-success-light'
        }`}>
          {doneVariant === 'failed' ? (
            <>
              <AlertCircle size={28} className="mx-auto text-sw-danger" />
              <p className="text-sm font-semibold text-sw-text">Extraction failed</p>
              <p className="text-xs text-sw-muted max-w-xs mx-auto">
                We could not extract data from this document. You can try re-uploading,
                or answer the interview questions manually.
              </p>
            </>
          ) : doneVariant === 'duplicate' ? (
            <>
              <CheckCircle size={28} className="mx-auto text-sw-success" />
              <p className="text-sm font-semibold text-sw-text">Already on file</p>
              <p className="text-xs text-sw-muted max-w-xs mx-auto">
                {duplicateDoc?.category_label
                  ? `Your ${duplicateDoc.category_label}${duplicateDoc.created_at ? ` uploaded ${formatDate(duplicateDoc.created_at)}` : ''} is already in your vault — no duplicate created.`
                  : 'This document is already in your vault — no duplicate created.'}
              </p>
            </>
          ) : doneVariant === 'processing_timeout' ? (
            <>
              <CheckCircle size={28} className="mx-auto text-sw-success" />
              <p className="text-sm font-semibold text-sw-text">Upload received</p>
              <p className="text-xs text-sw-muted max-w-xs mx-auto">
                Your document is still being processed. Suggestions will appear below
                once extraction completes — usually within a minute.
              </p>
            </>
          ) : (
            <>
              <CheckCircle size={28} className="mx-auto text-sw-success" />
              <p className="text-sm font-semibold text-sw-text">
                {extractedFieldsCount !== null && extractedFieldsCount > 0
                  ? `Parsed — ${extractedFieldsCount} field${extractedFieldsCount !== 1 ? 's' : ''} extracted`
                  : 'Parsed successfully'}
              </p>
              <p className="text-xs text-sw-muted max-w-xs mx-auto">
                {extractedFieldsCount !== null && extractedFieldsCount > 0
                  ? 'Review and confirm the suggestions below to save them to your profile.'
                  : 'No data fields were found in this document. You can answer questions manually.'}
              </p>
            </>
          )}
          <button
            onClick={handleReset}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-sw-border text-xs text-sw-muted hover:text-sw-text transition"
          >
            Upload another
          </button>
        </div>
      )}
    </div>
  );
}
