/**
 * DocumentUploadFlow — Phase 12-05 Task 3 (D4, DOC-05, UI-03).
 *
 * Multi-step document upload wizard:
 *   Step 1 — Choose document type (financial / substantiation categories from 12-01)
 *   Step 2 — Drop/select file (FileDropZone; pdf/png/jpg/jpeg accepted)
 *   Step 3 — Processing indicator (polling status)
 *   Step 4 — Review extracted data + navigate to proposals
 *
 * Reuses FileDropZone for the file selection step (unchanged).
 * Posts to POST /api/v1/documents (existing vault upload endpoint).
 *
 * DESIGN (Decision 6/7 — elevate, don't replace):
 *   sw-* tokens; Inter; shadow-sm cards; 4px/8px rhythm
 *   Applied: soft-skill — step indicator, spacing rhythm, focus ring on inputs
 */

import { useState } from 'react';
import {
  ChevronRight,
  CheckCircle,
  Loader2,
  AlertCircle,
  Upload,
  FileText,
} from 'lucide-react';
import FileDropZone from './FileDropZone';
import axios from 'axios';

// ─── Document type catalogue (subset from 12-01 TaxDocumentCategory) ──────────

const DOC_TYPES: { value: string; label: string; description: string }[] = [
  { value: 'paystub', label: 'Pay Stub', description: 'Recent paycheck stub with YTD figures' },
  { value: 'w2', label: 'W-2', description: 'Annual wage and tax statement' },
  { value: 'benefits_guide', label: 'Benefits Guide', description: 'Employer benefits enrollment document' },
  { value: 'hsa_statement', label: 'HSA Statement', description: 'Health Savings Account statement' },
  { value: 'retirement_statement', label: 'Retirement Statement', description: '401(k) / IRA account statement' },
  { value: 'medical_receipt', label: 'Medical Receipt', description: 'Out-of-pocket medical expense receipt' },
  { value: 'other', label: 'Other', description: 'Any other financial document' },
];

type FlowStep = 'type' | 'upload' | 'processing' | 'done';

export interface DocumentUploadFlowProps {
  /** Called after upload completes — parent should refresh proposals. */
  onComplete?: (documentId: number) => void;
  /** Compact mode for inline use (hides step labels). */
  compact?: boolean;
}

export default function DocumentUploadFlow({ onComplete, compact = false }: DocumentUploadFlowProps) {
  const [step, setStep] = useState<FlowStep>('type');
  const [docType, setDocType] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [docId, setDocId] = useState<number | null>(null);

  // ── Step 1: Document type ─────────────────────────────────────────────────────
  const handleTypeSelect = (type: string) => {
    setDocType(type);
    setStep('upload');
  };

  // ── Step 2/3: Upload ──────────────────────────────────────────────────────────
  const handleUpload = async () => {
    if (!file || !docType) return;
    setUploading(true);
    setError(null);
    setStep('processing');

    try {
      const form = new FormData();
      form.append('file', file);
      form.append('document_type', docType);

      const res = await axios.post<{ document?: { id: number }; id?: number }>(
        '/api/v1/tax-vault/documents',
        form,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      );

      const id = res.data.document?.id ?? res.data.id ?? 0;
      setDocId(id);
      setStep('done');
      onComplete?.(id);
    } catch (err: unknown) {
      const message =
        (err as { response?: { data?: { message?: string } } })?.response?.data?.message ??
        'Upload failed. Please try again.';
      setError(message);
      setStep('upload');
    } finally {
      setUploading(false);
    }
  };

  const handleReset = () => {
    setStep('type');
    setDocType('');
    setFile(null);
    setError(null);
    setDocId(null);
  };

  // ─── Step indicators ──────────────────────────────────────────────────────────
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

      {/* ── Step 1: Type selection ─────────────────────────────────────────── */}
      {step === 'type' && (
        <div className="space-y-2">
          <p className="text-[12px] text-sw-muted mb-3">
            What type of document would you like to upload?
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
            {DOC_TYPES.map((dt) => (
              <button
                key={dt.value}
                onClick={() => handleTypeSelect(dt.value)}
                className="text-left p-3.5 rounded-xl border border-sw-border bg-sw-card hover:border-sw-accent hover:bg-sw-accent/5 transition group"
              >
                <div className="flex items-start gap-2.5">
                  <FileText size={16} className="text-sw-muted group-hover:text-sw-accent shrink-0 mt-0.5 transition-colors" />
                  <div>
                    <p className="text-[13px] font-semibold text-sw-text">{dt.label}</p>
                    <p className="text-[11px] text-sw-dim leading-snug mt-0.5">{dt.description}</p>
                  </div>
                </div>
              </button>
            ))}
          </div>
        </div>
      )}

      {/* ── Step 2: File upload ────────────────────────────────────────────── */}
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

      {/* ── Step 3: Processing ─────────────────────────────────────────────── */}
      {step === 'processing' && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-8 text-center space-y-3">
          <Loader2 size={28} className="mx-auto animate-spin text-sw-accent" />
          <p className="text-sm font-medium text-sw-text">Extracting information...</p>
          <p className="text-xs text-sw-muted">
            AI may take a moment to analyze your document.
          </p>
        </div>
      )}

      {/* ── Step 4: Done ───────────────────────────────────────────────────── */}
      {step === 'done' && (
        <div className="rounded-2xl border border-sw-success/30 bg-sw-success-light p-6 text-center space-y-3">
          <CheckCircle size={28} className="mx-auto text-sw-success" />
          <p className="text-sm font-semibold text-sw-text">Upload complete</p>
          <p className="text-xs text-sw-muted max-w-xs mx-auto">
            Your document has been processed. Review and confirm any extracted suggestions below.
          </p>
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
