import { useState } from 'react';
import { Receipt, Check, ChevronDown } from 'lucide-react';
import Badge from './Badge';
import type { Transaction } from '@/types/spendwise';

interface TransactionRowProps {
  transaction: Transaction;
  categories?: string[];
  onCategoryChange?: (id: number, category: string) => void;
}

function formatDate(dateStr: string): string {
  const date = new Date(dateStr);
  return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
}

function formatAmount(amount: number): string {
  const abs = Math.abs(amount);
  return `${amount < 0 ? '-' : '+'}$${abs.toFixed(2)}`;
}

export default function TransactionRow({
  transaction,
  categories = [],
  onCategoryChange,
}: TransactionRowProps) {
  const [editingCategory, setEditingCategory] = useState(false);
  const isNegative = transaction.amount < 0;
  const needsReview = transaction.review_status === 'needs_review';

  const handleCategorySelect = (cat: string) => {
    onCategoryChange?.(transaction.id, cat);
    setEditingCategory(false);
  };

  return (
    <div className="flex items-center gap-3.5 py-3 border-b border-sw-border last:border-b-0">
      {/* Icon */}
      <div
        className={`w-9 h-9 rounded-lg flex items-center justify-center shrink-0 ${
          needsReview
            ? 'bg-sw-warning/10 border border-sw-warning/20'
            : 'bg-blue-400/10 border border-blue-400/20'
        }`}
      >
        <Receipt size={16} className={needsReview ? 'text-sw-warning' : 'text-blue-400'} />
      </div>

      {/* Info */}
      <div className="flex-1 min-w-0">
        <div className="text-[13px] font-medium text-sw-text truncate">{transaction.merchant_name}</div>
        <div className="flex items-center gap-1.5 mt-0.5">
          <span className="text-[11px] text-sw-dim">{formatDate(transaction.date)}</span>
          <span className="text-sw-dim text-[11px]">-</span>

          {/* Category (editable) */}
          <div className="relative">
            <button
              onClick={() => setEditingCategory(!editingCategory)}
              className="flex items-center gap-1 text-[11px] text-sw-muted hover:text-sw-text transition"
            >
              {transaction.category || 'Uncategorized'}
              {onCategoryChange && <ChevronDown size={10} />}
            </button>

            {editingCategory && categories.length > 0 && (
              <>
                <div className="fixed inset-0 z-20" onClick={() => setEditingCategory(false)} />
                <div className="absolute left-0 top-full mt-1 w-48 max-h-48 overflow-y-auto rounded-lg border border-sw-border bg-sw-card shadow-lg z-30 py-1">
                  {categories.map((cat) => (
                    <button
                      key={cat}
                      onClick={() => handleCategorySelect(cat)}
                      className={`w-full text-left px-3 py-1.5 text-xs hover:bg-sw-card-hover transition ${
                        cat === transaction.category ? 'text-sw-accent' : 'text-sw-muted'
                      }`}
                    >
                      {cat === transaction.category && <Check size={10} className="inline mr-1" />}
                      {cat}
                    </button>
                  ))}
                </div>
              </>
            )}
          </div>

          {needsReview && <Badge variant="warning">Review</Badge>}
          {transaction.tax_deductible && <Badge variant="success">Tax</Badge>}
          {transaction.account_purpose === 'business' && <Badge variant="info">Business</Badge>}
        </div>
      </div>

      {/* Amount */}
      <span className={`text-sm font-semibold ${isNegative ? 'text-sw-text' : 'text-sw-accent'}`}>
        {formatAmount(transaction.amount)}
      </span>
    </div>
  );
}
