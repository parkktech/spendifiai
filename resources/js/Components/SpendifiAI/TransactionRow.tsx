import { useState } from 'react';
import {
  Receipt,
  Check,
  ChevronDown,
  ChevronUp,
  CheckCircle,
  Search,
  Mail,
  Package,
  Tag,
  CreditCard,
  MapPin,
} from 'lucide-react';
import Badge from './Badge';
import type { Transaction, TransactionOrderItem } from '@/types/spendifiai';

interface TransactionRowProps {
  transaction: Transaction;
  categories?: string[];
  onCategoryChange?: (id: number, category: string) => void;
  onConfirm?: (id: number) => void;
}

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

function formatDate(dateStr: string): string {
  const date = new Date(dateStr);
  return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
}

function formatAmount(amount: number): string {
  const abs = Math.abs(amount);
  return `${amount < 0 ? '-' : '+'}$${abs.toFixed(2)}`;
}

function confidenceLabel(confidence: number | null): string {
  if (!confidence) return '';
  if (confidence >= 0.85) return 'High';
  if (confidence >= 0.6) return 'Medium';
  return 'Low';
}

export default function TransactionRow({
  transaction,
  categories = [],
  onCategoryChange,
  onConfirm,
}: TransactionRowProps) {
  const [editingCategory, setEditingCategory] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [expanded, setExpanded] = useState(false);
  const isNegative = transaction.amount < 0;
  const needsReview = transaction.review_status === 'needs_review';
  const hasOrderItems = (transaction.order_items?.length ?? 0) > 0;
  const hasDetails = hasOrderItems || transaction.description || transaction.account;

  const handleCategorySelect = (cat: string) => {
    onCategoryChange?.(transaction.id, cat);
    setEditingCategory(false);
    setSearchQuery('');
  };

  const filteredCategories = searchQuery
    ? categories.filter((c) => c.toLowerCase().includes(searchQuery.toLowerCase()))
    : categories;

  return (
    <div className={`border-b border-sw-border last:border-b-0 ${needsReview ? 'bg-amber-50/50 -mx-2 px-2 rounded-lg' : ''}`}>
      {/* Main row */}
      <div className="flex items-center gap-3.5 py-3">
        {/* Icon */}
        <div
          className={`w-9 h-9 rounded-lg flex items-center justify-center shrink-0 ${
            needsReview
              ? 'bg-sw-warning-light border border-amber-200'
              : transaction.is_reconciled
                ? 'bg-emerald-50 border border-emerald-200'
                : 'bg-sw-accent-light border border-blue-200'
          }`}
        >
          {transaction.is_reconciled ? (
            <Mail size={16} className="text-emerald-600" />
          ) : (
            <Receipt size={16} className={needsReview ? 'text-sw-warning' : 'text-sw-accent'} />
          )}
        </div>

        {/* Info */}
        <div className="flex-1 min-w-0">
          <div className="text-[13px] font-medium text-sw-text truncate">{transaction.merchant_name}</div>
          <div className="flex items-center gap-1.5 mt-0.5 flex-wrap">
            <span className="text-[11px] text-sw-dim">{formatDate(transaction.date)}</span>
            <span className="text-sw-dim text-[11px]">-</span>

            {/* Category (editable) */}
            <div className="relative">
              <button
                onClick={(e) => { e.stopPropagation(); setEditingCategory(!editingCategory); }}
                className="flex items-center gap-1 text-[11px] text-sw-muted hover:text-sw-text transition"
              >
                {transaction.category || 'Uncategorized'}
                {onCategoryChange && <ChevronDown size={10} />}
              </button>

              {editingCategory && categories.length > 0 && (
                <>
                  <div className="fixed inset-0 z-20" onClick={() => { setEditingCategory(false); setSearchQuery(''); }} />
                  <div className="absolute left-0 top-full mt-1 w-56 rounded-lg border border-sw-border bg-sw-card shadow-lg z-30 py-1">
                    <div className="px-2 py-1.5 border-b border-sw-border">
                      <div className="flex items-center gap-1.5 px-2 py-1 rounded-md bg-gray-50 border border-gray-200">
                        <Search size={10} className="text-sw-dim shrink-0" />
                        <input
                          type="text"
                          value={searchQuery}
                          onChange={(e) => setSearchQuery(e.target.value)}
                          placeholder="Search categories..."
                          className="w-full text-xs bg-transparent outline-none text-sw-text placeholder:text-sw-dim"
                          autoFocus
                        />
                      </div>
                    </div>
                    <div className="max-h-48 overflow-y-auto">
                      {filteredCategories.map((cat) => (
                        <button
                          key={cat}
                          onClick={() => handleCategorySelect(cat)}
                          className={`w-full text-left px-3 py-1.5 text-xs hover:bg-sw-card-hover transition ${
                            cat === transaction.category ? 'text-sw-accent font-medium' : 'text-sw-muted'
                          }`}
                        >
                          {cat === transaction.category && <Check size={10} className="inline mr-1" />}
                          {cat}
                        </button>
                      ))}
                      {filteredCategories.length === 0 && (
                        <div className="px-3 py-2 text-xs text-sw-dim">No matching categories</div>
                      )}
                    </div>
                  </div>
                </>
              )}
            </div>

            {needsReview && (
              <Badge variant="warning">
                Review{transaction.ai_confidence ? ` (${confidenceLabel(transaction.ai_confidence)})` : ''}
              </Badge>
            )}
            {transaction.is_reconciled && (
              <Badge variant="info">
                <Mail size={9} className="inline -mt-px mr-0.5" />Receipt
              </Badge>
            )}
            {hasOrderItems && (
              <Badge variant="info">
                <Package size={9} className="inline -mt-px mr-0.5" />{transaction.order_items!.length} items
              </Badge>
            )}
            {transaction.tax_deductible && <Badge variant="success">Tax</Badge>}
            {transaction.account_purpose === 'business' && <Badge variant="info">Business</Badge>}
          </div>
        </div>

        {/* Review actions */}
        {needsReview && onConfirm && (
          <button
            onClick={(e) => { e.stopPropagation(); onConfirm(transaction.id); }}
            title="Confirm AI category"
            className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-[11px] font-medium hover:bg-emerald-100 transition shrink-0"
          >
            <CheckCircle size={12} />
            Confirm
          </button>
        )}

        {/* Amount */}
        <span className={`text-sm font-semibold ${isNegative ? 'text-sw-text' : 'text-sw-accent'}`}>
          {formatAmount(transaction.amount)}
        </span>

        {/* Expand/collapse toggle */}
        {hasDetails && (
          <button
            onClick={() => setExpanded(!expanded)}
            className="shrink-0 p-1 rounded-md text-sw-dim hover:text-sw-text hover:bg-sw-surface transition"
          >
            {expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
          </button>
        )}
      </div>

      {/* Expanded detail section */}
      {expanded && hasDetails && (
        <div className="ml-[52px] pb-3 space-y-3">
          {/* Transaction details */}
          <div className="flex flex-wrap gap-x-6 gap-y-1.5 text-[11px]">
            {transaction.description && (
              <div className="flex items-center gap-1.5 text-sw-muted">
                <Receipt size={10} className="text-sw-dim" />
                <span>{transaction.description}</span>
              </div>
            )}
            {transaction.account && (
              <div className="flex items-center gap-1.5 text-sw-muted">
                <CreditCard size={10} className="text-sw-dim" />
                <span>{transaction.account.name}{transaction.account.mask ? ` ····${transaction.account.mask}` : ''}</span>
              </div>
            )}
            {transaction.expense_type && (
              <div className="flex items-center gap-1.5 text-sw-muted">
                <MapPin size={10} className="text-sw-dim" />
                <span className="capitalize">{transaction.expense_type}</span>
              </div>
            )}
          </div>

          {/* Order items */}
          {hasOrderItems && (
            <div className="mt-2 pt-2 border-t border-sw-border/50">
              <div className="flex items-center gap-2 mb-2">
                <Mail size={11} className="text-sw-info" />
                <span className="text-[11px] font-semibold text-sw-text">Order Items</span>
                <span className="text-[10px] text-sw-dim">({transaction.order_items!.length} items)</span>
              </div>
              <div className="space-y-1">
                {transaction.order_items!.map((item) => (
                  <OrderItemRow key={item.id} item={item} />
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function OrderItemRow({ item }: { item: TransactionOrderItem }) {
  const category = item.user_category || item.ai_category || 'Uncategorized';
  const isBusiness = item.expense_type === 'business';

  return (
    <div
      className={`flex items-center gap-2.5 py-1.5 px-2.5 rounded-md text-[11px] ${
        item.tax_deductible
          ? 'bg-emerald-50/50 border border-emerald-200/50'
          : 'bg-sw-surface/50'
      }`}
    >
      <Package size={10} className="text-sw-dim shrink-0" />
      <span className="text-sw-text font-medium truncate flex-1">{item.product_name}</span>
      {item.quantity > 1 && <span className="text-sw-dim">x{item.quantity}</span>}
      <span className="inline-flex items-center gap-1 text-sw-dim">
        <Tag size={8} />
        {category}
      </span>
      <span
        className={`px-1.5 py-0.5 rounded text-[9px] font-semibold ${
          isBusiness
            ? 'bg-sw-accent/10 text-sw-accent'
            : 'text-sw-dim'
        }`}
      >
        {isBusiness ? 'Business' : 'Personal'}
      </span>
      {item.tax_deductible && (
        <span className="text-[9px] font-medium text-emerald-600">Write-off</span>
      )}
      <span className="font-semibold text-sw-text shrink-0">{fmt.format(Number(item.total_price))}</span>
    </div>
  );
}
