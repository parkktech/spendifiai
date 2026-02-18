import { useState, useMemo } from 'react';
import {
  PieChart,
  Pie,
  Cell,
  ResponsiveContainer,
  Tooltip,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
} from 'recharts';
import {
  Home,
  Shield,
  Zap,
  Smartphone,
  Wifi,
  ShoppingCart,
  Car,
  Heart,
  Baby,
  Banknote,
  CreditCard,
  ChevronDown,
  ChevronUp,
  TrendingUp,
  DollarSign,
  Briefcase,
  FileText,
  ArrowDownLeft,
  Info,
  Loader2,
} from 'lucide-react';
import type { CostOfLivingData, IncomeBreakdown, IncomeSource } from '@/types/spendifiai';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

function formatCompact(n: number): string {
  if (n >= 1000) return `$${(n / 1000).toFixed(1)}k`;
  return `$${n.toFixed(0)}`;
}

// Category icon + color mapping
const CATEGORY_CONFIG: Record<string, { Icon: typeof Home; color: string; bg: string; ring: string }> = {
  Housing:          { Icon: Home,         color: '#6366f1', bg: 'bg-indigo-50',  ring: 'ring-indigo-200' },
  'Loan Payments':  { Icon: Banknote,     color: '#8b5cf6', bg: 'bg-violet-50',  ring: 'ring-violet-200' },
  Insurance:        { Icon: Shield,       color: '#0ea5e9', bg: 'bg-sky-50',     ring: 'ring-sky-200' },
  Utilities:        { Icon: Zap,          color: '#f59e0b', bg: 'bg-amber-50',   ring: 'ring-amber-200' },
  Phone:            { Icon: Smartphone,   color: '#10b981', bg: 'bg-emerald-50', ring: 'ring-emerald-200' },
  'Internet & Cable': { Icon: Wifi,       color: '#06b6d4', bg: 'bg-cyan-50',    ring: 'ring-cyan-200' },
  Groceries:        { Icon: ShoppingCart, color: '#22c55e', bg: 'bg-green-50',   ring: 'ring-green-200' },
  'Gas & Auto':     { Icon: Car,          color: '#f97316', bg: 'bg-orange-50',  ring: 'ring-orange-200' },
  Medical:          { Icon: Heart,        color: '#ef4444', bg: 'bg-red-50',     ring: 'ring-red-200' },
  Childcare:        { Icon: Baby,         color: '#ec4899', bg: 'bg-pink-50',    ring: 'ring-pink-200' },
  'Credit Card Payments': { Icon: CreditCard, color: '#64748b', bg: 'bg-slate-50', ring: 'ring-slate-200' },
  'Car Payment':    { Icon: Car,          color: '#f97316', bg: 'bg-orange-50',  ring: 'ring-orange-200' },
};

const DEFAULT_CONFIG = { Icon: DollarSign, color: '#64748b', bg: 'bg-slate-50', ring: 'ring-slate-200' };

function getConfig(category: string) {
  return CATEGORY_CONFIG[category] ?? DEFAULT_CONFIG;
}

// Income type icon + color mapping
const INCOME_TYPE_CONFIG: Record<IncomeSource['type'], { Icon: typeof Briefcase; color: string; bg: string; border: string }> = {
  employment: { Icon: Briefcase,    color: 'text-emerald-600', bg: 'bg-emerald-50', border: 'border-emerald-200' },
  contractor: { Icon: FileText,     color: 'text-blue-600',    bg: 'bg-blue-50',    border: 'border-blue-200' },
  interest:   { Icon: TrendingUp,   color: 'text-violet-600',  bg: 'bg-violet-50',  border: 'border-violet-200' },
  transfer:   { Icon: ArrowDownLeft, color: 'text-slate-500',  bg: 'bg-slate-50',   border: 'border-slate-200' },
  other:      { Icon: DollarSign,   color: 'text-amber-600',   bg: 'bg-amber-50',   border: 'border-amber-200' },
};

const FREQUENCY_LABELS: Record<string, string> = {
  weekly: 'Weekly',
  'bi-weekly': 'Bi-weekly',
  monthly: 'Monthly',
  quarterly: 'Quarterly',
  annual: 'Annual',
  irregular: 'Irregular',
};

// Custom tooltip for pie chart
function PieTooltip({ active, payload }: { active?: boolean; payload?: Array<{ name: string; value: number }> }) {
  if (!active || !payload?.[0]) return null;
  const { name, value } = payload[0];
  return (
    <div className="rounded-lg bg-sw-card border border-sw-border shadow-lg px-3 py-2">
      <div className="text-xs font-semibold text-sw-text">{name}</div>
      <div className="text-sm font-bold text-sw-accent">{fmt.format(value)}/mo</div>
    </div>
  );
}

// Income Sources Panel
function IncomeSourcesPanel({
  incomeSources,
  onClassify,
  classifyLoading,
}: {
  incomeSources: IncomeBreakdown;
  onClassify?: (overrideType: string, overrideKey: string, classification: string) => Promise<void>;
  classifyLoading?: boolean;
}) {
  const [expanded, setExpanded] = useState(false);
  const [loadingKey, setLoadingKey] = useState<string | null>(null);

  if (!incomeSources.sources.length) return null;

  const hasReliableIncome = incomeSources.reliable_monthly > 0;
  const reliableDiffersFromTotal = hasReliableIncome &&
    Math.abs(incomeSources.reliable_monthly - incomeSources.total_monthly_avg) > 100;

  return (
    <div className="px-6 pt-2 pb-4 border-t border-sw-border">
      <button
        onClick={() => setExpanded(!expanded)}
        className="flex items-center gap-2 text-xs font-medium text-sw-accent hover:text-sw-accent-hover transition mb-3"
      >
        {expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
        {expanded ? 'Hide' : 'Show'} Income Sources ({incomeSources.sources.length})
      </button>

      {expanded && (
        <div className="animate-in fade-in slide-in-from-top-2 duration-300 space-y-2">
          {/* Reliable vs total insight */}
          {reliableDiffersFromTotal && (
            <div className="rounded-lg bg-blue-50 border border-blue-200 p-3 flex items-start gap-2 mb-3">
              <Info size={14} className="text-blue-600 mt-0.5 shrink-0" />
              <p className="text-xs text-blue-800 leading-relaxed">
                Your reliable employment income is <span className="font-bold">{fmt.format(incomeSources.reliable_monthly)}/mo</span>.
                {' '}Including contractor and other income, you average{' '}
                <span className="font-bold">{fmt.format(incomeSources.total_monthly_avg)}/mo</span>.
                {' '}Budget using the reliable figure for safety.
              </p>
            </div>
          )}

          {/* Summary row */}
          <div className="flex items-center justify-between py-2 px-3 rounded-lg bg-sw-surface">
            <span className="text-xs font-medium text-sw-muted">Total Monthly Income</span>
            <span className="text-sm font-bold text-sw-text">{fmt.format(incomeSources.total_monthly_avg)}/mo</span>
          </div>

          {/* Individual sources */}
          {incomeSources.sources.map((source, idx) => {
            const typeConfig = INCOME_TYPE_CONFIG[source.type];
            const { Icon } = typeConfig;
            const overrideKey = `${source.type}|${source.label}`;
            const isLoading = classifyLoading && loadingKey === overrideKey;

            const handleToggle = async () => {
              if (!onClassify) return;
              const newClassification = source.classification === 'primary' ? 'extra' : 'primary';
              setLoadingKey(overrideKey);
              await onClassify('income_source', overrideKey, newClassification);
              setLoadingKey(null);
            };

            return (
              <div key={idx} className="flex items-center gap-3 py-2 px-3 rounded-lg hover:bg-sw-surface transition">
                <div className={`w-8 h-8 rounded-lg ${typeConfig.bg} border ${typeConfig.border} flex items-center justify-center shrink-0`}>
                  <Icon size={14} className={typeConfig.color} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="text-[13px] font-medium text-sw-text truncate">{source.label}</span>
                    {source.frequency && (
                      <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold ${
                        source.is_regular
                          ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                          : 'bg-slate-100 text-slate-500 border border-slate-200'
                      }`}>
                        {FREQUENCY_LABELS[source.frequency] ?? source.frequency}
                      </span>
                    )}
                  </div>
                  <div className="text-[11px] text-sw-dim mt-0.5">
                    {source.occurrences} deposit{source.occurrences !== 1 ? 's' : ''} — avg {fmt.format(source.avg_amount)} each
                  </div>
                </div>
                <div className="text-sm font-semibold text-sw-text shrink-0">{fmt.format(source.monthly_equivalent)}/mo</div>
                {onClassify && source.type !== 'transfer' && (
                  <button
                    onClick={handleToggle}
                    disabled={isLoading}
                    className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border transition cursor-pointer hover:opacity-80 disabled:opacity-50 shrink-0 ${
                      source.classification === 'primary'
                        ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                        : 'bg-amber-50 text-amber-600 border-amber-200'
                    }`}
                    title={`Click to mark as ${source.classification === 'primary' ? 'extra' : 'primary'}`}
                  >
                    {isLoading ? <Loader2 size={10} className="animate-spin mr-0.5" /> : null}
                    {source.classification === 'primary' ? 'Primary' : 'Extra'}
                  </button>
                )}
              </div>
            );
          })}

          <div className="text-[11px] text-sw-dim pt-1">
            Based on {incomeSources.months_analyzed} months of transaction data
          </div>
        </div>
      )}
    </div>
  );
}

interface Props {
  data: CostOfLivingData;
  incomeSources?: IncomeBreakdown;
  onClassify?: (overrideType: string, overrideKey: string, classification: string) => Promise<void>;
  classifyLoading?: boolean;
}

export default function CostOfLivingBreakdown({ data, incomeSources, onClassify, classifyLoading }: Props) {
  const [expandedCategory, setExpandedCategory] = useState<string | null>(null);
  const [showComparison, setShowComparison] = useState(true);

  const incomeAfterEssentials = data.monthly_income - data.total_essential_monthly;
  const essentialPct = data.monthly_income > 0
    ? Math.round((data.total_essential_monthly / data.monthly_income) * 100)
    : 0;
  const discretionaryPct = data.monthly_income > 0
    ? Math.round((data.discretionary_monthly / data.monthly_income) * 100)
    : 0;
  const remainingPct = Math.max(100 - essentialPct - discretionaryPct, 0);

  // Pie chart data
  const pieData = useMemo(() =>
    data.items.map(item => ({
      name: item.category,
      value: Number(item.monthly_avg),
      color: getConfig(item.category).color,
    })),
    [data.items]
  );

  // Comparison bar data: income vs essential vs discretionary vs surplus
  const comparisonData = useMemo(() => [
    {
      label: 'Income',
      amount: data.monthly_income,
      fill: '#059669',
    },
    {
      label: 'Must-Pay Bills',
      amount: data.total_essential_monthly,
      fill: '#6366f1',
    },
    {
      label: 'Other Spending',
      amount: data.discretionary_monthly,
      fill: '#f59e0b',
    },
    {
      label: 'Remaining',
      amount: Math.max(data.monthly_income - data.total_essential_monthly - data.discretionary_monthly, 0),
      fill: '#10b981',
    },
  ], [data]);

  if (data.items.length === 0) return null;

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card overflow-hidden">
      {/* Header with gradient accent strip */}
      <div className="relative px-6 pt-6 pb-4">
        <div className="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-indigo-500 via-sky-500 to-emerald-500" />

        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <div className="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-100 to-sky-100 border border-indigo-200 flex items-center justify-center">
              <TrendingUp size={22} className="text-indigo-600" />
            </div>
            <div>
              <h2 className="text-[16px] font-bold text-sw-text tracking-tight">Cost of Living</h2>
              <p className="text-xs text-sw-muted mt-0.5">
                Your must-pay monthly bills ({data.months_analyzed}-month average)
              </p>
            </div>
          </div>
          <div className="text-right">
            <div className="text-xl font-bold text-sw-text">{fmt.format(data.total_essential_monthly)}</div>
            <div className="text-[11px] text-sw-dim">
              {essentialPct}% of income
            </div>
          </div>
        </div>
      </div>

      {/* Three-segment income allocation bar */}
      <div className="px-6 pb-4">
        <div className="flex h-3 rounded-full overflow-hidden bg-sw-surface ring-1 ring-sw-border">
          <div
            className="transition-all duration-700 ease-out"
            style={{
              width: `${essentialPct}%`,
              background: 'linear-gradient(90deg, #6366f1, #818cf8)',
            }}
            title={`Must-Pay Bills: ${essentialPct}%`}
          />
          <div
            className="transition-all duration-700 ease-out"
            style={{
              width: `${discretionaryPct}%`,
              background: 'linear-gradient(90deg, #f59e0b, #fbbf24)',
            }}
            title={`Other Spending: ${discretionaryPct}%`}
          />
          <div
            className="transition-all duration-700 ease-out"
            style={{
              width: `${remainingPct}%`,
              background: 'linear-gradient(90deg, #10b981, #34d399)',
            }}
            title={`Remaining: ${remainingPct}%`}
          />
        </div>
        <div className="flex items-center justify-between mt-2 text-[11px]">
          <div className="flex items-center gap-1.5">
            <div className="w-2 h-2 rounded-full bg-indigo-500" />
            <span className="text-sw-muted">Must-Pay <span className="font-semibold text-sw-text">{essentialPct}%</span></span>
          </div>
          <div className="flex items-center gap-1.5">
            <div className="w-2 h-2 rounded-full bg-amber-500" />
            <span className="text-sw-muted">Other <span className="font-semibold text-sw-text">{discretionaryPct}%</span></span>
          </div>
          <div className="flex items-center gap-1.5">
            <div className="w-2 h-2 rounded-full bg-emerald-500" />
            <span className="text-sw-muted">Left Over <span className="font-semibold text-sw-text">{remainingPct}%</span></span>
          </div>
        </div>
      </div>

      {/* Main content: Donut chart + Bill list side by side */}
      <div className="px-6 pb-2 grid grid-cols-1 lg:grid-cols-5 gap-6">
        {/* Donut chart */}
        <div className="lg:col-span-2 flex flex-col items-center justify-center">
          <div className="relative">
            <ResponsiveContainer width={200} height={200}>
              <PieChart>
                <Pie
                  data={pieData}
                  cx="50%"
                  cy="50%"
                  innerRadius={58}
                  outerRadius={88}
                  paddingAngle={2}
                  dataKey="value"
                  nameKey="name"
                  strokeWidth={0}
                  animationBegin={0}
                  animationDuration={800}
                >
                  {pieData.map((entry, i) => (
                    <Cell
                      key={i}
                      fill={entry.color}
                      className="cursor-pointer transition-opacity"
                      opacity={expandedCategory && expandedCategory !== entry.name ? 0.3 : 1}
                      onClick={() => setExpandedCategory(expandedCategory === entry.name ? null : entry.name)}
                    />
                  ))}
                </Pie>
                <Tooltip content={<PieTooltip />} />
              </PieChart>
            </ResponsiveContainer>
            {/* Center label */}
            <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
              <div className="text-center">
                <div className="text-lg font-bold text-sw-text">{formatCompact(data.total_essential_monthly)}</div>
                <div className="text-[10px] text-sw-dim uppercase tracking-wider font-medium">per month</div>
              </div>
            </div>
          </div>
        </div>

        {/* Bills breakdown list */}
        <div className="lg:col-span-3 space-y-1">
          {data.items.map((item) => {
            const config = getConfig(item.category);
            const { Icon } = config;
            const isExpanded = expandedCategory === item.category;
            const pctOfTotal = data.total_essential_monthly > 0
              ? Math.round((item.monthly_avg / data.total_essential_monthly) * 100)
              : 0;

            return (
              <div key={item.category}>
                <button
                  onClick={() => setExpandedCategory(isExpanded ? null : item.category)}
                  className="w-full flex items-center gap-3 py-2.5 px-2 rounded-lg hover:bg-sw-surface transition group text-left"
                >
                  <div className={`w-8 h-8 rounded-lg ${config.bg} ring-1 ${config.ring} flex items-center justify-center shrink-0`}>
                    <Icon size={15} style={{ color: config.color }} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between">
                      <span className="text-[13px] font-medium text-sw-text">{item.category}</span>
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-bold text-sw-text">{fmt.format(item.monthly_avg)}</span>
                        <span className="text-[10px] text-sw-dim font-medium w-8 text-right">{pctOfTotal}%</span>
                        {isExpanded
                          ? <ChevronUp size={14} className="text-sw-dim" />
                          : <ChevronDown size={14} className="text-sw-dim opacity-0 group-hover:opacity-100 transition" />
                        }
                      </div>
                    </div>
                    {/* Mini progress bar */}
                    <div className="h-1 bg-sw-surface rounded-full mt-1.5 overflow-hidden">
                      <div
                        className="h-full rounded-full transition-all duration-500"
                        style={{
                          width: `${pctOfTotal}%`,
                          backgroundColor: config.color,
                        }}
                      />
                    </div>
                  </div>
                </button>

                {/* Expanded merchant breakdown */}
                {isExpanded && item.top_merchants.length > 0 && (
                  <div className="ml-13 pl-2 border-l-2 mb-2 space-y-1 animate-in fade-in slide-in-from-top-1 duration-200" style={{ borderColor: config.color + '40', marginLeft: '3.25rem' }}>
                    {item.top_merchants.map((m, idx) => (
                      <div key={idx} className="flex items-center justify-between py-1 px-2">
                        <div className="flex items-center gap-1.5">
                          <span className="text-xs text-sw-muted truncate max-w-[200px]">{m.name}</span>
                          {item.category === 'Housing' && m.name === 'Rent / Mortgage' && (
                            <span className="text-[9px] text-indigo-600 bg-indigo-50 border border-indigo-200 px-1 py-0.5 rounded font-medium">
                              servicer change detected
                            </span>
                          )}
                        </div>
                        <span className="text-xs font-semibold text-sw-text-secondary">{fmt.format(m.monthly_avg)}/mo</span>
                      </div>
                    ))}
                    <div className="flex items-center justify-between py-1 px-2 border-t border-sw-border">
                      <span className="text-[11px] text-sw-dim">{item.transaction_count} transactions over {data.months_analyzed} months</span>
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Income Sources Panel */}
      {incomeSources && incomeSources.sources.length > 0 && (
        <IncomeSourcesPanel
          incomeSources={incomeSources}
          onClassify={onClassify}
          classifyLoading={classifyLoading}
        />
      )}

      {/* Income comparison toggle */}
      <div className="px-6 pt-2 pb-4 border-t border-sw-border mt-2">
        <button
          onClick={() => setShowComparison(!showComparison)}
          className="flex items-center gap-2 text-xs font-medium text-sw-accent hover:text-sw-accent-hover transition mb-3"
        >
          {showComparison ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
          {showComparison ? 'Hide' : 'Show'} Income vs Expenses Breakdown
        </button>

        {showComparison && (
          <div className="animate-in fade-in slide-in-from-top-2 duration-300">
            <ResponsiveContainer width="100%" height={160}>
              <BarChart
                data={comparisonData}
                layout="vertical"
                margin={{ left: 0, right: 10, top: 0, bottom: 0 }}
                barGap={4}
              >
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" horizontal={false} />
                <XAxis
                  type="number"
                  tick={{ fill: '#94a3b8', fontSize: 11 }}
                  axisLine={false}
                  tickLine={false}
                  tickFormatter={formatCompact}
                />
                <YAxis
                  type="category"
                  dataKey="label"
                  tick={{ fill: '#64748b', fontSize: 11 }}
                  axisLine={false}
                  tickLine={false}
                  width={100}
                />
                <Tooltip
                  contentStyle={{
                    background: '#ffffff',
                    border: '1px solid #e2e8f0',
                    borderRadius: 10,
                    fontSize: 12,
                    boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)',
                  }}
                  formatter={(value: number | undefined) => [fmt.format(value ?? 0), 'Monthly']}
                />
                <Bar dataKey="amount" radius={[0, 6, 6, 0]} maxBarSize={28}>
                  {comparisonData.map((entry, i) => (
                    <Cell key={i} fill={entry.fill} />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>

            {/* Bottom insight */}
            <div className={`mt-3 rounded-xl p-3 ${
              incomeAfterEssentials > data.discretionary_monthly
                ? 'bg-emerald-50 border border-emerald-200'
                : 'bg-amber-50 border border-amber-200'
            }`}>
              <p className={`text-xs leading-relaxed ${
                incomeAfterEssentials > data.discretionary_monthly ? 'text-emerald-800' : 'text-amber-800'
              }`}>
                {incomeAfterEssentials > data.discretionary_monthly ? (
                  <>
                    After must-pay bills ({fmt.format(data.total_essential_monthly)}), you have{' '}
                    <span className="font-bold">{fmt.format(incomeAfterEssentials)}</span> left.
                    {' '}Your other spending is {fmt.format(data.discretionary_monthly)}, leaving{' '}
                    <span className="font-bold">{fmt.format(incomeAfterEssentials - data.discretionary_monthly)}</span> to save.
                  </>
                ) : (
                  <>
                    Your must-pay bills take {fmt.format(data.total_essential_monthly)} of your {fmt.format(data.monthly_income)} income,
                    leaving only {fmt.format(incomeAfterEssentials)} for everything else.
                    Your other spending ({fmt.format(data.discretionary_monthly)}) exceeds what's left.
                  </>
                )}
              </p>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
