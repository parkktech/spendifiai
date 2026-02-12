import { useState } from 'react';
import { Sparkles, Check, Loader2, Send, MessageCircle, ArrowRight } from 'lucide-react';
import Badge from './Badge';
import type { AIQuestion } from '@/types/spendwise';
import axios from 'axios';

interface QuestionCardProps {
  question: AIQuestion;
  onAnswer: (id: number, answer: string) => void;
}

interface AISuggestion {
  category: string;
  expense_type: string;
  tax_deductible: boolean;
  explanation: string;
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

  // Chat with AI state
  const [showChat, setShowChat] = useState(false);
  const [chatMessage, setChatMessage] = useState('');
  const [chatLoading, setChatLoading] = useState(false);
  const [aiSuggestion, setAiSuggestion] = useState<AISuggestion | null>(null);
  const [chatError, setChatError] = useState<string | null>(null);

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

  const handleChatSubmit = async () => {
    if (!chatMessage.trim()) return;

    setChatLoading(true);
    setChatError(null);
    setAiSuggestion(null);

    try {
      const response = await axios.post<AISuggestion>(
        `/api/v1/questions/${question.id}/chat`,
        { message: chatMessage.trim() }
      );
      setAiSuggestion(response.data);
    } catch {
      setChatError('Could not get AI suggestion. Try again or pick an option above.');
    } finally {
      setChatLoading(false);
    }
  };

  const handleApplySuggestion = () => {
    if (aiSuggestion) {
      handleAnswer(aiSuggestion.category);
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

      {/* Tell AI More */}
      {!answered && !aiSuggestion && (
        <div className="mt-3">
          {!showChat ? (
            <button
              onClick={() => setShowChat(true)}
              className="flex items-center gap-1.5 text-[11px] text-sw-muted hover:text-sw-accent transition"
            >
              <MessageCircle size={12} />
              None of these? Tell AI more
            </button>
          ) : (
            <div className="mt-2 space-y-2">
              <p className="text-[11px] text-sw-dim">
                Describe this purchase and the AI will suggest the right category:
              </p>
              <div className="flex gap-2">
                <input
                  type="text"
                  value={chatMessage}
                  onChange={(e) => setChatMessage(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && !chatLoading && handleChatSubmit()}
                  placeholder="e.g. This was for my daughter's birthday party..."
                  disabled={chatLoading}
                  className="flex-1 px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-xs placeholder:text-sw-dim focus:outline-none focus:border-sw-accent disabled:opacity-50"
                />
                <button
                  onClick={handleChatSubmit}
                  disabled={!chatMessage.trim() || chatLoading}
                  className="px-3 py-2 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {chatLoading ? (
                    <Loader2 size={14} className="animate-spin" />
                  ) : (
                    <Send size={14} />
                  )}
                </button>
              </div>
              {chatError && (
                <p className="text-[11px] text-red-500">{chatError}</p>
              )}
            </div>
          )}
        </div>
      )}

      {/* AI Suggestion */}
      {aiSuggestion && !answered && (
        <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
          <div className="flex items-start gap-2">
            <Sparkles size={14} className="text-emerald-600 mt-0.5 shrink-0" />
            <div className="flex-1 min-w-0">
              <p className="text-xs text-sw-text">
                I'd categorize this as <span className="font-semibold text-emerald-700">{aiSuggestion.category}</span>
                {aiSuggestion.expense_type !== 'personal' && (
                  <span className="text-sw-dim"> ({aiSuggestion.expense_type})</span>
                )}
                {aiSuggestion.tax_deductible && (
                  <Badge variant="success">Tax Deductible</Badge>
                )}
              </p>
              <p className="text-[11px] text-sw-muted mt-1">{aiSuggestion.explanation}</p>
            </div>
          </div>
          <div className="flex gap-2 mt-3">
            <button
              onClick={handleApplySuggestion}
              disabled={submitting}
              className="flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700 transition disabled:opacity-50"
            >
              {submitting ? <Loader2 size={12} className="animate-spin" /> : <Check size={12} />}
              Apply
            </button>
            <button
              onClick={() => {
                setAiSuggestion(null);
                setChatMessage('');
              }}
              disabled={submitting}
              className="px-3.5 py-1.5 rounded-lg border border-sw-border text-xs font-medium text-sw-muted hover:text-sw-text transition"
            >
              Try again
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
