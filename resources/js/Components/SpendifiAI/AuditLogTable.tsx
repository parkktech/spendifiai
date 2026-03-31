import type { TaxVaultAuditEntry } from '@/types/spendifiai';

interface AuditLogTableProps {
  entries: TaxVaultAuditEntry[];
  isLoading?: boolean;
}

const ACTION_LABELS: Record<string, string> = {
  'document.uploaded': 'Document Uploaded',
  'document.deleted': 'Document Deleted',
  'document.downloaded': 'Document Downloaded',
  'document.classified': 'Document Classified',
  'document.extracted': 'Data Extracted',
  'document.shared': 'Document Shared',
  'document.updated': 'Document Updated',
};

function formatAction(action: string): string {
  return ACTION_LABELS[action] || action.replace(/[._]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDateTime(dateStr: string): string {
  try {
    return new Date(dateStr).toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    });
  } catch {
    return dateStr;
  }
}

function SkeletonRow() {
  return (
    <tr>
      <td className="px-4 py-3"><div className="h-3 w-32 bg-sw-border rounded animate-pulse" /></td>
      <td className="px-4 py-3"><div className="h-3 w-24 bg-sw-border rounded animate-pulse" /></td>
      <td className="px-4 py-3"><div className="h-3 w-36 bg-sw-border rounded animate-pulse" /></td>
    </tr>
  );
}

export default function AuditLogTable({ entries, isLoading = false }: AuditLogTableProps) {
  const sorted = [...entries].sort(
    (a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime(),
  );

  return (
    <div className="overflow-x-auto rounded-lg border border-sw-border">
      <table className="w-full text-left">
        <thead>
          <tr className="bg-sw-bg border-b border-sw-border">
            <th className="px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wider">
              Action
            </th>
            <th className="px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wider">
              User
            </th>
            <th className="px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wider">
              Date / Time
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-sw-border">
          {isLoading ? (
            <>
              <SkeletonRow />
              <SkeletonRow />
              <SkeletonRow />
            </>
          ) : sorted.length === 0 ? (
            <tr>
              <td colSpan={3} className="px-4 py-8 text-center text-sm text-sw-dim">
                No audit entries yet.
              </td>
            </tr>
          ) : (
            sorted.map((entry) => (
              <tr key={entry.id} className="hover:bg-sw-card-hover transition-colors">
                <td className="px-4 py-3 text-sm text-sw-text font-medium">
                  {formatAction(entry.action)}
                </td>
                <td className="px-4 py-3 text-sm text-sw-muted">
                  {entry.user.name}
                </td>
                <td className="px-4 py-3 text-sm text-sw-dim">
                  {formatDateTime(entry.created_at)}
                </td>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}
