import { useState } from 'react';
import { Sparkles, Check, Loader2, Send } from 'lucide-react';
import Badge from './Badge';
import type { AIQuestion } from '@/types/spendwise';

interface QuestionCardProps {
  question: AIQuestion;
  onAnswer: (id: number, answer: string) => void;
}

function formatDate(dateStr: string): string {
  const date = new Date(dateStr);
  return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric' }).format(date);
}

export default function QuestionCard({ question, onAnswer }: QuestionCardProps) {
  const [selected, setSelected] = useState<string | null>(null);
  const [freeText, setFreeText] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [answered, setAnswered] = useState(false);

  const hasOptions = question.options && question.options.length > 0;
  const tx = question.transaction;

  const handleAnswer = async (answer: string) => {
    setSubmitting(true);
    setSelected(answer);
    try {
      await onAnswer(question.id, answer);
      setAnswered(true);
    } finally {
      setSubmitting(false);
    }
  };

  const handleFreeTextSubmit = () => {
    if (freeText.trim()) {
      handleAnswer(freeText.trim());
    }
  };

  return (
    <div
      className={`rounded-xl border border-sw-border bg-sw-card p-5 transition-opacity ${
        answered ? 'opacity-50' : ''
      }`}
    >
      {/* Header */}
      <div className="flex items-start gap-3 mb-4">
        <div className="w-8 h-8 rounded-lg bg-sw-info-light border border-violet-200 flex items-center justify-center shrink-0">
          <Sparkles size={15} className="text-sw-info" />
        </div>
        <div className="flex-1 min-w-0">
          {tx && (
            <div className="flex items-center gap-2 mb-1 flex-wrap">
              <span className="text-[11px] text-sw-dim truncate">{tx.merchant_name}</span>
              <span className="text-sw-dim text-[11px]">-</span>
              <span className="text-[11px] text-sw-dim">{formatDate(tx.date)}</span>
              <span className="text-[11px] font-semibold text-sw-text">
                ${Math.abs(tx.amount).toFixed(2)}
              </span>
              {tx.ai_confidence !== null && (
                <Badge variant="warning">{Math.round(tx.ai_confidence * 100)}% sure</Badge>
              )}
            </div>
          )}
          <p className="text-[13px] text-sw-text leading-relaxed">{question.question}</p>
        </div>
      </div>

      {/* Answer Options */}
      {hasOptions ? (
        <div className="flex gap-2 flex-wrap">
          {question.options!.map((opt) => (
            <button
              key={opt}
              onClick={() => !answered && handleAnswer(opt)}
              disabled={answered || submitting}
              className={`px-3.5 py-1.5 rounded-lg border text-xs font-medium transition ${
                selected === opt
                  ? 'border-sw-accent bg-sw-accent/10 text-sw-accent'
                  : 'border-sw-border bg-transparent text-sw-muted hover:text-sw-text hover:border-sw-muted'
              } disabled:cursor-not-allowed`}
            >
              {selected === opt && <Check size={12} className="inline mr-1" />}
              {opt}
            </button>
          ))}
        </div>
      ) : (
        <div className="flex gap-2">
          <input
            type="text"
            value={freeText}
            onChange={(e) => setFreeText(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && handleFreeTextSubmit()}
            placeholder="Type your answer..."
            disabled={answered || submitting}
            className="flex-1 px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-xs placeholder:text-sw-dim focus:outline-none focus:border-sw-accent disabled:opacity-50"
          />
          <button
            onClick={handleFreeTextSubmit}
            disabled={!freeText.trim() || answered || submitting}
            className="px-3 py-2 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {submitting ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />}
          </button>
        </div>
      )}
    </div>
  );
}
