import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { PiggyBank, Target, Zap, Loader2, Activity, Calendar } from 'lucide-react';
import { useApi, useApiPost } from '@/hooks/useApi';
import StatCard from '@/Components/SpendWise/StatCard';
import RecommendationCard from '@/Components/SpendWise/RecommendationCard';
import type { SavingsRecommendation, SavingsTarget } from '@/types/spendwise';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export default function SavingsIndex() {
  const { data: recommendations, loading: recsLoading, error: recsError, refresh: refreshRecs } =
    useApi<SavingsRecommendation[]>('/api/v1/savings/recommendations');
  const { data: target, loading: targetLoading, refresh: refreshTarget } =
    useApi<SavingsTarget>('/api/v1/savings/target');

  const analyze = useApiPost('/api/v1/savings/analyze');
  const dismiss = useApiPost('/api/v1/savings/recommendations');
  const apply = useApiPost('/api/v1/savings/recommendations');
  const setTargetPost = useApiPost<SavingsTarget>('/api/v1/savings/target');
  const pulseCheck = useApiPost('/api/v1/savings/pulse-check', 'GET');

  const [pulseResult, setPulseResult] = useState<string | null>(null);
  const [showTargetForm, setShowTargetForm] = useState(false);
  const [targetForm, setTargetForm] = useState({ name: '', target_amount: '', deadline: '' });

  const recs = recommendations ?? [];
  const totalPotential = recs.reduce((sum, r) => sum + r.potential_savings, 0);
  const highPriority = recs.filter((r) => r.priority === 'high');
  const highTotal = highPriority.reduce((sum, r) => sum + r.potential_savings, 0);

  const handleAnalyze = async () => {
    await analyze.submit();
    refreshRecs();
  };

  const handleDismiss = async (id: number) => {
    await dismiss.submit(undefined, { url: `/api/v1/savings/recommendations/${id}/dismiss`, method: 'POST' });
    refreshRecs();
  };

  const handleApply = async (id: number) => {
    await apply.submit(undefined, { url: `/api/v1/savings/recommendations/${id}/apply`, method: 'POST' });
    refreshRecs();
  };

  const handlePulseCheck = async () => {
    const result = await pulseCheck.submit();
    if (result && typeof result === 'object' && 'summary' in result) {
      setPulseResult((result as { summary: string }).summary);
    } else if (typeof result === 'string') {
      setPulseResult(result);
    } else {
      setPulseResult('Pulse check completed. Check your recommendations for updates.');
    }
  };

  const handleSetTarget = async (e: React.FormEvent) => {
    e.preventDefault();
    await setTargetPost.submit({
      name: targetForm.name,
      target_amount: parseFloat(targetForm.target_amount),
      deadline: targetForm.deadline || undefined,
    } as never);
    setShowTargetForm(false);
    setTargetForm({ name: '', target_amount: '', deadline: '' });
    refreshTarget();
  };

  // Progress percentage
  const progress = target ? Math.min((target.current_amount / target.target_amount) * 100, 100) : 0;

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-sw-text tracking-tight">Savings</h1>
            <p className="text-xs text-sw-dim mt-0.5">AI-powered recommendations to cut costs</p>
          </div>
          <button
            onClick={handleAnalyze}
            disabled={analyze.loading}
            className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition disabled:opacity-50"
          >
            {analyze.loading ? (
              <Loader2 size={14} className="animate-spin" />
            ) : (
              <Zap size={14} />
            )}
            Analyze Spending
          </button>
        </div>
      }
    >
      <Head title="Savings" />

      {/* Stat cards */}
      <div className="flex gap-4 mb-6 flex-wrap">
        <StatCard
          title="Potential Savings"
          value={`${fmt.format(totalPotential)}/mo`}
          subtitle={`${fmt.format(totalPotential * 12)}/year`}
          icon={<PiggyBank size={18} />}
        />
        <StatCard
          title="High Priority"
          value={`${fmt.format(highTotal)}/mo`}
          subtitle={`${highPriority.length} recommendation${highPriority.length !== 1 ? 's' : ''}`}
          icon={<Zap size={18} />}
        />
        <StatCard
          title="Savings Goal"
          value={target ? fmt.format(target.target_amount) : 'Not set'}
          subtitle={target ? `${fmt.format(target.monthly_target)}/mo target` : 'Set a target to track progress'}
          icon={<Target size={18} />}
        />
      </div>

      {/* Section 1: Savings Target */}
      <div className="rounded-2xl border border-sw-border bg-sw-card p-6 mb-6">
        <h2 className="text-sm font-semibold text-sw-text mb-4 flex items-center gap-2">
          <Target size={16} className="text-sw-accent" />
          Savings Target
        </h2>

        {targetLoading && (
          <div className="animate-pulse">
            <div className="h-4 bg-sw-border rounded w-1/3 mb-3" />
            <div className="h-6 bg-sw-border rounded w-full mb-2" />
            <div className="h-3 bg-sw-border rounded w-1/4" />
          </div>
        )}

        {!targetLoading && target && (
          <div>
            <div className="flex items-center justify-between mb-2">
              <div>
                <h3 className="text-base font-semibold text-sw-text">{target.name}</h3>
                <div className="flex items-center gap-3 mt-1 text-xs text-sw-dim">
                  {target.deadline && (
                    <span className="inline-flex items-center gap-1">
                      <Calendar size={12} />
                      Deadline: {target.deadline}
                    </span>
                  )}
                  <span>Monthly target: {fmt.format(target.monthly_target)}</span>
                </div>
              </div>
              <div className="text-right">
                <div className="text-lg font-bold text-sw-accent">{fmt.format(target.current_amount)}</div>
                <div className="text-xs text-sw-dim">of {fmt.format(target.target_amount)}</div>
              </div>
            </div>

            {/* Progress bar */}
            <div className="w-full h-3 bg-sw-border rounded-full overflow-hidden mt-3">
              <div
                className="h-full bg-sw-accent rounded-full transition-all duration-500"
                style={{ width: `${progress}%` }}
              />
            </div>
            <div className="text-xs text-sw-dim mt-1 text-right">{progress.toFixed(1)}%</div>

            {/* Actions list */}
            {target.actions && target.actions.length > 0 && (
              <div className="mt-4 space-y-2">
                <h4 className="text-xs font-medium text-sw-muted">Action Plan</h4>
                {target.actions.map((action) => (
                  <div
                    key={action.id}
                    className="flex items-center gap-3 p-2 rounded-lg bg-sw-bg/50 border border-sw-border"
                  >
                    <div
                      className={`w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 ${
                        action.status === 'completed'
                          ? 'border-sw-accent bg-sw-accent/20'
                          : 'border-sw-border'
                      }`}
                    >
                      {action.status === 'completed' && (
                        <div className="w-2 h-2 rounded-full bg-sw-accent" />
                      )}
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className={`text-xs font-medium ${action.status === 'completed' ? 'text-sw-muted line-through' : 'text-sw-text'}`}>
                        {action.title}
                      </div>
                      {action.estimated_savings > 0 && (
                        <div className="text-[10px] text-sw-dim">
                          Save {fmt.format(action.estimated_savings)}{action.frequency ? `/${action.frequency}` : ''}
                        </div>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}

            <button
              onClick={() => setShowTargetForm(true)}
              className="mt-3 text-xs text-sw-accent hover:text-sw-accent-hover transition"
            >
              Edit Target
            </button>
          </div>
        )}

        {!targetLoading && !target && !showTargetForm && (
          <div className="text-center py-4">
            <Target size={32} className="mx-auto text-sw-dim mb-2" />
            <p className="text-xs text-sw-muted mb-3">Set a savings target to track your progress</p>
            <button
              onClick={() => setShowTargetForm(true)}
              className="px-4 py-2 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition"
            >
              Set a Savings Target
            </button>
          </div>
        )}

        {showTargetForm && (
          <form onSubmit={handleSetTarget} className="space-y-3 mt-4 pt-4 border-t border-sw-border">
            <div>
              <label className="block text-xs text-sw-muted font-medium mb-1">Goal Name</label>
              <input
                type="text"
                value={targetForm.name}
                onChange={(e) => setTargetForm((f) => ({ ...f, name: e.target.value }))}
                placeholder="e.g., Emergency Fund"
                required
                className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm placeholder:text-sw-dim focus:outline-none focus:border-sw-accent"
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs text-sw-muted font-medium mb-1">Target Amount</label>
                <input
                  type="number"
                  step="0.01"
                  min="1"
                  value={targetForm.target_amount}
                  onChange={(e) => setTargetForm((f) => ({ ...f, target_amount: e.target.value }))}
                  placeholder="5000"
                  required
                  className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm placeholder:text-sw-dim focus:outline-none focus:border-sw-accent"
                />
              </div>
              <div>
                <label className="block text-xs text-sw-muted font-medium mb-1">Deadline (optional)</label>
                <input
                  type="date"
                  value={targetForm.deadline}
                  onChange={(e) => setTargetForm((f) => ({ ...f, deadline: e.target.value }))}
                  className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent"
                />
              </div>
            </div>
            <div className="flex gap-3">
              <button
                type="submit"
                disabled={setTargetPost.loading}
                className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-sm font-semibold transition disabled:opacity-50"
              >
                {setTargetPost.loading && <Loader2 size={14} className="animate-spin" />}
                Save Target
              </button>
              <button
                type="button"
                onClick={() => setShowTargetForm(false)}
                className="px-4 py-2 rounded-lg border border-sw-border text-sw-muted text-sm hover:bg-sw-card-hover transition"
              >
                Cancel
              </button>
            </div>
          </form>
        )}
      </div>

      {/* Section 2: Recommendations */}
      <div className="mb-6">
        <h2 className="text-sm font-semibold text-sw-text mb-4 flex items-center gap-2">
          <PiggyBank size={16} className="text-sw-accent" />
          Recommendations
        </h2>

        {recsError && (
          <div className="rounded-xl border border-sw-danger/30 bg-sw-danger/5 p-4 text-center mb-4">
            <p className="text-sm text-sw-danger mb-2">{recsError}</p>
            <button
              onClick={refreshRecs}
              className="text-xs text-sw-accent hover:text-sw-accent-hover transition"
            >
              Try again
            </button>
          </div>
        )}

        {recsLoading && !recommendations && (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="rounded-lg border border-sw-border bg-sw-card p-4 animate-pulse">
                <div className="h-4 bg-sw-border rounded w-2/3 mb-3" />
                <div className="h-3 bg-sw-border rounded w-full mb-2" />
                <div className="h-3 bg-sw-border rounded w-3/4 mb-3" />
                <div className="h-6 bg-sw-border rounded w-1/4" />
              </div>
            ))}
          </div>
        )}

        {!recsLoading && recs.length === 0 && !recsError && (
          <div className="rounded-2xl border border-sw-border bg-sw-card p-12 text-center">
            <PiggyBank size={40} className="mx-auto text-sw-dim mb-3" />
            <h3 className="text-sm font-semibold text-sw-text mb-1">No recommendations yet</h3>
            <p className="text-xs text-sw-muted max-w-md mx-auto">
              Run a savings analysis to get personalized recommendations based on your spending patterns.
            </p>
          </div>
        )}

        {!recsLoading && recs.length > 0 && (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {recs.map((rec) => (
              <RecommendationCard
                key={rec.id}
                recommendation={rec}
                onDismiss={handleDismiss}
                onApply={handleApply}
              />
            ))}
          </div>
        )}
      </div>

      {/* Section 3: Pulse Check */}
      <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
        <h2 className="text-sm font-semibold text-sw-text mb-3 flex items-center gap-2">
          <Activity size={16} className="text-sw-accent" />
          Pulse Check
        </h2>
        <p className="text-xs text-sw-muted mb-4">
          Get a quick summary of how your spending and savings are trending.
        </p>

        {pulseResult && (
          <div className="p-4 rounded-lg bg-sw-bg/50 border border-sw-border mb-4">
            <p className="text-sm text-sw-text leading-relaxed">{pulseResult}</p>
          </div>
        )}

        <button
          onClick={handlePulseCheck}
          disabled={pulseCheck.loading}
          className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-card border border-sw-border hover:bg-sw-card-hover text-sw-text text-xs font-semibold transition disabled:opacity-50"
        >
          {pulseCheck.loading ? (
            <Loader2 size={14} className="animate-spin" />
          ) : (
            <Activity size={14} />
          )}
          How am I doing?
        </button>
      </div>
    </AuthenticatedLayout>
  );
}
