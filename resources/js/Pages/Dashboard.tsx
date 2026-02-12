import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
  DollarSign,
  TrendingDown,
  PiggyBank,
  Receipt,
  AlertTriangle,
  Sparkles,
  RefreshCw,
  Loader2,
  Target,
  Zap,
  CreditCard,
} from 'lucide-react';
import StatCard from '@/Components/SpendWise/StatCard';
import SpendingChart from '@/Components/SpendWise/SpendingChart';
import TransactionRow from '@/Components/SpendWise/TransactionRow';
import RecommendationCard from '@/Components/SpendWise/RecommendationCard';
import Badge from '@/Components/SpendWise/Badge';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { DashboardData } from '@/types/spendwise';

function formatCurrency(amount: number): string {
  return `$${Math.abs(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

function LoadingSkeleton() {
  return (
    <div className="animate-pulse space-y-6">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {[1, 2, 3, 4].map((i) => (
          <div key={i} className="h-28 rounded-2xl bg-sw-card border border-sw-border" />
        ))}
      </div>
      <div className="h-64 rounded-2xl bg-sw-card border border-sw-border" />
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div className="h-72 rounded-2xl bg-sw-card border border-sw-border" />
        <div className="h-72 rounded-2xl bg-sw-card border border-sw-border" />
      </div>
    </div>
  );
}

export default function Dashboard() {
  const { data, loading, error, refresh } = useApi<DashboardData>('/api/v1/dashboard');
  const { submit: analyzeSpending, loading: analyzing } = useApiPost('/api/v1/savings/analyze');
  const { submit: dismissRec } = useApiPost('', 'POST');
  const { submit: applyRec } = useApiPost('', 'POST');

  const handleAnalyze = async () => {
    await analyzeSpending();
    refresh();
  };

  const handleDismiss = async (id: number) => {
    await dismissRec(undefined, { url: `/api/v1/savings/${id}/dismiss` } as never);
    refresh();
  };

  const handleApply = async (id: number) => {
    await applyRec(undefined, { url: `/api/v1/savings/${id}/apply` } as never);
    refresh();
  };

  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text tracking-tight">Dashboard</h1>
          <p className="text-xs text-sw-dim mt-0.5">Your complete financial picture at a glance</p>
        </div>
      }
    >
      <Head title="Dashboard" />

      {loading && <LoadingSkeleton />}

      {error && (
        <div className="rounded-2xl border border-sw-danger/30 bg-sw-danger/5 p-6 text-center">
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

      {data && (
        <div aria-live="polite" className="space-y-6">
          {/* Sync status */}
          {data.sync_status && (
            <div className="flex items-center gap-2 text-xs text-sw-dim">
              <RefreshCw size={12} />
              <span>
                {data.sync_status.institution_name} - Last synced:{' '}
                {data.sync_status.last_synced_at
                  ? new Intl.DateTimeFormat('en-US', {
                      month: 'short',
                      day: 'numeric',
                      hour: 'numeric',
                      minute: '2-digit',
                    }).format(new Date(data.sync_status.last_synced_at))
                  : 'Never'}
              </span>
            </div>
          )}

          {/* Stat Cards */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <StatCard
              title="This Month"
              value={formatCurrency(data.summary.this_month_spending)}
              trend={data.summary.month_over_month}
              subtitle="vs last month"
              icon={<DollarSign size={18} />}
            />
            <StatCard
              title="Month over Month"
              value={`${data.summary.month_over_month > 0 ? '+' : ''}${data.summary.month_over_month.toFixed(1)}%`}
              subtitle="spending change"
              icon={<TrendingDown size={18} />}
            />
            <StatCard
              title="Potential Savings"
              value={formatCurrency(data.summary.potential_savings)}
              subtitle="per month"
              icon={<PiggyBank size={18} />}
            />
            <StatCard
              title="Tax Deductible"
              value={formatCurrency(data.summary.tax_deductible_ytd)}
              subtitle="YTD"
              icon={<Receipt size={18} />}
            />
          </div>

          {/* AI Savings Insights — Front and Center */}
          <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
            <div className="flex items-center justify-between mb-5">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-lg bg-sw-info-light border border-violet-200 flex items-center justify-center">
                  <Sparkles size={20} className="text-sw-info" />
                </div>
                <div>
                  <h2 className="text-[15px] font-semibold text-sw-text">AI Savings Insights</h2>
                  {data.savings_recommendations.length > 0 ? (
                    <p className="text-xs text-sw-muted">
                      AI found <span className="font-semibold text-sw-accent">{fmt.format(data.summary.potential_savings)}/mo</span> in potential savings
                    </p>
                  ) : (
                    <p className="text-xs text-sw-dim">Run an analysis to get personalized recommendations</p>
                  )}
                </div>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={handleAnalyze}
                  disabled={analyzing}
                  className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition disabled:opacity-50"
                >
                  {analyzing ? <Loader2 size={14} className="animate-spin" /> : <Zap size={14} />}
                  Analyze Spending
                </button>
                {data.savings_recommendations.length > 0 && (
                  <Link
                    href="/savings"
                    className="text-xs text-sw-accent hover:text-sw-accent-hover transition font-medium"
                  >
                    View All
                  </Link>
                )}
              </div>
            </div>

            {data.savings_recommendations.length > 0 ? (
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {data.savings_recommendations.slice(0, 4).map((rec) => (
                  <RecommendationCard
                    key={rec.id}
                    recommendation={rec}
                    onDismiss={handleDismiss}
                    onApply={handleApply}
                  />
                ))}
              </div>
            ) : (
              <div className="text-center py-8">
                <PiggyBank size={36} className="mx-auto text-sw-dim mb-3" />
                <p className="text-sm text-sw-muted mb-1">No savings recommendations yet</p>
                <p className="text-xs text-sw-dim max-w-sm mx-auto">
                  Connect your bank and click "Analyze Spending" to get AI-powered recommendations on where to cut costs and find cheaper alternatives.
                </p>
              </div>
            )}
          </div>

          {/* Financial Health Summary — Two Column */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {/* Savings Target */}
            <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-9 h-9 rounded-lg bg-sw-accent-light border border-blue-200 flex items-center justify-center">
                  <Target size={18} className="text-sw-accent" />
                </div>
                <h3 className="text-[15px] font-semibold text-sw-text">Savings Goal</h3>
              </div>

              {data.savings_target ? (
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-sm text-sw-muted">{data.savings_target.motivation || 'Monthly Target'}</span>
                    <span className="text-sm font-bold text-sw-accent">
                      {fmt.format(data.savings_target.monthly_target)}/mo
                    </span>
                  </div>
                  {data.savings_target.goal_total && data.savings_target.current_month && (
                    <>
                      <div className="w-full h-3 bg-sw-border rounded-full overflow-hidden">
                        <div
                          className="h-full bg-sw-accent rounded-full transition-all duration-500"
                          style={{
                            width: `${Math.min(
                              (data.savings_target.current_month.cumulative_saved / data.savings_target.goal_total) * 100,
                              100
                            )}%`,
                          }}
                        />
                      </div>
                      <div className="flex items-center justify-between mt-2 text-xs text-sw-dim">
                        <span>{fmt.format(data.savings_target.current_month.cumulative_saved)} saved</span>
                        <span>{fmt.format(data.savings_target.goal_total)} goal</span>
                      </div>
                    </>
                  )}
                  <Link
                    href="/savings"
                    className="inline-block mt-3 text-xs text-sw-accent hover:text-sw-accent-hover transition"
                  >
                    View Details
                  </Link>
                </div>
              ) : (
                <div className="text-center py-4">
                  <Target size={28} className="mx-auto text-sw-dim mb-2" />
                  <p className="text-xs text-sw-muted mb-3">Set a savings goal to track your progress</p>
                  <Link
                    href="/savings"
                    className="px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition"
                  >
                    Set a Goal
                  </Link>
                </div>
              )}
            </div>

            {/* Subscription Waste */}
            <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-3">
                  <div className="w-9 h-9 rounded-lg bg-sw-warning-light border border-amber-200 flex items-center justify-center">
                    <CreditCard size={18} className="text-sw-warning" />
                  </div>
                  <h3 className="text-[15px] font-semibold text-sw-text">Subscription Health</h3>
                </div>
                {data.summary.unused_subscriptions > 0 && (
                  <Badge variant="warning">{data.summary.unused_subscriptions} unused</Badge>
                )}
              </div>

              {data.unused_subscription_details && data.unused_subscription_details.length > 0 ? (
                <div>
                  <div className="space-y-2 mb-3">
                    {data.unused_subscription_details.slice(0, 3).map((sub) => (
                      <div key={sub.id} className="flex items-center justify-between py-2 border-b border-sw-border last:border-b-0">
                        <div>
                          <span className="text-sm text-sw-text font-medium">{sub.merchant_normalized || sub.merchant_name}</span>
                          <span className="text-xs text-sw-dim ml-2">
                            {sub.last_used_at ? `Last used ${new Date(sub.last_used_at).toLocaleDateString()}` : 'Never used'}
                          </span>
                        </div>
                        <span className="text-sm font-semibold text-sw-danger">{fmt.format(sub.amount)}/mo</span>
                      </div>
                    ))}
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-xs text-sw-dim">
                      Wasting {fmt.format(data.unused_subscription_details.reduce((s, sub) => s + sub.amount, 0))}/mo
                    </span>
                    <Link
                      href="/subscriptions"
                      className="text-xs text-sw-accent hover:text-sw-accent-hover transition"
                    >
                      Manage Subscriptions
                    </Link>
                  </div>
                </div>
              ) : (
                <div className="text-center py-4">
                  <CreditCard size={28} className="mx-auto text-sw-dim mb-2" />
                  <p className="text-xs text-sw-muted">No unused subscriptions detected</p>
                  <Link
                    href="/subscriptions"
                    className="inline-block mt-2 text-xs text-sw-accent hover:text-sw-accent-hover transition"
                  >
                    View Subscriptions
                  </Link>
                </div>
              )}
            </div>
          </div>

          {/* Spending Charts */}
          <SpendingChart data={data.spending_trend} categories={data.categories} />

          {/* AI Questions Alert */}
          {data.summary.pending_questions > 0 && (
            <Link
              href="/questions"
              className="flex items-center gap-3 rounded-xl border border-violet-200 bg-sw-info-light p-4 hover:bg-violet-100 transition"
            >
              <div className="w-10 h-10 rounded-lg bg-sw-info-light border border-violet-200 flex items-center justify-center shrink-0">
                <Sparkles size={18} className="text-sw-info" />
              </div>
              <div className="flex-1">
                <div className="text-sm font-semibold text-sw-text">AI Needs Your Help</div>
                <div className="text-xs text-sw-muted mt-0.5">
                  {data.summary.pending_questions} transaction{data.summary.pending_questions !== 1 ? 's' : ''} need your input for better categorization
                </div>
              </div>
              <Badge variant="info">{data.summary.pending_questions} questions</Badge>
            </Link>
          )}

          {/* Recent Transactions */}
          {data.recent && data.recent.length > 0 && (
            <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-[15px] font-semibold text-sw-text">Recent Transactions</h3>
                <Link
                  href="/transactions"
                  className="text-xs text-sw-accent hover:text-sw-accent-hover transition"
                >
                  View All
                </Link>
              </div>
              <div>
                {data.recent.map((tx) => (
                  <TransactionRow key={tx.id} transaction={tx} />
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </AuthenticatedLayout>
  );
}
