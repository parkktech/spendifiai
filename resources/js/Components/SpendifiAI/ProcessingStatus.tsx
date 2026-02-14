import { Loader2, FileSearch, Sparkles, CheckCircle2, AlertTriangle } from 'lucide-react';
import type { StatementProcessingStatus } from '@/types/spendifiai';

interface ProcessingStatusProps {
  status: StatementProcessingStatus;
}

const STATUS_CONFIG: Record<
  StatementProcessingStatus['status'],
  { icon: typeof Loader2; label: string; color: string }
> = {
  uploading: {
    icon: Loader2,
    label: 'Uploading file',
    color: 'text-sw-accent',
  },
  parsing: {
    icon: FileSearch,
    label: 'Reading document',
    color: 'text-sw-accent',
  },
  extracting: {
    icon: FileSearch,
    label: 'Extracting transactions',
    color: 'text-sw-info',
  },
  analyzing: {
    icon: Sparkles,
    label: 'AI analyzing transactions',
    color: 'text-sw-info',
  },
  complete: {
    icon: CheckCircle2,
    label: 'Processing complete',
    color: 'text-sw-success',
  },
  error: {
    icon: AlertTriangle,
    label: 'Processing failed',
    color: 'text-sw-danger',
  },
};

export default function ProcessingStatus({ status }: ProcessingStatusProps) {
  const config = STATUS_CONFIG[status.status];
  const Icon = config.icon;
  const isActive = status.status !== 'complete' && status.status !== 'error';
  const progressPercent = Math.min(Math.max(status.progress, 0), 100);

  return (
    <div className="space-y-6">
      {/* Main status display */}
      <div className="flex flex-col items-center text-center py-6">
        <div
          className={`w-16 h-16 rounded-2xl flex items-center justify-center mb-4 ${
            status.status === 'complete'
              ? 'bg-sw-success-light border border-emerald-200'
              : status.status === 'error'
                ? 'bg-sw-danger-light border border-red-200'
                : 'bg-sw-accent-light border border-blue-200'
          }`}
        >
          <Icon
            size={28}
            className={`${config.color} ${isActive ? 'animate-spin' : ''}`}
            style={
              isActive && config.icon !== Loader2
                ? { animation: 'none' }
                : undefined
            }
          />
          {isActive && config.icon !== Loader2 && (
            <style>{`
              @keyframes pulse-gentle {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.6; }
              }
            `}</style>
          )}
        </div>

        <h3 className="text-base font-semibold text-sw-text">{config.label}</h3>
        <p className="text-sm text-sw-muted mt-1 max-w-md">{status.message}</p>

        {status.transactions_found !== undefined && status.transactions_found > 0 && (
          <p className="text-xs text-sw-accent font-medium mt-2">
            {status.transactions_found} transactions found so far
          </p>
        )}
      </div>

      {/* Progress bar */}
      {isActive && (
        <div className="space-y-2">
          <div className="w-full h-2 bg-sw-surface rounded-full overflow-hidden">
            <div
              className="h-full bg-sw-accent rounded-full transition-all duration-500 ease-out"
              style={{ width: `${progressPercent}%` }}
            />
          </div>
          <div className="flex items-center justify-between">
            <span className="text-[11px] text-sw-dim">
              {status.current_page && status.total_pages
                ? `Page ${status.current_page} of ${status.total_pages}`
                : 'Processing...'}
            </span>
            <span className="text-[11px] text-sw-dim font-medium">
              {Math.round(progressPercent)}%
            </span>
          </div>
        </div>
      )}

      {/* Completed progress bar */}
      {status.status === 'complete' && (
        <div className="w-full h-2 bg-sw-surface rounded-full overflow-hidden">
          <div className="h-full bg-sw-success rounded-full w-full" />
        </div>
      )}

      {/* Processing steps timeline */}
      {isActive && (
        <div className="space-y-3 pt-2">
          {[
            { key: 'uploading', label: 'Upload file to server' },
            { key: 'parsing', label: 'Read and validate document format' },
            { key: 'extracting', label: 'Extract transaction rows' },
            { key: 'analyzing', label: 'AI categorization and duplicate detection' },
          ].map((step) => {
            const stepOrder = ['uploading', 'parsing', 'extracting', 'analyzing'];
            const currentIndex = stepOrder.indexOf(status.status);
            const stepIndex = stepOrder.indexOf(step.key);
            const isDone = stepIndex < currentIndex;
            const isCurrentStep = stepIndex === currentIndex;

            return (
              <div key={step.key} className="flex items-center gap-3">
                <div
                  className={`w-6 h-6 rounded-full flex items-center justify-center shrink-0 transition-all ${
                    isDone
                      ? 'bg-sw-accent text-white'
                      : isCurrentStep
                        ? 'bg-sw-accent-light border-2 border-sw-accent text-sw-accent'
                        : 'bg-sw-surface border border-sw-border text-sw-dim'
                  }`}
                >
                  {isDone ? (
                    <CheckCircle2 size={14} />
                  ) : isCurrentStep ? (
                    <Loader2 size={12} className="animate-spin" />
                  ) : (
                    <span className="text-[10px] font-medium">{stepIndex + 1}</span>
                  )}
                </div>
                <span
                  className={`text-xs ${
                    isDone
                      ? 'text-sw-text font-medium'
                      : isCurrentStep
                        ? 'text-sw-accent font-medium'
                        : 'text-sw-dim'
                  }`}
                >
                  {step.label}
                </span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
