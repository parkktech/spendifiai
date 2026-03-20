import { useState, useEffect } from 'react';
import { FileText, RefreshCw, Loader2, CheckCircle2, XCircle, AlertTriangle, Calendar, ChevronDown, ChevronUp, Database } from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import { useApi, useApiPost } from '@/hooks/useApi';

interface PlaidStatementRecord {
  id: number;
  month: number;
  year: number;
  status: string;
  total_extracted: number;
  duplicates_found: number;
  transactions_imported: number;
  date_range_from: string | null;
  date_range_to: string | null;
  created_at: string | null;
}

interface PlaidStatementsResponse {
  statements: PlaidStatementRecord[];
  refresh_status: string | null;
  statements_supported: boolean | null;
  last_refreshed_at: string | null;
  oldest_data_date: string | null;
}

interface Props {
  connectionId: number;
  institutionName: string;
  statementsSupported: boolean | null;
  statementsRefreshStatus: string | null;
}

const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const statusBadge = (status: string) => {
  switch (status) {
    case 'complete':
      return <Badge variant="success">Complete</Badge>;
    case 'downloading':
    case 'parsing':
      return <Badge variant="info">Processing</Badge>;
    case 'pending':
      return <Badge variant="neutral">Pending</Badge>;
    case 'error':
      return <Badge variant="danger">Error</Badge>;
    default:
      return <Badge variant="neutral">{status}</Badge>;
  }
};

/** Format a date string as "Jan 15, 2025" */
const formatDate = (dateStr: string): string => {
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

/** Get the max allowed start date (2 years before today) */
const getMinStartDate = (): string => {
  const d = new Date();
  d.setFullYear(d.getFullYear() - 2);
  return d.toISOString().split('T')[0];
};

export default function PlaidStatementsPanel({ connectionId, institutionName, statementsSupported, statementsRefreshStatus }: Props) {
  const { data, loading, refresh } = useApi<PlaidStatementsResponse>(
    `/api/v1/plaid/${connectionId}/statements`,
    { immediate: true }
  );
  const { submit: triggerRefresh, loading: refreshing } = useApiPost(`/api/v1/plaid/${connectionId}/statements/refresh`);

  const [pollingActive, setPollingActive] = useState(false);
  const [showImportBackward, setShowImportBackward] = useState(false);
  const [importStartDate, setImportStartDate] = useState('');
  const [importError, setImportError] = useState('');

  const statements = data?.statements || [];
  const refreshStatus = data?.refresh_status ?? statementsRefreshStatus;
  const isSupported = data?.statements_supported ?? statementsSupported;
  const oldestDataDate = data?.oldest_data_date ?? null;

  // Set default import start date when oldest data loads
  useEffect(() => {
    if (oldestDataDate && !importStartDate) {
      // Default to 6 months before the oldest data
      const oldest = new Date(oldestDataDate + 'T00:00:00');
      oldest.setMonth(oldest.getMonth() - 6);
      const minAllowed = new Date(getMinStartDate());
      const startDate = oldest < minAllowed ? minAllowed : oldest;
      setImportStartDate(startDate.toISOString().split('T')[0]);
    }
  }, [oldestDataDate, importStartDate]);

  // Poll while refreshing
  useEffect(() => {
    if (refreshStatus === 'refreshing' || pollingActive) {
      const interval = setInterval(() => {
        refresh();
      }, 5000);
      return () => clearInterval(interval);
    }
  }, [refreshStatus, pollingActive, refresh]);

  // Stop polling when ready
  useEffect(() => {
    if (refreshStatus === 'ready' || refreshStatus === 'failed') {
      setPollingActive(false);
    }
  }, [refreshStatus]);

  if (isSupported === false) {
    return null;
  }

  const handleRefresh = async () => {
    try {
      await triggerRefresh();
      setPollingActive(true);
      refresh();
    } catch {
      // Error handled by useApiPost
    }
  };

  const handleImportBackward = async () => {
    setImportError('');

    if (!importStartDate) {
      setImportError('Please select a start date.');
      return;
    }

    if (!oldestDataDate) {
      setImportError('Unable to determine oldest data date.');
      return;
    }

    const start = new Date(importStartDate + 'T00:00:00');
    const end = new Date(oldestDataDate + 'T00:00:00');
    const minAllowed = new Date(getMinStartDate());

    if (start < minAllowed) {
      setImportError('Start date cannot be more than 2 years ago (Plaid limit).');
      return;
    }

    if (start >= end) {
      setImportError('Start date must be before your oldest existing data.');
      return;
    }

    try {
      await triggerRefresh({
        start_date: importStartDate,
        end_date: oldestDataDate,
      });
      setPollingActive(true);
      setShowImportBackward(false);
      refresh();
    } catch {
      setImportError('Failed to initiate import. Please try again.');
    }
  };

  const totalImported = statements.reduce((sum, s) => sum + s.transactions_imported, 0);
  const isProcessing = refreshStatus === 'refreshing' || statements.some(s => ['downloading', 'parsing', 'pending'].includes(s.status));

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card p-6 mb-6">
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2">
          <FileText size={18} className="text-sw-accent" />
          <h3 className="text-[15px] font-semibold text-sw-text">Bank Statements</h3>
          <span className="text-xs text-sw-dim">{institutionName}</span>
        </div>
        <button
          onClick={handleRefresh}
          disabled={refreshing || refreshStatus === 'refreshing'}
          className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
        >
          {refreshing || refreshStatus === 'refreshing' ? (
            <><Loader2 size={13} className="animate-spin" /> Refreshing...</>
          ) : (
            <><RefreshCw size={13} /> Download Statements</>
          )}
        </button>
      </div>

      {/* Oldest data indicator + Import further back */}
      {oldestDataDate && (
        <div className="rounded-xl border border-sw-border bg-sw-bg p-3 mb-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Database size={14} className="text-sw-muted" />
              <span className="text-xs text-sw-text-secondary">
                Oldest data: <strong className="text-sw-text">{formatDate(oldestDataDate)}</strong>
              </span>
            </div>
            <button
              onClick={() => setShowImportBackward(!showImportBackward)}
              disabled={refreshing || refreshStatus === 'refreshing'}
              className="inline-flex items-center gap-1 text-xs text-sw-accent hover:text-sw-accent-hover font-medium transition disabled:opacity-50"
            >
              Import further back
              {showImportBackward ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
            </button>
          </div>

          {showImportBackward && (
            <div className="mt-3 pt-3 border-t border-sw-border">
              <p className="text-[11px] text-sw-muted mb-2">
                Select a start date to import statements before your existing data (up to 2 years back).
              </p>
              <div className="flex items-end gap-3">
                <div className="flex-1">
                  <label className="block text-[11px] text-sw-muted mb-1">From</label>
                  <input
                    type="date"
                    value={importStartDate}
                    onChange={(e) => {
                      setImportStartDate(e.target.value);
                      setImportError('');
                    }}
                    min={getMinStartDate()}
                    max={oldestDataDate}
                    className="w-full rounded-lg border border-sw-border bg-sw-card px-3 py-1.5 text-xs text-sw-text focus:outline-none focus:ring-2 focus:ring-sw-accent/30 focus:border-sw-accent"
                  />
                </div>
                <div className="flex-1">
                  <label className="block text-[11px] text-sw-muted mb-1">To (oldest data)</label>
                  <input
                    type="date"
                    value={oldestDataDate}
                    disabled
                    className="w-full rounded-lg border border-sw-border bg-sw-surface px-3 py-1.5 text-xs text-sw-dim cursor-not-allowed"
                  />
                </div>
                <button
                  onClick={handleImportBackward}
                  disabled={refreshing || refreshStatus === 'refreshing'}
                  className="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 whitespace-nowrap"
                >
                  {refreshing ? (
                    <><Loader2 size={12} className="animate-spin" /> Importing...</>
                  ) : (
                    <>Import</>
                  )}
                </button>
              </div>
              {importError && (
                <p className="text-[11px] text-sw-danger mt-1.5">{importError}</p>
              )}
            </div>
          )}
        </div>
      )}

      {isProcessing && (
        <div className="flex items-center gap-2 p-3 rounded-lg bg-sw-info/5 border border-sw-info/20 mb-4">
          <Loader2 size={14} className="animate-spin text-sw-info" />
          <span className="text-xs text-sw-info font-medium">Downloading and processing statements...</span>
        </div>
      )}

      {loading ? (
        <div className="flex items-center justify-center py-8">
          <Loader2 size={20} className="animate-spin text-sw-accent" />
        </div>
      ) : statements.length === 0 ? (
        <div className="text-center py-6">
          <Calendar size={28} className="mx-auto text-sw-dim mb-2" />
          <p className="text-xs text-sw-muted">No statements downloaded yet. Click "Download Statements" to get started.</p>
        </div>
      ) : (
        <>
          <div className="flex items-center gap-4 mb-3 text-xs text-sw-muted">
            <span>{statements.length} statement{statements.length !== 1 ? 's' : ''}</span>
            <span>{totalImported} transaction{totalImported !== 1 ? 's' : ''} imported</span>
            {data?.last_refreshed_at && (
              <span>Last refresh: {new Date(data.last_refreshed_at).toLocaleDateString()}</span>
            )}
          </div>
          <div className="space-y-2">
            {statements.map((stmt) => (
              <div
                key={stmt.id}
                className="flex items-center gap-3 p-3 rounded-xl border border-sw-border bg-sw-bg"
              >
                <div className="w-8 h-8 rounded-lg bg-sw-accent-light border border-blue-200 flex items-center justify-center shrink-0">
                  <FileText size={14} className="text-sw-accent" />
                </div>
                <div className="flex-1 min-w-0">
                  <span className="text-sm font-medium text-sw-text">
                    {MONTH_NAMES[stmt.month - 1]} {stmt.year}
                  </span>
                  {stmt.status === 'complete' && (
                    <div className="flex items-center gap-2 mt-0.5">
                      <span className="text-[11px] text-sw-dim">
                        {stmt.transactions_imported} imported
                      </span>
                      {stmt.duplicates_found > 0 && (
                        <span className="text-[11px] text-sw-warning">
                          {stmt.duplicates_found} duplicates
                        </span>
                      )}
                    </div>
                  )}
                </div>
                {statusBadge(stmt.status)}
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  );
}
