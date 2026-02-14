import { CreditCard, Calendar, Tag } from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import type { Subscription } from '@/types/spendifiai';

interface SubscriptionCardProps {
  subscription: Subscription;
  onCancel?: (id: number) => void;
}

const statusVariant: Record<string, 'success' | 'neutral' | 'danger' | 'warning'> = {
  active: 'success',
  inactive: 'neutral',
  cancelled: 'danger',
  paused: 'warning',
  unused: 'warning',
};

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export default function SubscriptionCard({ subscription, onCancel }: SubscriptionCardProps) {
  const isUnused = subscription.status === 'unused' || subscription.status === 'inactive';
  const variant = statusVariant[subscription.status] ?? 'neutral';

  return (
    <div
      className={`rounded-lg border p-4 transition-colors ${
        isUnused
          ? 'border-sw-warning bg-sw-card hover:bg-sw-card-hover'
          : 'border-sw-border bg-sw-card hover:bg-sw-card-hover'
      }`}
    >
      {/* Top: merchant name + status badge */}
      <div className="flex items-start justify-between mb-3">
        <h3 className="text-lg font-semibold text-sw-text truncate pr-2">
          {subscription.merchant_name}
        </h3>
        <div className="flex items-center gap-2 shrink-0">
          {isUnused && <Badge variant="warning">Unused</Badge>}
          <Badge variant={variant}>
            {subscription.status.charAt(0).toUpperCase() + subscription.status.slice(1)}
          </Badge>
        </div>
      </div>

      {/* Middle: amount and annual cost */}
      <div className="mb-3">
        <div className="text-xl font-bold text-sw-text">
          {fmt.format(subscription.amount)}
          <span className="text-sm font-normal text-sw-dim">/{subscription.frequency}</span>
        </div>
        <div className="text-xs text-sw-muted mt-0.5">
          {fmt.format(subscription.annual_cost)}/year
        </div>
      </div>

      {/* Bottom: category, last charge, next expected */}
      <div className="flex flex-wrap items-center gap-2 text-xs text-sw-dim mb-3">
        {subscription.category && (
          <span className="inline-flex items-center gap-1">
            <Tag size={12} />
            {subscription.category}
          </span>
        )}
        {subscription.last_charge_date && (
          <span className="inline-flex items-center gap-1">
            <Calendar size={12} />
            Last: {subscription.last_charge_date}
          </span>
        )}
        {subscription.next_expected_date && (
          <span className="inline-flex items-center gap-1">
            <CreditCard size={12} />
            Next: {subscription.next_expected_date}
          </span>
        )}
      </div>

      {/* Cancel/review button */}
      {onCancel && (
        <button
          onClick={() => onCancel(subscription.id)}
          className={`w-full py-2 rounded-lg text-xs font-medium transition ${
            isUnused
              ? 'bg-sw-danger/10 border border-sw-danger/30 text-sw-danger hover:bg-sw-danger/20'
              : 'bg-transparent border border-sw-border text-sw-muted hover:text-sw-text hover:border-sw-muted'
          }`}
        >
          {isUnused ? 'Cancel Subscription' : 'Review'}
        </button>
      )}
    </div>
  );
}
