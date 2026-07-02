/**
 * LearnedTaxFactsSection — Phase 11-05 STORE-01 anchor UI (Decision 1/D3).
 *
 * An additive review section rendered alongside EnhancedProfileSection in
 * Settings. Shows the user's durable tax facts from the append-only UserTaxFact
 * store: confirmed facts and pending proposals (document_extraction awaiting D4
 * user confirmation).
 *
 * Actions:
 *   Confirm  (proposals only)  — POST /optimizer/facts/{id}/confirm
 *   Re-answer (confirmed only) — POST /optimizer/facts/{id}/supersede
 *
 * EDUCATIONAL FRAMING (mandatory per SAFE requirements):
 *   All copy uses "may / could / consider". Non-assertive language throughout.
 *   An inline disclaimer is always visible.
 *
 * DESIGN (D14 / Decision 7 — elevate, don't replace):
 *   sw-* tokens only. Inter type stack. Badge for source/confidence indicators.
 *   Applied: ui-ux-pro-max "review list with confirm and edit actions" direction —
 *   clear row hierarchy, confidence indicator for doc-extraction proposals,
 *   inline confirm/edit actions without disruptive modals.
 *   Applied: soft-skill — spacing rhythm 8px gap on rows, 1.5rem between groups,
 *   subtle border separators, no heavy shadows on list rows.
 *
 * PRESERVATION AUDIT (Decision 7 Step 4):
 *   - EnhancedProfileSection: unchanged (no props changes, no behavior changes)
 *   - Settings page: additive only (this component added after EnhancedProfileSection)
 *   - No URL, nav label, form field name, or existing anchor changed
 */

import { useState, useCallback } from 'react';
import { BookOpen, Check, Pencil, Loader2, Send, ChevronDown, ChevronRight, Info, Sparkles } from 'lucide-react';
import Badge from './Badge';
import { useApi } from '@/hooks/useApi';
import type { UserTaxFactView, DurableFactsResponse } from '@/types/spendifiai';
import axios from 'axios';

// ── Source label helpers ──────────────────────────────────────────────────────

function sourceLabel(sourceType: UserTaxFactView['source_type']): string {
  switch (sourceType) {
    case 'interview_answer': return 'Interview';
    case 'document_extraction': return 'From document';
    case 'detector': return 'Auto-detected';
    case 'profile_field': return 'From profile';
    case 'user_edit': return 'Edited';
    default: return 'Manual';
  }
}

function sourceBadgeVariant(
  sourceType: UserTaxFactView['source_type']
): 'success' | 'info' | 'warning' | 'neutral' {
  switch (sourceType) {
    case 'interview_answer': return 'success';
    case 'document_extraction': return 'warning';
    case 'detector': return 'info';
    case 'user_edit': return 'success';
    default: return 'neutral';
  }
}

/** Extraction confidence as a % string if present in metadata. */
function extractionConfidence(fact: UserTaxFactView): number | null {
  if (fact.source_type !== 'document_extraction') return null;
  const conf = fact.metadata?.extraction_confidence;
  if (typeof conf === 'number') return Math.round(conf * 100);
  return null;
}

// ── Sub-components ────────────────────────────────────────────────────────────

interface FactRowProps {
  fact: UserTaxFactView;
  onConfirm: (factId: number) => Promise<void>;
  onSupersede: (factId: number, newAnswer: string) => Promise<void>;
}

function FactRow({ fact, onConfirm, onSupersede }: FactRowProps) {
  const [confirming, setConfirming] = useState(false);
  const [editing, setEditing] = useState(false);
  const [editValue, setEditValue] = useState('');
  const [saving, setSaving] = useState(false);
  const [localConfirmed, setLocalConfirmed] = useState(false);

  const confidence = extractionConfidence(fact);
  const isProposal = fact.source_type === 'document_extraction' && !fact.confirmed_at && !fact.is_current;

  const handleConfirm = async () => {
    setConfirming(true);
    try {
      await onConfirm(fact.id);
      setLocalConfirmed(true);
    } finally {
      setConfirming(false);
    }
  };

  const handleSupersede = async () => {
    if (!editValue.trim()) return;
    setSaving(true);
    try {
      await onSupersede(fact.id, editValue.trim());
      setEditing(false);
      setEditValue('');
    } finally {
      setSaving(false);
    }
  };

  const confirmed = localConfirmed || (fact.confirmed_at !== null);

  return (
    <div className={`px-4 py-3 border-b border-sw-border/60 last:border-b-0 ${
      isProposal ? 'bg-amber-50/40' : ''
    }`}>
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div className="flex-1 min-w-0">
          {/* Label */}
          <p className="text-[13px] font-medium text-sw-text leading-snug">
            {fact.label ?? fact.fact_key}
          </p>

          {/* Metadata row */}
          <div className="flex items-center gap-2 flex-wrap mt-1">
            <Badge variant={sourceBadgeVariant(fact.source_type)}>
              {sourceLabel(fact.source_type)}
            </Badge>

            {fact.tax_year && (
              <span className="text-[11px] text-sw-dim">Tax year {fact.tax_year}</span>
            )}

            {isProposal && (
              <Badge variant="warning">Awaiting confirmation</Badge>
            )}

            {confidence !== null && (
              <span className="text-[11px] text-sw-muted">
                {confidence}% confidence
              </span>
            )}

            {confirmed && !isProposal && fact.source_type === 'document_extraction' && (
              <span className="text-[11px] text-sw-success flex items-center gap-0.5">
                <Check size={10} /> Confirmed
              </span>
            )}
          </div>

          {/* Edit inline form */}
          {editing && (
            <div className="mt-3 flex gap-2">
              <input
                type="text"
                value={editValue}
                onChange={(e) => setEditValue(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && !saving && handleSupersede()}
                placeholder="Your updated answer..."
                disabled={saving}
                autoFocus
                className="flex-1 px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-xs placeholder:text-sw-dim focus:outline-none focus:ring-2 focus:ring-sw-accent/30 focus:border-sw-accent transition disabled:opacity-50"
              />
              <button
                onClick={handleSupersede}
                disabled={!editValue.trim() || saving}
                className="px-3 py-2 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
              >
                {saving ? <Loader2 size={13} className="animate-spin" /> : <Send size={13} />}
              </button>
              <button
                onClick={() => { setEditing(false); setEditValue(''); }}
                className="px-3 py-2 rounded-lg border border-sw-border text-xs text-sw-muted hover:text-sw-text transition"
              >
                Cancel
              </button>
            </div>
          )}
        </div>

        {/* Action buttons */}
        <div className="flex items-center gap-2 shrink-0 self-start">
          {isProposal && !localConfirmed && (
            <button
              onClick={handleConfirm}
              disabled={confirming}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
            >
              {confirming ? <Loader2 size={12} className="animate-spin" /> : <Check size={12} />}
              Confirm
            </button>
          )}

          {localConfirmed && (
            <span className="text-[11px] text-sw-success flex items-center gap-1">
              <Check size={12} /> Confirmed
            </span>
          )}

          {fact.is_current && !editing && !isProposal && (
            <button
              onClick={() => setEditing(true)}
              className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-sw-border text-xs text-sw-muted hover:text-sw-text hover:border-sw-border-strong transition"
              title="Update this answer"
            >
              <Pencil size={12} /> Re-answer
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────

export default function LearnedTaxFactsSection() {
  const { data, loading, error, refresh } = useApi<DurableFactsResponse>('/api/v1/optimizer/facts');
  const [expanded, setExpanded] = useState(true);
  const [localFacts, setLocalFacts] = useState<DurableFactsResponse | null>(null);

  // Use local state for optimistic updates, fall back to API data
  const facts = localFacts ?? data;

  const handleConfirm = useCallback(async (factId: number) => {
    await axios.post(`/api/v1/optimizer/facts/${factId}/confirm`);
    // Optimistic: move from proposals to confirmed
    setLocalFacts((prev) => {
      const base = prev ?? data;
      if (!base) return prev;
      const proposal = base.proposals.find((f) => f.id === factId);
      if (!proposal) return prev;
      const confirmed: UserTaxFactView = {
        ...proposal,
        is_current: true,
        confirmed_at: new Date().toISOString(),
        source_type: 'document_extraction',
      };
      return {
        confirmed: [...base.confirmed, confirmed],
        proposals: base.proposals.filter((f) => f.id !== factId),
      };
    });
  }, [data]);

  const handleSupersede = useCallback(async (factId: number, newAnswer: string) => {
    await axios.post(`/api/v1/optimizer/facts/${factId}/supersede`, { answer: newAnswer });
    // Refresh the list to show the new current fact
    refresh();
    setLocalFacts(null);
  }, [refresh]);

  const confirmedFacts = facts?.confirmed ?? [];
  const proposalFacts = facts?.proposals ?? [];
  const totalFacts = confirmedFacts.length + proposalFacts.length;

  if (loading) {
    return (
      <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
        <div className="flex items-center gap-3 mb-4">
          <div className="w-9 h-9 rounded-lg bg-sw-info-light border border-violet-200 flex items-center justify-center">
            <BookOpen size={18} className="text-sw-info" />
          </div>
          <div>
            <h3 className="text-[15px] font-semibold text-sw-text">AI-Learned Tax Facts</h3>
            <p className="text-xs text-sw-dim">Loading your tax profile facts...</p>
          </div>
        </div>
        <div className="flex justify-center py-6">
          <Loader2 size={20} className="animate-spin text-sw-accent" />
        </div>
      </div>
    );
  }

  if (error || (facts && totalFacts === 0)) {
    // If there are no facts yet, show a subtle informational card
    if (totalFacts === 0) {
      return (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-9 h-9 rounded-lg bg-sw-info-light border border-violet-200 flex items-center justify-center">
              <BookOpen size={18} className="text-sw-info" />
            </div>
            <div>
              <h3 className="text-[15px] font-semibold text-sw-text">AI-Learned Tax Facts</h3>
              <p className="text-xs text-sw-dim">Facts gathered from your interview and documents</p>
            </div>
          </div>
          <div className="rounded-lg border border-sw-border bg-sw-surface/50 px-4 py-4 text-center">
            <Sparkles size={20} className="mx-auto text-sw-info/60 mb-2" />
            <p className="text-xs text-sw-muted">
              No tax facts on file yet. Complete the Income Review questions to build your profile,
              or upload tax documents to have facts extracted automatically.
            </p>
          </div>
          <FactsDisclaimer />
        </div>
      );
    }
    return null; // silent fail if API error (non-critical feature)
  }

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card overflow-hidden">
      {/* Section header */}
      <button
        onClick={() => setExpanded((e) => !e)}
        className="w-full flex items-center justify-between px-6 py-4 text-left hover:bg-sw-surface/30 transition"
      >
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-lg bg-sw-info-light border border-violet-200 flex items-center justify-center shrink-0">
            <BookOpen size={18} className="text-sw-info" />
          </div>
          <div>
            <h3 className="text-[15px] font-semibold text-sw-text">AI-Learned Tax Facts</h3>
            <p className="text-xs text-sw-dim">
              {confirmedFacts.length} confirmed{proposalFacts.length > 0 ? `, ${proposalFacts.length} awaiting confirmation` : ''}
              {' '}&mdash; review, confirm, or re-answer below
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          {proposalFacts.length > 0 && (
            <Badge variant="warning">{proposalFacts.length} pending</Badge>
          )}
          {expanded ? <ChevronDown size={16} className="text-sw-dim" /> : <ChevronRight size={16} className="text-sw-dim" />}
        </div>
      </button>

      {expanded && (
        <div className="border-t border-sw-border">
          {/* Educational framing */}
          <div className="px-6 py-3 bg-sw-surface/40 border-b border-sw-border/60">
            <div className="flex items-start gap-2">
              <Info size={13} className="text-sw-info shrink-0 mt-0.5" />
              <p className="text-[11px] text-sw-muted leading-relaxed">
                These facts were gathered from your interview responses and uploaded documents.
                They may help identify relevant tax opportunities. Review and confirm to include
                them in your profile. You can re-answer any confirmed fact at any time.
              </p>
            </div>
          </div>

          {/* Proposals section */}
          {proposalFacts.length > 0 && (
            <div>
              <div className="px-4 py-2 bg-amber-50/60 border-b border-amber-100">
                <p className="text-[11px] font-semibold text-amber-800 uppercase tracking-wide">
                  Awaiting your confirmation
                </p>
              </div>
              {proposalFacts.map((fact) => (
                <FactRow
                  key={fact.id}
                  fact={fact}
                  onConfirm={handleConfirm}
                  onSupersede={handleSupersede}
                />
              ))}
            </div>
          )}

          {/* Confirmed facts section */}
          {confirmedFacts.length > 0 && (
            <div>
              {proposalFacts.length > 0 && (
                <div className="px-4 py-2 bg-sw-surface/50 border-b border-sw-border/60">
                  <p className="text-[11px] font-semibold text-sw-muted uppercase tracking-wide">
                    Confirmed facts
                  </p>
                </div>
              )}
              {confirmedFacts.map((fact) => (
                <FactRow
                  key={fact.id}
                  fact={fact}
                  onConfirm={handleConfirm}
                  onSupersede={handleSupersede}
                />
              ))}
            </div>
          )}

          {/* Footer disclaimer */}
          <div className="px-6 py-4 border-t border-sw-border/60">
            <FactsDisclaimer />
          </div>
        </div>
      )}
    </div>
  );
}

function FactsDisclaimer() {
  return (
    <div className="flex items-start gap-1.5 mt-2">
      <Info size={11} className="text-sw-dim shrink-0 mt-0.5" />
      <p className="text-[10px] text-sw-dim leading-relaxed">
        AI-gathered facts are suggestions and may not reflect your actual tax situation.
        All information could benefit from review by a qualified tax professional.
        SpendifiAI does not provide tax, legal, or financial advice.
      </p>
    </div>
  );
}
