import { useState, useEffect, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import {
  FileText, DollarSign, Briefcase, Download, Send,
  ChevronDown, ChevronRight, Receipt, Mail, Building2,
} from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { useApi } from '@/hooks/useApi';
import StatCard from '@/Components/SpendifiAI/StatCard';
import ConnectBankPrompt from '@/Components/SpendifiAI/ConnectBankPrompt';
import ExportModal from '@/Components/SpendifiAI/ExportModal';
import type { TaxSummary, TaxLineItem } from '@/types/spendifiai';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
const currentYear = new Date().getFullYear();

function formatDate(dateStr: string): string {
  const date = new Date(dateStr + 'T00:00:00');
  return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric' }).format(date);
}

interface MergedCategory {
  category: string;
  total: number;
  item_count: number;
  items: TaxLineItem[];
  scheduleCLine?: string;
  scheduleCLabel?: string;
}

export default function TaxIndex() {
  const { auth } = usePage().props as unknown as { auth: { hasBankConnected: boolean } };
  const [year, setYear] = useState(currentYear);
  const [exportOpen, setExportOpen] = useState(false);
  const [exportMode, setExportMode] = useState<'download' | 'email'>('download');
  const [expanded, setExpanded] = useState<Set<string>>(new Set());

  const { data: summary, loading, error, refresh } = useApi<TaxSummary>(
    `/api/v1/tax/summary?year=${year}`,
    { enabled: auth.hasBankConnected }
  );

  useEffect(() => {
    if (auth.hasBankConnected) {
      refresh();
    }
  }, [year, auth.hasBankConnected]); // eslint-disable-line react-hooks/exhaustive-deps

  // Merge categories from both sources and attach line items
  const sortedDeductions = useMemo(() => {
    if (!summary) return [];

    const txCategories = summary.transaction_categories ?? [];
    const orderCategories = summary.order_item_categories ?? [];
    const allDetails: TaxLineItem[] = [
      ...(summary.transaction_details ?? []),
      ...(summary.order_item_details ?? []),
    ];
    const cMap = summary.schedule_c_map ?? {};

    const catMap = new Map<string, MergedCategory>();

    for (const cat of [...txCategories, ...orderCategories]) {
      const existing = catMap.get(cat.category);
      if (existing) {
        existing.total += cat.total;
        existing.item_count += cat.item_count;
      } else {
        const mapping = cMap[cat.category];
        catMap.set(cat.category, {
          ...cat,
          items: [],
          scheduleCLine: mapping ? `Line ${mapping.line}` : undefined,
          scheduleCLabel: mapping?.label,
        });
      }
    }

    // Attach line items to their categories
    for (const item of allDetails) {
      const entry = catMap.get(item.category);
      if (entry) {
        entry.items.push(item);
      }
    }

    // Sort items within each category by date descending
    for (const entry of catMap.values()) {
      entry.items.sort((a, b) => b.date.localeCompare(a.date));
    }

    return Array.from(catMap.values()).sort((a, b) => b.total - a.total);
  }, [summary]);

  const chartData = sortedDeductions.slice(0, 10).map((d) => ({
    name: d.category.length > 20 ? d.category.substring(0, 18) + '...' : d.category,
    amount: d.total,
  }));

  const toggleCategory = (cat: string) => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(cat)) {
        next.delete(cat);
      } else {
        next.add(cat);
      }
      return next;
    });
  };

  const openExportModal = (mode: 'download' | 'email') => {
    setExportMode(mode);
    setExportOpen(true);
  };

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-sw-text tracking-tight">Tax Center</h1>
            <p className="text-xs text-sw-dim mt-0.5">Track deductible expenses for tax season</p>
          </div>
          <div className="flex items-center gap-3 flex-wrap">
            <div className="relative">
              <select
                value={year}
                onChange={(e) => setYear(parseInt(e.target.value))}
                className="appearance-none px-3 py-1.5 pr-8 rounded-lg border border-sw-border bg-sw-card text-sw-text text-xs font-medium focus:outline-none focus:border-sw-accent cursor-pointer"
              >
                <option value={currentYear}>{currentYear}</option>
                <option value={currentYear - 1}>{currentYear - 1}</option>
              </select>
              <ChevronDown size={12} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-sw-dim pointer-events-none" />
            </div>

            <button
              onClick={() => openExportModal('download')}
              className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition"
            >
              <Download size={14} />
              Export
            </button>
            <button
              onClick={() => openExportModal('email')}
              className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-sw-border bg-sw-card hover:bg-sw-card-hover text-sw-text text-xs font-semibold transition"
            >
              <Send size={14} />
              Send to Accountant
            </button>
          </div>
        </div>
      }
    >
      <Head title="Tax Center" />

      {/* Stat cards */}
      <div className="flex gap-4 mb-6 flex-wrap">
        <StatCard
          title="Total Deductible"
          value={summary ? fmt.format(summary.total_deductible) : '$0.00'}
          subtitle={`${year}`}
          icon={<Briefcase size={18} />}
        />
        <StatCard
          title="Estimated Tax Savings"
          value={summary ? fmt.format(summary.estimated_tax_savings) : '$0.00'}
          subtitle={`at ${summary ? (summary.effective_rate_used * 100).toFixed(0) : 0}% rate`}
          icon={<DollarSign size={18} />}
        />
        <StatCard
          title="Line Items"
          value={(summary ? (summary.transaction_details?.length ?? 0) + (summary.order_item_details?.length ?? 0) : 0).toString()}
          subtitle={`across ${sortedDeductions.length} categories`}
          icon={<FileText size={18} />}
        />
      </div>

      {/* Connect Bank Prompt */}
      {!loading && !error && !summary && (
        <ConnectBankPrompt
          feature="tax"
          description="Link your bank account to start tracking deductible expenses and prepare for tax season."
        />
      )}

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
      {loading && !summary && (
        <div className="space-y-6">
          <div className="rounded-2xl border border-sw-border bg-sw-card p-6 animate-pulse">
            <div className="h-4 bg-sw-border rounded w-1/3 mb-4" />
            <div className="h-48 bg-sw-border rounded" />
          </div>
          <div className="rounded-2xl border border-sw-border bg-sw-card p-6 animate-pulse">
            <div className="h-4 bg-sw-border rounded w-1/4 mb-4" />
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="h-8 bg-sw-border rounded mb-2" />
            ))}
          </div>
        </div>
      )}

      {/* Empty state */}
      {!loading && !error && summary && sortedDeductions.length === 0 && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-12 text-center">
          <FileText size={40} className="mx-auto text-sw-dim mb-3" />
          <h3 className="text-sm font-semibold text-sw-text mb-1">No tax data available for {year}</h3>
          <p className="text-xs text-sw-muted max-w-md mx-auto">
            Connect your bank to start tracking deductible expenses. Business transactions will be automatically categorized by IRS Schedule C line.
          </p>
        </div>
      )}

      {/* Chart section */}
      {!loading && chartData.length > 0 && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6 mb-6">
          <h2 className="text-sm font-semibold text-sw-text mb-4">Expenses by Category (Top 10)</h2>
          <ResponsiveContainer width="100%" height={280}>
            <BarChart data={chartData} layout="vertical" margin={{ left: 130 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" horizontal={false} />
              <XAxis
                type="number"
                tick={{ fill: '#64748b', fontSize: 11 }}
                axisLine={false}
                tickLine={false}
                tickFormatter={(v: number) => `$${v >= 1000 ? `${(v / 1000).toFixed(1)}k` : v}`}
              />
              <YAxis
                type="category"
                dataKey="name"
                tick={{ fill: '#94a3b8', fontSize: 11 }}
                axisLine={false}
                tickLine={false}
                width={120}
              />
              <Tooltip
                contentStyle={{
                  background: '#ffffff',
                  border: '1px solid #e2e8f0',
                  borderRadius: 8,
                  fontSize: 12,
                  color: '#0f172a',
                  boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)',
                }}
                formatter={(value: number | undefined) => [fmt.format(value ?? 0), 'Amount']}
              />
              <Bar dataKey="amount" fill="#2563eb" radius={[0, 6, 6, 0]} barSize={20} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}

      {/* Expandable deductions table */}
      {!loading && sortedDeductions.length > 0 && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-sm font-semibold text-sw-text">Deductions by Category</h2>
            <button
              onClick={() => {
                if (expanded.size === sortedDeductions.length) {
                  setExpanded(new Set());
                } else {
                  setExpanded(new Set(sortedDeductions.map((d) => d.category)));
                }
              }}
              className="text-[11px] text-sw-muted hover:text-sw-accent transition"
            >
              {expanded.size === sortedDeductions.length ? 'Collapse all' : 'Expand all'}
            </button>
          </div>

          <div className="overflow-x-auto">
            <table aria-label="Tax deductions by category" className="w-full">
              <thead>
                <tr className="border-b border-sw-border">
                  <th scope="col" className="w-6" />
                  <th scope="col" className="text-left text-xs text-sw-muted font-medium py-2.5 pr-4">Category</th>
                  <th scope="col" className="text-left text-xs text-sw-muted font-medium py-2.5 pr-4 hidden sm:table-cell">Schedule C</th>
                  <th scope="col" className="text-right text-xs text-sw-muted font-medium py-2.5 pr-4">Items</th>
                  <th scope="col" className="text-right text-xs text-sw-muted font-medium py-2.5">Total</th>
                </tr>
              </thead>
              <tbody>
                {sortedDeductions.map((deduction) => {
                  const isExpanded = expanded.has(deduction.category);
                  return (
                    <CategoryRow
                      key={deduction.category}
                      deduction={deduction}
                      isExpanded={isExpanded}
                      onToggle={() => toggleCategory(deduction.category)}
                    />
                  );
                })}
              </tbody>
              <tfoot>
                <tr className="border-t-2 border-sw-border">
                  <td />
                  <td colSpan={2} className="py-3 text-sm font-semibold text-sw-text hidden sm:table-cell">
                    Total Deductible
                  </td>
                  <td className="py-3 text-sm font-semibold text-sw-text sm:hidden">
                    Total Deductible
                  </td>
                  <td className="py-3 text-right text-xs text-sw-muted">
                    {sortedDeductions.reduce((sum, d) => sum + d.item_count, 0)} items
                  </td>
                  <td className="py-3 text-right text-sm font-bold text-sw-accent">
                    {fmt.format(sortedDeductions.reduce((sum, d) => sum + d.total, 0))}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      )}

      <ExportModal
        open={exportOpen}
        onClose={() => setExportOpen(false)}
        year={year}
        mode={exportMode}
      />
    </AuthenticatedLayout>
  );
}

/* ─── Category Row with expandable line items ─────────────────────── */

function CategoryRow({
  deduction,
  isExpanded,
  onToggle,
}: {
  deduction: MergedCategory;
  isExpanded: boolean;
  onToggle: () => void;
}) {
  const hasItems = deduction.items.length > 0;

  return (
    <>
      <tr
        onClick={hasItems ? onToggle : undefined}
        className={`border-b border-sw-border transition-colors ${hasItems ? 'cursor-pointer hover:bg-sw-card-hover' : ''}`}
      >
        <td className="py-3 pl-1 w-6">
          {hasItems && (
            isExpanded
              ? <ChevronDown size={14} className="text-sw-accent" />
              : <ChevronRight size={14} className="text-sw-dim" />
          )}
        </td>
        <td className="py-3 pr-4">
          <span className="text-sm text-sw-text font-medium">{deduction.category}</span>
        </td>
        <td className="py-3 pr-4 hidden sm:table-cell">
          {deduction.scheduleCLine ? (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-blue-50 border border-blue-100 text-[10px] font-medium text-blue-700">
              <Building2 size={10} />
              {deduction.scheduleCLine}
              <span className="text-blue-500 font-normal">- {deduction.scheduleCLabel}</span>
            </span>
          ) : (
            <span className="text-[10px] text-sw-dim">Line 27a - Other</span>
          )}
        </td>
        <td className="py-3 pr-4 text-right">
          <span className="text-xs text-sw-muted">{deduction.item_count}</span>
        </td>
        <td className="py-3 text-right">
          <span className="text-sm font-bold text-sw-accent">{fmt.format(deduction.total)}</span>
        </td>
      </tr>

      {/* Expanded line items */}
      {isExpanded && deduction.items.map((item, i) => (
        <tr key={`${item.date}-${item.merchant}-${i}`} className="border-b border-sw-border/50 bg-gray-50/50">
          <td />
          <td className="py-2 pr-4 pl-2" colSpan={2}>
            <div className="flex items-center gap-2">
              {item.source === 'email' ? (
                <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-emerald-50 border border-emerald-200 text-[9px] font-medium text-emerald-700 shrink-0">
                  <Mail size={8} />
                  Receipt
                </span>
              ) : (
                <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-blue-50 border border-blue-200 text-[9px] font-medium text-blue-700 shrink-0">
                  <Receipt size={8} />
                  Bank
                </span>
              )}
              <span className="text-xs text-sw-dim shrink-0">{formatDate(item.date)}</span>
              <span className="text-xs text-sw-text font-medium truncate">{item.merchant}</span>
              {item.description && item.description !== item.merchant && (
                <span className="text-[11px] text-sw-dim truncate hidden md:inline">- {item.description}</span>
              )}
            </div>
          </td>
          <td />
          <td className="py-2 text-right">
            <span className="text-xs font-semibold text-sw-text">{fmt.format(item.amount)}</span>
          </td>
        </tr>
      ))}
    </>
  );
}
