import { useState, useEffect, useCallback } from 'react';
import { Link2, Search, Check, DollarSign, AlertCircle, Loader2, X } from 'lucide-react';
import axios from 'axios';
import Badge from '@/Components/SpendifiAI/Badge';

interface CandidateTransaction {
  id: number;
  date: string;
  merchant: string;
  amount: number;
  category: string;
  description: string;
  already_deductible: boolean;
}

interface LinkedTransaction {
  id: number;
  date: string;
  merchant: string;
  amount: number;
  category: string;
  description: string;
  link_reason: string;
}

interface LinkedExpensesPanelProps {
  documentId: number;
  documentCategory: string;
  grossIncome: number;
}

export default function LinkedExpensesPanel({ documentId, documentCategory, grossIncome }: LinkedExpensesPanelProps) {
  const [linked, setLinked] = useState<LinkedTransaction[]>([]);
  const [candidates, setCandidates] = useState<CandidateTransaction[]>([]);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [loadingLinked, setLoadingLinked] = useState(true);
  const [loadingCandidates, setLoadingCandidates] = useState(false);
  const [linking, setLinking] = useState(false);
  const [showCandidates, setShowCandidates] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [message, setMessage] = useState<string | null>(null);

  // Fetch already-linked transactions
  const fetchLinked = useCallback(async () => {
    setLoadingLinked(true);
    try {
      const res = await axios.get(`/api/v1/tax-vault/documents/${documentId}/linked-transactions`);
      setLinked(res.data.data ?? []);
    } catch {
      // silently fail
    } finally {
      setLoadingLinked(false);
    }
  }, [documentId]);

  useEffect(() => {
    fetchLinked();
  }, [fetchLinked]);

  // Fetch candidate expenses
  const fetchCandidates = async (query?: string) => {
    setLoadingCandidates(true);
    setShowCandidates(true);
    try {
      const searchParam = query ?? searchQuery;
      const url = `/api/v1/tax-vault/documents/${documentId}/find-related-expenses${searchParam ? `?search=${encodeURIComponent(searchParam)}` : ''}`;
      const res = await axios.get(url);
      setCandidates(res.data.data ?? []);
    } catch {
      setCandidates([]);
    } finally {
      setLoadingCandidates(false);
    }
  };

  const toggleSelect = (id: number) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const handleLink = async () => {
    if (selected.size === 0) return;
    setLinking(true);
    setMessage(null);
    try {
      const reason = isSEDoc ? 'contract_labor' : 'related_transaction';
      const category = isSEDoc ? 'Contract Labor' : undefined;
      const res = await axios.post(`/api/v1/tax-vault/documents/${documentId}/link-transactions`, {
        transaction_ids: Array.from(selected),
        link_reason: reason,
        ...(category ? { tax_category: category } : {}),
      });
      setMessage(res.data.message);
      setSelected(new Set());
      fetchLinked();
      // Remove linked ones from candidates
      setCandidates((prev) => prev.filter((c) => !selected.has(c.id)));
    } catch (err: any) {
      setMessage(err?.response?.data?.message || 'Failed to link');
    } finally {
      setLinking(false);
      setTimeout(() => setMessage(null), 5000);
    }
  };

  const linkedTotal = linked.reduce((sum, tx) => sum + Number(tx.amount), 0);
  const selectedTotal = candidates
    .filter((c) => selected.has(c.id))
    .reduce((sum, c) => sum + Number(c.amount), 0);

  const isSEDoc = ['1099_nec', '1099_k'].includes(documentCategory);
  const netIncome = grossIncome - linkedTotal;
  const seTax = isSEDoc ? Math.max(0, netIncome * 0.9235 * 0.153) : 0;

  return (
    <div className="p-4 space-y-5">
      {/* Income / Expense Summary — only for income docs with amounts */}
      {grossIncome > 0 && (
        <div className={`grid ${isSEDoc ? 'grid-cols-3' : 'grid-cols-2'} gap-3`}>
          <div className="bg-sw-surface rounded-lg p-3 text-center">
            <p className="text-[10px] uppercase tracking-wide text-sw-dim font-semibold">Document Amount</p>
            <p className="text-lg font-bold text-sw-text">${Number(grossIncome).toLocaleString('en-US', { minimumFractionDigits: 2 })}</p>
          </div>
          <div className="bg-sw-surface rounded-lg p-3 text-center">
            <p className="text-[10px] uppercase tracking-wide text-sw-dim font-semibold">Linked Transactions</p>
            <p className="text-lg font-bold text-sw-accent">{linked.length}</p>
            {linkedTotal > 0 && (
              <p className="text-[10px] text-sw-dim">${linkedTotal.toLocaleString('en-US', { minimumFractionDigits: 2 })} total</p>
            )}
          </div>
          {isSEDoc && (
            <div className="bg-emerald-50 rounded-lg p-3 text-center border border-emerald-200">
              <p className="text-[10px] uppercase tracking-wide text-emerald-600 font-semibold">Net SE Income</p>
              <p className="text-lg font-bold text-emerald-700">${netIncome.toLocaleString('en-US', { minimumFractionDigits: 2 })}</p>
              <p className="text-[10px] text-emerald-600">SE Tax: ${seTax.toLocaleString('en-US', { minimumFractionDigits: 2 })}</p>
            </div>
          )}
        </div>
      )}

      {/* Already linked transactions */}
      <div>
        <h3 className="text-xs font-bold text-sw-text uppercase tracking-wide mb-2 flex items-center gap-1.5">
          <Link2 size={12} className="text-sw-accent" />
          Linked Business Expenses ({linked.length})
        </h3>
        {loadingLinked ? (
          <div className="flex justify-center py-4">
            <Loader2 size={16} className="animate-spin text-sw-accent" />
          </div>
        ) : linked.length === 0 ? (
          <p className="text-xs text-sw-dim py-3 text-center bg-sw-surface rounded-lg">
            No expenses linked yet. Use the button below to find and link subcontractor payments.
          </p>
        ) : (
          <div className="space-y-1">
            {linked.map((tx) => (
              <div key={tx.id} className="flex items-center justify-between px-3 py-2 rounded-lg bg-sw-surface text-xs">
                <div className="flex items-center gap-3 min-w-0 flex-1">
                  <Check size={12} className="text-sw-success shrink-0" />
                  <span className="text-sw-dim shrink-0">{tx.date}</span>
                  <span className="text-sw-text font-medium truncate">{tx.merchant}</span>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <Badge variant="neutral">{tx.link_reason.replace(/_/g, ' ')}</Badge>
                  <span className="font-bold text-sw-danger">${Number(tx.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}</span>
                  <button
                    onClick={async () => {
                      try {
                        await axios.delete(`/api/v1/tax-vault/documents/${documentId}/unlink-transaction/${tx.id}`);
                        fetchLinked();
                      } catch {
                        // silently fail
                      }
                    }}
                    className="p-1 rounded text-sw-dim hover:text-sw-danger hover:bg-sw-danger-light transition"
                    title="Unlink"
                  >
                    <X size={12} />
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Find related transactions */}
      <div className="space-y-2">
        <div className="flex gap-2">
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') fetchCandidates(); }}
            placeholder="Search transactions by merchant, description..."
            className="flex-1 px-3 py-2 text-xs rounded-lg border border-sw-border bg-sw-bg text-sw-text placeholder:text-sw-dim focus:outline-none focus:border-sw-accent"
          />
          <button
            onClick={() => fetchCandidates()}
            disabled={loadingCandidates}
            className="px-4 py-2 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent/90 transition disabled:opacity-50 flex items-center gap-1.5"
          >
            <Search size={12} />
            {showCandidates ? 'Search' : 'Find Transactions'}
          </button>
        </div>
        {!showCandidates && (
          <p className="text-[10px] text-sw-dim text-center">
            Search for transactions to link to this document, or click Find to auto-discover wire transfers and related payments.
          </p>
        )}
      </div>

      {/* Candidate transactions to link */}
      {showCandidates && (
        <div>
          <h3 className="text-xs font-bold text-sw-text uppercase tracking-wide mb-2 flex items-center gap-1.5">
            <Search size={12} className="text-sw-accent" />
            Suggested Expenses to Link
          </h3>
          {loadingCandidates ? (
            <div className="flex justify-center py-4">
              <Loader2 size={16} className="animate-spin text-sw-accent" />
            </div>
          ) : candidates.length === 0 ? (
            <div className="text-center py-4 bg-sw-surface rounded-lg">
              <p className="text-xs text-sw-dim">No candidate expenses found.</p>
              <p className="text-[10px] text-sw-dim mt-1">Wire transfers, ACH payments, and contractor invoices are searched automatically.</p>
            </div>
          ) : (
            <>
              <div className="space-y-1 max-h-64 overflow-y-auto">
                {candidates.map((c) => (
                  <button
                    key={c.id}
                    onClick={() => toggleSelect(c.id)}
                    className={`w-full flex items-center justify-between px-3 py-2 rounded-lg text-xs text-left transition ${
                      selected.has(c.id)
                        ? 'bg-sw-accent-light border border-sw-accent/30'
                        : 'bg-sw-surface hover:bg-sw-surface/80'
                    }`}
                  >
                    <div className="flex items-center gap-3 min-w-0 flex-1">
                      <div className={`w-4 h-4 rounded border flex items-center justify-center shrink-0 ${
                        selected.has(c.id) ? 'bg-sw-accent border-sw-accent' : 'border-sw-border'
                      }`}>
                        {selected.has(c.id) && <Check size={10} className="text-white" />}
                      </div>
                      <span className="text-sw-dim shrink-0">{c.date}</span>
                      <span className="text-sw-text font-medium truncate">{c.merchant}</span>
                      {c.already_deductible && <Badge variant="warning">Already deductible</Badge>}
                    </div>
                    <span className="font-bold text-sw-text shrink-0">${Number(c.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}</span>
                  </button>
                ))}
              </div>

              {selected.size > 0 && (
                <div className="flex items-center justify-between mt-3 p-3 rounded-lg bg-sw-accent-light border border-sw-accent/20">
                  <div className="flex items-center gap-2 text-xs">
                    <DollarSign size={14} className="text-sw-accent" />
                    <span className="font-semibold text-sw-accent">
                      {selected.size} selected — ${selectedTotal.toLocaleString('en-US', { minimumFractionDigits: 2 })} in deductions
                    </span>
                  </div>
                  <button
                    onClick={handleLink}
                    disabled={linking}
                    className="px-4 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent/90 transition disabled:opacity-50"
                  >
                    {linking ? 'Linking...' : isSEDoc ? 'Link as Contract Labor' : 'Link to Document'}
                  </button>
                </div>
              )}
            </>
          )}
        </div>
      )}

      {/* Status message */}
      {message && (
        <div className="flex items-center gap-2 text-xs text-sw-success">
          <AlertCircle size={12} />
          {message}
        </div>
      )}
    </div>
  );
}
