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
  CheckCircle2,
  XCircle,
  ChevronDown,
  Eye,
  EyeOff,
  Info,
  ArrowRight,
  Upload,
  FileText,
} from 'lucide-react';
import PlaidLinkButton from '@/Components/SpendifiAI/PlaidLinkButton';
import Badge from '@/Components/SpendifiAI/Badge';
import ConfirmDialog from '@/Components/SpendifiAI/ConfirmDialog';
import StatCard from '@/Components/SpendifiAI/StatCard';
import ConnectionMethodChooser from '@/Components/SpendifiAI/ConnectionMethodChooser';
import StatementUploadWizard from '@/Components/SpendifiAI/StatementUploadWizard';
import UploadHistory from '@/Components/SpendifiAI/UploadHistory';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { BankAccount, StatementUploadHistory } from '@/types/spendifiai';
import { usePage } from '@inertiajs/react';

interface EmailConnection {
  id: number;
  provider: string;
  connection_type: string;
  email_address: string;
  status: string;
  last_synced_at: string | null;
  sync_status: string | null;
}

const PROVIDERS = [
  { value: 'gmail', label: 'Gmail', domain: 'gmail.com' },
  { value: 'outlook', label: 'Outlook / Hotmail / MSN', domain: 'outlook.com' },
  { value: 'yahoo', label: 'Yahoo Mail', domain: 'yahoo.com' },
  { value: 'icloud', label: 'iCloud Mail', domain: 'icloud.com' },
  { value: 'aol', label: 'AOL Mail', domain: 'aol.com' },
  { value: 'fastmail', label: 'Fastmail', domain: 'fastmail.com' },
  { value: 'other', label: 'Other (Custom IMAP)', domain: '' },
];

const PROVIDER_INSTRUCTIONS: Record<string, { title: string; passwordLabel: string; placeholder: string; steps: Array<{ text: string; link?: string }>; note?: string }> = {
  gmail: {
    title: 'Gmail — App Password Required',
    passwordLabel: 'App Password',
    placeholder: 'xxxx xxxx xxxx xxxx',
    steps: [
      { text: 'Sign in to your Google Account security settings', link: 'https://myaccount.google.com/security' },
      { text: 'Under "How you sign in to Google", make sure 2-Step Verification is turned ON', link: 'https://myaccount.google.com/signinoptions/two-step-verification' },
      { text: 'Go to App Passwords (search "App Passwords" in your Google Account if the link below doesn\'t work)', link: 'https://myaccount.google.com/apppasswords' },
      { text: 'Enter "SpendifiAI" as the app name, then click Create' },
      { text: 'Copy the 16-character password shown and paste it below — this is your App Password' },
    ],
    note: 'Your regular Gmail password will NOT work. You must use an App Password. This requires 2-Step Verification to be enabled first.',
  },
  outlook: {
    title: 'Outlook / Hotmail / MSN — App Password Required',
    passwordLabel: 'App Password',
    placeholder: 'Your Microsoft App Password',
    steps: [
      { text: 'Sign in to your Microsoft account security page', link: 'https://account.microsoft.com/security' },
      { text: 'Enable two-step verification if not already on (required for App Passwords)', link: 'https://account.microsoft.com/security/two-step-verification' },
      { text: 'Go to Advanced security options → App passwords', link: 'https://account.live.com/proofs/AppPassword' },
      { text: 'Click "Create a new app password"' },
      { text: 'Copy the generated password and paste it below' },
    ],
    note: 'Microsoft requires an App Password for IMAP access. Your regular Outlook/Hotmail password will NOT work. You must enable two-step verification first.',
  },
  yahoo: {
    title: 'Yahoo Mail — App Password Required',
    passwordLabel: 'App Password',
    placeholder: 'Your Yahoo App Password',
    steps: [
      { text: 'Sign in to your Yahoo Account security page', link: 'https://login.yahoo.com/account/security' },
      { text: 'Turn on two-step verification if not already enabled' },
      { text: 'Scroll down and click "Generate app password"' },
      { text: 'Select "Other App", name it "SpendifiAI", and click Generate' },
      { text: 'Copy the generated password and paste it below' },
    ],
    note: 'Your regular Yahoo password will NOT work. You must generate an App Password.',
  },
  icloud: {
    title: 'iCloud Mail — App-Specific Password Required',
    passwordLabel: 'App-Specific Password',
    placeholder: 'xxxx-xxxx-xxxx-xxxx',
    steps: [
      { text: 'Sign in at appleid.apple.com', link: 'https://appleid.apple.com/account/manage' },
      { text: 'Go to Sign-In and Security → App-Specific Passwords' },
      { text: 'Click the "+" to generate a new app-specific password' },
      { text: 'Name it "SpendifiAI" and click Create' },
      { text: 'Copy the generated password and paste it below' },
    ],
    note: 'You must have two-factor authentication enabled on your Apple ID. Your regular iCloud password will NOT work.',
  },
  aol: {
    title: 'AOL Mail Setup',
    passwordLabel: 'Password or App Password',
    placeholder: 'Your AOL password',
    steps: [
      { text: 'Go to your AOL Account Security page', link: 'https://login.aol.com/account/security' },
      { text: 'If you have two-step verification enabled, generate an App Password' },
      { text: 'Otherwise, you may need to enable "Allow apps that use less secure sign in"' },
      { text: 'Enter your password or App Password below' },
    ],
  },
  fastmail: {
    title: 'Fastmail Setup',
    passwordLabel: 'App Password',
    placeholder: 'Your Fastmail App Password',
    steps: [
      { text: 'Sign in to Fastmail and go to Settings → Privacy & Security → Integrations', link: 'https://app.fastmail.com/settings/security/integrations' },
      { text: 'Click "New app password"' },
      { text: 'Name it "SpendifiAI", select IMAP access, and click Generate' },
      { text: 'Copy the password and paste it below' },
    ],
  },
  other: {
    title: 'Custom Email Provider',
    passwordLabel: 'Password',
    placeholder: 'Your email password or App Password',
    steps: [
      { text: 'Enter your email address and password below' },
      { text: 'If your provider requires an App Password (most do for IMAP), generate one in your email security settings' },
      { text: 'You\'ll also need to enter your IMAP server host and port (check your provider\'s help docs)' },
    ],
    note: 'Common IMAP settings: Host is usually imap.yourprovider.com, Port is usually 993, Encryption is SSL.',
  },
};

export default function ConnectIndex() {
  const { plaid_env } = usePage().props as Record<string, unknown>;
  const { data: accounts, loading, error, refresh } = useApi<{ accounts: BankAccount[] }>('/api/v1/accounts');
  const { submit: syncPlaid, loading: syncing } = useApiPost('/api/v1/plaid/sync');
  const { submit: disconnectPlaid } = useApiPost<unknown, unknown>('', 'DELETE');
  const { submit: updatePurpose } = useApiPost<unknown, { purpose: string }>('', 'PATCH');

  // Email connections
  const { data: emailData, loading: emailLoading, refresh: refreshEmails } = useApi<{ connections: EmailConnection[] }>('/api/v1/email/connections');
  const { submit: testImapConnection, loading: testing, error: testApiError } = useApiPost<{ success: boolean; error?: string; folders?: string[] }, unknown>('/api/v1/email/test');
  const { submit: connectImap, loading: connecting, error: connectApiError } = useApiPost('/api/v1/email/connect-imap');
  const { submit: syncEmail, loading: emailSyncing } = useApiPost('/api/v1/email/sync');
  const { submit: disconnectEmail } = useApiPost<unknown, unknown>('', 'DELETE');

  const [disconnectId, setDisconnectId] = useState<number | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [emailDisconnectId, setEmailDisconnectId] = useState<number | null>(null);
  const [emailConfirmOpen, setEmailConfirmOpen] = useState(false);

  // Email form state
  const [showEmailForm, setShowEmailForm] = useState(false);
  const [selectedProvider, setSelectedProvider] = useState('gmail');
  const [emailAddress, setEmailAddress] = useState('');
  const [emailPassword, setEmailPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [customHost, setCustomHost] = useState('');
  const [customPort, setCustomPort] = useState('');
  const [customEncryption, setCustomEncryption] = useState('ssl');
  const [testResult, setTestResult] = useState<{ success: boolean; error?: string; folders?: string[] } | null>(null);
  const [connectError, setConnectError] = useState<string | null>(null);

  // Statement uploads
  const { data: uploadHistoryData, refresh: refreshUploads } = useApi<{ uploads: StatementUploadHistory[] }>('/api/v1/statements/history');
  const [showUploadWizard, setShowUploadWizard] = useState(false);
  const [connectionMode, setConnectionMode] = useState<'choose' | 'plaid' | 'upload' | null>(null);

  const accountsList = accounts?.accounts || [];
  const emailConnections = emailData?.connections || [];
  const uploadHistory = uploadHistoryData?.uploads || [];
  const connectedCount = accountsList.length;
  const uploadedCount = uploadHistory.length;
  const providerInfo = PROVIDER_INSTRUCTIONS[selectedProvider];

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

  const handleTestConnection = async () => {
    setTestResult(null);
    setConnectError(null);
    const payload: Record<string, unknown> = {
      email: emailAddress,
      password: emailPassword,
    };
    if (selectedProvider === 'other') {
      if (customHost) payload.imap_host = customHost;
      if (customPort) payload.imap_port = parseInt(customPort);
      payload.imap_encryption = customEncryption;
    }
    const result = await testImapConnection(payload);
    if (result) {
      setTestResult(result);
    } else {
      setTestResult({ success: false, error: 'Could not connect. Check your email, password, and make sure you\'re using an App Password if required.' });
    }
  };

  const handleConnectEmail = async () => {
    setConnectError(null);
    const payload: Record<string, unknown> = {
      email: emailAddress,
      password: emailPassword,
    };
    if (selectedProvider === 'other') {
      if (customHost) payload.imap_host = customHost;
      if (customPort) payload.imap_port = parseInt(customPort);
      payload.imap_encryption = customEncryption;
    }
    const result = await connectImap(payload);
    if (result) {
      setShowEmailForm(false);
      setEmailAddress('');
      setEmailPassword('');
      setTestResult(null);
      refreshEmails();
    } else {
      setConnectError('Connection failed. Please test your connection first to check your credentials.');
    }
  };

  const handleSyncEmail = async (connectionId: number) => {
    await syncEmail({ connection_id: connectionId });
    refreshEmails();
  };

  const handleEmailDisconnect = async () => {
    if (emailDisconnectId !== null) {
      await disconnectEmail(undefined, { url: `/api/v1/email/${emailDisconnectId}`, method: 'DELETE' } as never);
      setEmailConfirmOpen(false);
      setEmailDisconnectId(null);
      refreshEmails();
    }
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
          <p className="text-xs text-sw-dim mt-0.5">Link your accounts and email for automatic tracking</p>
        </div>
      }
    >
      <Head title="Connect" />

      {/* Stats */}
      <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
        <StatCard
          title="Bank Accounts"
          value={connectedCount}
          subtitle={`${connectedCount} account${connectedCount !== 1 ? 's' : ''} linked via Plaid`}
          icon={<Link2 size={18} />}
        />
        <StatCard
          title="Uploaded Statements"
          value={uploadedCount}
          subtitle={uploadedCount > 0 ? `${uploadHistory.reduce((s, u) => s + u.transactions_imported, 0)} transactions` : 'Upload PDF or CSV'}
          icon={<FileText size={18} />}
        />
        <StatCard
          title="Email Connections"
          value={emailConnections.length}
          subtitle={emailConnections.length > 0 ? `${emailConnections.filter(c => c.status === 'active').length} active` : 'Connect to scan receipts'}
          icon={<Mail size={18} />}
        />
        <StatCard
          title="Sync Status"
          value={syncing ? 'Syncing...' : 'Ready'}
          subtitle="Auto-syncs every 4 hours"
          icon={<RefreshCw size={18} />}
        />
      </div>

      {/* Section 1: Add Bank Data (Smart Chooser or Upload Wizard) */}
      {showUploadWizard ? (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6 mb-6">
          <StatementUploadWizard
            onComplete={() => {
              setShowUploadWizard(false);
              setConnectionMode(null);
              refresh();
              refreshUploads();
            }}
            onCancel={() => {
              setShowUploadWizard(false);
              setConnectionMode(null);
            }}
          />
        </div>
      ) : connectionMode === 'plaid' ? (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6 mb-6">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h3 className="text-[15px] font-semibold text-sw-text">Connect Your Bank via Plaid</h3>
              <p className="text-xs text-sw-muted mt-0.5">
                Securely link your bank accounts for automatic transaction syncing.
              </p>
            </div>
            <button
              onClick={() => setConnectionMode(null)}
              className="text-xs text-sw-muted hover:text-sw-text transition"
            >
              Back to options
            </button>
          </div>
          <div className="flex items-center gap-3">
            <PlaidLinkButton onSuccess={() => { refresh(); setConnectionMode(null); }} />
            <button
              onClick={handleSync}
              disabled={syncing || connectedCount === 0}
              className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {syncing ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}
              Sync Now
            </button>
          </div>

          {plaid_env === 'sandbox' && (
            <div className="mt-4 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4">
              <p className="text-xs font-semibold text-sw-warning mb-2">Sandbox Mode -- Test Credentials</p>
              <div className="text-xs text-sw-muted space-y-1 leading-relaxed">
                <p>1. Select <strong className="text-sw-text">First Platypus Bank</strong> from the institution list</p>
                <p>2. Username: <code className="px-1.5 py-0.5 rounded bg-sw-bg text-sw-accent font-mono">user_good</code></p>
                <p>3. Password: <code className="px-1.5 py-0.5 rounded bg-sw-bg text-sw-accent font-mono">pass_good</code></p>
                <p>4. If asked for phone verification, enter any code (e.g. <code className="px-1.5 py-0.5 rounded bg-sw-bg text-sw-accent font-mono">123456</code>)</p>
              </div>
            </div>
          )}
        </div>
      ) : (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6 mb-6">
          {connectedCount === 0 && uploadedCount === 0 ? (
            /* First-time experience: equal-weight choice */
            <ConnectionMethodChooser
              onChoosePlaid={() => setConnectionMode('plaid')}
              onChooseUpload={() => setShowUploadWizard(true)}
            />
          ) : (
            /* Returning user: compact actions */
            <div>
              <h3 className="text-[15px] font-semibold text-sw-text mb-2">Add More Data</h3>
              <p className="text-xs text-sw-muted mb-4 leading-relaxed">
                Connect another bank account or upload additional statements to get more complete analysis.
              </p>
              <div className="flex flex-wrap items-center gap-3">
                <PlaidLinkButton onSuccess={refresh} />
                <button
                  onClick={() => setShowUploadWizard(true)}
                  className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-sw-border text-sw-text text-sm font-semibold hover:bg-sw-card-hover hover:border-sw-border-strong transition"
                >
                  <Upload size={16} />
                  Upload Statement
                </button>
                <button
                  onClick={handleSync}
                  disabled={syncing || connectedCount === 0}
                  className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {syncing ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}
                  Sync Now
                </button>
              </div>

              {plaid_env === 'sandbox' && (
                <div className="mt-4 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4">
                  <p className="text-xs font-semibold text-sw-warning mb-2">Sandbox Mode -- Test Credentials</p>
                  <div className="text-xs text-sw-muted space-y-1 leading-relaxed">
                    <p>1. Select <strong className="text-sw-text">First Platypus Bank</strong> from the institution list</p>
                    <p>2. Username: <code className="px-1.5 py-0.5 rounded bg-sw-bg text-sw-accent font-mono">user_good</code></p>
                    <p>3. Password: <code className="px-1.5 py-0.5 rounded bg-sw-bg text-sw-accent font-mono">pass_good</code></p>
                    <p>4. If asked for phone verification, enter any code (e.g. <code className="px-1.5 py-0.5 rounded bg-sw-bg text-sw-accent font-mono">123456</code>)</p>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {/* Section 2: Connected Bank Accounts */}
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
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition"
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
                <div className="w-10 h-10 rounded-lg bg-sw-accent-light border border-blue-200 flex items-center justify-center shrink-0">
                  <Wifi size={18} className="text-sw-accent" />
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

                <button
                  onClick={() => {
                    setDisconnectId(account.id);
                    setConfirmOpen(true);
                  }}
                  className="p-2 rounded-lg text-sw-dim hover:text-sw-danger hover:bg-sw-danger/10 transition"
                  title="Disconnect"
                  aria-label="Disconnect account"
                >
                  <Trash2 size={14} />
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {!loading && accountsList.length === 0 && uploadedCount === 0 && !error && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-12 text-center mb-6">
          <WifiOff size={40} className="mx-auto text-sw-dim mb-3" />
          <h3 className="text-sm font-semibold text-sw-text mb-1">No accounts connected</h3>
          <p className="text-xs text-sw-muted">
            Use the options above to link your bank or upload a statement
          </p>
        </div>
      )}

      {/* Section 3: Upload History */}
      {!showUploadWizard && (
        <div className="mb-6">
          <UploadHistory
            uploads={uploadHistory}
            onUploadMore={() => setShowUploadWizard(true)}
          />
        </div>
      )}

      {/* Section 4: Email Connections */}
      <div className="rounded-2xl border border-sw-border bg-sw-card p-6 mb-6">
        <div className="flex items-center justify-between mb-2">
          <h3 className="text-[15px] font-semibold text-sw-text">Connect Your Email</h3>
          {!showEmailForm && (
            <button
              onClick={() => setShowEmailForm(true)}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition"
            >
              <Mail size={14} /> Add Email
            </button>
          )}
        </div>
        <p className="text-xs text-sw-muted mb-4 leading-relaxed">
          Scan your inbox for order confirmations and receipts from Amazon, Walmart, and more.
          This helps break down vague bank charges into individual products for better categorization and tax tracking.
        </p>

        {/* Existing email connections */}
        {emailLoading && (
          <div className="flex items-center justify-center py-6">
            <Loader2 size={20} className="animate-spin text-sw-accent" />
          </div>
        )}

        {emailConnections.length > 0 && (
          <div className="space-y-3 mb-4">
            {emailConnections.map((conn) => (
              <div
                key={conn.id}
                className="flex items-center gap-4 p-4 rounded-xl border border-sw-border bg-sw-bg"
              >
                <div className="w-10 h-10 rounded-lg bg-blue-50 border border-blue-200 flex items-center justify-center shrink-0">
                  <Mail size={18} className="text-sw-accent" />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm font-semibold text-sw-text">{conn.email_address}</span>
                    {statusBadge(conn.status)}
                    <Badge variant="neutral">{conn.connection_type.toUpperCase()}</Badge>
                  </div>
                  <div className="flex items-center gap-2 mt-1 text-[11px] text-sw-dim">
                    <span className="capitalize">{conn.provider}</span>
                    {conn.last_synced_at && (
                      <>
                        <span>&middot;</span>
                        <span>Last synced: {new Date(conn.last_synced_at).toLocaleDateString()}</span>
                      </>
                    )}
                    {conn.sync_status === 'syncing' && (
                      <span className="text-sw-accent font-medium">Syncing...</span>
                    )}
                  </div>
                </div>
                <button
                  onClick={() => handleSyncEmail(conn.id)}
                  disabled={emailSyncing || conn.sync_status === 'syncing'}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-sw-border text-sw-muted text-xs font-medium hover:text-sw-text hover:bg-sw-card-hover transition disabled:opacity-50"
                >
                  {conn.sync_status === 'syncing' ? (
                    <Loader2 size={12} className="animate-spin" />
                  ) : (
                    <RefreshCw size={12} />
                  )}
                  Sync
                </button>
                <button
                  onClick={() => {
                    setEmailDisconnectId(conn.id);
                    setEmailConfirmOpen(true);
                  }}
                  className="p-2 rounded-lg text-sw-dim hover:text-sw-danger hover:bg-sw-danger/10 transition"
                  title="Disconnect"
                  aria-label="Disconnect email"
                >
                  <Trash2 size={14} />
                </button>
              </div>
            ))}
          </div>
        )}

        {/* New email connection form */}
        {showEmailForm && (
          <div className="rounded-xl border border-sw-border bg-sw-bg p-5 space-y-4">
            <div className="flex items-center justify-between">
              <h4 className="text-sm font-semibold text-sw-text">New Email Connection</h4>
              <button
                onClick={() => {
                  setShowEmailForm(false);
                  setTestResult(null);
                  setConnectError(null);
                }}
                className="text-xs text-sw-muted hover:text-sw-text transition"
              >
                Cancel
              </button>
            </div>

            {/* Provider selector */}
            <div>
              <label className="block text-xs font-medium text-sw-muted mb-1.5">Email Provider</label>
              <div className="relative">
                <select
                  value={selectedProvider}
                  onChange={(e) => {
                    setSelectedProvider(e.target.value);
                    setTestResult(null);
                    setConnectError(null);
                  }}
                  className="w-full px-3 py-2.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm appearance-none focus:outline-none focus:border-sw-accent pr-8"
                >
                  {PROVIDERS.map((p) => (
                    <option key={p.value} value={p.value}>{p.label}</option>
                  ))}
                </select>
                <ChevronDown size={14} className="absolute right-3 top-1/2 -translate-y-1/2 text-sw-dim pointer-events-none" />
              </div>
            </div>

            {/* Email address */}
            <div>
              <label className="block text-xs font-medium text-sw-muted mb-1.5">Email Address</label>
              <input
                type="email"
                value={emailAddress}
                onChange={(e) => setEmailAddress(e.target.value)}
                placeholder="you@example.com"
                className="w-full px-3 py-2.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm placeholder:text-sw-dim focus:outline-none focus:border-sw-accent"
              />
            </div>

            {/* Setup instructions — always visible based on selected provider */}
            <div className="rounded-lg border border-blue-200 bg-blue-50/50 p-4">
              <div className="flex items-start gap-2.5">
                <Info size={16} className="text-sw-accent shrink-0 mt-0.5" />
                <div className="flex-1 min-w-0">
                  <p className="text-xs font-semibold text-sw-text mb-2.5">{providerInfo.title}</p>
                  <ol className="text-xs text-sw-muted space-y-2 list-decimal list-inside">
                    {providerInfo.steps.map((step, i) => (
                      <li key={i} className="leading-relaxed">
                        {step.link ? (
                          <span>
                            {step.text}{' '}
                            <a
                              href={step.link}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-sw-accent underline underline-offset-2 hover:text-sw-accent-hover font-medium"
                            >
                              Open &rarr;
                            </a>
                          </span>
                        ) : (
                          step.text
                        )}
                      </li>
                    ))}
                  </ol>
                  {providerInfo.note && (
                    <div className="mt-3 p-2.5 rounded-md bg-amber-50 border border-amber-200">
                      <p className="text-[11px] text-amber-700 font-medium leading-relaxed">{providerInfo.note}</p>
                    </div>
                  )}
                </div>
              </div>
            </div>

            {/* App password / password */}
            <div>
              <label className="block text-xs font-medium text-sw-muted mb-1.5">
                {providerInfo.passwordLabel}
              </label>
              <div className="relative">
                <input
                  type={showPassword ? 'text' : 'password'}
                  value={emailPassword}
                  onChange={(e) => setEmailPassword(e.target.value)}
                  placeholder={providerInfo.placeholder}
                  className="w-full px-3 py-2.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm placeholder:text-sw-dim focus:outline-none focus:border-sw-accent pr-10"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-sw-dim hover:text-sw-text transition"
                >
                  {showPassword ? <EyeOff size={14} /> : <Eye size={14} />}
                </button>
              </div>
            </div>

            {/* Custom IMAP settings */}
            {selectedProvider === 'other' && (
              <div className="space-y-3 pt-1">
                <p className="text-xs font-medium text-sw-muted">Custom IMAP Server Settings</p>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-[11px] text-sw-dim mb-1">IMAP Host</label>
                    <input
                      type="text"
                      value={customHost}
                      onChange={(e) => setCustomHost(e.target.value)}
                      placeholder="imap.example.com"
                      className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm placeholder:text-sw-dim focus:outline-none focus:border-sw-accent"
                    />
                  </div>
                  <div>
                    <label className="block text-[11px] text-sw-dim mb-1">Port</label>
                    <input
                      type="number"
                      value={customPort}
                      onChange={(e) => setCustomPort(e.target.value)}
                      placeholder="993"
                      className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm placeholder:text-sw-dim focus:outline-none focus:border-sw-accent"
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-[11px] text-sw-dim mb-1">Encryption</label>
                  <select
                    value={customEncryption}
                    onChange={(e) => setCustomEncryption(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm focus:outline-none focus:border-sw-accent"
                  >
                    <option value="ssl">SSL (recommended)</option>
                    <option value="tls">TLS</option>
                  </select>
                </div>
              </div>
            )}

            {/* Test result */}
            {testResult && (
              <div className={`rounded-lg border p-3 ${testResult.success ? 'border-emerald-200 bg-emerald-50/50' : 'border-red-200 bg-red-50/50'}`}>
                <div className="flex items-center gap-2">
                  {testResult.success ? (
                    <>
                      <CheckCircle2 size={16} className="text-sw-success" />
                      <span className="text-xs font-semibold text-sw-success">Connection successful!</span>
                    </>
                  ) : (
                    <>
                      <XCircle size={16} className="text-sw-danger" />
                      <span className="text-xs font-semibold text-sw-danger">Connection failed</span>
                    </>
                  )}
                </div>
                {testResult.error && (
                  <p className="text-[11px] text-sw-danger mt-1">{testResult.error}</p>
                )}
                {testResult.folders && testResult.folders.length > 0 && (
                  <p className="text-[11px] text-sw-muted mt-1">
                    Found folders: {testResult.folders.join(', ')}
                  </p>
                )}
              </div>
            )}

            {/* Connect / API errors */}
            {(connectError || connectApiError || testApiError) && !testResult && (
              <div className="rounded-lg border border-red-200 bg-red-50/50 p-3">
                <div className="flex items-start gap-2">
                  <XCircle size={16} className="text-sw-danger shrink-0 mt-0.5" />
                  <div>
                    <p className="text-xs font-semibold text-sw-danger">Connection Error</p>
                    <p className="text-[11px] text-sw-danger mt-1">
                      {connectError || connectApiError || testApiError}
                    </p>
                    <p className="text-[11px] text-sw-muted mt-2">
                      Make sure you are using an App Password (not your regular password) and that IMAP access is enabled for your account.
                    </p>
                  </div>
                </div>
              </div>
            )}

            {/* Action buttons */}
            <div className="flex items-center gap-3 pt-1">
              <button
                onClick={handleTestConnection}
                disabled={testing || !emailAddress || !emailPassword}
                className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {testing ? <Loader2 size={14} className="animate-spin" /> : <CheckCircle2 size={14} />}
                Test Connection
              </button>
              <button
                onClick={handleConnectEmail}
                disabled={connecting || !emailAddress || !emailPassword}
                className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {connecting ? <Loader2 size={14} className="animate-spin" /> : <ArrowRight size={14} />}
                Connect Email
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Confirm disconnect bank account */}
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

      {/* Confirm disconnect email */}
      <ConfirmDialog
        open={emailConfirmOpen}
        onConfirm={handleEmailDisconnect}
        onCancel={() => {
          setEmailConfirmOpen(false);
          setEmailDisconnectId(null);
        }}
        title="Disconnect Email"
        message="Are you sure you want to remove this email connection? Parsed order data will be preserved."
        confirmText="Disconnect"
        variant="danger"
      />
    </AuthenticatedLayout>
  );
}
