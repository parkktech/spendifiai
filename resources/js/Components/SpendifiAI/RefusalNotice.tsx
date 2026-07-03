/**
 * RefusalNotice — SAFE-06 gap-closure component.
 *
 * Rendered when the backend returns {refused: true} on the chat or interview
 * escape-hatch path. Provides D18-compliant educational framing:
 *   - Leads with "This isn't something we can help optimize" (never the scheme name as headline)
 *   - Shows scheme category in body context only
 *   - Displays the education copy (what/why — never how)
 *   - Appends the best_effort_disclaimer verbatim from config
 *   - Amber/neutral styling — never green / success
 *   - No Apply or action affordance
 *
 * The input field remains available in the parent component so the user can
 * ask a different, legitimate question.
 */

import { AlertTriangle, Info } from 'lucide-react';

export interface RefusalResponse {
  refused: true;
  category: string;
  education: string;
  best_effort_disclaimer: string;
  blocked_reason: 'hard_block_safe06';
}

interface RefusalNoticeProps {
  refusal: RefusalResponse;
}

export default function RefusalNotice({ refusal }: RefusalNoticeProps) {
  return (
    <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3.5 space-y-2.5">
      {/* Header — D18: lead with refusal framing, not the scheme name */}
      <div className="flex items-start gap-2">
        <AlertTriangle size={14} className="text-amber-600 mt-0.5 shrink-0" />
        <p className="text-xs font-semibold text-amber-800">
          This isn&rsquo;t something we can help optimize
        </p>
      </div>

      {/* Education body — scheme name appears here in context, not as headline */}
      <div className="pl-5 space-y-1.5">
        <p className="text-[11px] text-amber-900 leading-relaxed">
          <span className="font-medium">{refusal.category}</span> falls outside our scope.{' '}
          {refusal.education}
        </p>
      </div>

      {/* best_effort_disclaimer — verbatim config copy, once per refusal */}
      <div className="pl-5 flex items-start gap-1.5 pt-1 border-t border-amber-200/70">
        <Info size={11} className="text-amber-500 shrink-0 mt-0.5" />
        <p className="text-[10px] text-amber-700 leading-relaxed">
          {refusal.best_effort_disclaimer}
        </p>
      </div>
    </div>
  );
}
