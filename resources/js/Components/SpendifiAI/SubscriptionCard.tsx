import { useState, useRef, useEffect } from 'react';
import { Tag, X, Ban, Loader2, ExternalLink, Phone, Pencil, Check, ChevronUp, AlertTriangle, Shield, CheckCircle2, Clock } from 'lucide-react';
import { usePage } from '@inertiajs/react';
import { formatDate } from '@/utils/formatDate';
import type { Subscription } from '@/types/spendifiai';
import axios from 'axios';

interface SubscriptionCardProps {
  subscription: Subscription;
  onUpdate?: () => void;
}

const CATEGORIES = [
  'Auto', 'Education', 'Finance', 'Fitness', 'Gaming', 'Health',
  'Hosting', 'Housing', 'Insurance', 'Music', 'Personal Training',
  'Phone', 'Recreation', 'Shopping', 'Software', 'Software & SaaS',
  'Streaming', 'Utilities', 'VPN & Security', 'Other',
];

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export default function SubscriptionCard({ subscription, onUpdate }: SubscriptionCardProps) {
  const tz = (usePage().props.auth as { timezone?: string }).timezone;
  const [actionsOpen, setActionsOpen] = useState(false);
  const [loading, setLoading] = useState<string | null>(null);
  const [notes, setNotes] = useState(subscription.user_notes ?? '');
  const [category, setCategory] = useState(subscription.category ?? '');
  const [dirty, setDirty] = useState(false);
  const notesRef = useRef<HTMLTextAreaElement>(null);

  const isCancelled = subscription.status === 'cancelled';
  const isRecharged = isCancelled && !!subscription.recharged_at;
  const isConfirmedSaved = isCancelled && !subscription.recharged_at;
  const isMissedCharge = !isCancelled && !!subscription.missed_charge_at;

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

  const handleToggleEssential = async () => {
    setLoading('essential');
    try {
      await axios.patch(`/api/v1/subscriptions/${subscription.id}`, {
        is_essential: !subscription.is_essential,
      });
      onUpdate?.();
    } catch {
      // ignore
    } finally {
      setLoading(null);
    }
  };

  const merchantName = subscription.merchant_normalized || subscription.merchant_name;
  const displayNote = subscription.user_notes;

  // Card border/background based on state
  const cardClasses = isRecharged
    ? 'border-amber-300 bg-amber-50/30'
    : isConfirmedSaved
      ? 'border-emerald-200/60 bg-sw-card/60'
      : isMissedCharge
        ? 'border-slate-300 bg-slate-50/40'
        : 'border-sw-border bg-sw-card hover:border-sw-border-strong';

  return (
    <div className={`rounded-xl border p-4 transition-colors ${cardClasses}`}>

      {/* Re-charge warning banner */}
      {isRecharged && (
        <div className="flex items-center gap-2 mb-3 px-2.5 py-2 rounded-lg bg-amber-100 border border-amber-200">
          <AlertTriangle size={14} className="text-amber-600 shrink-0" />
          <div className="text-xs text-amber-800">
            <span className="font-semibold">Charged {fmt.format(Number(subscription.recharged_amount ?? subscription.amount))} </span>
            after cancellation
            {subscription.recharged_at && (
              <span className="text-amber-600"> on {formatDate(subscription.recharged_at.split('T')[0], tz)}</span>
            )}
          </div>
        </div>
      )}

      {/* Missed charge indicator */}
      {isMissedCharge && (
        <div className="flex items-center gap-2 mb-3 px-2.5 py-2 rounded-lg bg-slate-100 border border-slate-200">
          <Clock size={13} className="text-slate-500 shrink-0" />
          <div className="text-[11px] text-slate-600">
            Expected charge overdue — may have been cancelled or billing changed
          </div>
        </div>
      )}

      {/* Header: merchant + amount on same line */}
      <div className="flex items-start justify-between gap-3 mb-1.5">
        <div className="min-w-0 flex-1">
          <h3 className="text-[15px] font-semibold text-sw-text truncate leading-tight">{merchantName}</h3>
          {!actionsOpen && (
            <div className="flex items-center gap-1.5 mt-0.5 text-[11px] text-sw-dim">
              {subscription.category && (
                <span className="inline-flex items-center gap-0.5">
                  <Tag size={10} />
                  {subscription.category}
                </span>
              )}
              {!isCancelled && (
                <button
                  onClick={handleToggleEssential}
                  disabled={loading === 'essential'}
                  className={`inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-md text-[10px] font-medium transition border ${
                    subscription.is_essential
                      ? 'bg-slate-100 border-slate-300 text-slate-600'
                      : 'bg-transparent border-sw-border text-sw-dim hover:border-slate-300 hover:text-slate-500'
                  }`}
                  title={subscription.is_essential ? 'Click to mark as non-essential' : 'Click to mark as essential bill'}
                >
                  <Shield size={9} />
                  {subscription.is_essential ? 'Essential' : 'Mark essential'}
                </button>
              )}
            </div>
          )}
        </div>
        <div className="text-right shrink-0">
          {isCancelled ? (
            <div className="text-base font-bold text-sw-dim line-through">{fmt.format(Number(subscription.previous_amount ?? subscription.amount))}</div>
          ) : (
            <div className="text-base font-bold text-sw-text">{fmt.format(Number(subscription.amount))}</div>
          )}
          <div className="text-[11px] text-sw-dim">/{subscription.frequency}</div>
        </div>
      </div>

      {/* Compact details row */}
      {!actionsOpen && (
        <div className="flex items-center gap-2 mt-2 text-[11px] text-sw-dim">
          {subscription.last_charge_date && (
            <span>Last charged {formatDate(subscription.last_charge_date, tz)}</span>
          )}
          {subscription.months_active != null && subscription.months_active > 1 && (
            <>
              <span className="text-sw-border">&middot;</span>
              <span>{subscription.months_active} payments</span>
            </>
          )}
          {subscription.cancellation_difficulty && !isCancelled && (
            <>
              <span className="text-sw-border">&middot;</span>
              <span className={
                subscription.cancellation_difficulty === 'easy' ? 'text-green-500' :
                subscription.cancellation_difficulty === 'hard' ? 'text-red-400' : 'text-amber-500'
              }>
                {subscription.cancellation_difficulty === 'easy' ? 'Easy cancel' : subscription.cancellation_difficulty === 'medium' ? 'Medium' : 'Hard to cancel'}
              </span>
            </>
          )}
        </div>
      )}

      {/* User note (when collapsed) */}
      {displayNote && !actionsOpen && (
        <div className="text-[11px] text-sw-accent mt-1.5 flex items-center gap-1 truncate">
          <Pencil size={9} className="shrink-0" />
          <span className="truncate" title={displayNote}>{displayNote}</span>
        </div>
      )}

      {/* Confirmed savings badge for cancelled */}
      {isConfirmedSaved && subscription.responded_at && (
        <div className="flex items-center gap-1.5 mt-3 text-xs text-emerald-700">
          <CheckCircle2 size={13} className="text-sw-success" />
          <span>Saving {fmt.format(Number(subscription.previous_amount ?? subscription.amount))}/mo since {formatDate(subscription.responded_at.split('T')[0], tz)}</span>
        </div>
      )}

      {/* Cancellation links for active subs */}
      {!isCancelled && !actionsOpen && (subscription.cancellation_url || subscription.cancellation_phone) && (
        <div className="mt-3">
          {subscription.cancellation_url ? (
            <a
              href={subscription.cancellation_url}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center justify-center gap-1.5 w-full py-1.5 rounded-lg text-[11px] font-medium bg-sw-accent/5 border border-sw-accent/20 text-sw-accent hover:bg-sw-accent/10 transition"
            >
              <ExternalLink size={11} />
              Cancel on website
            </a>
          ) : subscription.cancellation_phone ? (
            <a
              href={`tel:${subscription.cancellation_phone}`}
              className="flex items-center justify-center gap-1.5 w-full py-1.5 rounded-lg text-[11px] font-medium bg-sw-accent/5 border border-sw-accent/20 text-sw-accent hover:bg-sw-accent/10 transition"
            >
              <Phone size={11} />
              Call {subscription.cancellation_phone}
            </a>
          ) : null}
        </div>
      )}

      {/* Action button / Review panel for active subs */}
      {!isCancelled && (
        <>
          {!actionsOpen ? (
            <button
              onClick={() => setActionsOpen(true)}
              className="w-full mt-2 py-1.5 rounded-lg text-[11px] font-medium transition text-sw-dim hover:text-sw-muted border border-transparent hover:border-sw-border"
            >
              Review &middot; Edit &middot; Cancel
            </button>
          ) : (
            <div className="space-y-2.5 pt-3 mt-3 border-t border-sw-border/50">
              {/* Notes */}
              <div>
                <label className="block text-[11px] font-medium text-sw-muted mb-1">Notes</label>
                <textarea
                  ref={notesRef}
                  value={notes}
                  onChange={(e) => handleNotesChange(e.target.value)}
                  placeholder="e.g. Wife's personal trainer"
                  rows={2}
                  maxLength={500}
                  className="w-full rounded-lg border border-sw-border bg-sw-bg text-sw-text text-xs px-2.5 py-1.5 placeholder:text-sw-dim/50 focus:outline-none focus:border-sw-accent/50 focus:ring-1 focus:ring-sw-accent/30 resize-none"
                />
              </div>

              {/* Category */}
              <div>
                <label className="block text-[11px] font-medium text-sw-muted mb-1">Category</label>
                <select
                  value={category}
                  onChange={(e) => handleCategoryChange(e.target.value)}
                  className="w-full rounded-lg border border-sw-border bg-sw-bg text-sw-text text-xs px-2.5 py-1.5 focus:outline-none focus:border-sw-accent/50 focus:ring-1 focus:ring-sw-accent/30"
                >
                  <option value="">Select...</option>
                  {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
                  {subscription.category && !CATEGORIES.includes(subscription.category) && (
                    <option value={subscription.category}>{subscription.category}</option>
                  )}
                </select>
              </div>

              {/* Save (only when dirty) */}
              {dirty && (
                <button
                  onClick={handleSave}
                  disabled={loading !== null}
                  className="w-full inline-flex items-center justify-center gap-1.5 py-2 rounded-lg text-xs font-medium bg-sw-accent/10 border border-sw-accent/30 text-sw-accent hover:bg-sw-accent/20 transition disabled:opacity-50"
                >
                  {loading === 'save' ? <Loader2 size={12} className="animate-spin" /> : <Check size={12} />}
                  Save
                </button>
              )}

              {/* Cancel / Dismiss */}
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

              {/* Close */}
              <button
                onClick={() => { setActionsOpen(false); setNotes(subscription.user_notes ?? ''); setCategory(subscription.category ?? ''); setDirty(false); }}
                className="w-full inline-flex items-center justify-center gap-1 py-1 text-[11px] text-sw-dim hover:text-sw-muted transition"
              >
                <ChevronUp size={12} /> Close
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
