import { useState } from 'react';
import { CheckCircle2, TrendingDown, Shield, Loader2 } from 'lucide-react';

interface ActionResponsePanelProps {
  originalAmount: number;
  itemTitle: string;
  onConfirm: (response: { response_type: 'cancelled' | 'reduced' | 'kept'; new_amount?: number; reason?: string }) => void;
  onCancel: () => void;
  loading: boolean;
}

type ResponseOption = 'cancelled' | 'reduced' | 'kept';

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export default function ActionResponsePanel({ originalAmount, itemTitle, onConfirm, onCancel, loading }: ActionResponsePanelProps) {
  const [selected, setSelected] = useState<ResponseOption | null>(null);
  const [newAmount, setNewAmount] = useState<string>('');
  const [reason, setReason] = useState('');

  const parsedNewAmount = parseFloat(newAmount) || 0;
  const reducedSavings = Math.max(originalAmount - parsedNewAmount, 0);

  const handleConfirm = () => {
    if (!selected) return;

    const response: { response_type: ResponseOption; new_amount?: number; reason?: string } = {
      response_type: selected,
    };

    if (selected === 'reduced') {
      response.new_amount = parsedNewAmount;
    }

    if (selected === 'kept') {
      response.reason = reason || undefined;
    }

    onConfirm(response);
  };

  const confirmLabel = (): string => {
    switch (selected) {
      case 'cancelled':
        return `Confirm Cancellation — Save ${fmt.format(originalAmount)}/mo`;
      case 'reduced':
        return parsedNewAmount > 0
          ? `Confirm Reduction — Save ${fmt.format(reducedSavings)}/mo`
          : 'Enter new amount to confirm';
      case 'kept':
        return 'Mark as Kept';
      default:
        return 'Select an option';
    }
  };

  const options: { key: ResponseOption; label: string; sublabel: string; Icon: typeof CheckCircle2; selectedBorder: string; selectedBg: string; iconColor: string }[] = [
    {
      key: 'cancelled',
      label: 'Cancelled it',
      sublabel: `Saving the full ${fmt.format(originalAmount)}/mo`,
      Icon: CheckCircle2,
      selectedBorder: 'border-emerald-400',
      selectedBg: 'bg-emerald-50',
      iconColor: 'text-emerald-600',
    },
    {
      key: 'reduced',
      label: 'Reduced my plan',
      sublabel: 'I downgraded to a cheaper option',
      Icon: TrendingDown,
      selectedBorder: 'border-blue-400',
      selectedBg: 'bg-blue-50',
      iconColor: 'text-blue-600',
    },
    {
      key: 'kept',
      label: "Can't eliminate",
      sublabel: 'I still need this expense',
      Icon: Shield,
      selectedBorder: 'border-slate-400',
      selectedBg: 'bg-slate-50',
      iconColor: 'text-slate-500',
    },
  ];

  return (
    <div className="mt-3 rounded-xl border border-sw-border bg-sw-card p-4 space-y-3">
      <div className="text-xs font-medium text-sw-muted">
        How did you handle <span className="text-sw-text font-semibold">{itemTitle}</span>?
      </div>

      {/* Response options */}
      <div className="space-y-2">
        {options.map(({ key, label, sublabel, Icon, selectedBorder, selectedBg, iconColor }) => {
          const isSelected = selected === key;
          return (
            <div key={key}>
              <button
                type="button"
                onClick={() => setSelected(key)}
                className={`w-full flex items-center gap-3 p-3 rounded-xl border transition ${
                  isSelected
                    ? `${selectedBorder} ${selectedBg}`
                    : 'border-sw-border hover:border-sw-border-strong hover:bg-sw-surface'
                }`}
              >
                <div className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 ${
                  isSelected ? selectedBg : 'bg-sw-surface'
                }`}>
                  <Icon size={16} className={isSelected ? iconColor : 'text-sw-dim'} />
                </div>
                <div className="text-left flex-1 min-w-0">
                  <div className={`text-sm font-medium ${isSelected ? 'text-sw-text' : 'text-sw-muted'}`}>{label}</div>
                  <div className="text-[11px] text-sw-dim">{sublabel}</div>
                </div>
                <div className={`w-4 h-4 rounded-full border-2 shrink-0 flex items-center justify-center ${
                  isSelected ? `${selectedBorder} ${selectedBg}` : 'border-sw-border'
                }`}>
                  {isSelected && <div className={`w-2 h-2 rounded-full ${
                    key === 'cancelled' ? 'bg-emerald-500' : key === 'reduced' ? 'bg-blue-500' : 'bg-slate-400'
                  }`} />}
                </div>
              </button>

              {/* Reduced: amount input */}
              {isSelected && key === 'reduced' && (
                <div className="mt-2 ml-11 space-y-2">
                  <div className="flex items-center gap-2">
                    <span className="text-sm text-sw-muted font-medium">$</span>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      value={newAmount}
                      onChange={(e) => setNewAmount(e.target.value)}
                      placeholder="0.00"
                      className="w-28 px-3 py-1.5 text-sm rounded-lg border border-sw-border bg-white text-sw-text focus:outline-none focus:ring-2 focus:ring-sw-accent focus:border-transparent"
                    />
                    <span className="text-sm text-sw-dim">/mo</span>
                  </div>
                  {parsedNewAmount > 0 && (
                    <div className="text-xs text-blue-600 font-medium">
                      Saving {fmt.format(reducedSavings)}/mo (was {fmt.format(originalAmount)}/mo)
                    </div>
                  )}
                </div>
              )}

              {/* Kept: reason input */}
              {isSelected && key === 'kept' && (
                <div className="mt-2 ml-11">
                  <input
                    type="text"
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    placeholder="e.g., Need it for work"
                    className="w-full px-3 py-1.5 text-sm rounded-lg border border-sw-border bg-white text-sw-text placeholder:text-sw-placeholder focus:outline-none focus:ring-2 focus:ring-sw-accent focus:border-transparent"
                  />
                </div>
              )}
            </div>
          );
        })}
      </div>

      {/* Confirm button */}
      <button
        type="button"
        onClick={handleConfirm}
        disabled={!selected || loading || (selected === 'reduced' && parsedNewAmount <= 0)}
        className="w-full px-4 py-2.5 rounded-xl bg-sw-accent hover:bg-sw-accent-hover text-white text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
      >
        {loading ? <Loader2 size={14} className="animate-spin" /> : null}
        {confirmLabel()}
      </button>

      {/* Cancel link */}
      <div className="text-center">
        <button
          type="button"
          onClick={onCancel}
          className="text-xs text-sw-dim hover:text-sw-muted transition"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}
