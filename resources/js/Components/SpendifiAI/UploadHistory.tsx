import { FileText, Calendar, Hash, Upload } from 'lucide-react';
import Badge from './Badge';
import type { StatementUploadHistory } from '@/types/spendifiai';

interface UploadHistoryProps {
  uploads: StatementUploadHistory[];
  onUploadMore: () => void;
}

function formatDate(dateStr: string): string {
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(dateStr));
}

function formatDateShort(dateStr: string): string {
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
  }).format(new Date(dateStr));
}

export default function UploadHistory({ uploads, onUploadMore }: UploadHistoryProps) {
  if (uploads.length === 0) {
    return null;
  }

  const totalImported = uploads.reduce((sum, u) => sum + u.transactions_imported, 0);

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className="text-[15px] font-semibold text-sw-text">Upload History</h3>
          <p className="text-xs text-sw-muted mt-0.5">
            {totalImported.toLocaleString()} transactions imported from{' '}
            {uploads.length} upload{uploads.length !== 1 ? 's' : ''}
          </p>
        </div>
        <button
          onClick={onUploadMore}
          className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition"
        >
          <Upload size={14} />
          Upload More
        </button>
      </div>

      <div className="space-y-3">
        {uploads.map((upload) => (
          <div
            key={upload.id}
            className="flex items-center gap-4 p-4 rounded-xl border border-sw-border bg-sw-bg"
          >
            <div className="w-10 h-10 rounded-lg bg-sw-surface border border-sw-border flex items-center justify-center shrink-0">
              <FileText size={18} className="text-sw-muted" />
            </div>

            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 flex-wrap">
                <span className="text-sm font-semibold text-sw-text truncate">
                  {upload.file_name}
                </span>
                <Badge variant="neutral">{upload.account_type}</Badge>
              </div>
              <div className="flex items-center gap-3 mt-1 text-[11px] text-sw-dim flex-wrap">
                <span>{upload.bank_name}</span>
                <span className="flex items-center gap-1">
                  <Calendar size={10} />
                  {formatDateShort(upload.date_range.from)} &mdash;{' '}
                  {formatDateShort(upload.date_range.to)}
                </span>
                <span className="flex items-center gap-1">
                  <Hash size={10} />
                  {upload.transactions_imported} imported
                  {upload.duplicates_skipped > 0 && (
                    <span className="text-sw-warning">
                      , {upload.duplicates_skipped} skipped
                    </span>
                  )}
                </span>
              </div>
            </div>

            <div className="text-right shrink-0">
              <span className="text-[11px] text-sw-dim">
                {formatDate(upload.uploaded_at)}
              </span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
