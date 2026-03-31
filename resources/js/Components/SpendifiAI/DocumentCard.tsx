import { ChevronDown, ChevronUp, CheckCircle, Loader2, AlertCircle, Download } from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import type { VaultCategoryCard, TaxDocument } from '@/types/spendifiai';

interface DocumentCardProps {
  category: VaultCategoryCard;
  isExpanded: boolean;
  onToggle: () => void;
}

function statusBadge(doc: TaxDocument) {
  switch (doc.status) {
    case 'ready':
      return <Badge variant="success">Ready</Badge>;
    case 'classifying':
    case 'extracting':
      return <Badge variant="warning">Processing</Badge>;
    case 'failed':
      return <Badge variant="danger">Failed</Badge>;
    case 'upload':
      return <Badge variant="neutral">Uploading</Badge>;
    default:
      return <Badge variant="neutral">{doc.status}</Badge>;
  }
}

function overallStatus(statuses: VaultCategoryCard['statuses']) {
  if (statuses.failed > 0) {
    return (
      <span className="flex items-center gap-1 text-sw-danger">
        <AlertCircle size={14} />
        <span className="text-xs font-medium">{statuses.failed} failed</span>
      </span>
    );
  }
  if (statuses.processing > 0) {
    return (
      <span className="flex items-center gap-1 text-sw-warning">
        <Loader2 size={14} className="animate-spin" />
        <span className="text-xs font-medium">{statuses.processing} processing</span>
      </span>
    );
  }
  if (statuses.ready > 0) {
    return (
      <span className="flex items-center gap-1 text-sw-success">
        <CheckCircle size={14} />
        <span className="text-xs font-medium">All ready</span>
      </span>
    );
  }
  return null;
}

function formatDate(dateStr: string): string {
  try {
    return new Date(dateStr).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  } catch {
    return dateStr;
  }
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function DocumentCard({ category, isExpanded, onToggle }: DocumentCardProps) {
  return (
    <div className="bg-sw-card rounded-lg border border-sw-border hover:border-sw-accent transition overflow-hidden">
      {/* Header (clickable) */}
      <button
        onClick={onToggle}
        className="w-full flex items-center justify-between p-4 cursor-pointer text-left"
      >
        <div className="flex items-center gap-3 min-w-0">
          <span className="text-sm font-bold text-sw-text truncate">{category.label}</span>
          <span className="shrink-0 bg-sw-accent/10 text-sw-accent text-[11px] font-bold px-2 py-0.5 rounded-full">
            {category.count}
          </span>
        </div>
        <div className="flex items-center gap-3 shrink-0">
          {overallStatus(category.statuses)}
          {isExpanded ? (
            <ChevronUp size={16} className="text-sw-muted" />
          ) : (
            <ChevronDown size={16} className="text-sw-muted" />
          )}
        </div>
      </button>

      {/* Accordion body */}
      {isExpanded && (
        <div className="border-t border-sw-border px-4 py-2">
          {category.documents.length === 0 ? (
            <p className="text-xs text-sw-dim py-3 text-center">No documents uploaded yet</p>
          ) : (
            <div className="divide-y divide-sw-border">
              {category.documents.map((doc) => (
                <div
                  key={doc.id}
                  className="flex items-center justify-between py-2.5 gap-3"
                >
                  <div className="flex items-center gap-3 min-w-0 flex-1">
                    <span className="text-xs font-medium text-sw-text truncate">
                      {doc.original_filename}
                    </span>
                    <span className="text-[10px] text-sw-dim shrink-0">
                      {formatSize(doc.file_size)}
                    </span>
                  </div>
                  <div className="flex items-center gap-3 shrink-0">
                    <span className="text-[10px] text-sw-dim">
                      {formatDate(doc.created_at)}
                    </span>
                    {statusBadge(doc)}
                    {doc.signed_url && doc.status === 'ready' && (
                      <a
                        href={doc.signed_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="p-1 rounded text-sw-muted hover:text-sw-accent transition"
                        aria-label={`Download ${doc.original_filename}`}
                      >
                        <Download size={14} />
                      </a>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
