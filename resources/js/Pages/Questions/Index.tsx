import { useState, useCallback } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
  HelpCircle,
  CheckCircle,
  Loader2,
  AlertTriangle,
  RefreshCw,
  ToggleLeft,
  ToggleRight,
  Send,
} from 'lucide-react';
import QuestionCard from '@/Components/SpendifiAI/QuestionCard';
import Badge from '@/Components/SpendifiAI/Badge';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { AIQuestion } from '@/types/spendifiai';
import axios from 'axios';

export default function QuestionsIndex() {
  const { data, loading, error, refresh } = useApi<AIQuestion[]>('/api/v1/questions?status=pending');
  const [bulkMode, setBulkMode] = useState(false);
  const [bulkAnswers, setBulkAnswers] = useState<Record<number, string>>({});
  const [bulkSubmitting, setBulkSubmitting] = useState(false);
  const [answeredIds, setAnsweredIds] = useState<Set<number>>(new Set());

  const questions = (data || []).filter((q) => !answeredIds.has(q.id));

  const handleAnswer = useCallback(
    async (id: number, answer: string) => {
      try {
        await axios.post(`/api/v1/questions/${id}/answer`, { answer });
        setAnsweredIds((prev) => new Set(prev).add(id));
      } catch {
        // error handled by QuestionCard
      }
    },
    []
  );

  const handleBulkSubmit = async () => {
    const answers = Object.entries(bulkAnswers)
      .filter(([_, answer]) => answer.trim())
      .map(([id, answer]) => ({ question_id: Number(id), answer }));

    if (answers.length === 0) return;

    setBulkSubmitting(true);
    try {
      await axios.post('/api/v1/questions/bulk-answer', { answers });
      setAnsweredIds((prev) => {
        const next = new Set(prev);
        answers.forEach((a) => next.add(a.question_id));
        return next;
      });
      setBulkAnswers({});
    } catch {
      // ignore
    } finally {
      setBulkSubmitting(false);
    }
  };

  const answeredCount = Object.keys(bulkAnswers).filter((id) => bulkAnswers[Number(id)]?.trim()).length;

  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text tracking-tight">AI Questions</h1>
          <p className="text-xs text-sw-dim mt-0.5">Help your AI categorize transactions accurately</p>
        </div>
      }
    >
      <Head title="AI Questions" />

      {/* Header bar */}
      <div className="flex items-center justify-between mb-6">
        <div className="flex items-center gap-3">
          <HelpCircle size={20} className="text-sw-info" />
          <span className="text-sm font-medium text-sw-text">
            {questions.length} question{questions.length !== 1 ? 's' : ''} need{questions.length === 1 ? 's' : ''} your attention
          </span>
        </div>
        <button
          onClick={() => setBulkMode(!bulkMode)}
          className="flex items-center gap-2 px-3 py-1.5 rounded-lg border border-sw-border text-xs font-medium text-sw-muted hover:text-sw-text transition"
        >
          {bulkMode ? <ToggleRight size={16} className="text-sw-accent" /> : <ToggleLeft size={16} />}
          {bulkMode ? 'Single Mode' : 'Bulk Mode'}
        </button>
      </div>

      {/* Error */}
      {error && (
        <div className="rounded-2xl border border-sw-danger/30 bg-sw-danger/5 p-6 text-center mb-6">
          <AlertTriangle size={24} className="mx-auto text-sw-danger mb-2" />
          <p className="text-sm text-sw-text mb-3">{error}</p>
          <button
            onClick={refresh}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition"
          >
            <RefreshCw size={14} /> Retry
          </button>
        </div>
      )}

      {/* Loading */}
      {loading && (
        <div className="flex items-center justify-center py-16">
          <Loader2 size={24} className="animate-spin text-sw-accent" />
        </div>
      )}

      {/* Empty state */}
      {!loading && !error && questions.length === 0 && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-12 text-center">
          <CheckCircle size={40} className="mx-auto text-sw-accent mb-3" />
          <h3 className="text-sm font-semibold text-sw-text mb-1">All caught up!</h3>
          <p className="text-xs text-sw-muted">No questions pending. Your transactions are well categorized.</p>
        </div>
      )}

      {/* Single mode */}
      {!loading && !bulkMode && questions.length > 0 && (
        <div aria-live="polite" className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {questions.map((q) => (
            <QuestionCard key={q.id} question={q} onAnswer={handleAnswer} />
          ))}
        </div>
      )}

      {/* Bulk mode */}
      {!loading && bulkMode && questions.length > 0 && (
        <div>
          <div className="rounded-2xl border border-sw-border bg-sw-card overflow-hidden">
            {/* Table header */}
            <div className="grid grid-cols-1 md:grid-cols-[1fr_1fr_200px] gap-4 px-5 py-3 border-b border-sw-border bg-sw-bg/50">
              <span className="text-xs font-medium text-sw-dim uppercase tracking-wider">Transaction</span>
              <span className="text-xs font-medium text-sw-dim uppercase tracking-wider">Question</span>
              <span className="text-xs font-medium text-sw-dim uppercase tracking-wider">Answer</span>
            </div>

            {/* Table rows */}
            {questions.map((q) => (
              <div
                key={q.id}
                className="grid grid-cols-1 md:grid-cols-[1fr_1fr_200px] gap-4 px-5 py-3 border-b border-sw-border last:border-b-0 items-center"
              >
                <div className="min-w-0">
                  <div className="text-xs font-medium text-sw-text truncate">
                    {q.transaction?.merchant_name || 'Unknown'}
                  </div>
                  <div className="text-[11px] text-sw-dim">
                    ${q.transaction ? Math.abs(q.transaction.amount).toFixed(2) : '0.00'}
                  </div>
                </div>
                <p className="text-xs text-sw-muted truncate">{q.question}</p>
                <div>
                  {q.options && q.options.length > 0 ? (
                    <select
                      value={bulkAnswers[q.id] || ''}
                      onChange={(e) =>
                        setBulkAnswers({ ...bulkAnswers, [q.id]: e.target.value })
                      }
                      className="w-full px-2 py-1.5 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-xs focus:outline-none focus:border-sw-accent"
                    >
                      <option value="">Select...</option>
                      {q.options.map((opt) => (
                        <option key={opt} value={opt}>{opt}</option>
                      ))}
                    </select>
                  ) : (
                    <input
                      type="text"
                      value={bulkAnswers[q.id] || ''}
                      onChange={(e) =>
                        setBulkAnswers({ ...bulkAnswers, [q.id]: e.target.value })
                      }
                      placeholder="Type answer..."
                      className="w-full px-2 py-1.5 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-xs focus:outline-none focus:border-sw-accent"
                    />
                  )}
                </div>
              </div>
            ))}
          </div>

          {/* Bulk submit */}
          <div className="flex items-center justify-between mt-4">
            <span className="text-xs text-sw-dim">
              {answeredCount} of {questions.length} answered
            </span>
            <button
              onClick={handleBulkSubmit}
              disabled={answeredCount === 0 || bulkSubmitting}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {bulkSubmitting ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />}
              Submit All ({answeredCount})
            </button>
          </div>
        </div>
      )}
    </AuthenticatedLayout>
  );
}
