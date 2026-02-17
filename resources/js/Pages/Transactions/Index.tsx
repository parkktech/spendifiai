import { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import {
  Receipt,
  AlertTriangle,
  RefreshCw,
  ChevronLeft,
  ChevronRight,
  Loader2,
  Inbox,
  Sparkles,
  AlertCircle,
} from 'lucide-react';
import TransactionRow from '@/Components/SpendifiAI/TransactionRow';
import FilterBar, { FilterState } from '@/Components/SpendifiAI/FilterBar';
import StatCard from '@/Components/SpendifiAI/StatCard';
import ConnectBankPrompt from '@/Components/SpendifiAI/ConnectBankPrompt';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { PaginatedResponse, Transaction, ExpenseCategory } from '@/types/spendifiai';

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
  const { auth } = usePage().props as unknown as { auth: { hasBankConnected: boolean } };
  const [filters, setFilters] = useState<FilterState>({});
  const [page, setPage] = useState(1);
  const url = buildUrl(filters, page);
  const { data, loading, error, refresh, mutate } = useApi<PaginatedResponse<Transaction>>(url, { enabled: auth.hasBankConnected });
  const { data: categoriesData } = useApi<ExpenseCategory[]>('/api/v1/categories', { enabled: auth.hasBankConnected });
  const { submit: updateCategory } = useApiPost<unknown, { category: string }>('', 'PATCH');
  const { submit: categorizeNow, loading: categorizing } = useApiPost<{
    message: string;
    auto_categorized?: number;
    needs_review?: number;
    still_pending?: number;
    processed?: number;
  }>('/api/v1/transactions/categorize');
  const [categorizationResult, setCategorizationResult] = useState<string | null>(null);
  const autoCategorizedRef = useRef(false);

  const categoryNames = useMemo(
    () => (categoriesData || []).map((c) => c.name).sort(),
    [categoriesData]
  );

  const transactions = data?.data || [];
  const meta = data?.meta;
  const reviewCount = transactions.filter((tx) => tx.review_status === 'needs_review').length;

  // Auto-categorize pending transactions on first load
  useEffect(() => {
    if (!data || autoCategorizedRef.current) return;

    const hasPending = transactions.some(
      (tx) => tx.review_status === 'pending_ai' || tx.review_status === 'needs_review'
    );

    if (hasPending) {
      autoCategorizedRef.current = true;
      (async () => {
        const result = await categorizeNow();
        if (result && (result.auto_categorized || result.needs_review)) {
          setCategorizationResult(
            `AI categorized ${result.auto_categorized ?? 0} transactions automatically, ${result.needs_review ?? 0} need review.`
          );
          refresh();
        }
      })();
    }
  }, [data]); // eslint-disable-line react-hooks/exhaustive-deps

  const handleCategorize = async () => {
    const result = await categorizeNow();
    if (result) {
      setCategorizationResult(result.message);
    }
    refresh();
  };

  const handleFilterChange = useCallback((newFilters: FilterState) => {
    setFilters(newFilters);
    setPage(1);
  }, []);

  const handleCategoryChange = useCallback(
    async (id: number, category: string) => {
      const result = await updateCategory({ category }, { url: `/api/v1/transactions/${id}/category`, method: 'PATCH' } as never) as { message?: string; matched?: number } | undefined;
      if (result?.matched && result.matched > 0) {
        setCategorizationResult(result.message ?? `Also updated ${result.matched} matching transaction${result.matched !== 1 ? 's' : ''}`);
      }
      refresh();
    },
    [updateCategory, refresh]
  );

  const handleConfirm = useCallback(
    async (id: number) => {
      const tx = transactions.find((t) => t.id === id);
      if (!tx) return;
      const category = tx.category || tx.ai_category || 'Uncategorized';
      const result = await updateCategory({ category }, { url: `/api/v1/transactions/${id}/category`, method: 'PATCH' } as never) as { message?: string; matched?: number } | undefined;
      if (result?.matched && result.matched > 0) {
        setCategorizationResult(result.message ?? `Also updated ${result.matched} matching transaction${result.matched !== 1 ? 's' : ''}`);
      }
      refresh();
    },
    [updateCategory, refresh, transactions]
  );

  // Compute summary stats
  const totalAmount = transactions.reduce((sum, tx) => sum + Math.abs(tx.amount), 0);
  const businessCount = transactions.filter((tx) => tx.account_purpose === 'business').length;
  const personalCount = transactions.filter((tx) => tx.account_purpose === 'personal').length;

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-sw-text tracking-tight">Transactions</h1>
            <p className="text-xs text-sw-dim mt-0.5">View and manage all your transactions</p>
          </div>
          <button
            onClick={handleCategorize}
            disabled={categorizing}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {categorizing ? <Loader2 size={14} className="animate-spin" /> : <Sparkles size={14} />}
            {categorizing ? 'Categorizing...' : 'AI Categorize'}
          </button>
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

      {/* Auto-categorization banner */}
      {categorizing && (
        <div className="flex items-center gap-3 rounded-xl border border-violet-200 bg-sw-info-light p-4 mb-4">
          <Loader2 size={18} className="animate-spin text-sw-info shrink-0" />
          <div>
            <div className="text-sm font-semibold text-sw-text">AI is categorizing your transactions...</div>
            <div className="text-xs text-sw-muted mt-0.5">This may take 15-30 seconds depending on volume.</div>
          </div>
        </div>
      )}

      {categorizationResult && !categorizing && (
        <div className="flex items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50 p-4 mb-4">
          <div className="flex items-center gap-3">
            <Sparkles size={18} className="text-emerald-600 shrink-0" />
            <span className="text-sm text-emerald-800">{categorizationResult}</span>
          </div>
          <button
            onClick={() => setCategorizationResult(null)}
            className="text-xs text-emerald-600 hover:text-emerald-800 transition"
          >
            Dismiss
          </button>
        </div>
      )}

      {/* Needs review banner */}
      {reviewCount > 0 && !categorizing && (
        <div className="flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 mb-4">
          <AlertCircle size={18} className="text-amber-600 shrink-0" />
          <div className="flex-1">
            <div className="text-sm font-semibold text-sw-text">
              {reviewCount} transaction{reviewCount !== 1 ? 's' : ''} need{reviewCount === 1 ? 's' : ''} review
            </div>
            <div className="text-xs text-sw-muted mt-0.5">
              AI categorized these with moderate confidence. Click <strong>Confirm</strong> to accept, or change the category.
            </div>
          </div>
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
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition"
          >
            <RefreshCw size={14} /> Retry
          </button>
        </div>
      )}

      {/* Connect Bank Prompt */}
      {!loading && !error && !data && (
        <ConnectBankPrompt
          feature="transactions"
          description="Link your bank account to see all your transactions, track spending, and get AI-powered categorization."
        />
      )}

      {/* Loading */}
      {loading && (
        <div className="flex items-center justify-center py-16">
          <Loader2 size={24} className="animate-spin text-sw-accent" />
        </div>
      )}

      {/* Transaction list */}
      {!loading && !error && data && transactions.length > 0 && (
        <div aria-live="polite" className="rounded-2xl border border-sw-border bg-sw-card p-6">
          {transactions.map((tx) => (
            <TransactionRow
              key={tx.id}
              transaction={tx}
              categories={categoryNames}
              onCategoryChange={handleCategoryChange}
              onConfirm={handleConfirm}
            />
          ))}
        </div>
      )}

      {/* Empty state */}
      {!loading && !error && data && transactions.length === 0 && (
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
                    ? 'bg-sw-accent text-white'
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
