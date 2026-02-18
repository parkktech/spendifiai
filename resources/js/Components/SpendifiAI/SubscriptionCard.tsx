import { useState } from 'react';
import { CreditCard, Calendar, Tag, Hash, X, Ban, Loader2 } from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import type { Subscription } from '@/types/spendifiai';
import axios from 'axios';

interface SubscriptionCardProps {
  subscription: Subscription;
  onUpdate?: () => void;
}

const statusVariant: Record<string, 'success' | 'neutral' | 'danger' | 'warning'> = {
  active: 'success',
  inactive: 'neutral',
  cancelled: 'danger',
  paused: 'warning',
  unused: 'warning',
};

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export default function SubscriptionCard({ subscription, onUpdate }: SubscriptionCardProps) {
  const [actionsOpen, setActionsOpen] = useState(false);
  const [loading, setLoading] = useState<string | null>(null);
  const isUnused = subscription.status === 'unused' || subscription.status === 'inactive';
  const isCancelled = subscription.status === 'cancelled';
  const variant = statusVariant[subscription.status] ?? 'neutral';

  const handleCancel = async () => {
    setLoading('cancel');
    try {
      await axios.post(`/api/v1/subscriptions/${subscription.id}/respond`, {
        response_type: 'cancelled',
      });
      onUpdate?.();
    } catch {
      // ignore
    } finally {
      setLoading(null);
      setActionsOpen(false);
    }
  };

  const handleDismiss = async () => {
    setLoading('dismiss');
    try {
      await axios.delete(`/api/v1/subscriptions/${subscription.id}`);
      onUpdate?.();
    } catch {
      // ignore
    } finally {
      setLoading(null);
      setActionsOpen(false);
    }
  };

  return (
    <div
      className={`rounded-lg border p-4 transition-colors ${
        isCancelled
          ? 'border-sw-border/50 bg-sw-card/60 opacity-70'
          : isUnused
            ? 'border-sw-warning bg-sw-card hover:bg-sw-card-hover'
            : 'border-sw-border bg-sw-card hover:bg-sw-card-hover'
      }`}
    >
      {/* Top: merchant name + status badge */}
      <div className="flex items-start justify-between mb-3">
        <h3 className="text-lg font-semibold text-sw-text truncate pr-2">
          {subscription.merchant_normalized || subscription.merchant_name}
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

      {/* Details: category, payments, dates */}
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-sw-dim mb-3">
        {subscription.category && (
          <span className="inline-flex items-center gap-1">
            <Tag size={12} />
            {subscription.category}
          </span>
        )}
        {subscription.months_active != null && (
          <span className="inline-flex items-center gap-1">
            <Hash size={12} />
            {subscription.months_active} payment{subscription.months_active !== 1 ? 's' : ''}
          </span>
        )}
        {subscription.first_charge_date && (
          <span className="inline-flex items-center gap-1">
            <Calendar size={12} />
            Since {formatDate(subscription.first_charge_date)}
          </span>
        )}
        {subscription.last_charge_date && (
          <span className="inline-flex items-center gap-1">
            <CreditCard size={12} />
            Last: {formatDate(subscription.last_charge_date)}
          </span>
        )}
      </div>

      {/* Action buttons */}
      {!isCancelled && (
        <>
          {!actionsOpen ? (
            <button
              onClick={() => setActionsOpen(true)}
              className={`w-full py-2 rounded-lg text-xs font-medium transition ${
                isUnused
                  ? 'bg-sw-danger/10 border border-sw-danger/30 text-sw-danger hover:bg-sw-danger/20'
                  : 'bg-transparent border border-sw-border text-sw-muted hover:text-sw-text hover:border-sw-muted'
              }`}
            >
              {isUnused ? 'Cancel Subscription' : 'Review'}
            </button>
          ) : (
            <div className="flex gap-2">
              <button
                onClick={handleCancel}
                disabled={loading !== null}
                className="flex-1 inline-flex items-center justify-center gap-1.5 py-2 rounded-lg text-xs font-medium bg-sw-danger/10 border border-sw-danger/30 text-sw-danger hover:bg-sw-danger/20 transition disabled:opacity-50"
              >
                {loading === 'cancel' ? <Loader2 size={12} className="animate-spin" /> : <X size={12} />}
                Mark Cancelled
              </button>
              <button
                onClick={handleDismiss}
                disabled={loading !== null}
                className="flex-1 inline-flex items-center justify-center gap-1.5 py-2 rounded-lg text-xs font-medium bg-sw-card border border-sw-border text-sw-muted hover:text-sw-text hover:border-sw-muted transition disabled:opacity-50"
              >
                {loading === 'dismiss' ? <Loader2 size={12} className="animate-spin" /> : <Ban size={12} />}
                Not a Subscription
              </button>
            </div>
          )}
        </>
      )}

      {/* Cancelled indicator */}
      {isCancelled && subscription.responded_at && (
        <div className="text-xs text-sw-dim text-center py-1">
          Cancelled {formatDate(subscription.responded_at.split('T')[0])}
        </div>
      )}
    </div>
  );
}
