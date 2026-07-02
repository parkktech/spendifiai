/**
 * AiOnboardingUploadSection — Phase 12-05 Task 3 (D4, DOC-07, UI-03).
 *
 * "Fastest path to a complete profile": upload a paystub → poll facts API →
 * render a ProposalConfirmCard per proposed field (is_current=false,
 * source_type='document_extraction').
 *
 * SECURITY (T-12-05-01): Nothing is written until each field is explicitly
 * confirmed via ProposalConfirmCard → POST /api/v1/optimizer/facts/{fact}/confirm.
 *
 * DESIGN: sw-* tokens; Inter; shadow-sm; 4px/8px rhythm; soft-skill standards.
 *
 * EDUCATIONAL FRAMING (UI-03): Inline disclaimer; 'may/could/consider' copy.
 */

import { useState, useCallback } from 'react';
import {
  FileText,
  ChevronDown,
  ChevronUp,
  Info,
  RefreshCw,
  Sparkles,
} from 'lucide-react';
import DocumentUploadFlow from './DocumentUploadFlow';
import ProposalConfirmCard from './ProposalConfirmCard';
import { useApi } from '@/hooks/useApi';
import type { DurableFactsResponse, UserTaxFactView } from '@/types/spendifiai';

export default function AiOnboardingUploadSection() {
  const [expanded, setExpanded] = useState(true);
  const [uploadDone, setUploadDone] = useState(false);
  const [dismissedIds, setDismissedIds] = useState<Set<number>>(new Set());

  const {
    data: factsData,
    loading: factsLoading,
    refresh: refreshFacts,
  } = useApi<DurableFactsResponse>('/api/v1/optimizer/facts');

  const proposals = (factsData?.proposals ?? []).filter(
    (p) => !dismissedIds.has(p.id)
  );

  const handleUploadComplete = useCallback(() => {
    setUploadDone(true);
    // Poll facts after a delay (give extraction job time to run)
    setTimeout(() => refreshFacts(), 4000);
  }, [refreshFacts]);

  const handleConfirmed = useCallback((id: number) => {
    // Refresh to remove the confirmed proposal from the proposals list
    setTimeout(() => refreshFacts(), 500);
  }, [refreshFacts]);

  const handleRejected = useCallback((id: number) => {
    setDismissedIds((prev) => new Set(prev).add(id));
  }, []);

  return (
    <section aria-labelledby="ai-onboarding-heading">
      {/* Section header */}
      <button
        id="ai-onboarding-heading"
        onClick={() => setExpanded(!expanded)}
        className="w-full flex items-center justify-between py-3 text-left group"
        aria-expanded={expanded}
      >
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-lg bg-sw-accent/10 border border-sw-accent/20 flex items-center justify-center">
            <Sparkles size={15} className="text-sw-accent" />
          </div>
          <div>
            <p className="text-[14px] font-semibold text-sw-text">AI Profile Onboarding</p>
            <p className="text-[11px] text-sw-dim">Upload a document to pre-fill your profile</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {proposals.length > 0 && (
            <span className="px-2 py-0.5 rounded-full bg-sw-warning/20 text-sw-warning text-[11px] font-semibold">
              {proposals.length} pending
            </span>
          )}
          {expanded ? <ChevronUp size={16} className="text-sw-dim" /> : <ChevronDown size={16} className="text-sw-dim" />}
        </div>
      </button>

      {expanded && (
        <div className="space-y-5 pb-2">
          {/* Educational intro (UI-03) */}
          <div className="flex items-start gap-2.5 rounded-xl bg-sw-surface border border-sw-border px-4 py-3">
            <Info size={14} className="text-sw-info shrink-0 mt-0.5" />
            <p className="text-[12px] text-sw-text-secondary leading-relaxed">
              <span className="font-semibold text-sw-text">Fastest way to a complete profile.</span>{' '}
              Upload a recent pay stub or benefits guide and we may be able to fill in many fields automatically.
              Nothing is saved until you confirm each suggestion below.
            </p>
          </div>

          {/* Upload flow */}
          <div className="rounded-2xl border border-sw-border bg-sw-card shadow-sm p-5">
            <div className="flex items-center gap-2 mb-4">
              <FileText size={15} className="text-sw-accent" />
              <p className="text-[13px] font-semibold text-sw-text">Upload a Document</p>
            </div>
            <DocumentUploadFlow
              onComplete={handleUploadComplete}
            />
          </div>

          {/* Proposals */}
          {(proposals.length > 0 || factsLoading) && (
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <p className="text-[13px] font-semibold text-sw-text">
                  {proposals.length > 0
                    ? `${proposals.length} suggestion${proposals.length !== 1 ? 's' : ''} to review`
                    : 'Checking for suggestions...'}
                </p>
                <button
                  onClick={() => refreshFacts()}
                  disabled={factsLoading}
                  className="inline-flex items-center gap-1.5 text-[11px] text-sw-muted hover:text-sw-text transition"
                >
                  <RefreshCw size={12} className={factsLoading ? 'animate-spin' : ''} />
                  Refresh
                </button>
              </div>

              {proposals.map((fact: UserTaxFactView) => (
                <ProposalConfirmCard
                  key={fact.id}
                  fact={fact}
                  onConfirmed={handleConfirmed}
                  onRejected={handleRejected}
                />
              ))}
            </div>
          )}

          {/* Empty proposals state after upload */}
          {uploadDone && proposals.length === 0 && !factsLoading && (
            <div className="rounded-xl border border-sw-border bg-sw-card px-4 py-4 text-center space-y-1">
              <p className="text-[13px] font-medium text-sw-text">No new suggestions</p>
              <p className="text-[11px] text-sw-dim">
                All extracted fields have been reviewed, or no fields could be extracted from this document.
              </p>
            </div>
          )}

          {/* Section disclaimer (UI-03) */}
          <div className="flex items-start gap-2">
            <Info size={11} className="text-sw-dim shrink-0 mt-0.5" />
            <p className="text-[10px] text-sw-dim leading-relaxed">
              AI extraction may contain errors. Review each suggestion carefully before confirming.
              This tool is for profile pre-fill purposes only and does not constitute tax advice.
            </p>
          </div>
        </div>
      )}
    </section>
  );
}
