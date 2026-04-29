import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import { RefreshCw, Loader2, ChevronDown, ChevronUp, AlertTriangle, CheckCircle2, ArrowRight, Clock } from 'lucide-react';
import { useApi, useApiPost } from '@/hooks/useApi';
import SubscriptionCard from '@/Components/SpendifiAI/SubscriptionCard';
import ViewModeToggle from '@/Components/SpendifiAI/ViewModeToggle';
import ConnectBankPrompt from '@/Components/SpendifiAI/ConnectBankPrompt';
import type { SubscriptionsResponse } from '@/types/spendifiai';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export default function SubscriptionsIndex() {
  const { auth } = usePage().props as unknown as { auth: { hasBankConnected: boolean } };
  const [viewMode, setViewMode] = useState<'all' | 'personal' | 'business'>('all');
  const [activeFilter, setActiveFilter] = useState<'all' | 'essential' | 'non-essential'>('all');
  const [showAllActive, setShowAllActive] = useState(false);
  const [showCancelled, setShowCancelled] = useState(false);
  const { data, loading, error, refresh } = useApi<SubscriptionsResponse>('/api/v1/subscriptions', { enabled: auth.hasBankConnected });
  const detect = useApiPost('/api/v1/subscriptions/detect');

  const items = data?.subscriptions ?? [];
  const activeItems = items.filter((s) => s.status !== 'cancelled');
  const cancelledItems = items.filter((s) => s.status === 'cancelled');
  const rechargedItems = cancelledItems.filter((s) => s.recharged_at);
  const confirmedCancelled = cancelledItems.filter((s) => !s.recharged_at);

  const totalMonthly = data?.total_monthly ?? 0;
  const totalAnnual = data?.total_annual ?? 0;
  const confirmedSavings = data?.confirmed_savings_monthly ?? 0;
  const essentialTotal = activeItems.filter((s) => s.is_essential).reduce((sum, s) => sum + Number(s.amount), 0);
  const nonEssentialTotal = activeItems.filter((s) => !s.is_essential).reduce((sum, s) => sum + Number(s.amount), 0);

  const missedChargeItems = activeItems.filter((s) => s.missed_charge_at);

  const essentialItems = activeItems.filter((s) => s.is_essential);
  const nonEssentialItems = activeItems.filter((s) => !s.is_essential);

  // Apply filter
  const filteredActive = activeFilter === 'essential'
    ? essentialItems
    : activeFilter === 'non-essential'
      ? nonEssentialItems
      : activeItems;

  // Sort: missed charges last, then by amount descending
  const sortedActive = [...filteredActive].sort((a, b) => {
    const aMissed = a.missed_charge_at ? 1 : 0;
    const bMissed = b.missed_charge_at ? 1 : 0;
    if (aMissed !== bMissed) return aMissed - bMissed;
    if (activeFilter === 'all' && a.is_essential !== b.is_essential) return a.is_essential ? 1 : -1;
    return Number(b.amount) - Number(a.amount);
  });

  const PREVIEW_COUNT = 6;
  // Show all when filtered (fewer items), preview when showing all
  const visibleActive = (showAllActive || activeFilter !== 'all') ? sortedActive : sortedActive.slice(0, PREVIEW_COUNT);
  const hasMoreActive = activeFilter === 'all' && sortedActive.length > PREVIEW_COUNT;

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
            <p className="text-xs text-sw-dim mt-0.5">See where your money goes every month</p>
          </div>
          <div className="flex items-center gap-3 flex-wrap">
            <ViewModeToggle value={viewMode} onChange={setViewMode} />
            <button
              onClick={handleDetect}
              disabled={detect.loading}
              className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition disabled:opacity-50"
            >
              {detect.loading ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}
              Scan Transactions
            </button>
          </div>
        </div>
      }
    >
      <Head title="Subscriptions" />

      {/* Error state */}
      {error && (
        <div className="rounded-xl border border-sw-danger/30 bg-sw-danger/5 p-6 text-center mb-6">
          <p className="text-sm text-sw-danger mb-2">{error}</p>
          <button onClick={refresh} className="text-xs text-sw-accent hover:text-sw-accent-hover transition">
            Try again
          </button>
        </div>
      )}

      {/* Loading skeleton */}
      {loading && !data && (
        <div className="space-y-4">
          <div className="rounded-2xl border border-sw-border bg-sw-card p-8 animate-pulse">
            <div className="h-8 bg-sw-border rounded w-48 mb-2" />
            <div className="h-5 bg-sw-border rounded w-32" />
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="rounded-xl border border-sw-border bg-sw-card p-4 animate-pulse">
                <div className="h-4 bg-sw-border rounded w-2/3 mb-3" />
                <div className="h-6 bg-sw-border rounded w-1/3 mb-2" />
                <div className="h-3 bg-sw-border rounded w-1/2" />
              </div>
            ))}
          </div>
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
      {!loading && data && items.length === 0 && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-12 text-center">
          <RefreshCw size={40} className="mx-auto text-sw-dim mb-3" />
          <h3 className="text-sm font-semibold text-sw-text mb-1">No subscriptions detected yet</h3>
          <p className="text-xs text-sw-muted max-w-md mx-auto">
            Click &ldquo;Scan Transactions&rdquo; to find recurring charges in your transaction history.
          </p>
        </div>
      )}

      {/* Main content */}
      {!loading && data && items.length > 0 && (
        <div className="space-y-8">

          {/* ── Hero: spending summary ── */}
          <div className="rounded-2xl border border-sw-border bg-sw-card overflow-hidden">
            <div className="p-6 pb-5">
              <div className="text-xs font-medium text-sw-muted uppercase tracking-wider mb-1">Monthly recurring charges</div>
              <div className="flex items-baseline gap-3 flex-wrap">
                <span className="text-3xl font-extrabold text-sw-text tracking-tight">{fmt.format(totalMonthly)}</span>
                <span className="text-sm text-sw-dim">{fmt.format(totalAnnual)}/yr across {activeItems.length} subscription{activeItems.length !== 1 ? 's' : ''}</span>
              </div>
            </div>

            {/* Spending breakdown bar */}
            {totalMonthly > 0 && (
              <div className="px-6 pb-5">
                <div className="flex rounded-full h-2.5 overflow-hidden bg-sw-surface">
                  {essentialTotal > 0 && (
                    <div
                      className="bg-slate-400 transition-all duration-500"
                      style={{ width: `${(essentialTotal / totalMonthly) * 100}%` }}
                      title={`Essential: ${fmt.format(essentialTotal)}`}
                    />
                  )}
                  {nonEssentialTotal > 0 && (
                    <div
                      className="bg-sw-accent transition-all duration-500"
                      style={{ width: `${(nonEssentialTotal / totalMonthly) * 100}%` }}
                      title={`Non-essential: ${fmt.format(nonEssentialTotal)}`}
                    />
                  )}
                </div>
                <div className="flex items-center gap-4 mt-2.5 text-xs">
                  <button
                    onClick={() => setActiveFilter(activeFilter === 'essential' ? 'all' : 'essential')}
                    className={`flex items-center gap-1.5 px-2 py-1 rounded-md transition border ${
                      activeFilter === 'essential'
                        ? 'border-slate-400 bg-slate-100 text-sw-text'
                        : 'border-transparent hover:bg-sw-surface text-sw-muted'
                    }`}
                  >
                    <span className="w-2 h-2 rounded-full bg-slate-400" />
                    <span>Essential</span>
                    <span className="font-semibold text-sw-text">{fmt.format(essentialTotal)}</span>
                    <span className="text-sw-dim">({essentialItems.length})</span>
                  </button>
                  <button
                    onClick={() => setActiveFilter(activeFilter === 'non-essential' ? 'all' : 'non-essential')}
                    className={`flex items-center gap-1.5 px-2 py-1 rounded-md transition border ${
                      activeFilter === 'non-essential'
                        ? 'border-sw-accent/40 bg-sw-accent-light text-sw-text'
                        : 'border-transparent hover:bg-sw-surface text-sw-muted'
                    }`}
                  >
                    <span className="w-2 h-2 rounded-full bg-sw-accent" />
                    <span>Non-essential</span>
                    <span className="font-semibold text-sw-text">{fmt.format(nonEssentialTotal)}</span>
                    <span className="text-sw-dim">({nonEssentialItems.length})</span>
                  </button>
                  {activeFilter !== 'all' && (
                    <button
                      onClick={() => setActiveFilter('all')}
                      className="text-sw-dim hover:text-sw-muted transition text-[11px]"
                    >
                      Clear filter
                    </button>
                  )}
                </div>
              </div>
            )}

            {/* Savings summary strip */}
            {(confirmedSavings > 0 || rechargedItems.length > 0 || missedChargeItems.length > 0) && (
              <div className="border-t border-sw-border px-6 py-3.5 bg-sw-surface/50 flex items-center justify-between flex-wrap gap-3">
                {confirmedSavings > 0 && (
                  <div className="flex items-center gap-2 text-sm">
                    <CheckCircle2 size={16} className="text-sw-success" />
                    <span className="text-sw-muted">Confirmed savings:</span>
                    <span className="font-bold text-sw-success">{fmt.format(confirmedSavings)}/mo</span>
                    <span className="text-sw-dim">({fmt.format(confirmedSavings * 12)}/yr)</span>
                  </div>
                )}
                {rechargedItems.length > 0 && (
                  <div className="flex items-center gap-2 text-sm">
                    <AlertTriangle size={16} className="text-sw-warning" />
                    <span className="font-medium text-sw-warning">{rechargedItems.length} charged after cancellation</span>
                  </div>
                )}
                {missedChargeItems.length > 0 && (
                  <div className="flex items-center gap-2 text-sm">
                    <Clock size={16} className="text-slate-400" />
                    <span className="text-sw-muted">{missedChargeItems.length} overdue — may no longer be active</span>
                  </div>
                )}
              </div>
            )}
          </div>

          {/* ── Active subscriptions ── */}
          <section>
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-sm font-bold text-sw-text uppercase tracking-wider">
                {activeFilter === 'essential' ? 'Essential Bills' : activeFilter === 'non-essential' ? 'Non-Essential' : 'Active'}
                <span className="ml-2 text-sw-dim font-normal normal-case tracking-normal">
                  {sortedActive.length} subscription{sortedActive.length !== 1 ? 's' : ''}
                  {activeFilter !== 'all' && ` — ${fmt.format(sortedActive.reduce((s, i) => s + Number(i.amount), 0))}/mo`}
                </span>
              </h2>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3" aria-live="polite">
              {visibleActive.map((sub) => (
                <SubscriptionCard key={sub.id} subscription={sub} onUpdate={handleUpdate} />
              ))}
            </div>

            {hasMoreActive && (
              <button
                onClick={() => setShowAllActive(!showAllActive)}
                className="mt-4 mx-auto flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium text-sw-muted hover:text-sw-text border border-sw-border hover:border-sw-border-strong transition"
              >
                {showAllActive ? (
                  <>Show less <ChevronUp size={14} /></>
                ) : (
                  <>Show all {sortedActive.length} subscriptions <ChevronDown size={14} /></>
                )}
              </button>
            )}
          </section>

          {/* ── Cancelled subscriptions ── */}
          {cancelledItems.length > 0 && (
            <section>
              <button
                onClick={() => setShowCancelled(!showCancelled)}
                className="w-full flex items-center justify-between mb-4 group"
              >
                <h2 className="text-sm font-bold text-sw-text uppercase tracking-wider flex items-center gap-2">
                  Cancelled
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-sw-success-light text-sw-success border border-emerald-200">
                    {cancelledItems.length} cancelled
                  </span>
                  {confirmedSavings > 0 && (
                    <span className="text-sw-dim font-normal normal-case tracking-normal text-xs">
                      saving {fmt.format(confirmedSavings)}/mo
                    </span>
                  )}
                </h2>
                <span className="text-sw-dim group-hover:text-sw-muted transition">
                  {showCancelled ? <ChevronUp size={18} /> : <ChevronDown size={18} />}
                </span>
              </button>

              {showCancelled && (
                <>
                  {/* Re-charge alert banner */}
                  {rechargedItems.length > 0 && (
                    <div className="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 flex items-start gap-3">
                      <AlertTriangle size={18} className="text-amber-600 shrink-0 mt-0.5" />
                      <div>
                        <div className="text-sm font-semibold text-amber-900">
                          {rechargedItems.length} subscription{rechargedItems.length !== 1 ? 's' : ''} charged after you cancelled
                        </div>
                        <div className="text-xs text-amber-700 mt-0.5">
                          These companies continued billing after cancellation. Review each one and contact them to dispute the charge if needed.
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Confirmed savings card */}
                  {confirmedCancelled.length > 0 && (
                    <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50/50 px-5 py-4">
                      <div className="flex items-center gap-3 mb-2">
                        <div className="w-8 h-8 rounded-lg bg-sw-success/10 flex items-center justify-center">
                          <CheckCircle2 size={18} className="text-sw-success" />
                        </div>
                        <div>
                          <div className="text-lg font-bold text-sw-success">{fmt.format(confirmedSavings)}<span className="text-sm font-normal">/mo</span></div>
                          <div className="text-xs text-emerald-700">Verified savings from {confirmedCancelled.length} confirmed cancellation{confirmedCancelled.length !== 1 ? 's' : ''}</div>
                        </div>
                      </div>
                      <div className="flex flex-wrap gap-2 mt-3">
                        {confirmedCancelled.slice(0, 5).map((s) => (
                          <span key={s.id} className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-white border border-emerald-200 text-xs text-emerald-800">
                            {s.merchant_normalized || s.merchant_name}
                            <ArrowRight size={10} className="text-emerald-400" />
                            <span className="font-semibold">{fmt.format(Number(s.previous_amount ?? s.amount))}</span>
                          </span>
                        ))}
                        {confirmedCancelled.length > 5 && (
                          <span className="inline-flex items-center px-2 py-1 rounded-md bg-emerald-100 text-xs text-emerald-700">
                            +{confirmedCancelled.length - 5} more
                          </span>
                        )}
                      </div>
                    </div>
                  )}

                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3" aria-live="polite">
                    {/* Show recharged first, then confirmed */}
                    {[...rechargedItems, ...confirmedCancelled].map((sub) => (
                      <SubscriptionCard key={sub.id} subscription={sub} onUpdate={handleUpdate} />
                    ))}
                  </div>
                </>
              )}
            </section>
          )}
        </div>
      )}
    </AuthenticatedLayout>
  );
}
