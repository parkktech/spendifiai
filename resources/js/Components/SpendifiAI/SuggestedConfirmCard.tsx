/**
 * SuggestedConfirmCard — Phase 11-05 INT-07 "suggested-confirm" UI.
 *
 * Renders a pre-filled treatment suggestion for auto-band optimization
 * findings. The treatment is shown as visually non-committed (sw-accent-light
 * highlight, italicised) until the user explicitly confirms.
 *
 * Two outcomes:
 *   Confirm  → calls onConfirm(suggested_treatment). Parent answers the question
 *              and the interview advances. pro_export_ready blocked until confirmed.
 *   Not quite → calls onEdit(). Parent shows the standard answer UI (options/free text).
 *
 * EDUCATIONAL FRAMING (mandatory per SAFE requirements):
 *   All copy uses "may / could / consider" — no assertive language.
 *   An inline disclaimer is shown at the bottom of every card.
 *
 * DESIGN (D14 / Decision 7 — elevate, don't replace):
 *   sw-* tokens only. Badge for band indicator. Inter type stack preserved.
 *   Dimensional layering via subtle shadow + sw-accent-light border for
 *   the non-committed treatment highlight.
 */

import { useState } from 'react';
import { Sparkles, Check, Pencil, Loader2, AlertCircle, Info } from 'lucide-react';
import Badge from './Badge';

export interface SuggestedConfirmCardProps {
  /** The pre-filled treatment text from the AI (INT-07). */
  suggestedTreatment: string;
  /** Band from the originating rule (auto | conditional | specialist). */
  band: 'auto' | 'conditional' | 'specialist' | null;
  /** How many transactions are linked to this finding. */
  transactionCount: number;
  /** Called when user clicks Confirm with the treatment string. */
  onConfirm: (treatment: string) => Promise<void>;
  /** Called when user clicks "Not quite" — parent shows manual answer UI. */
  onEdit: () => void;
  /** Whether the confirm API call is in flight. */
  disabled?: boolean;
}

export default function SuggestedConfirmCard({
  suggestedTreatment,
  band,
  transactionCount,
  onConfirm,
  onEdit,
  disabled = false,
}: SuggestedConfirmCardProps) {
  const [confirming, setConfirming] = useState(false);
  const [confirmed, setConfirmed] = useState(false);

  const handleConfirm = async () => {
    setConfirming(true);
    try {
      await onConfirm(suggestedTreatment);
      setConfirmed(true);
    } finally {
      setConfirming(false);
    }
  };

  const bandLabel = band === 'auto'
    ? 'High Confidence'
    : band === 'conditional'
      ? 'Needs Review'
      : band === 'specialist'
        ? 'Specialist'
        : 'Suggestion';

  const bandVariant: 'success' | 'warning' | 'info' | 'neutral' =
    band === 'auto' ? 'success' : band === 'conditional' ? 'warning' : 'info';

  if (confirmed) {
    return (
      <div className="rounded-xl border border-sw-success/30 bg-sw-success-light p-4 flex items-center gap-3">
        <div className="w-8 h-8 rounded-full bg-sw-success flex items-center justify-center shrink-0">
          <Check size={15} className="text-white" />
        </div>
        <div>
          <p className="text-[13px] font-semibold text-sw-success">Confirmed</p>
          <p className="text-[11px] text-sw-muted mt-0.5">
            Your response has been recorded. This may be used to identify relevant tax opportunities.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-xl border border-sw-border bg-sw-card overflow-hidden shadow-sm">
      {/* Band indicator header */}
      <div className="flex items-center justify-between px-4 pt-3 pb-2 border-b border-sw-border/60">
        <div className="flex items-center gap-2">
          <Sparkles size={13} className="text-sw-info" />
          <span className="text-[11px] font-semibold text-sw-text-secondary uppercase tracking-wide">
            AI Suggestion
          </span>
        </div>
        <Badge variant={bandVariant}>{bandLabel}</Badge>
      </div>

      <div className="p-4 space-y-3">
        {/* Pre-filled treatment — visually non-committed */}
        <div
          className="rounded-lg border border-sw-accent/30 bg-sw-accent-light px-4 py-3"
          aria-label="Suggested treatment (not yet confirmed)"
        >
          <p className="text-[11px] text-sw-accent font-semibold uppercase tracking-wide mb-1">
            Suggested treatment
          </p>
          <p className="text-[13px] text-sw-text italic leading-relaxed">
            {suggestedTreatment}
          </p>
          {transactionCount > 0 && (
            <p className="text-[11px] text-sw-muted mt-1.5">
              Based on {transactionCount} transaction{transactionCount !== 1 ? 's' : ''}
            </p>
          )}
        </div>

        {/* Pending state indicator */}
        <div className="flex items-center gap-1.5 px-1">
          <AlertCircle size={12} className="text-sw-warning shrink-0" />
          <span className="text-[11px] text-sw-warning font-medium">
            Not counted yet
          </span>
          <span className="text-[11px] text-sw-dim">
            — confirm to include this in your profile
          </span>
        </div>

        {/* Action buttons */}
        <div className="flex gap-2 pt-1">
          <button
            onClick={handleConfirm}
            disabled={confirming || disabled}
            className="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {confirming ? (
              <Loader2 size={14} className="animate-spin" />
            ) : (
              <Check size={14} />
            )}
            Confirm
          </button>
          <button
            onClick={onEdit}
            disabled={confirming || disabled}
            className="flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sm font-medium text-sw-muted hover:text-sw-text hover:border-sw-border-strong transition disabled:opacity-50"
          >
            <Pencil size={13} />
            Not quite
          </button>
        </div>

        {/* Educational disclaimer */}
        <div className="flex items-start gap-1.5 px-1 pt-1">
          <Info size={11} className="text-sw-dim shrink-0 mt-0.5" />
          <p className="text-[10px] text-sw-dim leading-relaxed">
            This is a suggestion based on your transaction patterns and may not reflect your actual tax
            situation. Consider consulting a qualified tax professional before making financial decisions.
          </p>
        </div>
      </div>
    </div>
  );
}
