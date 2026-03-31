import { useState, useEffect } from 'react';
import { ChevronDown, ChevronUp, CheckCircle, Loader2, AlertCircle, Eye, Download, ExternalLink, X } from 'lucide-react';
import { Link } from '@inertiajs/react';
import axios from 'axios';
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
    case 'splitting':
      return <Badge variant="warning">Splitting</Badge>;
    case 'split':
      return <Badge variant="info">Split</Badge>;
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

function isViewable(mimeType: string): boolean {
  return mimeType === 'application/pdf' || mimeType.startsWith('image/');
}

/** Fullscreen document viewer modal — fetches file via axios with auth */
function DocumentViewer({ doc, onClose }: { doc: TaxDocument; onClose: () => void }) {
  const [blobUrl, setBlobUrl] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let revoke: string | null = null;

    axios
      .get(`/api/v1/tax-vault/documents/${doc.id}/stream`, { responseType: 'blob' })
      .then((res) => {
        const url = URL.createObjectURL(res.data);
        revoke = url;
        setBlobUrl(url);
      })
      .catch(() => setError('Could not load document'));

    return () => {
      if (revoke) URL.revokeObjectURL(revoke);
    };
  }, [doc.id]);

  // Close on Escape key
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [onClose]);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm"
      onClick={onClose}
    >
      <div
        className="relative w-full max-w-5xl mx-4 max-h-[90vh] bg-sw-card rounded-xl shadow-2xl overflow-hidden flex flex-col"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-3 border-b border-sw-border bg-sw-surface shrink-0">
          <div className="flex items-center gap-3 min-w-0">
            <span className="text-sm font-semibold text-sw-text truncate">
              {doc.original_filename}
            </span>
            {doc.category_label && (
              <Badge variant="info">{doc.category_label}</Badge>
            )}
            <span className="text-[10px] text-sw-dim">{formatSize(doc.file_size)}</span>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg text-sw-muted hover:text-sw-danger hover:bg-sw-danger-light transition"
            aria-label="Close viewer"
          >
            <X size={18} />
          </button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-auto bg-neutral-100 min-h-0">
          {error ? (
            <div className="flex items-center justify-center min-h-[60vh]">
              <p className="text-sm text-sw-danger">{error}</p>
            </div>
          ) : !blobUrl ? (
            <div className="flex items-center justify-center min-h-[60vh]">
              <Loader2 size={24} className="animate-spin text-sw-accent" />
            </div>
          ) : doc.mime_type === 'application/pdf' ? (
            <iframe
              src={blobUrl}
              className="w-full h-full min-h-[75vh]"
              title={doc.original_filename}
            />
          ) : (
            <div className="flex items-center justify-center p-4 min-h-[60vh]">
              <img
                src={blobUrl}
                alt={doc.original_filename}
                className="max-w-full max-h-[80vh] object-contain rounded-lg shadow-lg"
              />
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

export default function DocumentCard({ category, isExpanded, onToggle }: DocumentCardProps) {
  const [viewingDoc, setViewingDoc] = useState<TaxDocument | null>(null);

  return (
    <>
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
                      <Link
                        href={`/vault/documents/${doc.id}`}
                        className="text-xs font-medium text-sw-text truncate hover:text-sw-accent hover:underline transition"
                      >
                        {doc.original_filename}
                      </Link>
                      <span className="text-[10px] text-sw-dim shrink-0">
                        {formatSize(doc.file_size)}
                      </span>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <span className="text-[10px] text-sw-dim">
                        {formatDate(doc.created_at)}
                      </span>
                      {statusBadge(doc)}
                      {doc.status === 'ready' && (
                        <Link
                          href={`/vault/documents/${doc.id}`}
                          className="p-1 rounded text-sw-muted hover:text-sw-accent hover:bg-sw-accent-light transition"
                          aria-label={`Open ${doc.original_filename}`}
                          title="Open details"
                        >
                          <ExternalLink size={14} />
                        </Link>
                      )}
                      {doc.status === 'ready' && isViewable(doc.mime_type) && (
                        <button
                          onClick={() => setViewingDoc(doc)}
                          className="p-1 rounded text-sw-muted hover:text-sw-accent hover:bg-sw-accent-light transition"
                          aria-label={`View ${doc.original_filename}`}
                          title="Quick view"
                        >
                          <Eye size={14} />
                        </button>
                      )}
                      {doc.status === 'ready' && (
                        <button
                          onClick={() => {
                            axios.get(`/api/v1/tax-vault/documents/${doc.id}/stream`, { responseType: 'blob' })
                              .then((res) => {
                                const url = URL.createObjectURL(res.data);
                                const a = document.createElement('a');
                                a.href = url;
                                a.download = doc.original_filename;
                                a.click();
                                URL.revokeObjectURL(url);
                              });
                          }}
                          className="p-1 rounded text-sw-muted hover:text-sw-accent hover:bg-sw-accent-light transition"
                          aria-label={`Download ${doc.original_filename}`}
                          title="Download"
                        >
                          <Download size={14} />
                        </button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Document viewer modal */}
      {viewingDoc && (
        <DocumentViewer doc={viewingDoc} onClose={() => setViewingDoc(null)} />
      )}
    </>
  );
}
