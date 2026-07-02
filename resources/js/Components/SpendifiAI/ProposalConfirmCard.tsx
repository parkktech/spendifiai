/**
 * ProposalConfirmCard — Phase 12-05 Task 3 (D4 gate, UI-03).
 *
 * Shows a single proposed fact extracted from a document (is_current=false,
 * source_type='document_extraction') with three actions:
 *   Confirm → POST /api/v1/optimizer/facts/{fact}/confirm (D4 gate)
 *   Edit    → editable field; submits via POST /api/v1/optimizer/facts/{fact}/supersede
 *   Reject  → local dismiss only (the proposal row stays in the DB until TTL)
 *
 * LAYOUT (value-first per owner brief):
 *   label (small, muted) → display_value (large hero) → source_label → actions
 *   Confidence chip rendered in the action row (not blocking the value).
 *
 * SECURITY (T-12-05-01): Nothing is written until the user explicitly confirms.
 *
 * DESIGN (Decision 6/7 — elevate, don't replace):
 *   sw-* tokens only; Inter type stack; shadow-sm depth; 4px/8px rhythm
 *
 * D18 NOTE: fact_key is never rendered as UI copy. label and display_value are the
 * only user-visible fact content — never snake_case key paths.
 *
 * DISCLAIMER: one disclaimer for the whole proposals group is rendered in the parent
 * (Optimize/Index.tsx). This card omits the per-card paragraph.
 */

import { useState } from 'react';
import {
  Check,
  Pencil,
  X,
  Loader2,
  AlertCircle,
  Sparkles,
  Save,
} from 'lucide-react';
import Badge from './Badge';
import type { UserTaxFactView } from '@/types/spendifiai';
import axios from 'axios';

export interface ProposalConfirmCardProps {
  fact: UserTaxFactView;
  /** Called when the proposal is confirmed (to refresh the parent). */
  onConfirmed: (factId: number) => void;
  /** Called when the proposal is rejected (local dismiss). */
  onRejected: (factId: number) => void;
}

function ConfidenceChip({ metadata }: { metadata: Record<string, unknown> | null }) {
  const conf = metadata?.confidence ?? metadata?.extraction_confidence;
  if (typeof conf !== 'number') return null;
  const pct = Math.round(conf * 100);
  const variant = pct >= 85 ? 'success' : pct >= 60 ? 'warning' : 'neutral';
  return (
    <Badge variant={variant} className="text-[10px] font-medium">
      {pct}% confident
    </Badge>
  );
}

export default function ProposalConfirmCard({
  fact,
  onConfirmed,
  onRejected,
}: ProposalConfirmCardProps) {
  const [state, setState] = useState<'idle' | 'editing' | 'confirming' | 'superseding' | 'done' | 'rejected'>('idle');
  const isSavingEdit = state === 'superseding';
  const [editValue, setEditValue] = useState('');
  const [error, setError] = useState<string | null>(null);

  const handleConfirm = async () => {
    setState('confirming');
    setError(null);
    try {
      await axios.post(`/api/v1/optimizer/facts/${fact.id}/confirm`);
      setState('done');
      onConfirmed(fact.id);
    } catch {
      setError('Could not confirm. Please try again.');
      setState('idle');
    }
  };

  const handleEdit = () => {
    setState('editing');
    setEditValue('');
  };

  const handleSaveEdit = async () => {
    if (!editValue.trim()) return;
    setState('superseding');
    setError(null);
    try {
      await axios.post(`/api/v1/optimizer/facts/${fact.id}/supersede`, {
        answer: editValue.trim(),
      });
      setState('done');
      onConfirmed(fact.id);
    } catch {
      setError('Could not save your edit. Please try again.');
      setState('idle');
    }
  };

  const handleReject = () => {
    setState('rejected');
    onRejected(fact.id);
  };

  // ── Confirmed state ───────────────────────────────────────────────────────────
  if (state === 'done') {
    return (
      <div className="rounded-xl border border-sw-success/30 bg-sw-success-light px-4 py-3 flex items-center gap-3">
        <div className="w-7 h-7 rounded-full bg-sw-success flex items-center justify-center shrink-0">
          <Check size={13} className="text-white" />
        </div>
        <div>
          <p className="text-[13px] font-semibold text-sw-success">Saved to your profile</p>
          <p className="text-[11px] text-sw-muted mt-0.5">{fact.label}</p>
        </div>
      </div>
    );
  }

  // ── Rejected state ────────────────────────────────────────────────────────────
  if (state === 'rejected') {
    return null;
  }

  // ── Idle / editing ─────────────────────────────────────────────────────────────
  return (
    <div className="rounded-xl border border-sw-border bg-sw-card shadow-sm overflow-hidden">
      {/* Header strip */}
      <div className="flex items-center justify-between px-4 pt-3 pb-2 border-b border-sw-border/60 bg-sw-surface/40">
        <div className="flex items-center gap-2">
          <Sparkles size={12} className="text-sw-info" />
          <span className="text-[11px] font-semibold text-sw-text-secondary uppercase tracking-wide">
            Extracted from document
          </span>
        </div>
        <Badge variant="neutral">Pending review</Badge>
      </div>

      <div className="p-4 space-y-3">
        {/* VALUE hero — this is what the user came to see */}
        <div>
          {/* Label (muted, small) */}
          <p className="text-[11px] text-sw-muted font-medium mb-1 leading-snug">{fact.label}</p>

          {/* display_value — big and prominent */}
          {fact.display_value ? (
            <p className="text-[22px] font-[700] text-sw-text tracking-tight leading-none">
              {fact.display_value}
            </p>
          ) : (
            <p className="text-[14px] font-semibold text-sw-dim italic">Value not yet available</p>
          )}

          {/* Source attribution */}
          {fact.source_label && (
            <p className="text-[11px] text-sw-dim mt-1 leading-snug">{fact.source_label}</p>
          )}
        </div>

        {/* Editing area */}
        {state === 'editing' ? (
          <div className="space-y-2 pt-1">
            <label className="block text-[11px] font-medium text-sw-muted mb-0.5">
              Enter the correct value:
            </label>
            <input
              type="text"
              value={editValue}
              onChange={(e) => setEditValue(e.target.value)}
              placeholder={fact.display_value ?? 'Enter value...'}
              className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:ring-2 focus:ring-sw-accent/30 focus:border-sw-accent transition"
              autoFocus
            />
            <div className="flex gap-2">
              <button
                onClick={handleSaveEdit}
                disabled={!editValue.trim() || isSavingEdit}
                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
              >
                {isSavingEdit ? <Loader2 size={12} className="animate-spin" /> : <Save size={12} />}
                Save my value
              </button>
              <button
                onClick={() => setState('idle')}
                className="px-3.5 py-2 rounded-lg border border-sw-border text-xs text-sw-muted hover:text-sw-text transition"
              >
                Cancel
              </button>
            </div>
          </div>
        ) : (
          /* Action row */
          <div className="flex items-center gap-2 pt-1">
            <button
              onClick={handleConfirm}
              disabled={state === 'confirming'}
              className="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {state === 'confirming' ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />}
              Confirm
            </button>
            <button
              onClick={handleEdit}
              className="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-lg border border-sw-border text-sm text-sw-muted hover:text-sw-text hover:border-sw-border-strong transition"
              title="Edit before confirming"
            >
              <Pencil size={13} />
              Edit
            </button>
            <button
              onClick={handleReject}
              className="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-lg border border-sw-border text-sm text-sw-muted hover:text-sw-danger hover:border-sw-danger/40 transition"
              aria-label="Reject suggestion"
              title="Not correct — dismiss"
            >
              <X size={13} />
            </button>
            {/* Confidence chip — informational, not blocking */}
            <div className="ml-auto">
              <ConfidenceChip metadata={fact.metadata} />
            </div>
          </div>
        )}

        {/* Error */}
        {error && (
          <p className="text-[11px] text-sw-danger flex items-center gap-1.5">
            <AlertCircle size={11} /> {error}
          </p>
        )}
      </div>
    </div>
  );
}
