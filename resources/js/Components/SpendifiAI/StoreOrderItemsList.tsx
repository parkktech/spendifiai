import { useState } from 'react';
import { Mail, Package, Tag, Loader2 } from 'lucide-react';
import axios from 'axios';
import type { StoreOrderItem } from '@/types/spendifiai';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

interface StoreOrderItemsListProps {
  items: StoreOrderItem[];
  onItemUpdated: (itemId: number, expenseType: 'personal' | 'business', taxDeductible: boolean) => void;
}

export default function StoreOrderItemsList({ items, onItemUpdated }: StoreOrderItemsListProps) {
  const [togglingId, setTogglingId] = useState<number | null>(null);

  if (items.length === 0) return null;

  // Group items by order date
  const grouped = items.reduce<Record<string, StoreOrderItem[]>>((acc, item) => {
    const key = item.order?.order_date ?? 'Unknown';
    if (!acc[key]) acc[key] = [];
    acc[key].push(item);
    return acc;
  }, {});

  const sortedDates = Object.keys(grouped).sort((a, b) => b.localeCompare(a));

  const handleToggle = async (item: StoreOrderItem) => {
    const newType = item.expense_type === 'personal' ? 'business' : 'personal';
    setTogglingId(item.id);
    try {
      await axios.patch(`/api/v1/order-items/${item.id}/expense-type`, {
        expense_type: newType,
      });
      onItemUpdated(item.id, newType, newType === 'business');
    } catch {
      // Silently fail — item stays in previous state
    } finally {
      setTogglingId(null);
    }
  };

  return (
    <div className="mt-3 pt-3 border-t border-sw-border/50">
      <div className="flex items-center gap-2 mb-3">
        <Mail size={13} className="text-sw-info" />
        <span className="text-xs font-semibold text-sw-text">Email Order Items</span>
        <span className="text-[10px] text-sw-dim">({items.length} items)</span>
      </div>

      <div className="space-y-4">
        {sortedDates.map((date) => (
          <div key={date}>
            {/* Order date header */}
            <div className="flex items-center gap-2 mb-1.5">
              <span className="text-[11px] font-medium text-sw-muted">
                {date !== 'Unknown'
                  ? new Date(date + 'T00:00:00').toLocaleDateString('en-US', {
                      month: 'short',
                      day: 'numeric',
                      year: 'numeric',
                    })
                  : 'Unknown Date'}
              </span>
              {grouped[date][0]?.order?.order_number && (
                <span className="text-[10px] text-sw-dim">
                  #{grouped[date][0].order.order_number}
                </span>
              )}
            </div>

            {/* Items for this order */}
            <div className="space-y-1">
              {grouped[date].map((item) => {
                const category = item.user_category || item.ai_category || 'Uncategorized';
                const isBusiness = item.expense_type === 'business';
                const isToggling = togglingId === item.id;

                return (
                  <div
                    key={item.id}
                    className={`flex items-center gap-3 py-2 px-3 rounded-lg border transition ${
                      item.tax_deductible
                        ? 'bg-emerald-50/40 border-emerald-200/60'
                        : 'bg-sw-surface/50 border-transparent'
                    }`}
                  >
                    {/* Product icon */}
                    <div className="w-7 h-7 rounded-md bg-sw-card border border-sw-border flex items-center justify-center shrink-0">
                      <Package size={12} className="text-sw-dim" />
                    </div>

                    {/* Product details */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="text-xs font-medium text-sw-text truncate">
                          {item.product_name}
                        </span>
                        {item.quantity > 1 && (
                          <span className="text-[10px] text-sw-dim">x{item.quantity}</span>
                        )}
                      </div>
                      <div className="flex items-center gap-2 mt-0.5">
                        <span className="inline-flex items-center gap-1 text-[10px] text-sw-dim">
                          <Tag size={9} />
                          {category}
                        </span>
                        {item.tax_deductible && (
                          <span className="text-[10px] font-medium text-emerald-600">
                            Write-off
                          </span>
                        )}
                      </div>
                    </div>

                    {/* Price */}
                    <span className="text-xs font-semibold text-sw-text shrink-0">
                      {fmt.format(Number(item.total_price))}
                    </span>

                    {/* Personal/Business toggle */}
                    <button
                      onClick={() => handleToggle(item)}
                      disabled={isToggling}
                      className={`shrink-0 px-2.5 py-1 rounded-md text-[10px] font-semibold transition border ${
                        isBusiness
                          ? 'bg-sw-accent/10 text-sw-accent border-sw-accent/20 hover:bg-sw-accent/20'
                          : 'bg-sw-surface text-sw-dim border-sw-border hover:bg-sw-card-hover hover:text-sw-text'
                      }`}
                    >
                      {isToggling ? (
                        <Loader2 size={10} className="animate-spin" />
                      ) : isBusiness ? (
                        'Business'
                      ) : (
                        'Personal'
                      )}
                    </button>
                  </div>
                );
              })}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
