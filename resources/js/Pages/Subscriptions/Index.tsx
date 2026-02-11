import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { RefreshCw, AlertTriangle, TrendingUp, Loader2 } from 'lucide-react';
import { useApi, useApiPost } from '@/hooks/useApi';
import StatCard from '@/Components/SpendWise/StatCard';
import SubscriptionCard from '@/Components/SpendWise/SubscriptionCard';
import ViewModeToggle from '@/Components/SpendWise/ViewModeToggle';
import type { Subscription } from '@/types/spendwise';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export default function SubscriptionsIndex() {
  const [viewMode, setViewMode] = useState<'all' | 'personal' | 'business'>('all');
  const { data: subscriptions, loading, error, refresh } = useApi<Subscription[]>('/api/v1/subscriptions');
  const detect = useApiPost('/api/v1/subscriptions/detect');

  const items = subscriptions ?? [];

  // Filter by view mode if subscriptions have account_purpose-like data
  const filtered = items;

  // Calculate summary stats
  const active = filtered.filter((s) => s.is_active);
  const totalMonthly = active.reduce((sum, s) => sum + s.amount, 0);
  const totalAnnual = active.reduce((sum, s) => sum + s.annual_cost, 0);
  const unused = filtered.filter((s) => s.status === 'inactive' || s.status === 'unused');
  const unusedMonthly = unused.reduce((sum, s) => sum + s.amount, 0);

  const handleDetect = async () => {
    await detect.submit();
    refresh();
  };

  const handleCancel = (id: number) => {
    // Future: POST to cancel endpoint
    console.log('Cancel subscription:', id);
  };

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-sw-text tracking-tight">Subscriptions</h1>
            <p className="text-xs text-sw-dim mt-0.5">Track and manage all recurring charges</p>
          </div>
          <div className="flex items-center gap-3">
            <ViewModeToggle value={viewMode} onChange={setViewMode} />
            <button
              onClick={handleDetect}
              disabled={detect.loading}
              className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition disabled:opacity-50"
            >
              {detect.loading ? (
                <Loader2 size={14} className="animate-spin" />
              ) : (
                <RefreshCw size={14} />
              )}
              Detect Subscriptions
            </button>
          </div>
        </div>
      }
    >
      <Head title="Subscriptions" />

      {/* Stat cards */}
      <div className="flex gap-4 mb-6 flex-wrap">
        <StatCard
          title="Monthly Cost"
          value={fmt.format(totalMonthly)}
          subtitle={`${active.length} active`}
          icon={<RefreshCw size={18} />}
        />
        <StatCard
          title="Annual Cost"
          value={fmt.format(totalAnnual)}
          subtitle="at current rate"
          icon={<TrendingUp size={18} />}
        />
        <StatCard
          title="Unused Subscriptions"
          value={unused.length.toString()}
          subtitle={unused.length > 0 ? `${fmt.format(unusedMonthly)}/mo wasted` : 'None detected'}
          icon={<AlertTriangle size={18} />}
        />
      </div>

      {/* Unused warning banner */}
      {unused.length > 0 && (
        <div className="flex items-center gap-3 p-4 mb-6 rounded-xl bg-sw-danger/5 border border-sw-danger/20">
          <AlertTriangle size={20} className="text-sw-danger shrink-0" />
          <div className="flex-1">
            <div className="text-sm font-semibold text-sw-text">
              You have {unused.length} potentially unused subscription{unused.length > 1 ? 's' : ''} costing {fmt.format(unusedMonthly)}/month
            </div>
            <div className="text-xs text-sw-muted mt-0.5">
              That is {fmt.format(unusedMonthly * 12)} per year. Consider canceling or pausing these.
            </div>
          </div>
        </div>
      )}

      {/* Error state */}
      {error && (
        <div className="rounded-xl border border-sw-danger/30 bg-sw-danger/5 p-6 text-center mb-6">
          <p className="text-sm text-sw-danger mb-2">{error}</p>
          <button
            onClick={refresh}
            className="text-xs text-sw-accent hover:text-sw-accent-hover transition"
          >
            Try again
          </button>
        </div>
      )}

      {/* Loading skeleton */}
      {loading && !subscriptions && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="rounded-lg border border-sw-border bg-sw-card p-4 animate-pulse">
              <div className="h-5 bg-sw-border rounded w-2/3 mb-3" />
              <div className="h-7 bg-sw-border rounded w-1/2 mb-2" />
              <div className="h-4 bg-sw-border rounded w-1/3 mb-3" />
              <div className="h-3 bg-sw-border rounded w-full mb-1" />
              <div className="h-3 bg-sw-border rounded w-3/4" />
            </div>
          ))}
        </div>
      )}

      {/* Empty state */}
      {!loading && filtered.length === 0 && !error && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-12 text-center">
          <RefreshCw size={40} className="mx-auto text-sw-dim mb-3" />
          <h3 className="text-sm font-semibold text-sw-text mb-1">No subscriptions detected yet</h3>
          <p className="text-xs text-sw-muted max-w-md mx-auto">
            Connect your bank and we will find them automatically. You can also click "Detect Subscriptions" to scan your transactions.
          </p>
        </div>
      )}

      {/* Subscription grid */}
      {!loading && filtered.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {/* Show unused first */}
          {[...unused, ...filtered.filter((s) => s.status !== 'inactive' && s.status !== 'unused')].map((sub) => (
            <SubscriptionCard
              key={sub.id}
              subscription={sub}
              onCancel={handleCancel}
            />
          ))}
        </div>
      )}
    </AuthenticatedLayout>
  );
}
