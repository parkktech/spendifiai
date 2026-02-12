import { useState, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
  AlertTriangle,
  Sparkles,
  RefreshCw,
  Loader2,
  Target,
  Zap,
  CreditCard,
  ArrowDownLeft,
  ArrowUpRight,
  Scissors,
  Check,
  X,
  Clock,
  ChevronDown,
  ChevronUp,
  MessageCircleQuestion,
  Wallet,
  TrendingUp,
  CheckCircle2,
} from 'lucide-react';
import SpendingChart from '@/Components/SpendWise/SpendingChart';
import TransactionRow from '@/Components/SpendWise/TransactionRow';
import Badge from '@/Components/SpendWise/Badge';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { DashboardData, SavingsRecommendation } from '@/types/spendwise';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

function formatCurrency(amount: number): string {
  return `$${Math.abs(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// --- Action Card Types ---
type ActionType = 'subscription' | 'recommendation' | 'overspending' | 'questions';
type ActionTab = 'quick' | 'week' | 'month';

interface ActionItem {
  id: string;
  type: ActionType;
  title: string;
  description: string;
  monthlySavings: number;
  annualSavings: number;
  tab: ActionTab;
  actionLabel: string;
  actionSteps?: string[];
  sourceId?: number;
  category?: string;
  relatedMerchants?: string[];
}

function buildActionItems(data: DashboardData): ActionItem[] {
  const items: ActionItem[] = [];

  // 1. Unused subscriptions → Quick Wins
  for (const sub of data.unused_subscription_details) {
    const merchant = sub.merchant_normalized || sub.merchant_name;
    const daysSinceUsed = sub.last_used_at
      ? Math.floor((Date.now() - new Date(sub.last_used_at).getTime()) / 86400000)
      : null;
    items.push({
      id: `sub-${sub.id}`,
      type: 'subscription',
      title: `Cancel ${merchant}`,
      description: daysSinceUsed
        ? `You haven't used ${merchant} in ${daysSinceUsed} days. That's ${fmt.format(sub.amount)} every month going to waste.`
        : `${merchant} appears to be unused. You're paying ${fmt.format(sub.amount)}/mo (${fmt.format(sub.annual_cost)}/yr).`,
      monthlySavings: sub.amount,
      annualSavings: sub.annual_cost,
      tab: 'quick',
      actionLabel: 'Cancel Subscription',
      sourceId: sub.id,
    });
  }

  // 2. AI savings recommendations → by difficulty
  for (const rec of data.savings_recommendations) {
    const tab: ActionTab = rec.difficulty === 'easy' ? 'quick' : rec.difficulty === 'medium' ? 'week' : 'month';
    items.push({
      id: `rec-${rec.id}`,
      type: 'recommendation',
      title: rec.title,
      description: rec.description,
      monthlySavings: rec.monthly_savings,
      annualSavings: rec.annual_savings,
      tab,
      actionLabel: 'Apply Recommendation',
      actionSteps: rec.action_steps ?? undefined,
      sourceId: rec.id,
      category: rec.category,
      relatedMerchants: rec.related_merchants ?? undefined,
    });
  }

  // 3. Overspending categories → This Month (behavioral changes)
  const avgMonthly = data.savings_opportunities.length > 0
    ? data.savings_opportunities.reduce((s, o) => s + o.monthly_avg, 0) / data.savings_opportunities.length
    : 0;

  for (const opp of data.savings_opportunities.slice(0, 3)) {
    if (opp.monthly_avg > avgMonthly * 1.5 && opp.category !== 'Uncategorized') {
      const savings = Math.round(opp.monthly_avg * 0.2);
      if (savings < 10) continue;
      // Don't add if there's already a recommendation for this category
      if (items.some((i) => i.category === opp.category && i.type === 'recommendation')) continue;
      items.push({
        id: `opp-${opp.category}`,
        type: 'overspending',
        title: `Reduce ${opp.category} spending`,
        description: `You average ${fmt.format(opp.monthly_avg)}/mo on ${opp.category} across ${opp.transaction_count} transactions. A 20% cut would save ${fmt.format(savings)}/mo.`,
        monthlySavings: savings,
        annualSavings: savings * 12,
        tab: 'month',
        actionLabel: 'Set Budget',
        category: opp.category,
      });
    }
  }

  // 4. AI questions → Quick Wins (if enough pending)
  if (data.summary.pending_questions >= 2) {
    items.push({
      id: 'questions',
      type: 'questions',
      title: `Answer ${data.summary.pending_questions} AI questions`,
      description: `Help the AI categorize your transactions more accurately. Better categories mean better savings recommendations.`,
      monthlySavings: 0,
      annualSavings: 0,
      tab: 'quick',
      actionLabel: 'Answer Now',
    });
  }

  // Sort each tab by savings descending (questions always last in quick wins)
  items.sort((a, b) => {
    if (a.tab !== b.tab) return 0;
    if (a.type === 'questions') return 1;
    if (b.type === 'questions') return -1;
    return b.monthlySavings - a.monthlySavings;
  });

  return items;
}

// --- Sub-components ---

function ActionCard({
  item,
  onApply,
  onDismiss,
  loading,
}: {
  item: ActionItem;
  onApply: (item: ActionItem) => void;
  onDismiss: (item: ActionItem) => void;
  loading: boolean;
}) {
  const [expanded, setExpanded] = useState(false);
  const iconMap: Record<ActionType, typeof Scissors> = {
    subscription: Scissors,
    recommendation: Sparkles,
    overspending: TrendingUp,
    questions: MessageCircleQuestion,
  };
  const colorMap: Record<ActionType, string> = {
    subscription: 'bg-red-50 border-red-200 text-red-600',
    recommendation: 'bg-violet-50 border-violet-200 text-violet-600',
    overspending: 'bg-amber-50 border-amber-200 text-amber-600',
    questions: 'bg-blue-50 border-blue-200 text-blue-600',
  };
  const Icon = iconMap[item.type];
  const color = colorMap[item.type];

  return (
    <div className="rounded-xl border border-sw-border bg-sw-card p-4 hover:shadow-sm transition">
      <div className="flex items-start gap-3">
        <div className={`w-9 h-9 rounded-lg border flex items-center justify-center shrink-0 ${color}`}>
          <Icon size={16} />
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <h4 className="text-sm font-semibold text-sw-text">{item.title}</h4>
              <p className="text-xs text-sw-muted mt-0.5 leading-relaxed">{item.description}</p>
            </div>
            {item.monthlySavings > 0 && (
              <div className="text-right shrink-0">
                <div className="text-base font-bold text-sw-accent">{fmt.format(item.monthlySavings)}/mo</div>
                <div className="text-[10px] text-sw-dim">{fmt.format(item.annualSavings)}/yr</div>
              </div>
            )}
          </div>

          {/* Related merchants */}
          {item.relatedMerchants && item.relatedMerchants.length > 0 && (
            <div className="text-[11px] text-sw-dim mt-1.5">
              Related: {item.relatedMerchants.join(', ')}
            </div>
          )}

          {/* Expandable action steps */}
          {item.actionSteps && item.actionSteps.length > 0 && (
            <div className="mt-2">
              <button
                onClick={() => setExpanded(!expanded)}
                className="flex items-center gap-1 text-xs text-sw-muted hover:text-sw-text transition"
              >
                {expanded ? <ChevronUp size={13} /> : <ChevronDown size={13} />}
                {expanded ? 'Hide' : 'Show'} steps ({item.actionSteps.length})
              </button>
              {expanded && (
                <ol className="mt-1.5 space-y-1 pl-4">
                  {item.actionSteps.map((step, i) => (
                    <li key={i} className="text-xs text-sw-muted list-decimal leading-relaxed">
                      {step}
                    </li>
                  ))}
                </ol>
              )}
            </div>
          )}

          {/* Action buttons */}
          <div className="flex items-center gap-2 mt-3">
            {item.type === 'questions' ? (
              <Link
                href="/questions"
                className="px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition"
              >
                {item.actionLabel}
              </Link>
            ) : (
              <button
                onClick={() => onApply(item)}
                disabled={loading}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition disabled:opacity-50"
              >
                {loading ? <Loader2 size={12} className="animate-spin" /> : <Check size={12} />}
                {item.actionLabel}
              </button>
            )}
            {item.type !== 'questions' && (
              <button
                onClick={() => onDismiss(item)}
                className="inline-flex items-center gap-1 text-xs text-sw-dim hover:text-sw-danger transition"
              >
                <X size={12} />
                Dismiss
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

function LoadingSkeleton() {
  return (
    <div className="animate-pulse space-y-6">
      <div className="h-20 rounded-2xl bg-sw-card border border-sw-border" />
      <div className="h-10 rounded-lg bg-sw-card border border-sw-border w-64" />
      <div className="space-y-3">
        {[1, 2, 3].map((i) => (
          <div key={i} className="h-28 rounded-xl bg-sw-card border border-sw-border" />
        ))}
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div className="h-64 rounded-2xl bg-sw-card border border-sw-border" />
        <div className="h-64 rounded-2xl bg-sw-card border border-sw-border" />
      </div>
    </div>
  );
}

// --- Main Dashboard ---

export default function Dashboard() {
  const { data, loading, error, refresh } = useApi<DashboardData>('/api/v1/dashboard');
  const { submit: analyzeSpending, loading: analyzing } = useApiPost('/api/v1/savings/analyze');
  const { submit: dismissRec } = useApiPost('', 'POST');
  const { submit: applyRec } = useApiPost('', 'POST');
  const [activeTab, setActiveTab] = useState<ActionTab>('quick');
  const [actionLoading, setActionLoading] = useState<string | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const actionItems = useMemo(() => (data ? buildActionItems(data) : []), [data]);

  const tabCounts = useMemo(() => ({
    quick: actionItems.filter((i) => i.tab === 'quick').length,
    week: actionItems.filter((i) => i.tab === 'week').length,
    month: actionItems.filter((i) => i.tab === 'month').length,
  }), [actionItems]);

  const filteredActions = useMemo(
    () => actionItems.filter((i) => i.tab === activeTab),
    [actionItems, activeTab]
  );

  const totalPotentialSavings = useMemo(
    () => actionItems.reduce((s, i) => s + i.monthlySavings, 0),
    [actionItems]
  );

  const showToast = (msg: string) => {
    setToast(msg);
    setTimeout(() => setToast(null), 4000);
  };

  const handleAnalyze = async () => {
    await analyzeSpending();
    refresh();
  };

  const handleApply = async (item: ActionItem) => {
    setActionLoading(item.id);
    if (item.type === 'recommendation' && item.sourceId) {
      const result = await applyRec(undefined, { url: `/api/v1/savings/${item.sourceId}/apply` } as never) as { budget_created?: boolean } | undefined;
      if (result?.budget_created) {
        showToast(`Applied! Budget set for ${item.category}.`);
      } else {
        showToast(`Applied: ${item.title}`);
      }
    } else if (item.type === 'subscription' && item.sourceId) {
      await applyRec(undefined, { url: `/api/v1/subscriptions/${item.sourceId}/cancel` } as never);
      showToast(`Cancelled: ${item.title}`);
    } else {
      showToast(`Applied: ${item.title}`);
    }
    setActionLoading(null);
    refresh();
  };

  const handleDismiss = async (item: ActionItem) => {
    if (item.type === 'recommendation' && item.sourceId) {
      await dismissRec(undefined, { url: `/api/v1/savings/${item.sourceId}/dismiss` } as never);
    }
    refresh();
  };

  // Contextual greeting message
  const getGreeting = (d: DashboardData): { headline: string; sub: string } => {
    const net = d.summary.net_this_month;
    const target = d.savings_target;
    const potential = d.summary.potential_savings;

    if (target && net >= target.monthly_target) {
      return {
        headline: `You're on track!`,
        sub: `${formatCurrency(net)} saved — ahead of your ${fmt.format(target.monthly_target)}/mo target.`,
      };
    }
    if (net > 0) {
      return {
        headline: `You're ${formatCurrency(net)} ahead this month`,
        sub: potential > 0
          ? `AI found ${fmt.format(potential)}/mo more you could save.`
          : 'Keep up the momentum.',
      };
    }
    if (actionItems.length > 0) {
      return {
        headline: `${actionItems.length} ways to get back on track`,
        sub: `You're ${formatCurrency(Math.abs(net))} over budget. Start with the quick wins below.`,
      };
    }
    return {
      headline: 'Your financial dashboard',
      sub: 'Connect your bank and run an AI analysis to get personalized savings tips.',
    };
  };

  const tabs: { key: ActionTab; label: string }[] = [
    { key: 'quick', label: 'Quick Wins' },
    { key: 'week', label: 'This Week' },
    { key: 'month', label: 'This Month' },
  ];

  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text tracking-tight">Dashboard</h1>
          <p className="text-xs text-sw-dim mt-0.5">Your financial command center</p>
        </div>
      }
    >
      <Head title="Dashboard" />

      {/* Toast notification */}
      {toast && (
        <div className="fixed top-4 right-4 z-50 animate-in fade-in slide-in-from-top-2 rounded-lg bg-emerald-600 text-white px-4 py-2.5 text-sm font-medium shadow-lg flex items-center gap-2">
          <CheckCircle2 size={16} />
          {toast}
        </div>
      )}

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
          {/* SECTION A: Smart Greeting + Hero Metrics */}
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
              <h2 className="text-lg font-bold text-sw-text">{getGreeting(data).headline}</h2>
              <p className="text-sm text-sw-muted">{getGreeting(data).sub}</p>
            </div>
            <div className="flex items-center gap-4 text-sm">
              <div className="flex items-center gap-1.5">
                <ArrowDownLeft size={14} className="text-emerald-500" />
                <span className="text-sw-dim">In:</span>
                <span className="font-semibold text-sw-text">{formatCurrency(data.summary.this_month_income)}</span>
              </div>
              <div className="flex items-center gap-1.5">
                <ArrowUpRight size={14} className="text-red-500" />
                <span className="text-sw-dim">Out:</span>
                <span className="font-semibold text-sw-text">{formatCurrency(data.summary.this_month_spending)}</span>
              </div>
              <div className={`flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold ${
                data.free_to_spend > 0
                  ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                  : 'bg-amber-50 text-amber-700 border border-amber-200'
              }`}>
                <Wallet size={13} />
                {formatCurrency(data.free_to_spend)} free
              </div>
            </div>
          </div>

          {/* Sync status */}
          {data.sync_status && (
            <div className="flex items-center gap-2 text-[11px] text-sw-dim -mt-3">
              <RefreshCw size={10} />
              <span>
                {data.sync_status.institution_name} — last synced:{' '}
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

          {/* SECTION B: Your Money Moves — Action Feed */}
          <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
            <div className="flex items-center justify-between mb-5">
              <div>
                <h2 className="text-[15px] font-semibold text-sw-text">Your Money Moves</h2>
                {totalPotentialSavings > 0 ? (
                  <p className="text-xs text-sw-muted mt-0.5">
                    {actionItems.filter((i) => i.type !== 'questions').length} actions could save you{' '}
                    <span className="font-semibold text-sw-accent">{fmt.format(totalPotentialSavings)}/mo</span>
                  </p>
                ) : (
                  <p className="text-xs text-sw-dim mt-0.5">Run an analysis to find savings opportunities</p>
                )}
              </div>
              <button
                onClick={handleAnalyze}
                disabled={analyzing}
                className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition disabled:opacity-50"
              >
                {analyzing ? <Loader2 size={14} className="animate-spin" /> : <Zap size={14} />}
                Analyze Spending
              </button>
            </div>

            {/* Tab bar */}
            <div className="flex items-center gap-1 mb-4 border-b border-sw-border">
              {tabs.map(({ key, label }) => (
                <button
                  key={key}
                  onClick={() => setActiveTab(key)}
                  className={`relative px-3 py-2 text-xs font-medium transition ${
                    activeTab === key
                      ? 'text-sw-accent'
                      : 'text-sw-dim hover:text-sw-text'
                  }`}
                >
                  {label}
                  {tabCounts[key] > 0 && (
                    <span className={`ml-1.5 inline-flex items-center justify-center w-5 h-5 rounded-full text-[10px] font-bold ${
                      activeTab === key
                        ? 'bg-sw-accent text-white'
                        : 'bg-sw-border text-sw-dim'
                    }`}>
                      {tabCounts[key]}
                    </span>
                  )}
                  {activeTab === key && (
                    <div className="absolute bottom-0 left-0 right-0 h-0.5 bg-sw-accent rounded-t" />
                  )}
                </button>
              ))}
            </div>

            {/* Action cards */}
            {filteredActions.length > 0 ? (
              <div className="space-y-3">
                {filteredActions.map((item) => (
                  <ActionCard
                    key={item.id}
                    item={item}
                    onApply={handleApply}
                    onDismiss={handleDismiss}
                    loading={actionLoading === item.id}
                  />
                ))}
              </div>
            ) : (
              <div className="text-center py-8">
                <CheckCircle2 size={32} className="mx-auto text-emerald-400 mb-2" />
                <p className="text-sm text-sw-muted">No actions here — you're on top of things!</p>
                {activeTab !== 'quick' && tabCounts.quick > 0 && (
                  <button
                    onClick={() => setActiveTab('quick')}
                    className="text-xs text-sw-accent hover:text-sw-accent-hover transition mt-2"
                  >
                    Check Quick Wins
                  </button>
                )}
              </div>
            )}
          </div>

          {/* SECTION C: Savings Momentum Tracker */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {/* Applied savings streak */}
            <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-9 h-9 rounded-lg bg-emerald-50 border border-emerald-200 flex items-center justify-center">
                  <CheckCircle2 size={18} className="text-emerald-600" />
                </div>
                <div>
                  <h3 className="text-[15px] font-semibold text-sw-text">Savings Progress</h3>
                  {data.applied_savings_total > 0 ? (
                    <p className="text-xs text-sw-muted">
                      Saving <span className="font-semibold text-emerald-600">{fmt.format(data.applied_savings_total)}/mo</span> from {data.applied_this_month.length} applied action{data.applied_this_month.length !== 1 ? 's' : ''}
                    </p>
                  ) : (
                    <p className="text-xs text-sw-dim">Apply recommendations above to start tracking</p>
                  )}
                </div>
              </div>

              {data.applied_this_month.length > 0 ? (
                <div className="space-y-2">
                  {data.applied_this_month.map((rec) => (
                    <div key={rec.id} className="flex items-center gap-2 py-1.5">
                      <Check size={14} className="text-emerald-500 shrink-0" />
                      <span className="text-xs text-sw-text flex-1 truncate">{rec.title}</span>
                      <span className="text-xs font-semibold text-emerald-600">{fmt.format(rec.monthly_savings)}/mo</span>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-4">
                  <p className="text-xs text-sw-dim">Complete your first action above to see your progress here.</p>
                </div>
              )}
            </div>

            {/* Savings Goal */}
            <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-9 h-9 rounded-lg bg-blue-50 border border-blue-200 flex items-center justify-center">
                  <Target size={18} className="text-blue-600" />
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
          </div>

          {/* SECTION D: AI Questions Inline */}
          {data.questions && data.questions.length > 0 && (
            <Link
              href="/questions"
              className="flex items-center gap-3 rounded-xl border border-violet-200 bg-violet-50 p-4 hover:bg-violet-100 transition"
            >
              <div className="w-10 h-10 rounded-lg bg-violet-100 border border-violet-200 flex items-center justify-center shrink-0">
                <MessageCircleQuestion size={18} className="text-violet-600" />
              </div>
              <div className="flex-1">
                <div className="text-sm font-semibold text-sw-text">
                  AI needs your input on {data.summary.pending_questions} transaction{data.summary.pending_questions !== 1 ? 's' : ''}
                </div>
                <div className="text-xs text-sw-muted mt-0.5">
                  Answering improves categorization accuracy and savings recommendations
                </div>
              </div>
              <Badge variant="info">{data.summary.pending_questions}</Badge>
            </Link>
          )}

          {/* SECTION E: Financial Health — Charts */}
          <SpendingChart data={data.spending_trend} categories={data.categories} />

          {/* SECTION F: Recent Transactions (compact) */}
          {data.recent && data.recent.length > 0 && (
            <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-[15px] font-semibold text-sw-text">Recent Activity</h3>
                <Link
                  href="/transactions"
                  className="text-xs text-sw-accent hover:text-sw-accent-hover transition"
                >
                  View All
                </Link>
              </div>
              <div>
                {data.recent.slice(0, 8).map((tx) => (
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
