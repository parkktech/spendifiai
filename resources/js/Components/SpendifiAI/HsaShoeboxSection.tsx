/**
 * HsaShoeboxSection — Phase 12-05 Task 3 (STORE-03, UI-03).
 *
 * Lists hsa_shoebox.* facts from GET /api/v1/optimizer/facts and allows
 * adding a new medical receipt (upload → shoebox).
 *
 * EDUCATION_COPY (verbatim from 12-02-SUMMARY, STORE-03):
 *   "Medical expenses you incurred after opening your HSA may be reimbursable
 *   tax-free in any future year, as long as you keep your receipts. The IRS sets
 *   no deadline for reimbursement after the HSA was established. Consider
 *   discussing your specific situation with a tax professional."
 *
 * DESIGN: sw-* tokens; Inter; shadow-sm; 4px/8px rhythm; soft-skill standards.
 */

import { useState } from 'react';
import {
  Archive,
  ChevronDown,
  ChevronUp,
  Plus,
  Info,
  Upload,
  Loader2,
  CheckCircle,
  AlertCircle,
} from 'lucide-react';
import Badge from './Badge';
import FileDropZone from './FileDropZone';
import { useApi } from '@/hooks/useApi';
import type { DurableFactsResponse, UserTaxFactView } from '@/types/spendifiai';
import axios from 'axios';

const EDUCATION_COPY =
  'Medical expenses you incurred after opening your HSA may be reimbursable tax-free in any future year, as long as you keep your receipts. The IRS sets no deadline for reimbursement after the HSA was established. Consider discussing your specific situation with a tax professional.';

function ShoeboxItem({ fact }: { fact: UserTaxFactView }) {
  const meta = fact.metadata ?? {};
  const incurredOn = (meta.incurred_on as string | null) ?? null;
  const description = (meta.description as string | null) ?? null;

  return (
    <div className="flex items-start gap-3 py-2.5 px-3 rounded-lg border border-sw-border bg-sw-card">
      <div className="w-8 h-8 rounded-lg bg-sw-success-light border border-sw-success/20 flex items-center justify-center shrink-0">
        <Archive size={14} className="text-sw-success" />
      </div>
      <div className="flex-1 min-w-0">
        {description && (
          <p className="text-[13px] font-medium text-sw-text break-words">{description}</p>
        )}
        {incurredOn && (
          <p className="text-[11px] text-sw-dim">{incurredOn}</p>
        )}
        {!description && !incurredOn && (
          <p className="text-[12px] text-sw-muted italic">Medical receipt</p>
        )}
      </div>
      <Badge variant="success">Shoebox</Badge>
    </div>
  );
}

export default function HsaShoeboxSection() {
  const [expanded, setExpanded] = useState(false);
  const [addingReceipt, setAddingReceipt] = useState(false);
  const [receiptFile, setReceiptFile] = useState<File | null>(null);
  const [receiptDate, setReceiptDate] = useState('');
  const [receiptDescription, setReceiptDescription] = useState('');
  const [uploading, setUploading] = useState(false);
  const [uploadSuccess, setUploadSuccess] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  const { data, loading, refresh } = useApi<DurableFactsResponse>('/api/v1/optimizer/facts');

  const shoeboxFacts = (data?.confirmed ?? []).filter((f) =>
    f.fact_key.startsWith('hsa_shoebox.')
  );

  const handleAddReceipt = async () => {
    if (!receiptFile) return;
    setUploading(true);
    setUploadError(null);

    try {
      const form = new FormData();
      form.append('file', receiptFile);
      form.append('document_type', 'medical_receipt');
      if (receiptDate) form.append('incurred_on', receiptDate);
      if (receiptDescription) form.append('description', receiptDescription);

      await axios.post('/api/v1/tax-vault/documents', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      setUploadSuccess(true);
      setAddingReceipt(false);
      setReceiptFile(null);
      setReceiptDate('');
      setReceiptDescription('');
      // Refresh after delay
      setTimeout(() => {
        refresh();
        setUploadSuccess(false);
      }, 2500);
    } catch {
      setUploadError('Upload failed. Please try again.');
    } finally {
      setUploading(false);
    }
  };

  return (
    <section aria-labelledby="hsa-shoebox-heading">
      <button
        id="hsa-shoebox-heading"
        onClick={() => setExpanded(!expanded)}
        className="w-full flex items-center justify-between py-3 text-left"
        aria-expanded={expanded}
      >
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-lg bg-sw-success-light border border-sw-success/20 flex items-center justify-center">
            <Archive size={15} className="text-sw-success" />
          </div>
          <div>
            <p className="text-[14px] font-semibold text-sw-text">HSA Receipt Shoebox</p>
            <p className="text-[11px] text-sw-dim">Track medical expenses for potential future reimbursement</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {shoeboxFacts.length > 0 && (
            <Badge variant="success">{shoeboxFacts.length} receipt{shoeboxFacts.length !== 1 ? 's' : ''}</Badge>
          )}
          {expanded ? <ChevronUp size={16} className="text-sw-dim" /> : <ChevronDown size={16} className="text-sw-dim" />}
        </div>
      </button>

      {expanded && (
        <div className="space-y-4 pb-2">
          {/* Education copy (STORE-03 approved wording, UI-03) */}
          <div className="flex items-start gap-2.5 rounded-xl bg-sw-info-light/40 border border-sw-info/20 px-4 py-3">
            <Info size={14} className="text-sw-info shrink-0 mt-0.5" />
            <p className="text-[12px] text-sw-text-secondary leading-relaxed">{EDUCATION_COPY}</p>
          </div>

          {/* Shoebox items */}
          {loading ? (
            <p className="text-[12px] text-sw-dim">Loading receipts...</p>
          ) : shoeboxFacts.length === 0 ? (
            <div className="rounded-xl border border-sw-border bg-sw-card px-4 py-5 text-center">
              <Archive size={22} className="mx-auto text-sw-dim mb-2" />
              <p className="text-[12px] text-sw-muted">No receipts in your shoebox yet.</p>
            </div>
          ) : (
            <div className="space-y-2">
              {shoeboxFacts.map((fact) => (
                <ShoeboxItem key={fact.id} fact={fact} />
              ))}
            </div>
          )}

          {/* Success feedback */}
          {uploadSuccess && (
            <div className="flex items-center gap-2 rounded-lg border border-sw-success/30 bg-sw-success-light px-3 py-2.5">
              <CheckCircle size={14} className="text-sw-success" />
              <span className="text-[12px] text-sw-success font-medium">Receipt added to your shoebox.</span>
            </div>
          )}

          {/* Add receipt flow */}
          {!addingReceipt && (
            <button
              onClick={() => setAddingReceipt(true)}
              className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-sw-border text-sm text-sw-muted hover:text-sw-text hover:border-sw-border-strong hover:bg-sw-surface transition"
            >
              <Plus size={14} /> Add Receipt
            </button>
          )}

          {addingReceipt && (
            <div className="rounded-2xl border border-sw-border bg-sw-card shadow-sm p-5 space-y-4">
              <p className="text-[13px] font-semibold text-sw-text">Add a Medical Receipt</p>

              <div className="space-y-3">
                <div>
                  <label className="block text-[11px] text-sw-muted mb-1">Date incurred (optional)</label>
                  <input
                    type="date"
                    value={receiptDate}
                    onChange={(e) => setReceiptDate(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:ring-2 focus:ring-sw-accent/30 focus:border-sw-accent transition"
                  />
                </div>
                <div>
                  <label className="block text-[11px] text-sw-muted mb-1">Description (optional)</label>
                  <input
                    type="text"
                    value={receiptDescription}
                    onChange={(e) => setReceiptDescription(e.target.value)}
                    placeholder="e.g. Dental exam, prescription..."
                    className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:ring-2 focus:ring-sw-accent/30 focus:border-sw-accent transition"
                  />
                </div>

                <FileDropZone
                  onFileSelect={setReceiptFile}
                  selectedFile={receiptFile}
                  onClear={() => setReceiptFile(null)}
                  acceptedExtensions={['.pdf', '.png', '.jpg', '.jpeg']}
                  acceptedMimes={['application/pdf', 'image/png', 'image/jpeg']}
                />
              </div>

              {uploadError && (
                <p className="text-[11px] text-sw-danger flex items-center gap-1.5">
                  <AlertCircle size={11} /> {uploadError}
                </p>
              )}

              <div className="flex gap-2">
                <button
                  onClick={handleAddReceipt}
                  disabled={!receiptFile || uploading}
                  className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed shadow-sm"
                >
                  {uploading ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />}
                  {uploading ? 'Saving...' : 'Add to Shoebox'}
                </button>
                <button
                  onClick={() => {
                    setAddingReceipt(false);
                    setReceiptFile(null);
                    setUploadError(null);
                  }}
                  className="px-4 py-2.5 rounded-xl border border-sw-border text-sm text-sw-muted hover:text-sw-text transition"
                >
                  Cancel
                </button>
              </div>
            </div>
          )}

          {/* Section disclaimer (UI-03) */}
          <div className="flex items-start gap-2">
            <Info size={11} className="text-sw-dim shrink-0 mt-0.5" />
            <p className="text-[10px] text-sw-dim leading-relaxed">
              HSA reimbursement rules depend on your specific plan and circumstances.
              This shoebox is for your reference only. Consult a tax professional before
              making reimbursement decisions.
            </p>
          </div>
        </div>
      )}
    </section>
  );
}
