import { useState, useRef, useEffect } from 'react';
import { CreditCard, Calendar, Tag, Hash, X, Ban, Loader2, ExternalLink, Phone, Pencil, Check, ChevronUp } from 'lucide-react';
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

const difficultyColors: Record<string, string> = {
  easy: 'bg-green-500/10 text-green-400 border-green-500/30',
  medium: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30',
  hard: 'bg-red-500/10 text-red-400 border-red-500/30',
};

const CATEGORIES = [
  'Auto', 'Education', 'Finance', 'Fitness', 'Gaming', 'Health',
  'Hosting', 'Housing', 'Insurance', 'Music', 'Personal Training',
  'Phone', 'Recreation', 'Shopping', 'Software', 'Software & SaaS',
  'Streaming', 'Utilities', 'VPN & Security', 'Other',
];

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export default function SubscriptionCard({ subscription, onUpdate }: SubscriptionCardProps) {
  const [actionsOpen, setActionsOpen] = useState(false);
  const [loading, setLoading] = useState<string | null>(null);
  const [notes, setNotes] = useState(subscription.user_notes ?? '');
  const [category, setCategory] = useState(subscription.category ?? '');
  const [dirty, setDirty] = useState(false);
  const notesRef = useRef<HTMLTextAreaElement>(null);
  const isUnused = subscription.status === 'unused' || subscription.status === 'inactive';
  const isCancelled = subscription.status === 'cancelled';
  const variant = statusVariant[subscription.status] ?? 'neutral';

  // Sync local state when subscription prop changes (after refresh)
  useEffect(() => {
    setNotes(subscription.user_notes ?? '');
    setCategory(subscription.category ?? '');
    setDirty(false);
  }, [subscription.id, subscription.user_notes, subscription.category]);

  const handleNotesChange = (val: string) => {
    setNotes(val);
    setDirty(val !== (subscription.user_notes ?? '') || category !== (subscription.category ?? ''));
  };

  const handleCategoryChange = (val: string) => {
    setCategory(val);
    setDirty(notes !== (subscription.user_notes ?? '') || val !== (subscription.category ?? ''));
  };

  const handleSave = async () => {
    setLoading('save');
    try {
      await axios.patch(`/api/v1/subscriptions/${subscription.id}`, {
        user_notes: notes || null,
        category: category || null,
      });
      setDirty(false);
      onUpdate?.();
    } catch {
      // ignore
    } finally {
      setLoading(null);
    }
  };

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

  // Display text: user_notes first, then description if different from merchant
  const displayNote = subscription.user_notes;
  const displayDesc = subscription.description && subscription.description !== subscription.merchant_name
    ? subscription.description
    : null;

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
          {subscription.cancellation_difficulty && (
            <span className={`inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded border ${difficultyColors[subscription.cancellation_difficulty]}`}>
              {subscription.cancellation_difficulty === 'easy' ? 'Easy Cancel' : subscription.cancellation_difficulty === 'medium' ? 'Medium' : 'Hard to Cancel'}
            </span>
          )}
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
        {displayNote && !actionsOpen && (
          <div className="text-[11px] text-sw-accent mt-1.5 flex items-center gap-1">
            <Pencil size={10} className="shrink-0" />
            <span className="truncate" title={displayNote}>{displayNote}</span>
          </div>
        )}
        {!displayNote && displayDesc && !actionsOpen && (
          <div className="text-[11px] text-sw-dim mt-1 truncate" title={displayDesc}>
            {displayDesc}
          </div>
        )}
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

      {/* Cancellation link */}
      {subscription.cancellation_url && !isCancelled && (
        <a
          href={subscription.cancellation_url}
          target="_blank"
          rel="noopener noreferrer"
          className="flex items-center justify-center gap-2 w-full py-2 mb-2 rounded-lg text-xs font-medium bg-sw-accent/10 border border-sw-accent/30 text-sw-accent hover:bg-sw-accent/20 transition"
        >
          <ExternalLink size={12} />
          Cancel on website
        </a>
      )}
      {!subscription.cancellation_url && subscription.cancellation_phone && !isCancelled && (
        <a
          href={`tel:${subscription.cancellation_phone}`}
          className="flex items-center justify-center gap-2 w-full py-2 mb-2 rounded-lg text-xs font-medium bg-sw-accent/10 border border-sw-accent/30 text-sw-accent hover:bg-sw-accent/20 transition"
        >
          <Phone size={12} />
          Call to cancel: {subscription.cancellation_phone}
        </a>
      )}

      {/* Action buttons / Review panel */}
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
            <div className="space-y-3 pt-1 border-t border-sw-border/50 mt-1">
              {/* Notes field */}
              <div>
                <label className="block text-[11px] font-medium text-sw-muted mb-1">
                  My Notes
                </label>
                <textarea
                  ref={notesRef}
                  value={notes}
                  onChange={(e) => handleNotesChange(e.target.value)}
                  placeholder="Add a personal note... e.g. Wife's personal trainer"
                  rows={2}
                  maxLength={500}
                  className="w-full rounded-md border border-sw-border bg-sw-bg text-sw-text text-xs px-2.5 py-1.5 placeholder:text-sw-dim/50 focus:outline-none focus:border-sw-accent/50 focus:ring-1 focus:ring-sw-accent/30 resize-none"
                />
              </div>

              {/* Category selector */}
              <div>
                <label className="block text-[11px] font-medium text-sw-muted mb-1">
                  Category
                </label>
                <select
                  value={category}
                  onChange={(e) => handleCategoryChange(e.target.value)}
                  className="w-full rounded-md border border-sw-border bg-sw-bg text-sw-text text-xs px-2.5 py-1.5 focus:outline-none focus:border-sw-accent/50 focus:ring-1 focus:ring-sw-accent/30"
                >
                  <option value="">Select category...</option>
                  {CATEGORIES.map((c) => (
                    <option key={c} value={c}>{c}</option>
                  ))}
                  {/* Include current category if not in the list */}
                  {subscription.category && !CATEGORIES.includes(subscription.category) && (
                    <option value={subscription.category}>{subscription.category}</option>
                  )}
                </select>
              </div>

              {/* Save button (only when dirty) */}
              {dirty && (
                <button
                  onClick={handleSave}
                  disabled={loading !== null}
                  className="w-full inline-flex items-center justify-center gap-1.5 py-2 rounded-lg text-xs font-medium bg-sw-accent/10 border border-sw-accent/30 text-sw-accent hover:bg-sw-accent/20 transition disabled:opacity-50"
                >
                  {loading === 'save' ? <Loader2 size={12} className="animate-spin" /> : <Check size={12} />}
                  Save Changes
                </button>
              )}

              {/* Cancel / Dismiss / Close actions */}
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
                  Not a Sub
                </button>
              </div>

              {/* Collapse button */}
              <button
                onClick={() => { setActionsOpen(false); setNotes(subscription.user_notes ?? ''); setCategory(subscription.category ?? ''); setDirty(false); }}
                className="w-full inline-flex items-center justify-center gap-1 py-1.5 text-[11px] text-sw-dim hover:text-sw-muted transition"
              >
                <ChevronUp size={12} />
                Close
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
