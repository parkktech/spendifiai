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
} from 'lucide-react';
import StatCard from '@/Components/SpendWise/StatCard';
import SpendingChart from '@/Components/SpendWise/SpendingChart';
import TransactionRow from '@/Components/SpendWise/TransactionRow';
import Badge from '@/Components/SpendWise/Badge';
import { useApi } from '@/hooks/useApi';
import type { DashboardData } from '@/types/spendwise';

function formatCurrency(amount: number): string {
  return `$${Math.abs(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function LoadingSkeleton() {
  return (
    <div className="animate-pulse space-y-6">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {[1, 2, 3, 4].map((i) => (
          <div key={i} className="h-28 rounded-2xl bg-sw-card border border-sw-border" />
        ))}
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div className="h-72 rounded-2xl bg-sw-card border border-sw-border" />
        <div className="h-72 rounded-2xl bg-sw-card border border-sw-border" />
      </div>
      <div className="h-64 rounded-2xl bg-sw-card border border-sw-border" />
    </div>
  );
}

export default function Dashboard() {
  const { data, loading, error, refresh } = useApi<DashboardData>('/api/v1/dashboard');

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
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-sw-bg text-sm font-semibold hover:bg-sw-accent-hover transition"
          >
            <RefreshCw size={14} /> Retry
          </button>
        </div>
      )}

      {data && (
        <div className="space-y-6">
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

          {/* Charts */}
          <SpendingChart data={data.spending_trend} categories={data.categories} />

          {/* AI Questions Alert */}
          {data.summary.pending_questions > 0 && (
            <Link
              href="/questions"
              className="flex items-center gap-3 rounded-xl border border-purple-500/30 bg-purple-500/5 p-4 hover:bg-purple-500/10 transition"
            >
              <div className="w-10 h-10 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center shrink-0">
                <Sparkles size={18} className="text-purple-400" />
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

          {/* Unused subscriptions alert */}
          {data.summary.unused_subscriptions > 0 && (
            <Link
              href="/subscriptions"
              className="flex items-center gap-3 rounded-xl border border-sw-warning/30 bg-sw-warning/5 p-4 hover:bg-sw-warning/10 transition"
            >
              <AlertTriangle size={18} className="text-sw-warning shrink-0" />
              <span className="text-sm text-sw-text">
                You have {data.summary.unused_subscriptions} unused subscription{data.summary.unused_subscriptions !== 1 ? 's' : ''}
              </span>
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
