import { useState, useEffect, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import {
  FileText, DollarSign, Briefcase, Download, Send,
  ChevronDown, ChevronRight, Receipt, Mail, Building2, Landmark, Heart,
} from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { useApi } from '@/hooks/useApi';
import StatCard from '@/Components/SpendifiAI/StatCard';
import ConnectBankPrompt from '@/Components/SpendifiAI/ConnectBankPrompt';
import ExportModal from '@/Components/SpendifiAI/ExportModal';
import type { TaxSummary, TaxLineItem, NormalizedTaxLine } from '@/types/spendifiai';

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
  scheduleLine?: string;
  scheduleLabel?: string;
  schedule?: 'C' | 'A';
}

export default function TaxIndex() {
  const { auth } = usePage().props as unknown as { auth: { hasBankConnected: boolean } };
  const [year, setYear] = useState(currentYear);
  const [exportOpen, setExportOpen] = useState(false);
  const [exportMode, setExportMode] = useState<'download' | 'email'>('download');
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [viewMode, setViewMode] = useState<'category' | 'irs_line'>('category');

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
        const lineStr = mapping?.schedule === 'A'
          ? mapping.line
          : mapping ? `Line ${mapping.line}` : undefined;
        catMap.set(cat.category, {
          ...cat,
          items: [],
          scheduleLine: lineStr,
          scheduleLabel: mapping?.label,
          schedule: (mapping?.schedule as 'C' | 'A') ?? undefined,
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

  // Group by IRS line for alternative view
  const normalizedLines = useMemo(() => {
    if (!summary?.normalized_lines) return [];
    return summary.normalized_lines;
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
          subtitle={`${year} — ${sortedDeductions.length} categories`}
          icon={<Briefcase size={18} />}
        />
        <StatCard
          title="Schedule C (Business)"
          value={summary ? fmt.format(summary.schedule_c_total ?? 0) : '$0.00'}
          subtitle="Form 1040"
          icon={<Landmark size={18} />}
        />
        <StatCard
          title="Schedule A (Personal)"
          value={summary ? fmt.format(summary.schedule_a_total ?? 0) : '$0.00'}
          subtitle="Itemized deductions"
          icon={<Heart size={18} />}
        />
        <StatCard
          title="Est. Tax Savings"
          value={summary ? fmt.format(summary.estimated_tax_savings) : '$0.00'}
          subtitle={`at ${summary ? (summary.effective_rate_used * 100).toFixed(0) : 0}% rate`}
          icon={<DollarSign size={18} />}
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
            <div className="flex items-center gap-3">
              <h2 className="text-sm font-semibold text-sw-text">Deductions</h2>
              <div className="flex rounded-lg border border-sw-border overflow-hidden">
                <button
                  onClick={() => setViewMode('category')}
                  className={`px-3 py-1 text-[11px] font-medium transition ${viewMode === 'category' ? 'bg-sw-accent text-white' : 'text-sw-muted hover:text-sw-text'}`}
                >
                  By Category
                </button>
                <button
                  onClick={() => setViewMode('irs_line')}
                  className={`px-3 py-1 text-[11px] font-medium transition ${viewMode === 'irs_line' ? 'bg-sw-accent text-white' : 'text-sw-muted hover:text-sw-text'}`}
                >
                  By IRS Line
                </button>
              </div>
            </div>
            <button
              onClick={() => {
                const items = viewMode === 'category'
                  ? sortedDeductions.map((d) => d.category)
                  : normalizedLines.map((l) => l.line);
                if (expanded.size === items.length) {
                  setExpanded(new Set());
                } else {
                  setExpanded(new Set(items));
                }
              }}
              className="text-[11px] text-sw-muted hover:text-sw-accent transition"
            >
              {expanded.size > 0 ? 'Collapse all' : 'Expand all'}
            </button>
          </div>

          {viewMode === 'category' ? (
            <div className="overflow-x-auto">
              <table aria-label="Tax deductions by category" className="w-full">
                <thead>
                  <tr className="border-b border-sw-border">
                    <th scope="col" className="w-6" />
                    <th scope="col" className="text-left text-xs text-sw-muted font-medium py-2.5 pr-4">Category</th>
                    <th scope="col" className="text-left text-xs text-sw-muted font-medium py-2.5 pr-4 hidden sm:table-cell">IRS Line</th>
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
          ) : (
            <div className="overflow-x-auto">
              <table aria-label="Tax deductions by IRS line" className="w-full">
                <thead>
                  <tr className="border-b border-sw-border">
                    <th scope="col" className="w-6" />
                    <th scope="col" className="text-left text-xs text-sw-muted font-medium py-2.5 pr-4">IRS Line</th>
                    <th scope="col" className="text-left text-xs text-sw-muted font-medium py-2.5 pr-4 hidden sm:table-cell">Schedule</th>
                    <th scope="col" className="text-right text-xs text-sw-muted font-medium py-2.5 pr-4">Categories</th>
                    <th scope="col" className="text-right text-xs text-sw-muted font-medium py-2.5">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {normalizedLines.map((line) => (
                    <IrsLineRow
                      key={line.line}
                      line={line}
                      isExpanded={expanded.has(line.line)}
                      onToggle={() => toggleCategory(line.line)}
                    />
                  ))}
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
                      {normalizedLines.reduce((sum, l) => sum + l.categories.length, 0)} categories
                    </td>
                    <td className="py-3 text-right text-sm font-bold text-sw-accent">
                      {fmt.format(normalizedLines.reduce((sum, l) => sum + l.total, 0))}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          )}
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
  const isScheduleA = deduction.schedule === 'A';

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
          {deduction.scheduleLine ? (
            <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-medium border ${
              isScheduleA
                ? 'bg-emerald-50 border-emerald-100 text-emerald-700'
                : 'bg-blue-50 border-blue-100 text-blue-700'
            }`}>
              {isScheduleA ? <Heart size={10} /> : <Building2 size={10} />}
              {deduction.scheduleLine}
              <span className={`font-normal ${isScheduleA ? 'text-emerald-500' : 'text-blue-500'}`}>
                — {deduction.scheduleLabel}
              </span>
            </span>
          ) : (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-blue-50 border border-blue-100 text-[10px] font-medium text-blue-700">
              <Building2 size={10} />
              Line 27a
              <span className="text-blue-500 font-normal">— Other expenses</span>
            </span>
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
                <span className="text-[11px] text-sw-dim truncate hidden md:inline">— {item.description}</span>
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

/* ─── IRS Line Row with expandable sub-categories ─────────────────── */

function IrsLineRow({
  line,
  isExpanded,
  onToggle,
}: {
  line: NormalizedTaxLine;
  isExpanded: boolean;
  onToggle: () => void;
}) {
  const isScheduleA = line.schedule === 'A';
  const lineDisplay = isScheduleA ? line.label : `Line ${line.line} — ${line.label}`;
  const hasCategories = line.categories.length > 0;

  return (
    <>
      <tr
        onClick={hasCategories ? onToggle : undefined}
        className={`border-b border-sw-border transition-colors ${hasCategories ? 'cursor-pointer hover:bg-sw-card-hover' : ''}`}
      >
        <td className="py-3 pl-1 w-6">
          {hasCategories && (
            isExpanded
              ? <ChevronDown size={14} className="text-sw-accent" />
              : <ChevronRight size={14} className="text-sw-dim" />
          )}
        </td>
        <td className="py-3 pr-4">
          <span className="text-sm text-sw-text font-medium">{lineDisplay}</span>
          {line.line === '24b' && (
            <span className="ml-2 text-[10px] text-amber-600 font-medium">(50% limitation)</span>
          )}
        </td>
        <td className="py-3 pr-4 hidden sm:table-cell">
          <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold border ${
            isScheduleA
              ? 'bg-emerald-50 border-emerald-100 text-emerald-700'
              : 'bg-blue-50 border-blue-100 text-blue-700'
          }`}>
            {isScheduleA ? 'Schedule A' : 'Schedule C'}
          </span>
        </td>
        <td className="py-3 pr-4 text-right">
          <span className="text-xs text-sw-muted">{line.categories.length}</span>
        </td>
        <td className="py-3 text-right">
          <span className="text-sm font-bold text-sw-accent">{fmt.format(line.total)}</span>
        </td>
      </tr>

      {/* Expanded sub-categories */}
      {isExpanded && line.categories.map((cat, i) => (
        <tr key={`${line.line}-${cat.name}-${i}`} className="border-b border-sw-border/50 bg-gray-50/50">
          <td />
          <td className="py-2 pr-4 pl-4" colSpan={2}>
            <span className="text-xs text-sw-text">{cat.name}</span>
            <span className="text-[11px] text-sw-dim ml-2">({cat.items} item{cat.items !== 1 ? 's' : ''})</span>
          </td>
          <td />
          <td className="py-2 text-right">
            <span className="text-xs font-semibold text-sw-text">{fmt.format(cat.amount)}</span>
          </td>
        </tr>
      ))}
    </>
  );
}
