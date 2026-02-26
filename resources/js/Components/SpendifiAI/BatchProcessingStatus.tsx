import { useState } from 'react';
import { Loader2, CheckCircle2, AlertTriangle, ChevronDown } from 'lucide-react';
import type { BatchStatusResponse } from '@/types/spendifiai';

interface BatchProcessingStatusProps {
  batchStatus: BatchStatusResponse;
  onContinue: () => void;
}

function getFileType(fileName: string | null): 'PDF' | 'CSV' {
  if (!fileName) return 'PDF';
  return fileName.toLowerCase().endsWith('.csv') ? 'CSV' : 'PDF';
}

export default function BatchProcessingStatus({
  batchStatus,
  onContinue,
}: BatchProcessingStatusProps) {
  const [expandedError, setExpandedError] = useState<number | null>(null);
  const { uploads, summary } = batchStatus;

  const progressPercent =
    summary.total > 0
      ? Math.round(((summary.completed + summary.failed) / summary.total) * 100)
      : 0;

  const isProcessing = !summary.all_done;
  const hasFailures = summary.failed > 0;
  const successfulCount = summary.completed;

  return (
    <div className="space-y-5">
      {/* Progress header */}
      <div className="flex flex-col items-center text-center py-4">
        <div
          className={`w-16 h-16 rounded-2xl flex items-center justify-center mb-4 ${
            summary.all_done
              ? hasFailures
                ? 'bg-amber-50 border border-amber-200'
                : 'bg-sw-success-light border border-emerald-200'
              : 'bg-sw-accent-light border border-blue-200'
          }`}
        >
          {isProcessing ? (
            <Loader2 size={28} className="text-sw-accent animate-spin" />
          ) : hasFailures ? (
            <AlertTriangle size={28} className="text-sw-warning" />
          ) : (
            <CheckCircle2 size={28} className="text-sw-success" />
          )}
        </div>

        <h3 className="text-base font-semibold text-sw-text">
          {isProcessing
            ? `Processing ${summary.total} statement${summary.total !== 1 ? 's' : ''}...`
            : hasFailures
              ? `${successfulCount} of ${summary.total} files processed`
              : 'All files processed'}
        </h3>

        <p className="text-sm text-sw-muted mt-1">
          {isProcessing ? (
            <>
              <span className="text-sw-text font-medium">
                {summary.completed + summary.failed}
              </span>{' '}
              of {summary.total} complete
              {summary.processing > 0 && (
                <span>
                  {' '}&middot; {summary.processing} in progress
                </span>
              )}
            </>
          ) : (
            <>
              {summary.total_extracted.toLocaleString()} transaction
              {summary.total_extracted !== 1 ? 's' : ''} found across{' '}
              {successfulCount} file{successfulCount !== 1 ? 's' : ''}
            </>
          )}
        </p>
      </div>

      {/* Overall progress bar */}
      <div className="space-y-1.5">
        <div className="w-full h-2 bg-sw-surface rounded-full overflow-hidden">
          <div
            className={`h-full rounded-full transition-all duration-700 ease-out ${
              summary.all_done
                ? hasFailures
                  ? 'bg-amber-400'
                  : 'bg-sw-success'
                : 'bg-sw-accent'
            }`}
            style={{ width: `${progressPercent}%` }}
          />
        </div>
        <div className="flex items-center justify-between">
          <span className="text-[11px] text-sw-dim">
            {isProcessing
              ? 'PDF statements take 2\u20133 minutes each'
              : `${summary.completed} succeeded${hasFailures ? `, ${summary.failed} failed` : ''}`}
          </span>
          <span className="text-[11px] text-sw-dim font-medium">{progressPercent}%</span>
        </div>
      </div>

      {/* Per-file status list */}
      <div className="rounded-xl border border-sw-border bg-sw-bg overflow-hidden">
        <div className="max-h-64 overflow-y-auto divide-y divide-sw-border/50">
          {uploads.map((upload) => {
            const fileType = getFileType(upload.file_name);
            const isComplete = upload.status === 'complete';
            const isError = upload.status === 'error';
            const isActive = !isComplete && !isError;
            const isExpanded = expandedError === upload.upload_id;

            return (
              <div key={upload.upload_id}>
                <div
                  className={`flex items-center gap-2.5 px-3 py-2 ${
                    isError ? 'cursor-pointer hover:bg-sw-danger-light/30' : ''
                  }`}
                  onClick={() => {
                    if (isError) {
                      setExpandedError(isExpanded ? null : upload.upload_id);
                    }
                  }}
                >
                  {/* File type badge */}
                  <span
                    className={`shrink-0 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide ${
                      fileType === 'PDF'
                        ? 'bg-red-50 text-red-600 border border-red-200'
                        : 'bg-emerald-50 text-emerald-600 border border-emerald-200'
                    }`}
                  >
                    {fileType}
                  </span>

                  {/* File name */}
                  <span
                    className={`text-xs truncate flex-1 ${
                      isError
                        ? 'text-sw-danger'
                        : isComplete
                          ? 'text-sw-text'
                          : 'text-sw-muted'
                    }`}
                  >
                    {upload.file_name ?? `Upload #${upload.upload_id}`}
                  </span>

                  {/* Transaction count for completed files */}
                  {isComplete && upload.total_extracted !== undefined && (
                    <span className="shrink-0 text-[10px] font-medium text-sw-muted bg-sw-surface px-1.5 py-0.5 rounded">
                      {upload.total_extracted} txns
                    </span>
                  )}

                  {/* Status icon */}
                  <div className="shrink-0 w-5 h-5 flex items-center justify-center">
                    {isActive && (
                      <Loader2 size={14} className="text-sw-accent animate-spin" />
                    )}
                    {isComplete && (
                      <CheckCircle2 size={14} className="text-sw-success" />
                    )}
                    {isError && (
                      <div className="flex items-center gap-0.5">
                        <AlertTriangle size={14} className="text-sw-danger" />
                        <ChevronDown
                          size={10}
                          className={`text-sw-danger transition-transform ${
                            isExpanded ? 'rotate-180' : ''
                          }`}
                        />
                      </div>
                    )}
                  </div>
                </div>

                {/* Expanded error message */}
                {isError && isExpanded && upload.error_message && (
                  <div className="px-3 pb-2">
                    <p className="text-[11px] text-sw-danger/80 bg-sw-danger-light rounded-lg px-2.5 py-1.5 leading-relaxed">
                      {upload.error_message}
                    </p>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Completion actions */}
      {summary.all_done && (
        <div className="space-y-3">
          {/* Failure warning */}
          {hasFailures && (
            <div className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50/50 p-3">
              <AlertTriangle size={14} className="text-sw-warning shrink-0 mt-0.5" />
              <p className="text-xs text-sw-muted leading-relaxed">
                <span className="font-semibold text-sw-warning">
                  {summary.failed} file{summary.failed !== 1 ? 's' : ''} failed
                </span>{' '}
                to process. You can continue with the {successfulCount} successful
                file{successfulCount !== 1 ? 's' : ''} or try uploading the failed
                ones again later.
              </p>
            </div>
          )}

          {/* Continue button */}
          {successfulCount > 0 && (
            <div className="flex justify-center pt-1">
              <button
                onClick={onContinue}
                className="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition"
              >
                <CheckCircle2 size={14} />
                Review {summary.total_extracted.toLocaleString()} Transactions
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
