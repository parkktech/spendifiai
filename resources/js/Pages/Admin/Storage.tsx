import { useEffect, useState, useRef, useCallback } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { HardDrive, Cloud, RefreshCw, CheckCircle, XCircle, Eye, EyeOff, Loader2 } from 'lucide-react';
import StatCard from '@/Components/SpendifiAI/StatCard';
import ConfirmDialog from '@/Components/SpendifiAI/ConfirmDialog';
import type { StorageConfig } from '@/types/spendifiai';
import axios from 'axios';

const AWS_REGIONS = [
  { value: 'us-east-1', label: 'US East (N. Virginia)' },
  { value: 'us-east-2', label: 'US East (Ohio)' },
  { value: 'us-west-1', label: 'US West (N. California)' },
  { value: 'us-west-2', label: 'US West (Oregon)' },
  { value: 'ca-central-1', label: 'Canada (Central)' },
  { value: 'eu-west-1', label: 'EU (Ireland)' },
  { value: 'eu-west-2', label: 'EU (London)' },
  { value: 'eu-central-1', label: 'EU (Frankfurt)' },
  { value: 'ap-southeast-1', label: 'Asia Pacific (Singapore)' },
  { value: 'ap-southeast-2', label: 'Asia Pacific (Sydney)' },
  { value: 'ap-northeast-1', label: 'Asia Pacific (Tokyo)' },
];

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}

export default function AdminStorage() {
  const [config, setConfig] = useState<StorageConfig | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedDriver, setSelectedDriver] = useState<'local' | 's3'>('local');

  // S3 form state
  const [bucket, setBucket] = useState('');
  const [region, setRegion] = useState('us-east-1');
  const [accessKey, setAccessKey] = useState('');
  const [secretKey, setSecretKey] = useState('');
  const [showSecret, setShowSecret] = useState(false);

  // Connection test state
  const [testStatus, setTestStatus] = useState<'idle' | 'testing' | 'success' | 'failed'>('idle');
  const [testMessage, setTestMessage] = useState('');

  // Migration state
  const [showMigrateConfirm, setShowMigrateConfirm] = useState(false);
  const [migrating, setMigrating] = useState(false);
  const [migrationStatus, setMigrationStatus] = useState<StorageConfig['migration_progress']>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Saving state
  const [saving, setSaving] = useState(false);
  const [saveMessage, setSaveMessage] = useState('');

  const fetchConfig = useCallback(() => {
    axios.get<StorageConfig>('/api/v1/admin/storage')
      .then((res) => {
        setConfig(res.data);
        setSelectedDriver(res.data.driver);
        setMigrationStatus(res.data.migration_progress);
        if (res.data.migration_progress?.status === 'running') {
          startPolling();
        }
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    fetchConfig();
    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
    };
  }, [fetchConfig]);

  const startPolling = () => {
    if (pollRef.current) clearInterval(pollRef.current);
    pollRef.current = setInterval(() => {
      axios.get<StorageConfig['migration_progress']>('/api/v1/admin/storage/migration-status')
        .then((res) => {
          setMigrationStatus(res.data);
          if (res.data?.status !== 'running') {
            if (pollRef.current) clearInterval(pollRef.current);
            pollRef.current = null;
            setMigrating(false);
            fetchConfig();
          }
        })
        .catch(() => {
          if (pollRef.current) clearInterval(pollRef.current);
          pollRef.current = null;
          setMigrating(false);
        });
    }, 2000);
  };

  const handleDriverChange = (driver: 'local' | 's3') => {
    if (migrationStatus?.status === 'running') return;
    setSelectedDriver(driver);
    setTestStatus('idle');
    setTestMessage('');
    setSaveMessage('');

    if (driver === 'local') {
      axios.put('/api/v1/admin/storage', { driver: 'local' })
        .then(() => fetchConfig())
        .catch(() => {});
    }
  };

  const handleTestConnection = () => {
    setTestStatus('testing');
    setTestMessage('');
    axios.post('/api/v1/admin/storage/test', {
      bucket,
      region,
      access_key: accessKey,
      secret_key: secretKey,
    })
      .then(() => {
        setTestStatus('success');
        setTestMessage('Connection successful');
      })
      .catch((err) => {
        setTestStatus('failed');
        setTestMessage(err.response?.data?.message || 'Connection failed');
      });
  };

  const handleSaveConfig = () => {
    setSaving(true);
    setSaveMessage('');
    axios.put('/api/v1/admin/storage', {
      driver: 's3',
      bucket,
      region,
      access_key: accessKey,
      secret_key: secretKey,
    })
      .then(() => {
        setSaveMessage('Configuration saved successfully');
        fetchConfig();
      })
      .catch((err) => {
        setSaveMessage(err.response?.data?.message || 'Failed to save configuration');
      })
      .finally(() => setSaving(false));
  };

  const handleMigrate = () => {
    setShowMigrateConfirm(false);
    setMigrating(true);
    axios.post('/api/v1/admin/storage/migrate')
      .then(() => {
        startPolling();
      })
      .catch((err) => {
        setMigrating(false);
        setMigrationStatus({
          total: 0,
          migrated: 0,
          status: 'failed',
          error: err.response?.data?.message || 'Migration failed to start',
        });
      });
  };

  const isMigrationRunning = migrationStatus?.status === 'running' || migrating;
  const targetDriver = selectedDriver === 'local' ? 's3' : 'local';
  const migrationPercent = migrationStatus && migrationStatus.total > 0
    ? Math.round((migrationStatus.migrated / migrationStatus.total) * 100)
    : 0;

  if (loading) {
    return (
      <AuthenticatedLayout header={<h1 className="text-xl font-bold text-sw-text">Storage Settings</h1>}>
        <Head title="Storage Settings" />
        <div className="animate-pulse space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {[1, 2, 3].map((i) => (
              <div key={i} className="h-24 rounded-lg bg-sw-card border border-sw-border" />
            ))}
          </div>
        </div>
      </AuthenticatedLayout>
    );
  }

  return (
    <AuthenticatedLayout header={<h1 className="text-xl font-bold text-sw-text">Storage Settings</h1>}>
      <Head title="Storage Settings" />

      {/* Summary Stats */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <StatCard
          title="Total Documents"
          value={config?.stats.total_documents ?? 0}
          icon={<HardDrive size={18} />}
        />
        <StatCard
          title="Storage Used"
          value={formatBytes(config?.stats.total_size_bytes ?? 0)}
          icon={<HardDrive size={18} />}
        />
        <div className="relative overflow-hidden rounded-xl border border-sw-border bg-sw-card p-5 shadow-sm">
          <div className="flex items-center gap-2.5 mb-3.5">
            <div className="w-9 h-9 rounded-lg flex items-center justify-center bg-sw-accent-light text-sw-accent">
              {config?.stats.active_driver === 's3' ? <Cloud size={18} /> : <HardDrive size={18} />}
            </div>
            <span className="text-xs text-sw-muted font-medium uppercase tracking-wider">Active Driver</span>
          </div>
          <div className="flex items-center gap-2">
            <span className={`w-2.5 h-2.5 rounded-full ${config?.stats.active_driver === 's3' ? 'bg-emerald-500' : 'bg-sw-accent'}`} />
            <span className="text-2xl font-bold text-sw-text tracking-tight capitalize">
              {config?.stats.active_driver === 's3' ? 'Amazon S3' : 'Local'}
            </span>
          </div>
        </div>
      </div>

      {/* Storage Driver Toggle */}
      <div className="rounded-xl border border-sw-border bg-sw-card p-6 mb-6">
        <h2 className="text-sm font-semibold text-sw-text mb-4">Storage Driver</h2>
        <div className="flex gap-3">
          <button
            onClick={() => handleDriverChange('local')}
            disabled={isMigrationRunning}
            className={`flex items-center gap-2.5 px-5 py-3 rounded-lg border-2 text-sm font-semibold transition ${
              selectedDriver === 'local'
                ? 'border-sw-accent bg-sw-accent/5 text-sw-accent'
                : 'border-sw-border bg-transparent text-sw-muted hover:border-sw-accent/40'
            } ${isMigrationRunning ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
          >
            <HardDrive size={18} />
            Local Filesystem
          </button>
          <button
            onClick={() => handleDriverChange('s3')}
            disabled={isMigrationRunning}
            className={`flex items-center gap-2.5 px-5 py-3 rounded-lg border-2 text-sm font-semibold transition ${
              selectedDriver === 's3'
                ? 'border-sw-accent bg-sw-accent/5 text-sw-accent'
                : 'border-sw-border bg-transparent text-sw-muted hover:border-sw-accent/40'
            } ${isMigrationRunning ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
          >
            <Cloud size={18} />
            Amazon S3
          </button>
        </div>
        {isMigrationRunning && (
          <p className="text-xs text-sw-warning mt-2">Storage driver cannot be changed while migration is in progress.</p>
        )}
      </div>

      {/* S3 Configuration Form */}
      {selectedDriver === 's3' && (
        <div className="rounded-xl border border-sw-border bg-sw-card p-6 mb-6">
          <h2 className="text-sm font-semibold text-sw-text mb-4">S3 Configuration</h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <div>
              <label className="block text-xs font-medium text-sw-muted mb-1.5">Bucket Name</label>
              <input
                type="text"
                value={bucket}
                onChange={(e) => { setBucket(e.target.value); setTestStatus('idle'); }}
                placeholder="my-document-vault"
                className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm focus:border-sw-accent focus:ring-1 focus:ring-sw-accent outline-none"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-sw-muted mb-1.5">Region</label>
              <select
                value={region}
                onChange={(e) => { setRegion(e.target.value); setTestStatus('idle'); }}
                className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm focus:border-sw-accent focus:ring-1 focus:ring-sw-accent outline-none"
              >
                {AWS_REGIONS.map((r) => (
                  <option key={r.value} value={r.value}>{r.label}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-sw-muted mb-1.5">Access Key ID</label>
              <input
                type="text"
                value={accessKey}
                onChange={(e) => { setAccessKey(e.target.value); setTestStatus('idle'); }}
                placeholder="AKIAIOSFODNN7EXAMPLE"
                className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm focus:border-sw-accent focus:ring-1 focus:ring-sw-accent outline-none"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-sw-muted mb-1.5">Secret Access Key</label>
              <div className="relative">
                <input
                  type={showSecret ? 'text' : 'password'}
                  value={secretKey}
                  onChange={(e) => { setSecretKey(e.target.value); setTestStatus('idle'); }}
                  placeholder="wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
                  className="w-full px-3 py-2 pr-10 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm focus:border-sw-accent focus:ring-1 focus:ring-sw-accent outline-none"
                />
                <button
                  type="button"
                  onClick={() => setShowSecret(!showSecret)}
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 text-sw-dim hover:text-sw-muted transition"
                >
                  {showSecret ? <EyeOff size={16} /> : <Eye size={16} />}
                </button>
              </div>
            </div>
          </div>

          {/* Test Connection */}
          <div className="flex items-center gap-3 mb-4">
            <button
              onClick={handleTestConnection}
              disabled={!bucket || !accessKey || !secretKey || testStatus === 'testing'}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-sw-accent text-sw-accent text-sm font-semibold hover:bg-sw-accent/5 transition disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {testStatus === 'testing' ? (
                <Loader2 size={16} className="animate-spin" />
              ) : (
                <RefreshCw size={16} />
              )}
              Test Connection
            </button>
            {testStatus === 'success' && (
              <span className="inline-flex items-center gap-1.5 text-sm text-emerald-600 font-medium">
                <CheckCircle size={16} /> {testMessage}
              </span>
            )}
            {testStatus === 'failed' && (
              <span className="inline-flex items-center gap-1.5 text-sm text-red-500 font-medium">
                <XCircle size={16} /> {testMessage}
              </span>
            )}
          </div>

          {/* Save Button */}
          <div className="flex items-center gap-3">
            <button
              onClick={handleSaveConfig}
              disabled={testStatus !== 'success' || saving}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {saving && <Loader2 size={16} className="animate-spin" />}
              Save Configuration
            </button>
            {saveMessage && (
              <span className={`text-sm font-medium ${saveMessage.includes('success') ? 'text-emerald-600' : 'text-red-500'}`}>
                {saveMessage}
              </span>
            )}
          </div>
        </div>
      )}

      {/* Document Migration */}
      <div className="rounded-xl border border-sw-border bg-sw-card p-6">
        <h2 className="text-sm font-semibold text-sw-text mb-4">Document Migration</h2>

        {isMigrationRunning && migrationStatus ? (
          <div>
            <div className="flex items-center justify-between text-sm text-sw-muted mb-2">
              <span className="flex items-center gap-2">
                <RefreshCw size={14} className="animate-spin text-sw-accent" />
                Migrating {migrationStatus.migrated}/{migrationStatus.total} documents...
              </span>
              <span className="font-semibold text-sw-text">{migrationPercent}%</span>
            </div>
            <div className="w-full h-3 rounded-full bg-sw-border overflow-hidden">
              <div
                className="h-full rounded-full bg-sw-accent transition-all duration-300"
                style={{ width: `${migrationPercent}%` }}
              />
            </div>
          </div>
        ) : migrationStatus?.status === 'complete' ? (
          <div className="flex items-center gap-2 text-sm text-emerald-600 font-medium mb-4">
            <CheckCircle size={16} />
            Migration completed successfully. {migrationStatus.migrated} documents migrated.
          </div>
        ) : migrationStatus?.status === 'failed' ? (
          <div className="flex items-center gap-2 text-sm text-red-500 font-medium mb-4">
            <XCircle size={16} />
            Migration failed: {migrationStatus.error || 'Unknown error'}
          </div>
        ) : null}

        {!isMigrationRunning && (
          <button
            onClick={() => setShowMigrateConfirm(true)}
            disabled={(config?.stats.total_documents ?? 0) === 0}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-sw-border text-sw-text text-sm font-semibold hover:bg-sw-accent/5 transition disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <RefreshCw size={16} />
            Migrate {config?.stats.total_documents ?? 0} documents to {targetDriver === 's3' ? 'Amazon S3' : 'Local'}
          </button>
        )}
      </div>

      <ConfirmDialog
        open={showMigrateConfirm}
        onConfirm={handleMigrate}
        onCancel={() => setShowMigrateConfirm(false)}
        title="Migrate Documents"
        message={`This will migrate ${config?.stats.total_documents ?? 0} documents from ${selectedDriver === 'local' ? 'Local' : 'S3'} to ${targetDriver === 's3' ? 'Amazon S3' : 'Local Filesystem'}. This operation may take a while for large document sets.`}
        confirmText="Start Migration"
      />
    </AuthenticatedLayout>
  );
}
