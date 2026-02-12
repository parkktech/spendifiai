import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { FileText, DollarSign, Briefcase, Download, Send, CheckCircle, ChevronDown } from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { useApi } from '@/hooks/useApi';
import StatCard from '@/Components/SpendWise/StatCard';
import ExportModal from '@/Components/SpendWise/ExportModal';
import type { TaxSummary } from '@/types/spendwise';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
const currentYear = new Date().getFullYear();

export default function TaxIndex() {
  const [year, setYear] = useState(currentYear);
  const [exportOpen, setExportOpen] = useState(false);
  const [exportMode, setExportMode] = useState<'download' | 'email'>('download');

  const { data: summary, loading, error, refresh } = useApi<TaxSummary>(
    `/api/v1/tax/summary?year=${year}`
  );

  // Re-fetch when year changes
  useEffect(() => {
    refresh();
  }, [year]); // eslint-disable-line react-hooks/exhaustive-deps

  // Combine transaction and order item categories for the deductions view
  const txCategories = summary?.transaction_categories ?? [];
  const orderCategories = summary?.order_item_categories ?? [];
  const allCategories = [...txCategories, ...orderCategories];

  // Merge categories with the same name
  const categoryMap = new Map<string, { category: string; total: number; item_count: number }>();
  for (const cat of allCategories) {
    const existing = categoryMap.get(cat.category);
    if (existing) {
      existing.total += cat.total;
      existing.item_count += cat.item_count;
    } else {
      categoryMap.set(cat.category, { ...cat });
    }
  }
  const deductions = Array.from(categoryMap.values());
  const sortedDeductions = [...deductions].sort((a, b) => b.total - a.total);

  // Top 10 categories for chart data
  const chartData = sortedDeductions.slice(0, 10).map((d) => ({
    name: d.category.length > 20 ? d.category.substring(0, 18) + '...' : d.category,
    amount: d.total,
  }));

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
            {/* Year selector */}
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
          title="Categories"
          value={sortedDeductions.length.toString()}
          subtitle="deductible categories"
          icon={<FileText size={18} />}
        />
      </div>

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
      {!loading && !error && deductions.length === 0 && (
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

      {/* Deductions table */}
      {!loading && sortedDeductions.length > 0 && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
          <h2 className="text-sm font-semibold text-sw-text mb-4">Deductions by Category</h2>
          <div className="overflow-x-auto">
            <table aria-label="Tax deductions by category" className="w-full">
              <thead>
                <tr className="border-b border-sw-border">
                  <th scope="col" className="text-left text-xs text-sw-muted font-medium py-2.5 pr-4">Category</th>
                  <th scope="col" className="text-right text-xs text-sw-muted font-medium py-2.5 pr-4">Items</th>
                  <th scope="col" className="text-right text-xs text-sw-muted font-medium py-2.5">Total</th>
                </tr>
              </thead>
              <tbody>
                {sortedDeductions.map((deduction, i) => (
                  <tr
                    key={i}
                    className="border-b border-sw-border last:border-b-0 hover:bg-sw-card-hover transition-colors cursor-pointer"
                  >
                    <td className="py-3 pr-4">
                      <div className="flex items-center gap-2">
                        <CheckCircle size={14} className="text-sw-accent shrink-0" />
                        <span className="text-sm text-sw-text font-medium">{deduction.category}</span>
                      </div>
                    </td>
                    <td className="py-3 pr-4 text-right">
                      <span className="text-xs text-sw-muted">{deduction.item_count} items</span>
                    </td>
                    <td className="py-3 text-right">
                      <span className="text-sm font-bold text-sw-accent">{fmt.format(deduction.total)}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-t-2 border-sw-border">
                  <td colSpan={2} className="py-3 text-sm font-semibold text-sw-text">
                    Total Deductible
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

      {/* Export Modal */}
      <ExportModal
        open={exportOpen}
        onClose={() => setExportOpen(false)}
        year={year}
        mode={exportMode}
      />
    </AuthenticatedLayout>
  );
}
