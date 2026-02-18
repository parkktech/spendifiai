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
  ShoppingBag,
  ChevronDown,
  ChevronUp,
  Mail,
  Loader2,
  Store,
  Receipt,
  Package,
  Tag,
  Calendar,
} from 'lucide-react';
import axios from 'axios';
import StoreOrderItemsList from './StoreOrderItemsList';
import type { TopStore, StoreDetail, StoreTransaction, PeriodMeta } from '@/types/spendifiai';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

const CHART_COLORS = [
  '#2563eb', '#7c3aed', '#059669', '#d97706', '#dc2626',
  '#06b6d4', '#ec4899', '#f97316', '#a855f7', '#14b8a6',
];

interface TopStoresSectionProps {
  stores: TopStore[];
  total: number;
  period?: PeriodMeta;
  avgMode: 'total' | 'monthly_avg';
}

function formatDate(dateStr: string): string {
  return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(
    new Date(dateStr + 'T00:00:00')
  );
}

export default function TopStoresSection({ stores, total, period, avgMode }: TopStoresSectionProps) {
  const [expandedStore, setExpandedStore] = useState<string | null>(null);
  const [expandedMonth, setExpandedMonth] = useState<string | null>(null);
  const [storeDetails, setStoreDetails] = useState<Record<string, StoreDetail>>({});
  const [loadingStore, setLoadingStore] = useState<string | null>(null);

  // Top 8 for the pie chart, rest grouped as "Other"
  const pieData = useMemo(() => {
    const top8 = stores.slice(0, 8);
    const rest = stores.slice(8);
    const otherTotal = rest.reduce((s, st) => s + Number(st.total_spent), 0);
    const data = top8.map((st) => ({
      name: st.store_name,
      value: Number(st.total_spent),
    }));
    if (otherTotal > 0) {
      data.push({ name: 'Other', value: otherTotal });
    }
    return data;
  }, [stores]);

  const displayStores = stores.slice(0, 10);

  const handleExpand = async (storeName: string) => {
    if (expandedStore === storeName) {
      setExpandedStore(null);
      setExpandedMonth(null);
      return;
    }
    setExpandedStore(storeName);
    setExpandedMonth(null);
    if (!storeDetails[storeName]) {
      setLoadingStore(storeName);
      try {
        const res = await axios.get<StoreDetail>(
          `/api/v1/dashboard/store/${encodeURIComponent(storeName)}`
        );
        setStoreDetails((prev) => ({ ...prev, [storeName]: res.data }));
      } catch {
        // Silently fail — detail section stays empty
      } finally {
        setLoadingStore(null);
      }
    }
  };

  const handleMonthClick = (monthKey: string) => {
    setExpandedMonth(expandedMonth === monthKey ? null : monthKey);
  };

  const handleOrderItemUpdated = (
    storeName: string,
    itemId: number,
    expenseType: 'personal' | 'business',
    taxDeductible: boolean
  ) => {
    setStoreDetails((prev) => {
      const updated = { ...prev };
      const detail = updated[storeName];
      if (detail) {
        updated[storeName] = {
          ...detail,
          order_items: detail.order_items.map((item) =>
            item.id === itemId
              ? { ...item, expense_type: expenseType, tax_deductible: taxDeductible }
              : item
          ),
        };
      }
      return updated;
    });
  };

  const months = period?.months ?? 1;
  const showAvg = avgMode === 'monthly_avg' && months > 1;

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
      {/* Header */}
      <div className="flex items-start justify-between mb-5">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-violet-50 border border-violet-200 flex items-center justify-center">
            <ShoppingBag size={20} className="text-violet-600" />
          </div>
          <div>
            <h2 className="text-[15px] font-semibold text-sw-text">Where You Shop Most</h2>
            <p className="text-xs text-sw-muted mt-0.5">
              {stores.length} stores · {showAvg ? 'monthly avg' : 'total'} {fmt.format(showAvg ? total / months : total)}
            </p>
          </div>
        </div>
      </div>

      {/* Chart + List layout */}
      <div className="grid grid-cols-1 lg:grid-cols-5 gap-6">
        {/* Donut chart */}
        <div className="lg:col-span-2">
          <ResponsiveContainer width="100%" height={220}>
            <PieChart>
              <Pie
                data={pieData}
                cx="50%"
                cy="50%"
                innerRadius={55}
                outerRadius={90}
                paddingAngle={2}
                dataKey="value"
                stroke="none"
              >
                {pieData.map((_, index) => (
                  <Cell key={index} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                ))}
              </Pie>
              <Tooltip
                formatter={(value: number | undefined) => [
                  `$${(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
                  showAvg ? 'Monthly Avg' : 'Total',
                ]}
                contentStyle={{
                  background: '#fff',
                  border: '1px solid #e2e8f0',
                  borderRadius: '8px',
                  fontSize: '12px',
                  boxShadow: '0 2px 8px rgba(0,0,0,0.08)',
                }}
              />
            </PieChart>
          </ResponsiveContainer>

          {/* Legend */}
          <div className="space-y-1.5 mt-2 max-h-[160px] overflow-y-auto pr-1">
            {pieData.map((item, i) => (
              <div key={item.name} className="flex items-center gap-2">
                <div
                  className="w-2.5 h-2.5 rounded-sm shrink-0"
                  style={{ backgroundColor: CHART_COLORS[i % CHART_COLORS.length] }}
                />
                <span className="text-xs text-sw-text truncate flex-1">{item.name}</span>
                <span className="text-xs text-sw-muted font-medium">
                  {fmt.format(showAvg ? item.value / months : item.value)}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Ranked list */}
        <div className="lg:col-span-3 space-y-1">
          {displayStores.map((store, index) => {
            const isExpanded = expandedStore === store.store_name;
            const detail = storeDetails[store.store_name];
            const isLoading = loadingStore === store.store_name;
            const displayAmount = showAvg
              ? Number(store.total_spent) / months
              : Number(store.total_spent);

            return (
              <div key={store.store_name}>
                {/* Store row */}
                <button
                  onClick={() => handleExpand(store.store_name)}
                  className={`w-full flex items-center gap-3 py-3 px-3 rounded-xl text-left transition hover:bg-sw-card-hover ${
                    isExpanded ? 'bg-sw-surface' : ''
                  }`}
                >
                  {/* Rank badge */}
                  <div
                    className="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 text-[11px] font-bold text-white"
                    style={{ backgroundColor: CHART_COLORS[index % CHART_COLORS.length] }}
                  >
                    {index + 1}
                  </div>

                  {/* Store info */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="text-[13px] font-medium text-sw-text truncate">
                        {store.store_name}
                      </span>
                      {store.has_order_items && (
                        <Mail size={11} className="text-sw-info shrink-0" />
                      )}
                      {store.tax_deductible_total > 0 && (
                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 shrink-0">
                          {fmt.format(Number(store.tax_deductible_total))} deductible
                        </span>
                      )}
                    </div>
                    <div className="flex items-center gap-3 mt-0.5 text-[11px] text-sw-dim">
                      <span>{store.transaction_count} transactions</span>
                      <span>Avg {fmt.format(Number(store.avg_per_visit))}/visit</span>
                    </div>
                  </div>

                  {/* Amount + percentage */}
                  <div className="text-right shrink-0">
                    <div className="text-sm font-bold text-sw-text">{fmt.format(displayAmount)}</div>
                    <div className="text-[10px] text-sw-dim">{store.pct_of_total}% of total</div>
                  </div>

                  {/* Percentage bar */}
                  <div className="w-16 shrink-0 hidden sm:block">
                    <div className="h-2 bg-sw-surface rounded-full overflow-hidden">
                      <div
                        className="h-full rounded-full transition-all duration-500"
                        style={{
                          width: `${Math.min(store.pct_of_total, 100)}%`,
                          backgroundColor: CHART_COLORS[index % CHART_COLORS.length],
                        }}
                      />
                    </div>
                  </div>

                  {/* Expand icon */}
                  <div className="shrink-0 text-sw-dim">
                    {isExpanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                  </div>
                </button>

                {/* Expanded detail */}
                {isExpanded && (
                  <div className="ml-10 mr-3 mb-3 pl-4 border-l-2 border-sw-accent/30">
                    {isLoading ? (
                      <div className="flex items-center gap-2 py-4 text-xs text-sw-muted">
                        <Loader2 size={14} className="animate-spin" />
                        Loading store details...
                      </div>
                    ) : detail ? (
                      <div className="space-y-3 py-2">
                        {/* Monthly trend chart */}
                        {detail.monthly_trend.length > 1 && (
                          <div>
                            <div className="flex items-center gap-2 mb-2">
                              <Store size={12} className="text-sw-muted" />
                              <span className="text-xs font-medium text-sw-text">Monthly Spending</span>
                              <span className="text-[10px] text-sw-dim ml-1">Click a month to see transactions</span>
                            </div>
                            <ResponsiveContainer width="100%" height={120}>
                              <BarChart data={detail.monthly_trend}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                                <XAxis
                                  dataKey="month"
                                  tick={{ fontSize: 10, fill: '#94a3b8' }}
                                  axisLine={false}
                                  tickLine={false}
                                />
                                <YAxis
                                  tick={{ fontSize: 10, fill: '#94a3b8' }}
                                  axisLine={false}
                                  tickLine={false}
                                  tickFormatter={(v: number) => `$${v >= 1000 ? `${(v / 1000).toFixed(0)}k` : v}`}
                                />
                                <Tooltip
                                  formatter={(value: number | undefined) => [
                                    `$${(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
                                    'Spent',
                                  ]}
                                  contentStyle={{
                                    background: '#fff',
                                    border: '1px solid #e2e8f0',
                                    borderRadius: '8px',
                                    fontSize: '11px',
                                    boxShadow: '0 2px 8px rgba(0,0,0,0.08)',
                                  }}
                                />
                                <Bar
                                  dataKey="total"
                                  fill={CHART_COLORS[index % CHART_COLORS.length]}
                                  radius={[4, 4, 0, 0]}
                                  maxBarSize={24}
                                  cursor="pointer"
                                  onClick={(_data: unknown, barIndex: number) => {
                                    const month = detail.monthly_trend[barIndex]?.month;
                                    if (month) handleMonthClick(month);
                                  }}
                                />
                              </BarChart>
                            </ResponsiveContainer>
                          </div>
                        )}

                        {/* Clickable month rows */}
                        <div className="space-y-1">
                          {detail.monthly_trend.map((m) => {
                            const isMonthExpanded = expandedMonth === m.month;
                            const monthTxs: StoreTransaction[] = detail.transactions?.[m.month] ?? [];

                            return (
                              <div key={m.month}>
                                <button
                                  onClick={() => handleMonthClick(m.month)}
                                  className={`w-full flex items-center gap-3 py-2 px-3 rounded-lg text-left transition hover:bg-sw-card-hover ${
                                    isMonthExpanded ? 'bg-sw-surface' : ''
                                  }`}
                                >
                                  <Calendar size={12} className="text-sw-dim shrink-0" />
                                  <span className="text-xs font-medium text-sw-text flex-1">{m.month}</span>
                                  <span className="text-[11px] text-sw-dim">{m.count} txns</span>
                                  <span className="text-xs font-semibold text-sw-text">{fmt.format(m.total)}</span>
                                  <div className="shrink-0 text-sw-dim">
                                    {isMonthExpanded ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
                                  </div>
                                </button>

                                {/* Month transactions table */}
                                {isMonthExpanded && (
                                  <div className="ml-6 mb-2 mt-1">
                                    {monthTxs.length > 0 ? (
                                      <div className="rounded-lg border border-sw-border overflow-hidden">
                                        <table className="w-full text-xs">
                                          <thead>
                                            <tr className="bg-sw-surface/70 text-sw-dim">
                                              <th className="text-left py-1.5 px-3 font-medium">Date</th>
                                              <th className="text-left py-1.5 px-3 font-medium">Description</th>
                                              <th className="text-left py-1.5 px-3 font-medium">Category</th>
                                              <th className="text-right py-1.5 px-3 font-medium">Amount</th>
                                            </tr>
                                          </thead>
                                          <tbody>
                                            {monthTxs.map((tx) => (
                                              <TransactionWithItems key={tx.id} tx={tx} />
                                            ))}
                                          </tbody>
                                        </table>
                                      </div>
                                    ) : (
                                      <div className="text-[11px] text-sw-dim py-2 px-3">
                                        No transaction details available for this month.
                                      </div>
                                    )}
                                  </div>
                                )}
                              </div>
                            );
                          })}
                        </div>

                        {/* Order items section */}
                        {detail.order_items.length > 0 && (
                          <StoreOrderItemsList
                            items={detail.order_items}
                            onItemUpdated={(itemId, expenseType, taxDeductible) =>
                              handleOrderItemUpdated(store.store_name, itemId, expenseType, taxDeductible)
                            }
                          />
                        )}

                        {/* Empty order items hint */}
                        {store.has_order_items && detail.order_items.length === 0 && (
                          <div className="text-xs text-sw-dim py-2">
                            Email order data is being processed. Check back soon.
                          </div>
                        )}
                      </div>
                    ) : (
                      <div className="py-3 text-xs text-sw-dim">
                        Unable to load store details. Try again later.
                      </div>
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

/** Inline sub-component — every transaction row is clickable and shows details */
function TransactionWithItems({ tx }: { tx: StoreTransaction }) {
  const [expanded, setExpanded] = useState(false);
  const hasItems = tx.order_items.length > 0;

  return (
    <>
      <tr
        onClick={() => setExpanded(!expanded)}
        className={`border-t border-sw-border/50 transition cursor-pointer hover:bg-sw-card-hover ${
          tx.tax_deductible ? 'bg-emerald-50/30' : ''
        }`}
      >
        <td className="py-2 px-3 text-sw-muted whitespace-nowrap">{formatDate(tx.date)}</td>
        <td className="py-2 px-3 text-sw-text">
          <div className="flex items-center gap-1.5">
            <span className="truncate max-w-[140px]">{tx.description || tx.merchant_name}</span>
            {tx.is_reconciled && <Mail size={10} className="text-sw-info shrink-0" />}
            {hasItems && (
              <span className="text-[9px] text-sw-accent font-medium shrink-0">
                {tx.order_items.length} items
              </span>
            )}
            <div className="shrink-0 text-sw-dim ml-auto">
              {expanded ? <ChevronUp size={10} /> : <ChevronDown size={10} />}
            </div>
          </div>
        </td>
        <td className="py-2 px-3">
          <span className="inline-flex items-center gap-1 text-sw-dim">
            <Tag size={9} />
            {tx.category}
          </span>
        </td>
        <td className="py-2 px-3 text-right font-semibold text-sw-text">{fmt.format(tx.amount)}</td>
      </tr>

      {/* Expanded details — always shown when clicked */}
      {expanded && (
        <tr>
          <td colSpan={4} className="px-3 pb-2">
            <div className="ml-4 mt-1 space-y-2">
              {/* Transaction details (always shown) */}
              <div className="flex flex-wrap gap-x-5 gap-y-1 text-[10px] text-sw-dim py-1">
                <span>Merchant: <strong className="text-sw-text">{tx.merchant_name}</strong></span>
                {tx.description && tx.description !== tx.merchant_name && (
                  <span>Description: <strong className="text-sw-text">{tx.description}</strong></span>
                )}
                <span>Category: <strong className="text-sw-text">{tx.category}</strong></span>
                <span>Type: <strong className="text-sw-text capitalize">{tx.expense_type || 'personal'}</strong></span>
                {tx.tax_deductible && (
                  <span className="text-emerald-600 font-semibold">Tax Deductible</span>
                )}
                {tx.is_reconciled && (
                  <span className="text-sw-info font-semibold">Email Matched</span>
                )}
              </div>

              {/* Order items (shown when available) */}
              {hasItems && (
                <div className="space-y-1">
                  <div className="flex items-center gap-1.5 text-[10px] text-sw-muted font-medium">
                    <Package size={10} />
                    Items from email receipt:
                  </div>
                  {tx.order_items.map((item) => {
                    const category = item.user_category || item.ai_category || 'Uncategorized';
                    return (
                      <div
                        key={item.id}
                        className={`flex items-center gap-2.5 py-1.5 px-2.5 rounded-md text-[11px] ${
                          item.tax_deductible ? 'bg-emerald-50/50 border border-emerald-200/50' : 'bg-sw-surface/50'
                        }`}
                      >
                        <Package size={10} className="text-sw-dim shrink-0" />
                        <span className="text-sw-text font-medium truncate flex-1">{item.product_name}</span>
                        {item.quantity > 1 && (
                          <span className="text-sw-dim">x{item.quantity}</span>
                        )}
                        <span className="text-sw-dim">{category}</span>
                        <span className={`px-1.5 py-0.5 rounded text-[9px] font-semibold ${
                          item.expense_type === 'business'
                            ? 'bg-sw-accent/10 text-sw-accent'
                            : 'text-sw-dim'
                        }`}>
                          {item.expense_type === 'business' ? 'Business' : 'Personal'}
                        </span>
                        <span className="font-semibold text-sw-text">{fmt.format(Number(item.total_price))}</span>
                      </div>
                    );
                  })}
                </div>
              )}

              {/* No items hint */}
              {!hasItems && !tx.is_reconciled && (
                <div className="text-[10px] text-sw-dim italic py-1">
                  No email receipt matched to this transaction.
                </div>
              )}
            </div>
          </td>
        </tr>
      )}
    </>
  );
}
