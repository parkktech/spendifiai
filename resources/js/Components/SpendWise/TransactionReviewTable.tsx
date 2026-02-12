import { useState, useMemo } from 'react';
import {
  Check,
  X,
  Edit3,
  AlertTriangle,
  Copy,
  ChevronDown,
  ChevronUp,
  ArrowDownLeft,
  ArrowUpRight,
} from 'lucide-react';
import Badge from './Badge';
import type { ParsedTransaction } from '@/types/spendwise';

interface TransactionReviewTableProps {
  transactions: ParsedTransaction[];
  onUpdate: (rowIndex: number, updates: Partial<ParsedTransaction>) => void;
  onRemove: (rowIndex: number) => void;
  duplicatesCount: number;
}

function formatDate(dateStr: string): string {
  const date = new Date(dateStr);
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(date);
}

function formatAmount(amount: number): string {
  return `$${Math.abs(amount).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

export default function TransactionReviewTable({
  transactions,
  onUpdate,
  onRemove,
  duplicatesCount,
}: TransactionReviewTableProps) {
  const [editingRow, setEditingRow] = useState<number | null>(null);
  const [editValues, setEditValues] = useState<Partial<ParsedTransaction>>({});
  const [showDuplicates, setShowDuplicates] = useState(false);
  const [sortField, setSortField] = useState<'date' | 'amount' | 'merchant_name'>('date');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc');

  const nonDuplicates = useMemo(
    () => transactions.filter((t) => !t.is_duplicate),
    [transactions],
  );

  const duplicates = useMemo(
    () => transactions.filter((t) => t.is_duplicate),
    [transactions],
  );

  const displayedTransactions = useMemo(() => {
    const list = showDuplicates ? transactions : nonDuplicates;
    return [...list].sort((a, b) => {
      let comparison = 0;
      switch (sortField) {
        case 'date':
          comparison = new Date(a.date).getTime() - new Date(b.date).getTime();
          break;
        case 'amount':
          comparison = Math.abs(a.amount) - Math.abs(b.amount);
          break;
        case 'merchant_name':
          comparison = a.merchant_name.localeCompare(b.merchant_name);
          break;
      }
      return sortDirection === 'asc' ? comparison : -comparison;
    });
  }, [transactions, nonDuplicates, showDuplicates, sortField, sortDirection]);

  const totalIncome = nonDuplicates
    .filter((t) => t.is_income)
    .reduce((sum, t) => sum + Math.abs(t.amount), 0);
  const totalExpenses = nonDuplicates
    .filter((t) => !t.is_income)
    .reduce((sum, t) => sum + Math.abs(t.amount), 0);

  const handleSort = (field: typeof sortField) => {
    if (sortField === field) {
      setSortDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortField(field);
      setSortDirection('desc');
    }
  };

  const startEditing = (row: ParsedTransaction) => {
    setEditingRow(row.row_index);
    setEditValues({
      date: row.date,
      merchant_name: row.merchant_name,
      amount: row.amount,
    });
  };

  const saveEdit = () => {
    if (editingRow !== null) {
      onUpdate(editingRow, editValues);
      setEditingRow(null);
      setEditValues({});
    }
  };

  const cancelEdit = () => {
    setEditingRow(null);
    setEditValues({});
  };

  const SortIcon = ({ field }: { field: typeof sortField }) => {
    if (sortField !== field) return null;
    return sortDirection === 'asc' ? (
      <ChevronUp size={12} className="inline ml-0.5" />
    ) : (
      <ChevronDown size={12} className="inline ml-0.5" />
    );
  };

  return (
    <div className="space-y-4">
      {/* Summary bar */}
      <div className="flex flex-wrap items-center gap-4 text-sm">
        <div className="flex items-center gap-1.5">
          <ArrowDownLeft size={14} className="text-sw-success" />
          <span className="text-sw-dim">Income:</span>
          <span className="font-semibold text-sw-success">{formatAmount(totalIncome)}</span>
        </div>
        <div className="flex items-center gap-1.5">
          <ArrowUpRight size={14} className="text-sw-danger" />
          <span className="text-sw-dim">Expenses:</span>
          <span className="font-semibold text-sw-text">{formatAmount(totalExpenses)}</span>
        </div>
        <div className="text-sw-dim">|</div>
        <span className="text-xs text-sw-muted">
          {nonDuplicates.length} transaction{nonDuplicates.length !== 1 ? 's' : ''} to import
        </span>
      </div>

      {/* Duplicate notice */}
      {duplicatesCount > 0 && (
        <div className="flex items-center justify-between rounded-xl border border-amber-200 bg-amber-50 p-4">
          <div className="flex items-center gap-3">
            <Copy size={16} className="text-sw-warning shrink-0" />
            <div>
              <p className="text-sm font-semibold text-sw-text">
                {duplicatesCount} duplicate{duplicatesCount !== 1 ? 's' : ''} detected
              </p>
              <p className="text-xs text-sw-muted mt-0.5">
                These transactions already exist in your account and will be skipped
                during import.
              </p>
            </div>
          </div>
          <button
            onClick={() => setShowDuplicates(!showDuplicates)}
            className="shrink-0 text-xs text-sw-accent hover:text-sw-accent-hover font-medium transition"
          >
            {showDuplicates ? 'Hide duplicates' : 'Show duplicates'}
          </button>
        </div>
      )}

      {/* Table */}
      <div className="overflow-x-auto rounded-xl border border-sw-border">
        <table className="w-full text-left">
          <thead>
            <tr className="border-b border-sw-border bg-sw-surface">
              <th className="px-4 py-3 text-[11px] font-semibold text-sw-muted uppercase tracking-wider">
                <button
                  onClick={() => handleSort('date')}
                  className="flex items-center gap-0.5 hover:text-sw-text transition"
                >
                  Date
                  <SortIcon field="date" />
                </button>
              </th>
              <th className="px-4 py-3 text-[11px] font-semibold text-sw-muted uppercase tracking-wider">
                <button
                  onClick={() => handleSort('merchant_name')}
                  className="flex items-center gap-0.5 hover:text-sw-text transition"
                >
                  Description
                  <SortIcon field="merchant_name" />
                </button>
              </th>
              <th className="px-4 py-3 text-[11px] font-semibold text-sw-muted uppercase tracking-wider text-right">
                <button
                  onClick={() => handleSort('amount')}
                  className="flex items-center gap-0.5 ml-auto hover:text-sw-text transition"
                >
                  Amount
                  <SortIcon field="amount" />
                </button>
              </th>
              <th className="px-4 py-3 text-[11px] font-semibold text-sw-muted uppercase tracking-wider text-center">
                Status
              </th>
              <th className="px-4 py-3 text-[11px] font-semibold text-sw-muted uppercase tracking-wider text-center w-24">
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            {displayedTransactions.map((tx) => {
              const isEditing = editingRow === tx.row_index;
              const isIncome = tx.is_income;

              return (
                <tr
                  key={tx.row_index}
                  className={`border-b border-sw-border last:border-b-0 transition ${
                    tx.is_duplicate
                      ? 'bg-amber-50/50 opacity-60'
                      : isEditing
                        ? 'bg-sw-accent-light'
                        : 'hover:bg-sw-card-hover'
                  }`}
                >
                  {/* Date */}
                  <td className="px-4 py-3 text-xs text-sw-muted whitespace-nowrap">
                    {isEditing ? (
                      <input
                        type="date"
                        value={editValues.date || ''}
                        onChange={(e) =>
                          setEditValues({ ...editValues, date: e.target.value })
                        }
                        className="px-2 py-1 rounded-md border border-sw-border bg-sw-card text-sw-text text-xs focus:outline-none focus:border-sw-accent w-32"
                      />
                    ) : (
                      formatDate(tx.date)
                    )}
                  </td>

                  {/* Description / Merchant */}
                  <td className="px-4 py-3">
                    {isEditing ? (
                      <input
                        type="text"
                        value={editValues.merchant_name || ''}
                        onChange={(e) =>
                          setEditValues({
                            ...editValues,
                            merchant_name: e.target.value,
                          })
                        }
                        className="w-full px-2 py-1 rounded-md border border-sw-border bg-sw-card text-sw-text text-xs focus:outline-none focus:border-sw-accent"
                      />
                    ) : (
                      <div>
                        <div className="text-[13px] font-medium text-sw-text truncate max-w-xs">
                          {tx.merchant_name}
                        </div>
                        {tx.description !== tx.merchant_name && tx.original_text && (
                          <div className="text-[11px] text-sw-dim truncate max-w-xs mt-0.5">
                            {tx.original_text}
                          </div>
                        )}
                      </div>
                    )}
                  </td>

                  {/* Amount */}
                  <td className="px-4 py-3 text-right whitespace-nowrap">
                    {isEditing ? (
                      <input
                        type="number"
                        step="0.01"
                        value={editValues.amount ?? ''}
                        onChange={(e) =>
                          setEditValues({
                            ...editValues,
                            amount: parseFloat(e.target.value) || 0,
                          })
                        }
                        className="px-2 py-1 rounded-md border border-sw-border bg-sw-card text-sw-text text-xs focus:outline-none focus:border-sw-accent w-28 text-right"
                      />
                    ) : (
                      <span
                        className={`text-sm font-semibold ${
                          isIncome ? 'text-sw-success' : 'text-sw-text'
                        }`}
                      >
                        {isIncome ? '+' : '-'}
                        {formatAmount(tx.amount)}
                      </span>
                    )}
                  </td>

                  {/* Status */}
                  <td className="px-4 py-3 text-center">
                    {tx.is_duplicate ? (
                      <Badge variant="warning">Duplicate</Badge>
                    ) : tx.confidence < 0.6 ? (
                      <Badge variant="danger">Low confidence</Badge>
                    ) : tx.confidence < 0.85 ? (
                      <Badge variant="warning">Review</Badge>
                    ) : (
                      <Badge variant="success">Ready</Badge>
                    )}
                  </td>

                  {/* Actions */}
                  <td className="px-4 py-3 text-center">
                    {tx.is_duplicate ? (
                      <span className="text-[11px] text-sw-dim">Skipped</span>
                    ) : isEditing ? (
                      <div className="flex items-center justify-center gap-1">
                        <button
                          onClick={saveEdit}
                          className="p-1.5 rounded-md bg-sw-accent text-white hover:bg-sw-accent-hover transition"
                          aria-label="Save edit"
                        >
                          <Check size={12} />
                        </button>
                        <button
                          onClick={cancelEdit}
                          className="p-1.5 rounded-md border border-sw-border text-sw-muted hover:text-sw-text transition"
                          aria-label="Cancel edit"
                        >
                          <X size={12} />
                        </button>
                      </div>
                    ) : (
                      <div className="flex items-center justify-center gap-1">
                        <button
                          onClick={() => startEditing(tx)}
                          className="p-1.5 rounded-md text-sw-dim hover:text-sw-accent hover:bg-sw-accent-light transition"
                          aria-label="Edit transaction"
                        >
                          <Edit3 size={12} />
                        </button>
                        <button
                          onClick={() => onRemove(tx.row_index)}
                          className="p-1.5 rounded-md text-sw-dim hover:text-sw-danger hover:bg-sw-danger-light transition"
                          aria-label="Remove transaction"
                        >
                          <X size={12} />
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Low confidence warning */}
      {transactions.some((t) => !t.is_duplicate && t.confidence < 0.6) && (
        <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
          <AlertTriangle size={16} className="text-sw-warning shrink-0 mt-0.5" />
          <div>
            <p className="text-xs font-semibold text-sw-text">
              Some transactions have low extraction confidence
            </p>
            <p className="text-xs text-sw-muted mt-0.5">
              These rows may have been parsed incorrectly. Please review the date,
              description, and amount before importing. You can click the edit icon to
              correct any mistakes.
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
