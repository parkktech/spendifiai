import { useState } from 'react';
import { ChevronDown, ChevronUp, Check, X } from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import type { SavingsRecommendation } from '@/types/spendifiai';

interface RecommendationCardProps {
  recommendation: SavingsRecommendation;
  onDismiss: (id: number) => void;
  onApply: (id: number) => void;
}

const difficultyBorder: Record<string, string> = {
  hard: 'border-l-sw-danger',
  medium: 'border-l-sw-warning',
  easy: 'border-l-sw-success',
};

const difficultyVariant: Record<string, 'danger' | 'warning' | 'success'> = {
  hard: 'danger',
  medium: 'warning',
  easy: 'success',
};

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export default function RecommendationCard({ recommendation, onDismiss, onApply }: RecommendationCardProps) {
  const [expanded, setExpanded] = useState(false);
  const isApplied = recommendation.status === 'applied';
  const borderClass = difficultyBorder[recommendation.difficulty] ?? 'border-l-sw-border';
  const variant = difficultyVariant[recommendation.difficulty] ?? 'neutral';

  return (
    <div
      className={`rounded-lg border border-sw-border border-l-4 ${borderClass} bg-sw-card p-4 transition-opacity ${
        isApplied ? 'opacity-50' : ''
      }`}
    >
      {/* Header: title + savings */}
      <div className="flex items-start justify-between mb-2">
        <div className="flex-1 pr-3">
          <div className="flex items-center gap-2 mb-1">
            {isApplied && <Check size={16} className="text-sw-accent shrink-0" />}
            <h3 className={`text-sm font-semibold ${isApplied ? 'text-sw-muted line-through' : 'text-sw-text'}`}>
              {recommendation.title}
            </h3>
          </div>
          <p className="text-xs text-sw-muted leading-relaxed">{recommendation.description}</p>
        </div>
        <div className="text-right shrink-0">
          <div className="text-lg font-bold text-sw-accent">{fmt.format(recommendation.monthly_savings)}/mo</div>
          <div className="text-[10px] text-sw-dim">{fmt.format(recommendation.annual_savings)}/yr</div>
        </div>
      </div>

      {/* Category + priority badges */}
      <div className="flex items-center gap-2 mb-3">
        <Badge variant={variant as 'success' | 'warning' | 'danger'}>
          {recommendation.difficulty}
        </Badge>
        <Badge variant="info">{recommendation.category}</Badge>
      </div>

      {/* Expandable action steps */}
      {recommendation.action_steps && recommendation.action_steps.length > 0 && (
        <div className="mb-3">
          <button
            onClick={() => setExpanded(!expanded)}
            className="flex items-center gap-1 text-xs text-sw-muted hover:text-sw-text transition"
          >
            {expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
            {expanded ? 'Hide' : 'Show'} action steps ({recommendation.action_steps.length})
          </button>
          {expanded && (
            <ul className="mt-2 space-y-1.5 pl-4">
              {recommendation.action_steps.map((step, i) => (
                <li key={i} className="text-xs text-sw-muted list-disc leading-relaxed">
                  {step}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {/* Related merchants */}
      {recommendation.related_merchants && recommendation.related_merchants.length > 0 && (
        <div className="text-[11px] text-sw-dim mb-3">
          Related: {recommendation.related_merchants.join(', ')}
        </div>
      )}

      {/* Actions */}
      {!isApplied && (
        <div className="flex items-center gap-3 pt-2 border-t border-sw-border">
          <button
            onClick={() => onDismiss(recommendation.id)}
            className="inline-flex items-center gap-1 text-xs text-sw-dim hover:text-sw-danger transition"
          >
            <X size={14} />
            Dismiss
          </button>
          <button
            onClick={() => onApply(recommendation.id)}
            className="ml-auto px-4 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition"
          >
            Apply
          </button>
        </div>
      )}
    </div>
  );
}
