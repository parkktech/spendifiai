import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import { RefreshCw, AlertTriangle, TrendingUp, Loader2, Scissors, Shield } from 'lucide-react';
import { useApi, useApiPost } from '@/hooks/useApi';
import StatCard from '@/Components/SpendifiAI/StatCard';
import SubscriptionCard from '@/Components/SpendifiAI/SubscriptionCard';
import ViewModeToggle from '@/Components/SpendifiAI/ViewModeToggle';
import ConnectBankPrompt from '@/Components/SpendifiAI/ConnectBankPrompt';
import type { SubscriptionsResponse } from '@/types/spendifiai';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

type TabType = 'all' | 'cancellable' | 'essential';

export default function SubscriptionsIndex() {
  const { auth } = usePage().props as unknown as { auth: { hasBankConnected: boolean } };
  const [viewMode, setViewMode] = useState<'all' | 'personal' | 'business'>('all');
  const [activeTab, setActiveTab] = useState<TabType>('all');
  const { data, loading, error, refresh } = useApi<SubscriptionsResponse>('/api/v1/subscriptions', { enabled: auth.hasBankConnected });
  const detect = useApiPost('/api/v1/subscriptions/detect');

  const items = data?.subscriptions ?? [];

  // Filter by tab
  const filtered = activeTab === 'cancellable'
    ? items.filter((s) => !s.is_essential)
    : activeTab === 'essential'
      ? items.filter((s) => s.is_essential)
      : items;

  // Use pre-computed stats from the API, with local filtering as fallback
  const totalMonthly = data?.total_monthly ?? 0;
  const totalAnnual = data?.total_annual ?? 0;
  const unused = items.filter((s) => s.status === 'inactive' || s.status === 'unused');
  const unusedMonthly = data?.unused_monthly ?? unused.reduce((sum, s) => sum + s.amount, 0);
  const active = items.filter((s) => s.status === 'active');
  const cancellableCount = items.filter((s) => !s.is_essential).length;
  const essentialCount = items.filter((s) => s.is_essential).length;

  const handleDetect = async () => {
    await detect.submit();
    refresh();
  };

  const handleUpdate = () => {
    refresh();
  };

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-sw-text tracking-tight">Subscriptions</h1>
            <p className="text-xs text-sw-dim mt-0.5">Track and manage all recurring charges</p>
          </div>
          <div className="flex items-center gap-3 flex-wrap">
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

      {/* Tabs: All / Cancellable / Essential */}
      {items.length > 0 && (
        <div className="flex items-center gap-1 mb-6 p-1 rounded-lg bg-sw-card border border-sw-border w-fit">
          {([
            { key: 'all' as TabType, label: 'All', count: items.length },
            { key: 'cancellable' as TabType, label: 'Cancellable', count: cancellableCount, icon: <Scissors size={13} /> },
            { key: 'essential' as TabType, label: 'Essential Bills', count: essentialCount, icon: <Shield size={13} /> },
          ]).map((tab) => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition ${
                activeTab === tab.key
                  ? 'bg-sw-accent text-white'
                  : 'text-sw-muted hover:text-sw-text'
              }`}
            >
              {tab.icon}
              {tab.label}
              <span className={`ml-0.5 text-[10px] px-1 py-0.5 rounded ${
                activeTab === tab.key ? 'bg-white/20' : 'bg-sw-border'
              }`}>
                {tab.count}
              </span>
            </button>
          ))}
        </div>
      )}

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
      {loading && !data && (
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

      {/* Connect Bank Prompt */}
      {!loading && !error && !data && (
        <ConnectBankPrompt
          feature="subscriptions"
          description="Link your bank account to detect recurring charges and find ways to cut costs."
        />
      )}

      {/* Empty state */}
      {!loading && filtered.length === 0 && data && (
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
        <div aria-live="polite" className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {/* Show unused first */}
          {[...unused, ...filtered.filter((s) => s.status !== 'inactive' && s.status !== 'unused')].map((sub) => (
            <SubscriptionCard
              key={sub.id}
              subscription={sub}
              onUpdate={handleUpdate}
            />
          ))}
        </div>
      )}
    </AuthenticatedLayout>
  );
}
