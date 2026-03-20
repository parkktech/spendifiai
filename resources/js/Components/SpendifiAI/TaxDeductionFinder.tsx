import { useState, useEffect } from 'react';
import {
  Search, CheckCircle2, HelpCircle, DollarSign, Loader2, ChevronDown,
  ChevronUp, FileText, ExternalLink, Sparkles,
} from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { UserTaxDeduction, TaxDeduction } from '@/types/spendifiai';

interface DeductionsResponse {
  year: number;
  deductions: {
    auto_detected: UserTaxDeduction[];
    profile_matched: UserTaxDeduction[];
    claimed: UserTaxDeduction[];
  };
  questionnaire_remaining: number;
  total_discovered: number;
  total_estimated_savings: number;
}

interface QuestionnaireResponse {
  questions: Array<{
    id: number;
    slug: string;
    name: string;
    category: string;
    question_text: string;
    question_options: Array<{ label: string; value: string }> | null;
    help_text: string | null;
    is_credit: boolean;
    max_amount: number | null;
    irs_form: string | null;
  }>;
  total_remaining: number;
}

interface Props {
  year: number;
}

const CATEGORY_LABELS: Record<string, string> = {
  above_the_line: 'Above-the-Line',
  itemized: 'Itemized (Schedule A)',
  schedule_c: 'Schedule C',
  credit: 'Tax Credits',
  new_2026: 'New for 2026',
  lesser_known: 'Lesser Known',
};

export default function TaxDeductionFinder({ year }: Props) {
  const { data, loading, refresh } = useApi<DeductionsResponse>(
    `/api/v1/tax/deductions?year=${year}`,
    { immediate: true }
  );
  const { submit: runScan, loading: scanning } = useApiPost('/api/v1/tax/deductions/scan');
  const { data: questionnaireData, refresh: refreshQuestions } = useApi<QuestionnaireResponse>(
    `/api/v1/tax/deductions/questionnaire?year=${year}`,
    { immediate: true }
  );
  const { submit: submitAnswers, loading: submitting } = useApiPost('/api/v1/tax/deductions/questionnaire');

  const [expandedSection, setExpandedSection] = useState<string | null>('auto_detected');
  const [showQuestionnaire, setShowQuestionnaire] = useState(false);
  const [answers, setAnswers] = useState<Record<number, { response: boolean; amount?: number }>>({});

  const deductions = data?.deductions;
  const autoDetected = deductions?.auto_detected || [];
  const profileMatched = deductions?.profile_matched || [];
  const claimed = deductions?.claimed || [];
  const questions = questionnaireData?.questions || [];
  const questionsRemaining = questionnaireData?.total_remaining ?? 0;
  const totalSavings = data?.total_estimated_savings ?? 0;
  const totalDiscovered = data?.total_discovered ?? 0;

  const handleScan = async () => {
    await runScan({ year });
    refresh();
    refreshQuestions();
  };

  const handleSubmitAnswers = async () => {
    const formattedAnswers = Object.entries(answers).map(([id, answer]) => ({
      deduction_id: parseInt(id),
      answer: { response: answer.response, amount: answer.amount },
    }));

    if (formattedAnswers.length === 0) return;

    await submitAnswers({ year, answers: formattedAnswers });
    setAnswers({});
    refresh();
    refreshQuestions();
  };

  const toggleSection = (section: string) => {
    setExpandedSection(expandedSection === section ? null : section);
  };

  const formatAmount = (amount: number | null | undefined) => {
    if (amount === null || amount === undefined) return '—';
    return '$' + Number(amount).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  };

  return (
    <div className="space-y-4">
      {/* Banner */}
      {totalDiscovered > 0 && (
        <div className="rounded-2xl bg-gradient-to-r from-sw-success/10 to-sw-accent-light border border-sw-success/20 p-5">
          <div className="flex items-center gap-3 mb-2">
            <Sparkles size={20} className="text-sw-success" />
            <h3 className="text-[15px] font-bold text-sw-text">
              {totalDiscovered} deduction{totalDiscovered !== 1 ? 's' : ''} found
            </h3>
          </div>
          <p className="text-sm text-sw-muted">
            Estimated additional savings: <span className="font-bold text-sw-success">{formatAmount(totalSavings)}</span>
          </p>
          {questionsRemaining > 0 && (
            <p className="text-xs text-sw-dim mt-1">
              Answer {questionsRemaining} more question{questionsRemaining !== 1 ? 's' : ''} to discover even more deductions.
            </p>
          )}
        </div>
      )}

      {/* Scan Button */}
      <div className="flex items-center gap-3">
        <button
          onClick={handleScan}
          disabled={scanning}
          className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
        >
          {scanning ? <Loader2 size={14} className="animate-spin" /> : <Search size={14} />}
          {scanning ? 'Scanning...' : 'Scan Transactions'}
        </button>
        {!showQuestionnaire && questionsRemaining > 0 && (
          <button
            onClick={() => setShowQuestionnaire(true)}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-sw-accent text-sw-accent text-sm font-semibold hover:bg-sw-accent-light transition"
          >
            <HelpCircle size={14} />
            Answer Questions ({questionsRemaining})
          </button>
        )}
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-12">
          <Loader2 size={24} className="animate-spin text-sw-accent" />
        </div>
      ) : (
        <>
          {/* Auto-Detected Section */}
          {autoDetected.length > 0 && (
            <DeductionSection
              title="Auto-Detected from Transactions"
              items={autoDetected}
              variant="success"
              expanded={expandedSection === 'auto_detected'}
              onToggle={() => toggleSection('auto_detected')}
              formatAmount={formatAmount}
            />
          )}

          {/* Profile-Matched Section */}
          {profileMatched.length > 0 && (
            <DeductionSection
              title="Based on Your Profile"
              items={profileMatched}
              variant="info"
              expanded={expandedSection === 'profile_matched'}
              onToggle={() => toggleSection('profile_matched')}
              formatAmount={formatAmount}
            />
          )}

          {/* Claimed Section */}
          {claimed.length > 0 && (
            <DeductionSection
              title="Claimed Deductions"
              items={claimed}
              variant="neutral"
              expanded={expandedSection === 'claimed'}
              onToggle={() => toggleSection('claimed')}
              formatAmount={formatAmount}
            />
          )}

          {/* Questionnaire */}
          {showQuestionnaire && questions.length > 0 && (
            <div className="rounded-2xl border border-sw-warning/30 bg-sw-warning/5 p-5">
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-[15px] font-semibold text-sw-text">Quick Questions</h3>
                <button
                  onClick={() => setShowQuestionnaire(false)}
                  className="text-xs text-sw-dim hover:text-sw-muted"
                >
                  Close
                </button>
              </div>
              <div className="space-y-4">
                {questions.map((q) => (
                  <div key={q.id} className="p-4 rounded-xl bg-sw-card border border-sw-border">
                    <p className="text-sm font-medium text-sw-text mb-2">{q.question_text}</p>
                    {q.help_text && (
                      <p className="text-xs text-sw-dim mb-3">{q.help_text}</p>
                    )}
                    <div className="flex items-center gap-3">
                      <button
                        onClick={() => setAnswers({ ...answers, [q.id]: { response: true } })}
                        className={`px-4 py-1.5 rounded-lg text-xs font-semibold transition ${
                          answers[q.id]?.response === true
                            ? 'bg-sw-success text-white'
                            : 'border border-sw-border text-sw-text hover:bg-sw-bg'
                        }`}
                      >
                        Yes
                      </button>
                      <button
                        onClick={() => setAnswers({ ...answers, [q.id]: { response: false } })}
                        className={`px-4 py-1.5 rounded-lg text-xs font-semibold transition ${
                          answers[q.id]?.response === false
                            ? 'bg-sw-danger text-white'
                            : 'border border-sw-border text-sw-text hover:bg-sw-bg'
                        }`}
                      >
                        No
                      </button>
                      {q.max_amount && (
                        <span className="text-[11px] text-sw-dim ml-2">
                          Up to {formatAmount(q.max_amount)}
                        </span>
                      )}
                      {q.irs_form && (
                        <Badge variant="neutral">{q.irs_form}</Badge>
                      )}
                    </div>
                  </div>
                ))}
              </div>
              {Object.keys(answers).length > 0 && (
                <button
                  onClick={handleSubmitAnswers}
                  disabled={submitting}
                  className="mt-4 inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
                >
                  {submitting ? <Loader2 size={14} className="animate-spin" /> : <CheckCircle2 size={14} />}
                  Submit {Object.keys(answers).length} Answer{Object.keys(answers).length !== 1 ? 's' : ''}
                </button>
              )}
            </div>
          )}

          {totalDiscovered === 0 && !loading && (
            <div className="text-center py-8 text-sw-muted">
              <Search size={32} className="mx-auto mb-2 text-sw-dim" />
              <p className="text-sm">Click "Scan Transactions" to discover eligible deductions.</p>
            </div>
          )}
        </>
      )}
    </div>
  );
}

function DeductionSection({
  title,
  items,
  variant,
  expanded,
  onToggle,
  formatAmount,
}: {
  title: string;
  items: UserTaxDeduction[];
  variant: 'success' | 'info' | 'neutral';
  expanded: boolean;
  onToggle: () => void;
  formatAmount: (n: number | null | undefined) => string;
}) {
  const [expandedItem, setExpandedItem] = useState<number | null>(null);

  const colors = {
    success: 'border-sw-success/30 bg-sw-success/5',
    info: 'border-sw-accent/30 bg-sw-accent-light',
    neutral: 'border-sw-border bg-sw-card',
  };

  const total = items.reduce((sum, i) => sum + Number(i.estimated_amount || i.actual_amount || 0), 0);

  return (
    <div className={`rounded-2xl border ${colors[variant]} overflow-hidden`}>
      <button
        onClick={onToggle}
        className="w-full flex items-center justify-between p-4 hover:opacity-80 transition"
      >
        <div className="flex items-center gap-2">
          <span className="text-sm font-semibold text-sw-text">{title}</span>
          <Badge variant={variant === 'success' ? 'success' : variant === 'info' ? 'info' : 'neutral'}>
            {items.length}
          </Badge>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-sm font-bold text-sw-text">{formatAmount(total)}</span>
          {expanded ? <ChevronUp size={16} className="text-sw-dim" /> : <ChevronDown size={16} className="text-sw-dim" />}
        </div>
      </button>
      {expanded && (
        <div className="border-t border-sw-border/50 divide-y divide-sw-border/30">
          {items.map((item) => {
            const ded = item.deduction;
            const amount = Number(item.actual_amount || item.estimated_amount || 0);
            const isExpanded = expandedItem === item.id;

            return (
              <div key={item.id}>
                <button
                  onClick={() => setExpandedItem(isExpanded ? null : item.id)}
                  className="w-full px-4 py-3 flex items-center gap-3 hover:bg-sw-bg/50 transition text-left"
                >
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-sw-text">
                        {ded?.name || `Deduction #${item.tax_deduction_id}`}
                      </span>
                      {ded?.is_credit && (
                        <Badge variant="success">Credit</Badge>
                      )}
                      {ded?.irs_form && (
                        <span className="text-[10px] text-sw-dim bg-sw-surface px-1.5 py-0.5 rounded">
                          {ded.irs_form}
                        </span>
                      )}
                    </div>
                    {ded?.category && (
                      <span className="text-[11px] text-sw-dim mt-0.5 block">
                        {CATEGORY_LABELS[ded.category] || ded.category}
                      </span>
                    )}
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <div className="text-right">
                      <div className={`text-sm font-semibold ${amount > 0 ? 'text-sw-text' : 'text-sw-dim'}`}>
                        {amount > 0 ? formatAmount(amount) : 'Amount TBD'}
                      </div>
                      {ded?.max_amount && amount === 0 && (
                        <span className="text-[10px] text-sw-muted">Up to {formatAmount(Number(ded.max_amount))}</span>
                      )}
                    </div>
                    {isExpanded ? <ChevronUp size={14} className="text-sw-dim" /> : <ChevronDown size={14} className="text-sw-dim" />}
                  </div>
                </button>

                {isExpanded && (
                  <div className="px-4 pb-3 -mt-1">
                    <div className="rounded-xl border border-sw-border bg-sw-bg p-3 space-y-2">
                      {ded?.description && (
                        <p className="text-xs text-sw-text-secondary">{ded.description}</p>
                      )}
                      {ded?.help_text && (
                        <p className="text-[11px] text-sw-muted italic">{ded.help_text}</p>
                      )}
                      {item.notes && (
                        <p className="text-[11px] text-sw-dim">
                          <span className="font-medium text-sw-muted">Detection note:</span> {item.notes}
                        </p>
                      )}
                      <div className="flex flex-wrap gap-3 pt-1 text-[11px] text-sw-dim">
                        {ded?.max_amount && (
                          <span>Max deduction: <strong className="text-sw-text">{formatAmount(Number(ded.max_amount))}</strong></span>
                        )}
                        {ded?.irs_form && ded?.irs_line && (
                          <span>IRS: {ded.irs_form}, Line {ded.irs_line}</span>
                        )}
                        {item.detection_confidence && (
                          <span>Confidence: {Math.round(Number(item.detection_confidence) * 100)}%</span>
                        )}
                        {item.detected_from && (
                          <span>Source: {item.detected_from === 'ai_scan' ? 'Transaction scan' : item.detected_from === 'profile_match' ? 'Profile match' : 'Questionnaire'}</span>
                        )}
                      </div>
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
