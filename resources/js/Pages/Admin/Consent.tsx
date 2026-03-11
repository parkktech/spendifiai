import { useState, useEffect, useCallback } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
  Shield,
  Search,
  ChevronDown,
  ChevronUp,
  Loader2,
  Users,
  BarChart3,
  Megaphone,
  AlertTriangle,
} from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import ConfirmDialog from '@/Components/SpendifiAI/ConfirmDialog';
import axios from 'axios';
import type {
  AdminConsentStats,
  AdminConsentUser,
  ConsentAuditEntry,
} from '@/types/spendifiai';

function StatCard({ icon, label, value, color }: { icon: React.ReactNode; label: string; value: number | string; color: string }) {
  return (
    <div className="rounded-xl border border-sw-border bg-sw-card p-4">
      <div className="flex items-center gap-2.5 mb-2">
        <div className={`w-8 h-8 rounded-lg flex items-center justify-center ${color}`}>
          {icon}
        </div>
        <span className="text-xs font-medium text-sw-muted">{label}</span>
      </div>
      <div className="text-xl font-bold text-sw-text">{value}</div>
    </div>
  );
}

export default function AdminConsent() {
  const [stats, setStats] = useState<AdminConsentStats | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState<AdminConsentUser[]>([]);
  const [searching, setSearching] = useState(false);
  const [expandedUser, setExpandedUser] = useState<number | null>(null);
  const [userHistory, setUserHistory] = useState<Record<number, ConsentAuditEntry[]>>({});
  const [historyLoading, setHistoryLoading] = useState<number | null>(null);
  const [confirmAction, setConfirmAction] = useState<{ type: 'revoke' | 'delete'; userId: number; email: string } | null>(null);
  const [actionLoading, setActionLoading] = useState(false);

  useEffect(() => {
    axios.get('/api/admin/consent/stats').then(({ data }) => setStats(data)).catch(() => {});
  }, []);

  // Debounced search
  useEffect(() => {
    if (!searchQuery.trim()) {
      setSearchResults([]);
      return;
    }
    const timeout = setTimeout(() => {
      setSearching(true);
      axios.get('/api/admin/consent/search', { params: { q: searchQuery } })
        .then(({ data }) => setSearchResults(data.users))
        .catch(() => {})
        .finally(() => setSearching(false));
    }, 400);
    return () => clearTimeout(timeout);
  }, [searchQuery]);

  const loadHistory = useCallback(async (userId: number) => {
    if (userHistory[userId]) {
      setExpandedUser(expandedUser === userId ? null : userId);
      return;
    }
    setHistoryLoading(userId);
    setExpandedUser(userId);
    try {
      const { data } = await axios.get(`/api/admin/consent/user/${userId}/history`);
      setUserHistory(prev => ({ ...prev, [userId]: data.history }));
    } catch {
      // ignore
    } finally {
      setHistoryLoading(null);
    }
  }, [expandedUser, userHistory]);

  const handleAction = async () => {
    if (!confirmAction) return;
    setActionLoading(true);
    try {
      if (confirmAction.type === 'revoke') {
        await axios.post(`/api/admin/consent/user/${confirmAction.userId}/revoke`);
      } else {
        await axios.delete(`/api/admin/consent/user/${confirmAction.userId}/cookies`);
      }
      // Refresh search results
      if (searchQuery.trim()) {
        const { data } = await axios.get('/api/admin/consent/search', { params: { q: searchQuery } });
        setSearchResults(data.users);
      }
      // Clear cached history for this user
      setUserHistory(prev => {
        const updated = { ...prev };
        delete updated[confirmAction!.userId];
        return updated;
      });
      // Refresh stats
      axios.get('/api/admin/consent/stats').then(({ data }) => setStats(data)).catch(() => {});
    } catch {
      // ignore
    } finally {
      setActionLoading(false);
      setConfirmAction(null);
    }
  };

  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text tracking-tight">Cookie Consent Management</h1>
          <p className="text-xs text-sw-dim mt-0.5">GDPR/CCPA compliance dashboard</p>
        </div>
      }
    >
      <Head title="Consent Management" />

      <div className="max-w-4xl space-y-6">
        {/* Stats */}
        {stats && (
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <StatCard
              icon={<Users size={16} className="text-sw-accent" />}
              label="Users with Consent"
              value={stats.users_with_consent}
              color="bg-sw-accent/10"
            />
            <StatCard
              icon={<BarChart3 size={16} className="text-emerald-600" />}
              label="Analytics Enabled"
              value={stats.analytics_enabled}
              color="bg-emerald-50"
            />
            <StatCard
              icon={<Megaphone size={16} className="text-purple-600" />}
              label="Marketing Enabled"
              value={stats.marketing_enabled}
              color="bg-purple-50"
            />
            <StatCard
              icon={<Shield size={16} className="text-amber-600" />}
              label="Total Users"
              value={stats.total_users}
              color="bg-amber-50"
            />
          </div>
        )}

        {/* Region breakdown */}
        {stats?.region_breakdown && Object.keys(stats.region_breakdown).length > 0 && (
          <div className="rounded-xl border border-sw-border bg-sw-card p-4">
            <h3 className="text-xs font-semibold text-sw-muted mb-3">Consent by Region</h3>
            <div className="flex flex-wrap gap-3">
              {Object.entries(stats.region_breakdown).map(([region, count]) => (
                <div key={region} className="flex items-center gap-2">
                  <Badge variant={region === 'eu' ? 'warning' : region === 'california' ? 'info' : 'neutral'}>
                    {region === 'eu' ? 'EU/GDPR' : region === 'california' ? 'California/CCPA' : 'Other'}
                  </Badge>
                  <span className="text-sm font-medium text-sw-text">{count as number}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Search */}
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
          <div className="flex items-center gap-3 mb-4">
            <div className="w-9 h-9 rounded-lg bg-sw-accent/10 border border-sw-accent/20 flex items-center justify-center">
              <Search size={18} className="text-sw-accent" />
            </div>
            <div>
              <h3 className="text-[15px] font-semibold text-sw-text">Search Users</h3>
              <p className="text-xs text-sw-dim">Find users and manage their cookie consent</p>
            </div>
          </div>

          <div className="relative mb-4">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-sw-dim" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Search by email or name..."
              className="w-full pl-9 pr-3 py-2.5 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent transition"
            />
            {searching && <Loader2 size={14} className="absolute right-3 top-1/2 -translate-y-1/2 text-sw-dim animate-spin" />}
          </div>

          {/* Results */}
          {searchResults.length > 0 && (
            <div className="border border-sw-border rounded-xl overflow-hidden">
              <table className="w-full text-xs">
                <thead>
                  <tr className="bg-sw-surface text-sw-muted">
                    <th className="text-left px-4 py-2.5 font-medium">User</th>
                    <th className="text-left px-4 py-2.5 font-medium">Consent</th>
                    <th className="text-left px-4 py-2.5 font-medium">Region</th>
                    <th className="text-left px-4 py-2.5 font-medium">Last Updated</th>
                    <th className="text-right px-4 py-2.5 font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-sw-border">
                  {searchResults.map((user) => (
                    <UserRow
                      key={user.id}
                      user={user}
                      expanded={expandedUser === user.id}
                      history={userHistory[user.id]}
                      historyLoading={historyLoading === user.id}
                      onToggleHistory={() => loadHistory(user.id)}
                      onRevoke={() => setConfirmAction({ type: 'revoke', userId: user.id, email: user.email })}
                      onDelete={() => setConfirmAction({ type: 'delete', userId: user.id, email: user.email })}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {searchQuery.trim() && !searching && searchResults.length === 0 && (
            <p className="text-xs text-sw-dim text-center py-4">No users found matching "{searchQuery}"</p>
          )}
        </div>
      </div>

      {/* Confirm dialog */}
      <ConfirmDialog
        open={!!confirmAction}
        onConfirm={handleAction}
        onCancel={() => setConfirmAction(null)}
        title={confirmAction?.type === 'revoke' ? 'Revoke Consent' : 'Delete Cookie Data'}
        message={
          confirmAction?.type === 'revoke'
            ? `This will revoke all analytics and marketing consent for ${confirmAction.email}. A record will be added to the audit trail.`
            : `This will record a GDPR cookie data deletion request for ${confirmAction?.email}. Consent will be set to denied.`
        }
        confirmText={actionLoading ? 'Processing...' : confirmAction?.type === 'revoke' ? 'Revoke Consent' : 'Delete Cookie Data'}
        variant="danger"
      />
    </AuthenticatedLayout>
  );
}

function UserRow({
  user,
  expanded,
  history,
  historyLoading,
  onToggleHistory,
  onRevoke,
  onDelete,
}: {
  user: AdminConsentUser;
  expanded: boolean;
  history?: ConsentAuditEntry[];
  historyLoading: boolean;
  onToggleHistory: () => void;
  onRevoke: () => void;
  onDelete: () => void;
}) {
  return (
    <>
      <tr className="hover:bg-sw-surface/50">
        <td className="px-4 py-3">
          <div className="font-medium text-sw-text">{user.name}</div>
          <div className="text-sw-dim">{user.email}</div>
        </td>
        <td className="px-4 py-3">
          {user.consent ? (
            <div className="flex flex-wrap gap-1">
              <Badge variant={user.consent.analytics ? 'success' : 'neutral'}>
                Analytics {user.consent.analytics ? 'On' : 'Off'}
              </Badge>
              <Badge variant={user.consent.marketing ? 'success' : 'neutral'}>
                Marketing {user.consent.marketing ? 'On' : 'Off'}
              </Badge>
            </div>
          ) : (
            <Badge variant="warning">No consent</Badge>
          )}
        </td>
        <td className="px-4 py-3">
          {user.consent?.region ? (
            <Badge variant={user.consent.region === 'eu' ? 'warning' : user.consent.region === 'california' ? 'info' : 'neutral'}>
              {user.consent.region.toUpperCase()}
            </Badge>
          ) : (
            <span className="text-sw-dim">-</span>
          )}
        </td>
        <td className="px-4 py-3 text-sw-muted">
          {user.consent?.last_updated
            ? new Date(user.consent.last_updated).toLocaleDateString()
            : '-'}
        </td>
        <td className="px-4 py-3">
          <div className="flex items-center justify-end gap-2">
            <button
              onClick={onToggleHistory}
              className="text-sw-muted hover:text-sw-accent transition"
              title="View history"
            >
              {expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
            </button>
            {user.consent && (
              <>
                <button
                  onClick={onRevoke}
                  className="px-2 py-1 text-[10px] font-semibold rounded border border-sw-danger/30 text-sw-danger hover:bg-sw-danger/10 transition"
                >
                  Revoke
                </button>
                <button
                  onClick={onDelete}
                  className="px-2 py-1 text-[10px] font-semibold rounded border border-sw-warning/30 text-sw-warning hover:bg-sw-warning/10 transition"
                  title="GDPR deletion"
                >
                  <AlertTriangle size={10} />
                </button>
              </>
            )}
          </div>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={5} className="px-4 py-3 bg-sw-surface/30">
            {historyLoading ? (
              <div className="flex items-center gap-2 text-xs text-sw-dim py-2">
                <Loader2 size={12} className="animate-spin" /> Loading audit trail...
              </div>
            ) : history && history.length > 0 ? (
              <div className="space-y-1.5">
                <p className="text-[10px] font-semibold text-sw-muted uppercase tracking-wider mb-2">Audit Trail</p>
                {history.map((entry) => (
                  <div key={entry.id} className="flex items-center gap-3 text-[11px] py-1.5 border-b border-sw-border/50 last:border-0">
                    <Badge variant={
                      entry.action === 'grant' ? 'success'
                        : entry.action === 'revoke' ? 'danger'
                          : entry.action === 'admin_override' ? 'warning'
                            : 'info'
                    }>
                      {entry.action}
                    </Badge>
                    <span className="text-sw-muted">
                      Analytics: <strong>{entry.analytics ? 'On' : 'Off'}</strong>
                      {' / '}
                      Marketing: <strong>{entry.marketing ? 'On' : 'Off'}</strong>
                    </span>
                    <span className="text-sw-dim">v{entry.version}</span>
                    <span className="text-sw-dim ml-auto">
                      {new Date(entry.created_at).toLocaleString()}
                    </span>
                    {entry.admin_user_id && (
                      <Badge variant="warning">Admin</Badge>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-xs text-sw-dim py-2">No consent history found.</p>
            )}
          </td>
        </tr>
      )}
    </>
  );
}
