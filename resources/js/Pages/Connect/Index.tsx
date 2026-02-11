import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
  Link2,
  Mail,
  RefreshCw,
  AlertTriangle,
  Loader2,
  Wifi,
  WifiOff,
  Trash2,
} from 'lucide-react';
import PlaidLinkButton from '@/Components/SpendWise/PlaidLinkButton';
import Badge from '@/Components/SpendWise/Badge';
import ConfirmDialog from '@/Components/SpendWise/ConfirmDialog';
import StatCard from '@/Components/SpendWise/StatCard';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { BankAccount, BankConnection } from '@/types/spendwise';

export default function ConnectIndex() {
  const { data: accounts, loading, error, refresh } = useApi<{ data: BankAccount[] }>('/api/v1/accounts');
  const { submit: syncPlaid, loading: syncing } = useApiPost('/api/v1/plaid/sync');
  const { submit: disconnectPlaid } = useApiPost<unknown, unknown>('', 'DELETE');
  const { submit: updatePurpose } = useApiPost<unknown, { purpose: string }>('', 'PATCH');
  const { submit: connectEmail, loading: emailConnecting } = useApiPost('/api/v1/email/connect/gmail');

  const [disconnectId, setDisconnectId] = useState<number | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);

  const accountsList = accounts?.data || [];
  const connectedCount = accountsList.length;

  const handleSync = async () => {
    await syncPlaid();
    refresh();
  };

  const handleDisconnect = async () => {
    if (disconnectId !== null) {
      await disconnectPlaid(undefined, { url: `/api/v1/plaid/${disconnectId}`, method: 'DELETE' } as never);
      setConfirmOpen(false);
      setDisconnectId(null);
      refresh();
    }
  };

  const handlePurposeChange = async (accountId: number, purpose: string) => {
    await updatePurpose({ purpose }, { url: `/api/v1/accounts/${accountId}/purpose`, method: 'PATCH' } as never);
    refresh();
  };

  const statusBadge = (status: string) => {
    switch (status) {
      case 'active':
      case 'connected':
        return <Badge variant="success">Connected</Badge>;
      case 'error':
        return <Badge variant="danger">Error</Badge>;
      case 'reauth_required':
        return <Badge variant="warning">Re-auth Required</Badge>;
      default:
        return <Badge variant="neutral">{status}</Badge>;
    }
  };

  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text tracking-tight">Connections</h1>
          <p className="text-xs text-sw-dim mt-0.5">Link your accounts for automatic tracking</p>
        </div>
      }
    >
      <Head title="Connect" />

      {/* Stats */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <StatCard
          title="Connected Accounts"
          value={connectedCount}
          subtitle={`${connectedCount} account${connectedCount !== 1 ? 's' : ''} linked`}
          icon={<Link2 size={18} />}
        />
        <StatCard
          title="Email Connections"
          value="0"
          subtitle="Connect to scan receipts"
          icon={<Mail size={18} />}
        />
        <StatCard
          title="Sync Status"
          value={syncing ? 'Syncing...' : 'Ready'}
          subtitle="Auto-syncs every 6 hours"
          icon={<RefreshCw size={18} />}
        />
      </div>

      {/* Section 1: Connect Your Bank */}
      <div className="rounded-2xl border border-sw-border bg-sw-card p-6 mb-6">
        <h3 className="text-[15px] font-semibold text-sw-text mb-2">Connect Your Bank</h3>
        <p className="text-xs text-sw-muted mb-4 leading-relaxed">
          Securely link your bank accounts through Plaid to automatically import transactions.
          We use bank-level encryption and never store your banking credentials.
        </p>
        <div className="flex items-center gap-3">
          <PlaidLinkButton onSuccess={refresh} />
          <button
            onClick={handleSync}
            disabled={syncing || connectedCount === 0}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {syncing ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}
            Sync Now
          </button>
        </div>
      </div>

      {/* Section 2: Connected Accounts */}
      {loading && (
        <div className="flex items-center justify-center py-12">
          <Loader2 size={24} className="animate-spin text-sw-accent" />
        </div>
      )}

      {error && (
        <div className="rounded-2xl border border-sw-danger/30 bg-sw-danger/5 p-6 text-center mb-6">
          <AlertTriangle size={24} className="mx-auto text-sw-danger mb-2" />
          <p className="text-sm text-sw-text mb-3">{error}</p>
          <button
            onClick={refresh}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-sw-bg text-sm font-semibold hover:bg-sw-accent-hover transition"
          >
            <RefreshCw size={14} /> Retry
          </button>
        </div>
      )}

      {!loading && accountsList.length > 0 && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6 mb-6">
          <h3 className="text-[15px] font-semibold text-sw-text mb-4">Connected Accounts</h3>
          <div className="space-y-3">
            {accountsList.map((account) => (
              <div
                key={account.id}
                className="flex items-center gap-4 p-4 rounded-xl border border-sw-border bg-sw-bg"
              >
                <div className="w-10 h-10 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center shrink-0">
                  <Wifi size={18} className="text-blue-400" />
                </div>

                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm font-semibold text-sw-text">{account.name}</span>
                    {account.mask && (
                      <span className="text-xs text-sw-dim">****{account.mask}</span>
                    )}
                    <Badge variant="neutral">{account.type}</Badge>
                  </div>
                  <div className="flex items-center gap-2 mt-1">
                    {account.institution_name && (
                      <span className="text-[11px] text-sw-dim">{account.institution_name}</span>
                    )}
                    {account.current_balance !== null && (
                      <span className="text-[11px] text-sw-muted font-medium">
                        ${Number(account.current_balance).toLocaleString('en-US', { minimumFractionDigits: 2 })}
                      </span>
                    )}
                  </div>
                </div>

                {/* Purpose selector */}
                <select
                  value={account.purpose || 'personal'}
                  onChange={(e) => handlePurposeChange(account.id, e.target.value)}
                  className="px-2.5 py-1.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-xs focus:outline-none focus:border-sw-accent"
                >
                  <option value="personal">Personal</option>
                  <option value="business">Business</option>
                  <option value="mixed">Mixed</option>
                  <option value="investment">Investment</option>
                </select>

                {/* Disconnect */}
                <button
                  onClick={() => {
                    setDisconnectId(account.id);
                    setConfirmOpen(true);
                  }}
                  className="p-2 rounded-lg text-sw-dim hover:text-sw-danger hover:bg-sw-danger/10 transition"
                  title="Disconnect"
                >
                  <Trash2 size={14} />
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {!loading && accountsList.length === 0 && !error && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-12 text-center mb-6">
          <WifiOff size={40} className="mx-auto text-sw-dim mb-3" />
          <h3 className="text-sm font-semibold text-sw-text mb-1">No accounts connected</h3>
          <p className="text-xs text-sw-muted">
            Use the "Connect Your Bank" button above to get started
          </p>
        </div>
      )}

      {/* Section 3: Email Connection */}
      <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
        <h3 className="text-[15px] font-semibold text-sw-text mb-2">Connect Your Email</h3>
        <p className="text-xs text-sw-muted mb-4 leading-relaxed">
          Scan your inbox for order confirmations from Amazon, Walmart, and more.
          This helps break down vague bank charges into individual products for better categorization and tax tracking.
        </p>
        <button
          onClick={() => connectEmail()}
          disabled={emailConnecting}
          className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition disabled:opacity-50"
        >
          {emailConnecting ? <Loader2 size={14} className="animate-spin" /> : <Mail size={14} />}
          Connect Gmail
        </button>
      </div>

      {/* Confirm disconnect dialog */}
      <ConfirmDialog
        open={confirmOpen}
        onConfirm={handleDisconnect}
        onCancel={() => {
          setConfirmOpen(false);
          setDisconnectId(null);
        }}
        title="Disconnect Account"
        message="Are you sure you want to disconnect this account? Transaction history will be preserved, but no new transactions will be imported."
        confirmText="Disconnect"
        variant="danger"
      />
    </AuthenticatedLayout>
  );
}
