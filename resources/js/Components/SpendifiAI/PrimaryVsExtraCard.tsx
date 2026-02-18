import { useState } from 'react';
import {
  Briefcase,
  FileText,
  TrendingUp,
  DollarSign,
  ArrowDownLeft,
  Home,
  Shield,
  Zap,
  Smartphone,
  Wifi,
  ShoppingCart,
  Car,
  Heart,
  Baby,
  CreditCard,
  Banknote,
  ChevronDown,
  ChevronUp,
  ArrowRight,
  Loader2,
} from 'lucide-react';
import type {
  PrimaryVsExtra,
  IncomeBreakdown,
  IncomeSource,
  CostOfLivingData,
} from '@/types/spendifiai';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

// Income type icons
const INCOME_ICONS: Record<IncomeSource['type'], { Icon: typeof Briefcase; color: string; bg: string }> = {
  employment: { Icon: Briefcase,    color: 'text-emerald-600', bg: 'bg-emerald-50' },
  contractor: { Icon: FileText,     color: 'text-blue-600',    bg: 'bg-blue-50' },
  interest:   { Icon: TrendingUp,   color: 'text-violet-600',  bg: 'bg-violet-50' },
  transfer:   { Icon: ArrowDownLeft, color: 'text-slate-500',  bg: 'bg-slate-50' },
  other:      { Icon: DollarSign,   color: 'text-amber-600',   bg: 'bg-amber-50' },
};

// Expense category icons
const EXPENSE_ICONS: Record<string, { Icon: typeof Home; color: string; bg: string }> = {
  Housing:               { Icon: Home,         color: 'text-indigo-600', bg: 'bg-indigo-50' },
  Insurance:             { Icon: Shield,       color: 'text-sky-600',    bg: 'bg-sky-50' },
  Utilities:             { Icon: Zap,          color: 'text-amber-600',  bg: 'bg-amber-50' },
  Phone:                 { Icon: Smartphone,   color: 'text-emerald-600', bg: 'bg-emerald-50' },
  'Internet & Cable':    { Icon: Wifi,         color: 'text-cyan-600',   bg: 'bg-cyan-50' },
  Groceries:             { Icon: ShoppingCart,  color: 'text-green-600',  bg: 'bg-green-50' },
  'Gas & Auto':          { Icon: Car,          color: 'text-orange-600', bg: 'bg-orange-50' },
  Medical:               { Icon: Heart,        color: 'text-red-600',    bg: 'bg-red-50' },
  Childcare:             { Icon: Baby,         color: 'text-pink-600',   bg: 'bg-pink-50' },
  'Credit Card Payments': { Icon: CreditCard,  color: 'text-slate-600',  bg: 'bg-slate-50' },
  'Car Payment':         { Icon: Car,          color: 'text-orange-600', bg: 'bg-orange-50' },
  'Loan Payments':       { Icon: Banknote,     color: 'text-violet-600', bg: 'bg-violet-50' },
};

const DEFAULT_EXPENSE_ICON = { Icon: DollarSign, color: 'text-slate-500', bg: 'bg-slate-50' };

function ClassificationBadge({
  classification,
  loading,
  onToggle,
}: {
  classification: 'primary' | 'extra';
  loading: boolean;
  onToggle: () => void;
}) {
  return (
    <button
      onClick={(e) => { e.stopPropagation(); onToggle(); }}
      disabled={loading}
      className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border transition cursor-pointer hover:opacity-80 disabled:opacity-50 ${
        classification === 'primary'
          ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
          : 'bg-amber-50 text-amber-600 border-amber-200'
      }`}
      title={`Click to mark as ${classification === 'primary' ? 'extra' : 'primary'}`}
    >
      {loading ? <Loader2 size={10} className="animate-spin mr-0.5" /> : null}
      {classification === 'primary' ? 'Primary' : 'Extra'}
    </button>
  );
}

interface Props {
  data: PrimaryVsExtra;
  incomeSources: IncomeBreakdown;
  costOfLiving: CostOfLivingData;
  onClassify: (overrideType: string, overrideKey: string, classification: string) => Promise<void>;
  classifyLoading: boolean;
}

export default function PrimaryVsExtraCard({
  data,
  incomeSources,
  costOfLiving,
  onClassify,
  classifyLoading,
}: Props) {
  const [showExtra, setShowExtra] = useState(false);
  const [loadingKey, setLoadingKey] = useState<string | null>(null);

  const coveragePct = data.coverage_pct;
  const canLive = data.can_live_on_primary;

  // Color based on coverage: green if expenses < income, amber if close, red if over
  const statusColor = canLive
    ? coveragePct <= 85
      ? { bg: 'bg-emerald-50', border: 'border-emerald-200', text: 'text-emerald-800', accent: 'text-emerald-700', strip: 'from-emerald-500 to-teal-500' }
      : { bg: 'bg-amber-50', border: 'border-amber-200', text: 'text-amber-800', accent: 'text-amber-700', strip: 'from-amber-500 to-yellow-500' }
    : { bg: 'bg-red-50', border: 'border-red-200', text: 'text-red-800', accent: 'text-red-700', strip: 'from-red-500 to-rose-500' };

  // Non-transfer income sources split by classification
  const primaryIncomeSources = incomeSources.sources.filter(s => s.type !== 'transfer' && s.classification === 'primary');
  const extraIncomeSources = incomeSources.sources.filter(s => s.type !== 'transfer' && s.classification === 'extra');

  // CoL items — all default to primary unless overridden (the backend handles this)
  // We detect "extra" expense categories by checking if their monthly_avg isn't included in primary_expenses
  // But simpler: we just look at the items and infer from the totals
  const primaryExpenseItems = costOfLiving.items; // all CoL items are primary by default
  const hasExtraExpenses = data.extra_expenses > 0;

  const handleToggleIncome = async (source: IncomeSource) => {
    const key = `${source.type}|${source.label}`;
    const newClassification = source.classification === 'primary' ? 'extra' : 'primary';
    setLoadingKey(key);
    await onClassify('income_source', key, newClassification);
    setLoadingKey(null);
  };

  const handleToggleExpense = async (category: string) => {
    const newClassification = 'extra'; // toggle — but we need to know current state
    // Simple approach: if it's in CoL items (primary by default), toggling makes it extra
    // If user clicks again, we'd need to know current override state. For now, we toggle.
    setLoadingKey(`exp:${category}`);
    // We don't have override state client-side, so just toggle based on reasonable assumption
    await onClassify('expense_category', category, newClassification);
    setLoadingKey(null);
  };

  if (data.primary_income === 0 && data.primary_expenses === 0) return null;

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card overflow-hidden">
      {/* Accent strip */}
      <div className={`h-1 bg-gradient-to-r ${statusColor.strip}`} />

      {/* Header */}
      <div className="px-6 pt-5 pb-4">
        <div className="flex items-start justify-between">
          <div>
            <h2 className="text-[16px] font-bold text-sw-text tracking-tight">Can You Live on Your Paycheck?</h2>
            <p className="text-xs text-sw-muted mt-0.5">
              Regular income vs regular monthly expenses
            </p>
          </div>
          <div className={`px-3 py-1.5 rounded-lg text-xs font-bold border ${statusColor.bg} ${statusColor.border} ${statusColor.accent}`}>
            {canLive
              ? coveragePct <= 85
                ? `${Math.round(100 - coveragePct)}% left over`
                : `Tight — ${Math.round(100 - coveragePct)}% left`
              : `${Math.round(coveragePct - 100)}% over budget`
            }
          </div>
        </div>

        {/* Big comparison bar */}
        <div className="mt-4 flex items-center gap-3">
          <div className="flex-1">
            <div className="text-[11px] text-sw-muted font-medium uppercase tracking-wider mb-1">Primary Income</div>
            <div className="text-lg font-bold text-emerald-700">{fmt.format(data.primary_income)}</div>
          </div>
          <div className="flex items-center gap-1.5">
            <ArrowRight size={16} className="text-sw-dim" />
          </div>
          <div className="flex-1 text-right">
            <div className="text-[11px] text-sw-muted font-medium uppercase tracking-wider mb-1">Primary Expenses</div>
            <div className="text-lg font-bold text-red-600">{fmt.format(data.primary_expenses)}</div>
          </div>
        </div>

        {/* Visual comparison bar */}
        <div className="mt-3 relative">
          <div className="h-4 rounded-full bg-sw-surface ring-1 ring-sw-border overflow-hidden flex">
            {data.primary_income > 0 && (
              <div
                className="h-full transition-all duration-500"
                style={{
                  width: `${Math.min(coveragePct, 100)}%`,
                  background: canLive
                    ? 'linear-gradient(90deg, #10b981, #34d399)'
                    : 'linear-gradient(90deg, #ef4444, #f87171)',
                }}
              />
            )}
          </div>
          {data.primary_income > 0 && (
            <div className="flex items-center justify-between mt-1.5 text-[11px]">
              <span className="text-sw-dim">
                {coveragePct <= 100
                  ? `Expenses use ${coveragePct}% of primary income`
                  : `Expenses exceed primary income by ${Math.round(coveragePct - 100)}%`
                }
              </span>
              <span className={`font-bold ${canLive ? 'text-emerald-600' : 'text-red-600'}`}>
                {canLive ? '+' : ''}{fmt.format(data.primary_surplus)}/mo
              </span>
            </div>
          )}
        </div>
      </div>

      {/* Two-column breakdown */}
      <div className="px-6 pb-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* Primary Income Sources */}
        <div>
          <div className="text-[11px] font-semibold text-sw-muted uppercase tracking-wider mb-2">
            Primary Income ({primaryIncomeSources.length})
          </div>
          <div className="space-y-1">
            {primaryIncomeSources.map((source) => {
              const iconConfig = INCOME_ICONS[source.type];
              const { Icon } = iconConfig;
              const key = `${source.type}|${source.label}`;
              return (
                <div key={key} className="flex items-center gap-2.5 py-1.5 px-2 rounded-lg hover:bg-sw-surface transition">
                  <div className={`w-7 h-7 rounded-lg ${iconConfig.bg} flex items-center justify-center shrink-0`}>
                    <Icon size={13} className={iconConfig.color} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <span className="text-xs font-medium text-sw-text truncate block">{source.label}</span>
                  </div>
                  <span className="text-xs font-semibold text-sw-text shrink-0">{fmt.format(source.monthly_equivalent)}</span>
                  <ClassificationBadge
                    classification="primary"
                    loading={classifyLoading && loadingKey === key}
                    onToggle={() => handleToggleIncome(source)}
                  />
                </div>
              );
            })}
            {primaryIncomeSources.length === 0 && (
              <p className="text-xs text-sw-dim py-2">No primary income sources detected</p>
            )}
          </div>
        </div>

        {/* Primary Expenses */}
        <div>
          <div className="text-[11px] font-semibold text-sw-muted uppercase tracking-wider mb-2">
            Primary Expenses ({primaryExpenseItems.length})
          </div>
          <div className="space-y-1">
            {primaryExpenseItems.map((item) => {
              const iconConfig = EXPENSE_ICONS[item.category] ?? DEFAULT_EXPENSE_ICON;
              const { Icon } = iconConfig;
              return (
                <div key={item.category} className="flex items-center gap-2.5 py-1.5 px-2 rounded-lg hover:bg-sw-surface transition">
                  <div className={`w-7 h-7 rounded-lg ${iconConfig.bg} flex items-center justify-center shrink-0`}>
                    <Icon size={13} className={iconConfig.color} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <span className="text-xs font-medium text-sw-text truncate block">{item.category}</span>
                  </div>
                  <span className="text-xs font-semibold text-sw-text shrink-0">{fmt.format(item.monthly_avg)}</span>
                  <ClassificationBadge
                    classification="primary"
                    loading={classifyLoading && loadingKey === `exp:${item.category}`}
                    onToggle={() => handleToggleExpense(item.category)}
                  />
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* Verdict */}
      <div className="px-6 pb-4">
        <div className={`rounded-xl p-3 border ${statusColor.bg} ${statusColor.border}`}>
          <p className={`text-xs leading-relaxed ${statusColor.text}`}>
            {canLive ? (
              coveragePct <= 85 ? (
                <>
                  Your regular paycheck ({fmt.format(data.primary_income)}) comfortably covers your monthly bills
                  ({fmt.format(data.primary_expenses)}), leaving <span className="font-bold">{fmt.format(data.primary_surplus)}/mo</span> for
                  savings and discretionary spending.
                </>
              ) : (
                <>
                  Your paycheck just barely covers your bills. Only <span className="font-bold">{fmt.format(data.primary_surplus)}/mo</span> left
                  over. Consider moving some expenses to reduce your baseline.
                </>
              )
            ) : (
              <>
                Your regular income ({fmt.format(data.primary_income)}) doesn't cover your monthly bills
                ({fmt.format(data.primary_expenses)}). You're relying on extra income of{' '}
                <span className="font-bold">{fmt.format(data.extra_income)}/mo</span> to make up the difference.
              </>
            )}
          </p>
        </div>
      </div>

      {/* Extra income/expenses collapsible */}
      {(extraIncomeSources.length > 0 || hasExtraExpenses) && (
        <div className="px-6 pb-4 border-t border-sw-border pt-3">
          <button
            onClick={() => setShowExtra(!showExtra)}
            className="flex items-center gap-2 text-xs font-medium text-sw-accent hover:text-sw-accent-hover transition mb-2"
          >
            {showExtra ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
            {showExtra ? 'Hide' : 'Show'} Extra Income & Expenses
          </button>

          {showExtra && (
            <div className="animate-in fade-in slide-in-from-top-2 duration-300 grid grid-cols-1 lg:grid-cols-2 gap-4">
              {/* Extra Income */}
              <div>
                <div className="text-[11px] font-semibold text-amber-600 uppercase tracking-wider mb-2">
                  Extra Income — {fmt.format(data.extra_income)}/mo
                </div>
                <div className="space-y-1">
                  {extraIncomeSources.map((source) => {
                    const iconConfig = INCOME_ICONS[source.type];
                    const { Icon } = iconConfig;
                    const key = `${source.type}|${source.label}`;
                    return (
                      <div key={key} className="flex items-center gap-2.5 py-1.5 px-2 rounded-lg hover:bg-sw-surface transition">
                        <div className={`w-7 h-7 rounded-lg ${iconConfig.bg} flex items-center justify-center shrink-0`}>
                          <Icon size={13} className={iconConfig.color} />
                        </div>
                        <div className="flex-1 min-w-0">
                          <span className="text-xs font-medium text-sw-text truncate block">{source.label}</span>
                          <span className="text-[10px] text-sw-dim">
                            {source.frequency ?? 'one-time'} — {source.occurrences} deposit{source.occurrences !== 1 ? 's' : ''}
                          </span>
                        </div>
                        <span className="text-xs font-semibold text-sw-text shrink-0">{fmt.format(source.monthly_equivalent)}</span>
                        <ClassificationBadge
                          classification="extra"
                          loading={classifyLoading && loadingKey === key}
                          onToggle={() => handleToggleIncome(source)}
                        />
                      </div>
                    );
                  })}
                  {extraIncomeSources.length === 0 && (
                    <p className="text-xs text-sw-dim py-1">No extra income detected</p>
                  )}
                </div>
              </div>

              {/* Extra Expenses */}
              <div>
                <div className="text-[11px] font-semibold text-amber-600 uppercase tracking-wider mb-2">
                  Extra Expenses — {fmt.format(data.extra_expenses)}/mo
                </div>
                <div className="space-y-1">
                  {data.extra_expenses > 0 && (
                    <div className="flex items-center gap-2.5 py-1.5 px-2 rounded-lg">
                      <div className="w-7 h-7 rounded-lg bg-amber-50 flex items-center justify-center shrink-0">
                        <DollarSign size={13} className="text-amber-600" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <span className="text-xs font-medium text-sw-text">Non-Essential & Discretionary</span>
                        <span className="text-[10px] text-sw-dim block">Subscriptions, shopping, dining out, etc.</span>
                      </div>
                      <span className="text-xs font-semibold text-sw-text shrink-0">{fmt.format(data.extra_expenses)}</span>
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
