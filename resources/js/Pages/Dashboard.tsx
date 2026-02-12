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
  ChevronDown,
  ChevronUp,
  MessageCircleQuestion,
  Wallet,
  TrendingUp,
  CheckCircle2,
  Home,
  Receipt,
  AlertCircle,
  PiggyBank,
  Building2,
  ShieldAlert,
  ArrowRight,
  TrendingDown,
  Shield,
} from 'lucide-react';
import SpendingChart from '@/Components/SpendWise/SpendingChart';
import TransactionRow from '@/Components/SpendWise/TransactionRow';
import Badge from '@/Components/SpendWise/Badge';
import ActionResponsePanel from '@/Components/SpendWise/ActionResponsePanel';
import ProjectedSavingsBanner from '@/Components/SpendWise/ProjectedSavingsBanner';
import SavingsTrackingChart from '@/Components/SpendWise/SavingsTrackingChart';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { DashboardData, RecurringBill, BudgetWaterfall, HomeAffordability } from '@/types/spendwise';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

function formatCurrency(amount: number): string {
  return `$${Math.abs(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatCompact(amount: number): string {
  if (amount >= 1000) return `$${(amount / 1000).toFixed(0)}k`;
  return `$${amount.toFixed(0)}`;
}

// --- Budget Waterfall Section ---

function BudgetWaterfallSection({ waterfall }: { waterfall: BudgetWaterfall }) {
  const surplus = waterfall.monthly_surplus;
  const canSave = waterfall.can_save;
  const rate = waterfall.savings_rate;

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
      <div className="flex items-start justify-between mb-5">
        <div className="flex items-center gap-3">
          <div className={`w-10 h-10 rounded-xl flex items-center justify-center ${canSave ? 'bg-emerald-50 border border-emerald-200' : 'bg-red-50 border border-red-200'}`}>
            {canSave ? <PiggyBank size={20} className="text-emerald-600" /> : <ShieldAlert size={20} className="text-red-600" />}
          </div>
          <div>
            <h2 className="text-[15px] font-semibold text-sw-text">Budget Reality Check</h2>
            <p className="text-xs text-sw-muted mt-0.5">Where every dollar goes this month</p>
          </div>
        </div>
        <div className={`px-3 py-1.5 rounded-lg text-xs font-bold ${canSave ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200'}`}>
          {canSave ? `Saving ${rate}%` : `${Math.abs(rate)}% over budget`}
        </div>
      </div>

      {/* Visual waterfall bar */}
      <div className="mb-4">
        <div className="flex h-8 rounded-lg overflow-hidden border border-sw-border">
          {waterfall.monthly_income > 0 && (
            <>
              {waterfall.essential_bills > 0 && (
                <div
                  className="bg-red-400 flex items-center justify-center text-[10px] font-bold text-white"
                  style={{ width: `${(waterfall.essential_bills / waterfall.monthly_income) * 100}%` }}
                  title={`Essential Bills: ${fmt.format(waterfall.essential_bills)}`}
                >
                  {(waterfall.essential_bills / waterfall.monthly_income) * 100 > 8 ? 'Bills' : ''}
                </div>
              )}
              {waterfall.non_essential_subscriptions > 0 && (
                <div
                  className="bg-amber-400 flex items-center justify-center text-[10px] font-bold text-white"
                  style={{ width: `${(waterfall.non_essential_subscriptions / waterfall.monthly_income) * 100}%` }}
                  title={`Subscriptions: ${fmt.format(waterfall.non_essential_subscriptions)}`}
                >
                  {(waterfall.non_essential_subscriptions / waterfall.monthly_income) * 100 > 8 ? 'Subs' : ''}
                </div>
              )}
              {waterfall.discretionary_spending > 0 && (
                <div
                  className="bg-orange-400 flex items-center justify-center text-[10px] font-bold text-white"
                  style={{ width: `${(waterfall.discretionary_spending / waterfall.monthly_income) * 100}%` }}
                  title={`Other: ${fmt.format(waterfall.discretionary_spending)}`}
                >
                  {(waterfall.discretionary_spending / waterfall.monthly_income) * 100 > 8 ? 'Other' : ''}
                </div>
              )}
              {surplus > 0 && (
                <div
                  className="bg-emerald-400 flex items-center justify-center text-[10px] font-bold text-white"
                  style={{ width: `${(surplus / waterfall.monthly_income) * 100}%` }}
                  title={`Surplus: ${fmt.format(surplus)}`}
                >
                  {(surplus / waterfall.monthly_income) * 100 > 8 ? 'Savings' : ''}
                </div>
              )}
            </>
          )}
        </div>
      </div>

      {/* Breakdown rows */}
      <div className="space-y-2.5">
        <div className="flex items-center justify-between py-1.5">
          <div className="flex items-center gap-2.5">
            <div className="w-3 h-3 rounded-sm bg-emerald-500" />
            <span className="text-sm text-sw-text">Monthly Income</span>
          </div>
          <span className="text-sm font-bold text-emerald-700">{fmt.format(waterfall.monthly_income)}</span>
        </div>
        <div className="flex items-center justify-between py-1.5">
          <div className="flex items-center gap-2.5">
            <div className="w-3 h-3 rounded-sm bg-red-400" />
            <span className="text-sm text-sw-text">Essential Bills</span>
          </div>
          <span className="text-sm font-semibold text-red-600">-{fmt.format(waterfall.essential_bills)}</span>
        </div>
        <div className="flex items-center justify-between py-1.5">
          <div className="flex items-center gap-2.5">
            <div className="w-3 h-3 rounded-sm bg-amber-400" />
            <span className="text-sm text-sw-text">Non-Essential Subscriptions</span>
          </div>
          <span className="text-sm font-semibold text-amber-600">-{fmt.format(waterfall.non_essential_subscriptions)}</span>
        </div>
        <div className="flex items-center justify-between py-1.5">
          <div className="flex items-center gap-2.5">
            <div className="w-3 h-3 rounded-sm bg-orange-400" />
            <span className="text-sm text-sw-text">Other Spending</span>
          </div>
          <span className="text-sm font-semibold text-orange-600">-{fmt.format(waterfall.discretionary_spending)}</span>
        </div>
        <div className="border-t border-sw-border pt-2.5 flex items-center justify-between">
          <span className="text-sm font-bold text-sw-text">{canSave ? 'Monthly Surplus' : 'Monthly Deficit'}</span>
          <span className={`text-lg font-bold ${canSave ? 'text-emerald-700' : 'text-red-600'}`}>
            {canSave ? '+' : ''}{fmt.format(surplus)}
          </span>
        </div>
      </div>

      {/* Verdict */}
      <div className={`mt-4 rounded-xl p-4 ${canSave ? 'bg-emerald-50 border border-emerald-200' : 'bg-red-50 border border-red-200'}`}>
        <p className={`text-sm font-medium ${canSave ? 'text-emerald-800' : 'text-red-800'}`}>
          {canSave
            ? `Yes, you can save! You have ${fmt.format(surplus)}/mo left after all expenses. That's a ${rate}% savings rate.`
            : `You're spending ${fmt.format(Math.abs(surplus))}/mo more than you earn. You need to cut expenses to start saving.`
          }
        </p>
      </div>
    </div>
  );
}

// --- Monthly Bills Section ---

function MonthlyBillsSection({ bills, totalMonthly }: { bills: RecurringBill[]; totalMonthly: number }) {
  const [showAll, setShowAll] = useState(false);
  const essentialBills = bills.filter(b => b.is_essential);
  const nonEssentialBills = bills.filter(b => !b.is_essential);
  const displayBills = showAll ? bills : bills.slice(0, 8);

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
      <div className="flex items-start justify-between mb-5">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-blue-50 border border-blue-200 flex items-center justify-center">
            <Receipt size={20} className="text-blue-600" />
          </div>
          <div>
            <h2 className="text-[15px] font-semibold text-sw-text">Your Monthly Bills</h2>
            <p className="text-xs text-sw-muted mt-0.5">{bills.length} recurring charges found</p>
          </div>
        </div>
        <div className="text-right">
          <div className="text-lg font-bold text-sw-text">{fmt.format(totalMonthly)}/mo</div>
          <div className="text-[11px] text-sw-dim">{fmt.format(totalMonthly * 12)}/yr</div>
        </div>
      </div>

      {/* Essential vs Non-essential summary */}
      <div className="grid grid-cols-2 gap-3 mb-4">
        <div className="rounded-lg bg-slate-50 border border-slate-200 p-3">
          <div className="text-[11px] text-sw-muted font-medium uppercase tracking-wider mb-1">Essential</div>
          <div className="text-base font-bold text-sw-text">{fmt.format(essentialBills.reduce((s, b) => s + b.amount, 0))}</div>
          <div className="text-[11px] text-sw-dim">{essentialBills.length} bills</div>
        </div>
        <div className="rounded-lg bg-amber-50 border border-amber-200 p-3">
          <div className="text-[11px] text-amber-700 font-medium uppercase tracking-wider mb-1">Non-Essential</div>
          <div className="text-base font-bold text-amber-700">{fmt.format(nonEssentialBills.reduce((s, b) => s + b.amount, 0))}</div>
          <div className="text-[11px] text-amber-600">{nonEssentialBills.length} could be cut</div>
        </div>
      </div>

      {/* Bills list */}
      <div className="space-y-1">
        {displayBills.map((bill) => (
          <div key={bill.id} className="flex items-center gap-3 py-2 border-b border-sw-border last:border-b-0">
            <div className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 ${
              bill.status === 'unused'
                ? 'bg-red-50 border border-red-200'
                : bill.is_essential
                  ? 'bg-slate-50 border border-slate-200'
                  : 'bg-amber-50 border border-amber-200'
            }`}>
              {bill.status === 'unused' ? (
                <AlertCircle size={14} className="text-red-500" />
              ) : bill.is_essential ? (
                <Building2 size={14} className="text-slate-500" />
              ) : (
                <CreditCard size={14} className="text-amber-500" />
              )}
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2">
                <span className="text-[13px] font-medium text-sw-text truncate">
                  {bill.merchant_normalized || bill.merchant_name}
                </span>
                {bill.status === 'unused' && <Badge variant="danger">Unused</Badge>}
              </div>
              <div className="text-[11px] text-sw-dim mt-0.5">
                {bill.frequency}
                {bill.next_expected_date && ` \u00B7 Next: ${new Date(bill.next_expected_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`}
              </div>
            </div>
            <div className="text-right shrink-0">
              <div className="text-sm font-semibold text-sw-text">{fmt.format(bill.amount)}/mo</div>
              <div className="text-[10px] text-sw-dim">{fmt.format(bill.annual_cost)}/yr</div>
            </div>
          </div>
        ))}
      </div>

      {bills.length > 8 && (
        <button
          onClick={() => setShowAll(!showAll)}
          className="flex items-center gap-1.5 mt-3 text-xs text-sw-accent hover:text-sw-accent-hover transition"
        >
          {showAll ? <ChevronUp size={13} /> : <ChevronDown size={13} />}
          {showAll ? 'Show less' : `Show all ${bills.length} bills`}
        </button>
      )}

      <Link
        href="/subscriptions"
        className="flex items-center gap-1.5 mt-3 text-xs text-sw-accent hover:text-sw-accent-hover font-medium transition"
      >
        Manage Subscriptions <ArrowRight size={12} />
      </Link>
    </div>
  );
}

// --- Home Affordability Section ---

function HomeAffordabilitySection({ affordability }: { affordability: HomeAffordability }) {
  const hasIncome = affordability.monthly_income > 0;

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
      <div className="flex items-center gap-3 mb-5">
        <div className="w-10 h-10 rounded-xl bg-violet-50 border border-violet-200 flex items-center justify-center">
          <Home size={20} className="text-violet-600" />
        </div>
        <div>
          <h2 className="text-[15px] font-semibold text-sw-text">Home Affordability</h2>
          <p className="text-xs text-sw-muted mt-0.5">Based on your income, debt, and ${formatCompact(affordability.down_payment)} down payment</p>
        </div>
      </div>

      {hasIncome ? (
        <>
          {/* Hero number */}
          <div className="text-center mb-5">
            <div className="text-xs text-sw-muted font-medium uppercase tracking-wider mb-1">Max Home Price</div>
            <div className="text-3xl font-bold text-sw-text">{fmt.format(affordability.max_home_price)}</div>
            <div className="text-xs text-sw-dim mt-1">
              {fmt.format(affordability.max_loan_amount)} loan + {fmt.format(affordability.down_payment)} down
            </div>
          </div>

          {/* Key numbers grid */}
          <div className="grid grid-cols-2 gap-3 mb-5">
            <div className="rounded-lg bg-sw-surface p-3">
              <div className="text-[11px] text-sw-muted font-medium uppercase tracking-wider">Monthly Payment</div>
              <div className="text-base font-bold text-sw-text mt-1">{fmt.format(affordability.estimated_monthly_mortgage)}</div>
            </div>
            <div className="rounded-lg bg-sw-surface p-3">
              <div className="text-[11px] text-sw-muted font-medium uppercase tracking-wider">Interest Rate</div>
              <div className="text-base font-bold text-sw-text mt-1">{affordability.interest_rate}%</div>
            </div>
            <div className="rounded-lg bg-sw-surface p-3">
              <div className="text-[11px] text-sw-muted font-medium uppercase tracking-wider">Current DTI</div>
              <div className={`text-base font-bold mt-1 ${affordability.current_dti > 36 ? 'text-red-600' : affordability.current_dti > 28 ? 'text-amber-600' : 'text-emerald-600'}`}>
                {affordability.current_dti}%
              </div>
            </div>
            <div className="rounded-lg bg-sw-surface p-3">
              <div className="text-[11px] text-sw-muted font-medium uppercase tracking-wider">Loan Term</div>
              <div className="text-base font-bold text-sw-text mt-1">{affordability.loan_term_years} years</div>
            </div>
          </div>

          {/* DTI context */}
          <div className={`rounded-xl p-3 ${
            affordability.current_dti > 36
              ? 'bg-red-50 border border-red-200'
              : affordability.current_dti > 28
                ? 'bg-amber-50 border border-amber-200'
                : 'bg-emerald-50 border border-emerald-200'
          }`}>
            <div className="flex items-start gap-2">
              <AlertCircle size={14} className={`mt-0.5 shrink-0 ${
                affordability.current_dti > 36 ? 'text-red-600' : affordability.current_dti > 28 ? 'text-amber-600' : 'text-emerald-600'
              }`} />
              <div className="text-xs leading-relaxed">
                {affordability.current_dti > 36 ? (
                  <span className="text-red-800">
                    Your debt-to-income ratio is {affordability.current_dti}%, which exceeds the 43% max for most lenders.
                    You need to reduce monthly debt by {fmt.format(affordability.monthly_debt - (affordability.monthly_income * 0.43))} to qualify.
                  </span>
                ) : affordability.current_dti > 28 ? (
                  <span className="text-amber-800">
                    Your DTI of {affordability.current_dti}% is moderate. Reducing monthly bills by {fmt.format(affordability.monthly_debt - (affordability.monthly_income * 0.28))} would improve your max home price.
                  </span>
                ) : (
                  <span className="text-emerald-800">
                    Your DTI of {affordability.current_dti}% is excellent. You're in a strong position to qualify for a mortgage.
                  </span>
                )}
              </div>
            </div>
          </div>

          {/* Income / Debt breakdown */}
          <div className="flex items-center justify-between mt-4 pt-3 border-t border-sw-border">
            <div className="text-xs text-sw-dim">
              Income: {fmt.format(affordability.monthly_income)}/mo | Debt: {fmt.format(affordability.monthly_debt)}/mo
            </div>
          </div>
        </>
      ) : (
        <div className="text-center py-6">
          <Home size={32} className="mx-auto text-sw-dim mb-2" />
          <p className="text-sm text-sw-muted mb-1">No income data available</p>
          <p className="text-xs text-sw-dim">Upload a bank statement or connect your bank to see home affordability estimates.</p>
        </div>
      )}
    </div>
  );
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

interface RespondedCard {
  type: 'cancelled' | 'reduced' | 'kept';
  savings: number;
  previousAmount?: number;
  newAmount?: number;
}

function buildActionItems(data: DashboardData): ActionItem[] {
  const items: ActionItem[] = [];

  // 1. Unused subscriptions -> Quick Wins
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
      actionLabel: 'Respond',
      sourceId: sub.id,
    });
  }

  // 2. AI savings recommendations -> by difficulty
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
      actionLabel: 'Respond',
      actionSteps: rec.action_steps ?? undefined,
      sourceId: rec.id,
      category: rec.category,
      relatedMerchants: rec.related_merchants ?? undefined,
    });
  }

  // 3. Overspending categories -> This Month
  const avgMonthly = data.savings_opportunities.length > 0
    ? data.savings_opportunities.reduce((s, o) => s + o.monthly_avg, 0) / data.savings_opportunities.length
    : 0;

  for (const opp of data.savings_opportunities.slice(0, 3)) {
    if (opp.monthly_avg > avgMonthly * 1.5 && opp.category !== 'Uncategorized') {
      const savings = Math.round(opp.monthly_avg * 0.2);
      if (savings < 10) continue;
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

  // 4. AI questions -> Quick Wins
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

  items.sort((a, b) => {
    if (a.tab !== b.tab) return 0;
    if (a.type === 'questions') return 1;
    if (b.type === 'questions') return -1;
    return b.monthlySavings - a.monthlySavings;
  });

  return items;
}

// --- Resolved Card Compact Display ---

function ResolvedCardDisplay({ item, responded }: { item: ActionItem; responded: RespondedCard }) {
  const iconMap: Record<RespondedCard['type'], { Icon: typeof CheckCircle2; color: string; bg: string; badgeClass: string; badgeLabel: string }> = {
    cancelled: {
      Icon: CheckCircle2,
      color: 'text-emerald-600',
      bg: 'bg-emerald-50 border-emerald-200',
      badgeClass: 'bg-emerald-50 text-emerald-700 border-emerald-200',
      badgeLabel: `Saving ${fmt.format(responded.savings)}/mo`,
    },
    reduced: {
      Icon: TrendingDown,
      color: 'text-blue-600',
      bg: 'bg-blue-50 border-blue-200',
      badgeClass: 'bg-blue-50 text-blue-700 border-blue-200',
      badgeLabel: `Saving ${fmt.format(responded.savings)}/mo`,
    },
    kept: {
      Icon: Shield,
      color: 'text-slate-400',
      bg: 'bg-slate-50 border-slate-200',
      badgeClass: 'bg-slate-100 text-slate-500 border-slate-200',
      badgeLabel: 'Kept',
    },
  };

  const config = iconMap[responded.type];
  const { Icon } = config;

  return (
    <div className={`rounded-xl border border-sw-border bg-sw-card p-4 ${responded.type === 'kept' ? 'opacity-60' : ''}`}>
      <div className="flex items-center gap-3">
        <div className={`w-9 h-9 rounded-lg border flex items-center justify-center shrink-0 ${config.bg}`}>
          <Icon size={16} className={config.color} />
        </div>
        <div className="flex-1 min-w-0">
          <h4 className={`text-sm font-medium text-sw-text ${responded.type === 'cancelled' ? 'line-through' : ''}`}>
            {item.title}
          </h4>
          {responded.type === 'reduced' && responded.previousAmount && responded.newAmount !== undefined && (
            <p className="text-[11px] text-sw-dim mt-0.5">
              Reduced from {fmt.format(responded.previousAmount)} to {fmt.format(responded.newAmount)}/mo
            </p>
          )}
        </div>
        <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold border ${config.badgeClass}`}>
          {config.badgeLabel}
        </span>
      </div>
    </div>
  );
}

// --- Sub-components ---

function ActionCard({
  item,
  onRespond,
  onDismiss,
  loading,
  isExpanded,
  respondedData,
  onConfirmResponse,
  onCancelResponse,
  responseLoading,
}: {
  item: ActionItem;
  onRespond: (item: ActionItem) => void;
  onDismiss: (item: ActionItem) => void;
  loading: boolean;
  isExpanded: boolean;
  respondedData?: RespondedCard;
  onConfirmResponse: (item: ActionItem, response: { response_type: 'cancelled' | 'reduced' | 'kept'; new_amount?: number; reason?: string }) => void;
  onCancelResponse: () => void;
  responseLoading: boolean;
}) {
  const [stepsExpanded, setStepsExpanded] = useState(false);

  // Show resolved state if this card has been responded to
  if (respondedData) {
    return <ResolvedCardDisplay item={item} responded={respondedData} />;
  }

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

          {item.relatedMerchants && item.relatedMerchants.length > 0 && (
            <div className="text-[11px] text-sw-dim mt-1.5">
              Related: {item.relatedMerchants.join(', ')}
            </div>
          )}

          {item.actionSteps && item.actionSteps.length > 0 && (
            <div className="mt-2">
              <button
                onClick={() => setStepsExpanded(!stepsExpanded)}
                className="flex items-center gap-1 text-xs text-sw-muted hover:text-sw-text transition"
              >
                {stepsExpanded ? <ChevronUp size={13} /> : <ChevronDown size={13} />}
                {stepsExpanded ? 'Hide' : 'Show'} steps ({item.actionSteps.length})
              </button>
              {stepsExpanded && (
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

          {!isExpanded && (
            <div className="flex items-center gap-2 mt-3">
              {item.type === 'questions' ? (
                <Link
                  href="/questions"
                  className="px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition"
                >
                  {item.actionLabel}
                </Link>
              ) : item.type === 'overspending' ? (
                <button
                  onClick={() => onRespond(item)}
                  disabled={loading}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition disabled:opacity-50"
                >
                  {loading ? <Loader2 size={12} className="animate-spin" /> : <Check size={12} />}
                  Set Budget
                </button>
              ) : (
                <button
                  onClick={() => onRespond(item)}
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
          )}

          {/* Inline ActionResponsePanel */}
          {isExpanded && (
            <ActionResponsePanel
              originalAmount={item.monthlySavings}
              itemTitle={item.title}
              onConfirm={(response) => onConfirmResponse(item, response)}
              onCancel={onCancelResponse}
              loading={responseLoading}
            />
          )}
        </div>
      </div>
    </div>
  );
}

function LoadingSkeleton() {
  return (
    <div className="animate-pulse space-y-6">
      <div className="h-20 rounded-2xl bg-sw-card border border-sw-border" />
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div className="h-80 rounded-2xl bg-sw-card border border-sw-border" />
        <div className="h-80 rounded-2xl bg-sw-card border border-sw-border" />
      </div>
      <div className="h-64 rounded-2xl bg-sw-card border border-sw-border" />
      <div className="h-48 rounded-2xl bg-sw-card border border-sw-border" />
    </div>
  );
}

// --- Main Dashboard ---

export default function Dashboard() {
  const { data, loading, error, refresh } = useApi<DashboardData>('/api/v1/dashboard');
  const { submit: analyzeSpending, loading: analyzing } = useApiPost('/api/v1/savings/analyze');
  const { submit: dismissRec } = useApiPost('', 'POST');
  const { submit: respondToAction } = useApiPost('', 'POST');
  const [activeTab, setActiveTab] = useState<ActionTab>('quick');
  const [actionLoading, setActionLoading] = useState<string | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [expandedCard, setExpandedCard] = useState<string | null>(null);
  const [respondedCards, setRespondedCards] = useState<Map<string, RespondedCard>>(new Map());
  const [responseLoading, setResponseLoading] = useState(false);

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

  const handleRespond = (item: ActionItem) => {
    // For overspending items without a sourceId, fall back to the old apply behavior
    if (item.type === 'overspending') {
      handleLegacyApply(item);
      return;
    }
    // Toggle the expansion panel for this card
    setExpandedCard(expandedCard === item.id ? null : item.id);
  };

  const handleLegacyApply = async (item: ActionItem) => {
    setActionLoading(item.id);
    if (item.type === 'recommendation' && item.sourceId) {
      const result = await respondToAction(undefined, { url: `/api/v1/savings/${item.sourceId}/apply` } as never) as { budget_created?: boolean } | undefined;
      if (result?.budget_created) {
        showToast(`Applied! Budget set for ${item.category}.`);
      } else {
        showToast(`Applied: ${item.title}`);
      }
    } else {
      showToast(`Applied: ${item.title}`);
    }
    setActionLoading(null);
    refresh();
  };

  const handleConfirmResponse = async (
    item: ActionItem,
    response: { response_type: 'cancelled' | 'reduced' | 'kept'; new_amount?: number; reason?: string }
  ) => {
    setResponseLoading(true);

    let apiUrl = '';
    if (item.type === 'subscription' && item.sourceId) {
      apiUrl = `/api/v1/subscriptions/${item.sourceId}/respond`;
    } else if (item.type === 'recommendation' && item.sourceId) {
      apiUrl = `/api/v1/savings/${item.sourceId}/respond`;
    }

    if (!apiUrl) {
      setResponseLoading(false);
      return;
    }

    const result = await respondToAction(response, { url: apiUrl } as never);

    if (result) {
      // Calculate savings for the responded card display
      let savings = 0;
      if (response.response_type === 'cancelled') {
        savings = item.monthlySavings;
      } else if (response.response_type === 'reduced' && response.new_amount !== undefined) {
        savings = Math.max(item.monthlySavings - response.new_amount, 0);
      }

      setRespondedCards(prev => {
        const next = new Map(prev);
        next.set(item.id, {
          type: response.response_type,
          savings,
          previousAmount: item.monthlySavings,
          newAmount: response.new_amount,
        });
        return next;
      });

      const messages: Record<string, string> = {
        cancelled: `Cancelled! Saving ${fmt.format(item.monthlySavings)}/mo`,
        reduced: `Reduced! Saving ${fmt.format(savings)}/mo`,
        kept: `Marked as kept: ${item.title}`,
      };
      showToast(messages[response.response_type]);
      setExpandedCard(null);
      refresh();
    } else {
      showToast('Something went wrong. Please try again.');
    }

    setResponseLoading(false);
  };

  const handleDismiss = async (item: ActionItem) => {
    if (item.type === 'recommendation' && item.sourceId) {
      await dismissRec(undefined, { url: `/api/v1/savings/${item.sourceId}/dismiss` } as never);
    }
    refresh();
  };

  const getGreeting = (d: DashboardData): { headline: string; sub: string } => {
    const surplus = d.budget_waterfall.monthly_surplus;
    const canSave = d.budget_waterfall.can_save;

    if (canSave && surplus > 500) {
      return {
        headline: `You can save ${formatCurrency(surplus)}/mo`,
        sub: totalPotentialSavings > 0
          ? `Plus AI found ${fmt.format(totalPotentialSavings)}/mo more you could save by cutting expenses.`
          : 'Your spending is under control. Keep it up.',
      };
    }
    if (canSave) {
      return {
        headline: `${formatCurrency(surplus)}/mo left after bills`,
        sub: 'Tight but possible. See the action items below to free up more cash.',
      };
    }
    if (actionItems.length > 0) {
      return {
        headline: `${actionItems.length} ways to stop the bleeding`,
        sub: `You're ${formatCurrency(Math.abs(surplus))} over budget. The cuts below could save ${fmt.format(totalPotentialSavings)}/mo.`,
      };
    }
    return {
      headline: 'Your financial dashboard',
      sub: 'Connect your bank or upload a statement to get personalized analysis.',
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
                data.budget_waterfall.can_save
                  ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                  : 'bg-red-50 text-red-700 border border-red-200'
              }`}>
                <Wallet size={13} />
                {data.budget_waterfall.can_save ? '+' : ''}{formatCurrency(data.budget_waterfall.monthly_surplus)}
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

          {/* SECTION B: Budget Reality Check + Home Affordability (side by side) */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <BudgetWaterfallSection waterfall={data.budget_waterfall} />
            <HomeAffordabilitySection affordability={data.home_affordability} />
          </div>

          {/* SECTION C: Your Monthly Bills */}
          {data.recurring_bills.length > 0 && (
            <MonthlyBillsSection bills={data.recurring_bills} totalMonthly={data.total_monthly_bills} />
          )}

          {/* SECTION D: Your Money Moves — Action Feed */}
          <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
            <div className="flex items-center justify-between mb-5">
              <div>
                <h2 className="text-[15px] font-semibold text-sw-text">Where to Cut</h2>
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

            {/* Projected Savings Banner */}
            {data.projected_savings && data.projected_savings.projected_monthly_savings > 0 && (
              <div className="mb-4">
                <ProjectedSavingsBanner projection={data.projected_savings} />
              </div>
            )}

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
                    onRespond={handleRespond}
                    onDismiss={handleDismiss}
                    loading={actionLoading === item.id}
                    isExpanded={expandedCard === item.id}
                    respondedData={respondedCards.get(item.id)}
                    onConfirmResponse={handleConfirmResponse}
                    onCancelResponse={() => setExpandedCard(null)}
                    responseLoading={responseLoading && expandedCard === item.id}
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

          {/* SECTION E: Savings Progress + Goal (side by side) */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {/* Savings Tracking Chart or Applied savings streak */}
            {data.savings_history && data.savings_history.length > 0 ? (
              <SavingsTrackingChart
                data={data.savings_history}
                projectedMonthly={data.projected_savings?.projected_monthly_savings ?? 0}
              />
            ) : (
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
            )}

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

          {/* SECTION F: AI Questions Inline */}
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

          {/* SECTION G: Financial Health — Charts */}
          <SpendingChart data={data.spending_trend} categories={data.categories} />

          {/* SECTION H: Recent Transactions (compact) */}
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
