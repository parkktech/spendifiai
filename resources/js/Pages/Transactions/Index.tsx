import { useState, useCallback } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
  Receipt,
  AlertTriangle,
  RefreshCw,
  ChevronLeft,
  ChevronRight,
  Loader2,
  Inbox,
} from 'lucide-react';
import TransactionRow from '@/Components/SpendWise/TransactionRow';
import FilterBar, { FilterState } from '@/Components/SpendWise/FilterBar';
import StatCard from '@/Components/SpendWise/StatCard';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { PaginatedResponse, Transaction } from '@/types/spendwise';

function buildUrl(filters: FilterState, page: number): string {
  const params = new URLSearchParams();
  if (filters.date_from) params.set('date_from', filters.date_from);
  if (filters.date_to) params.set('date_to', filters.date_to);
  if (filters.category) params.set('category', filters.category);
  if (filters.account_purpose) params.set('account_purpose', filters.account_purpose);
  if (filters.search) params.set('search', filters.search);
  params.set('page', String(page));
  return `/api/v1/transactions?${params.toString()}`;
}

export default function TransactionsIndex() {
  const [filters, setFilters] = useState<FilterState>({});
  const [page, setPage] = useState(1);
  const url = buildUrl(filters, page);
  const { data, loading, error, refresh, mutate } = useApi<PaginatedResponse<Transaction>>(url);
  const { submit: updateCategory } = useApiPost<unknown, { category: string }>('', 'PATCH');

  const handleFilterChange = useCallback((newFilters: FilterState) => {
    setFilters(newFilters);
    setPage(1);
  }, []);

  const handleCategoryChange = useCallback(
    async (id: number, category: string) => {
      await updateCategory({ category }, { url: `/api/v1/transactions/${id}/category`, method: 'PATCH' } as never);
      refresh();
    },
    [updateCategory, refresh]
  );

  const transactions = data?.data || [];
  const meta = data?.meta;

  // Compute summary stats
  const totalAmount = transactions.reduce((sum, tx) => sum + Math.abs(tx.amount), 0);
  const businessCount = transactions.filter((tx) => tx.account_purpose === 'business').length;
  const personalCount = transactions.filter((tx) => tx.account_purpose === 'personal').length;

  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text tracking-tight">Transactions</h1>
          <p className="text-xs text-sw-dim mt-0.5">View and manage all your transactions</p>
        </div>
      }
    >
      <Head title="Transactions" />

      {/* Summary stats */}
      {meta && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
          <StatCard
            title="Total Transactions"
            value={meta.total.toLocaleString()}
            icon={<Receipt size={18} />}
          />
          <StatCard
            title="Total Amount"
            value={`$${totalAmount.toLocaleString('en-US', { minimumFractionDigits: 2 })}`}
            subtitle={`${businessCount} business, ${personalCount} personal`}
          />
          <StatCard
            title="Page"
            value={`${meta.current_page} of ${meta.last_page}`}
            subtitle={`${meta.per_page} per page`}
          />
        </div>
      )}

      {/* Filter bar */}
      <FilterBar filters={filters} onChange={handleFilterChange} />

      {/* Error state */}
      {error && (
        <div className="rounded-2xl border border-sw-danger/30 bg-sw-danger/5 p-6 text-center">
          <AlertTriangle size={24} className="mx-auto text-sw-danger mb-2" />
          <p className="text-sm text-sw-text mb-3">{error}</p>
          <button
            onClick={refresh}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-sw-bg text-sm font-semibold hover:bg-sw-accent-hover transition"
          >
            <RefreshCw size={14} /> Retry
          </button>
        </div>
      )}

      {/* Loading */}
      {loading && (
        <div className="flex items-center justify-center py-16">
          <Loader2 size={24} className="animate-spin text-sw-accent" />
        </div>
      )}

      {/* Transaction list */}
      {!loading && !error && transactions.length > 0 && (
        <div aria-live="polite" className="rounded-2xl border border-sw-border bg-sw-card p-6">
          {transactions.map((tx) => (
            <TransactionRow
              key={tx.id}
              transaction={tx}
              onCategoryChange={handleCategoryChange}
            />
          ))}
        </div>
      )}

      {/* Empty state */}
      {!loading && !error && transactions.length === 0 && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-12 text-center">
          <Inbox size={40} className="mx-auto text-sw-dim mb-3" />
          <h3 className="text-sm font-semibold text-sw-text mb-1">No transactions found</h3>
          <p className="text-xs text-sw-muted">
            {Object.keys(filters).length > 0
              ? 'Try adjusting your filters'
              : 'Connect a bank account to start seeing transactions'}
          </p>
        </div>
      )}

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-center gap-2 mt-6">
          <button
            onClick={() => setPage(Math.max(1, page - 1))}
            disabled={page <= 1}
            aria-label="Previous page"
            className="p-2 rounded-lg border border-sw-border text-sw-muted hover:text-sw-text disabled:opacity-30 disabled:cursor-not-allowed transition"
          >
            <ChevronLeft size={16} />
          </button>

          {Array.from({ length: Math.min(meta.last_page, 7) }, (_, i) => {
            let pageNum: number;
            if (meta.last_page <= 7) {
              pageNum = i + 1;
            } else if (page <= 4) {
              pageNum = i + 1;
            } else if (page >= meta.last_page - 3) {
              pageNum = meta.last_page - 6 + i;
            } else {
              pageNum = page - 3 + i;
            }
            return (
              <button
                key={pageNum}
                onClick={() => setPage(pageNum)}
                className={`w-9 h-9 rounded-lg text-xs font-medium transition ${
                  pageNum === page
                    ? 'bg-sw-accent text-sw-bg'
                    : 'border border-sw-border text-sw-muted hover:text-sw-text'
                }`}
              >
                {pageNum}
              </button>
            );
          })}

          <button
            onClick={() => setPage(Math.min(meta.last_page, page + 1))}
            disabled={page >= meta.last_page}
            aria-label="Next page"
            className="p-2 rounded-lg border border-sw-border text-sw-muted hover:text-sw-text disabled:opacity-30 disabled:cursor-not-allowed transition"
          >
            <ChevronRight size={16} />
          </button>
        </div>
      )}
    </AuthenticatedLayout>
  );
}
