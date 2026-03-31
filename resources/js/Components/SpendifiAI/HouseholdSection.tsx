import { useState, useEffect } from 'react';
import { Users, Copy, Trash2, LogOut, Plus, Loader2, CheckCircle, Mail, Clock, XCircle } from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import ConfirmDialog from '@/Components/SpendifiAI/ConfirmDialog';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { HouseholdResponse, HouseholdMember, HouseholdInvitation } from '@/types/spendifiai';

export default function HouseholdSection() {
  const { data, loading, refresh } = useApi<HouseholdResponse>('/api/v1/household');
  const { submit: createHousehold, loading: creating } = useApiPost('/api/v1/household', 'POST');
  const { submit: invite, loading: inviting } = useApiPost('/api/v1/household/invite', 'POST');

  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteUrl, setInviteUrl] = useState('');
  const [copied, setCopied] = useState(false);
  const [success, setSuccess] = useState('');
  const [error, setError] = useState('');
  const [confirmRemove, setConfirmRemove] = useState<HouseholdMember | null>(null);
  const [confirmLeave, setConfirmLeave] = useState(false);

  const household = data?.household;
  const members = data?.members ?? [];
  const invitations = data?.invitations ?? [];
  const isOwner = household?.role === 'owner';

  const handleCreate = async () => {
    try {
      await createHousehold({});
      refresh();
      setSuccess('Household created!');
      setTimeout(() => setSuccess(''), 3000);
    } catch {
      setError('Failed to create household');
    }
  };

  const handleInvite = async () => {
    try {
      const res = await invite({ email: inviteEmail || undefined }) as { invite_url: string };
      setInviteUrl(res.invite_url);
      setInviteEmail('');
      refresh();
      setSuccess('Invitation created!');
      setTimeout(() => setSuccess(''), 3000);
    } catch {
      setError('Failed to create invitation');
    }
  };

  const handleCopyLink = () => {
    navigator.clipboard.writeText(inviteUrl);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleRemoveMember = async (member: HouseholdMember) => {
    try {
      const token = localStorage.getItem('auth_token');
      await fetch(`/api/v1/household/members/${member.id}`, {
        method: 'DELETE',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      });
      refresh();
      setConfirmRemove(null);
      setSuccess('Member removed');
      setTimeout(() => setSuccess(''), 3000);
    } catch {
      setError('Failed to remove member');
    }
  };

  const handleLeave = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      await fetch('/api/v1/household/leave', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      });
      refresh();
      setConfirmLeave(false);
      setSuccess('You left the household');
      setTimeout(() => setSuccess(''), 3000);
    } catch {
      setError('Failed to leave household');
    }
  };

  const handleRevokeInvite = async (token: string) => {
    try {
      const authToken = localStorage.getItem('auth_token');
      await fetch(`/api/v1/household/invite/${token}/revoke`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${authToken}`, 'Content-Type': 'application/json' },
      });
      refresh();
    } catch {
      setError('Failed to revoke invitation');
    }
  };

  if (loading) {
    return (
      <div className="bg-sw-card border border-sw-border rounded-xl p-6">
        <div className="flex items-center gap-2 text-sw-muted text-sm">
          <Loader2 size={14} className="animate-spin" /> Loading...
        </div>
      </div>
    );
  }

  return (
    <div className="bg-sw-card border border-sw-border rounded-xl p-6">
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2">
          <Users size={18} className="text-sw-accent" />
          <h2 className="text-base font-semibold text-sw-text">Household Sharing</h2>
        </div>
        {household && (
          <Badge variant="info">{household.member_count} member{household.member_count !== 1 ? 's' : ''}</Badge>
        )}
      </div>

      {success && (
        <div className="flex items-center gap-2 px-3 py-2 mb-4 rounded-lg bg-sw-success-light border border-sw-success/30 text-sw-success text-xs font-medium">
          <CheckCircle size={14} /> {success}
        </div>
      )}

      {error && (
        <div className="flex items-center gap-2 px-3 py-2 mb-4 rounded-lg bg-sw-danger/10 border border-sw-danger/30 text-sw-danger text-xs font-medium">
          <XCircle size={14} /> {error}
        </div>
      )}

      {!household ? (
        <div className="text-center py-6">
          <p className="text-sm text-sw-muted mb-4">
            Share your financial data with your spouse or partner. Both of you will see all bank accounts, transactions, and subscriptions.
          </p>
          <button
            onClick={handleCreate}
            disabled={creating}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-medium hover:bg-sw-accent-hover transition-colors disabled:opacity-50"
          >
            {creating ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />}
            Create Household
          </button>
        </div>
      ) : (
        <div className="space-y-4">
          {/* Household name */}
          <div className="text-sm text-sw-text-secondary">
            <span className="font-medium">{household.name}</span>
            {isOwner && <Badge variant="info" className="ml-2">Owner</Badge>}
          </div>

          {/* Members */}
          <div>
            <h3 className="text-xs font-semibold text-sw-muted uppercase tracking-wider mb-2">Members</h3>
            <div className="space-y-2">
              {members.map((member) => (
                <div key={member.id} className="flex items-center justify-between py-2 px-3 rounded-lg bg-sw-surface">
                  <div>
                    <span className="text-sm font-medium text-sw-text">{member.name}</span>
                    <span className="text-xs text-sw-muted ml-2">{member.email}</span>
                    {member.role === 'owner' && <Badge variant="info" className="ml-2">Owner</Badge>}
                  </div>
                  {isOwner && member.role !== 'owner' && (
                    <button
                      onClick={() => setConfirmRemove(member)}
                      className="text-sw-danger hover:text-sw-danger/80 p-1"
                      title="Remove member"
                    >
                      <Trash2 size={14} />
                    </button>
                  )}
                </div>
              ))}
            </div>
          </div>

          {/* Invite */}
          <div>
            <h3 className="text-xs font-semibold text-sw-muted uppercase tracking-wider mb-2">Invite Someone</h3>
            <div className="flex gap-2">
              <input
                type="email"
                value={inviteEmail}
                onChange={(e) => setInviteEmail(e.target.value)}
                placeholder="Email (optional)"
                className="flex-1 px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sm text-sw-text placeholder:text-sw-dim focus:ring-1 focus:ring-sw-accent focus:border-sw-accent"
              />
              <button
                onClick={handleInvite}
                disabled={inviting}
                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-medium hover:bg-sw-accent-hover transition-colors disabled:opacity-50"
              >
                {inviting ? <Loader2 size={14} className="animate-spin" /> : <Mail size={14} />}
                Invite
              </button>
            </div>
          </div>

          {/* Invite link */}
          {inviteUrl && (
            <div className="flex items-center gap-2 p-3 rounded-lg bg-sw-accent-light border border-sw-accent/20">
              <input
                type="text"
                value={inviteUrl}
                readOnly
                className="flex-1 px-2 py-1 rounded bg-white text-xs text-sw-text font-mono border border-sw-border"
              />
              <button
                onClick={handleCopyLink}
                className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-medium hover:bg-sw-accent-hover"
              >
                {copied ? <CheckCircle size={12} /> : <Copy size={12} />}
                {copied ? 'Copied!' : 'Copy'}
              </button>
            </div>
          )}

          {/* Pending invitations */}
          {invitations.length > 0 && (
            <div>
              <h3 className="text-xs font-semibold text-sw-muted uppercase tracking-wider mb-2">Pending Invitations</h3>
              <div className="space-y-2">
                {invitations.map((inv) => (
                  <div key={inv.id} className="flex items-center justify-between py-2 px-3 rounded-lg bg-sw-surface">
                    <div className="flex items-center gap-2">
                      <Clock size={12} className="text-sw-warning" />
                      <span className="text-xs text-sw-text-secondary">{inv.email || 'Link invite'}</span>
                      <span className="text-xs text-sw-dim">expires {new Date(inv.expires_at).toLocaleDateString()}</span>
                    </div>
                    <button
                      onClick={() => handleRevokeInvite((inv as unknown as { token: string }).token)}
                      className="text-sw-danger hover:text-sw-danger/80 p-1 text-xs"
                    >
                      Revoke
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Leave household (non-owners) */}
          {!isOwner && (
            <button
              onClick={() => setConfirmLeave(true)}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-sw-danger/30 text-sw-danger text-xs font-medium hover:bg-sw-danger/5"
            >
              <LogOut size={14} /> Leave Household
            </button>
          )}
        </div>
      )}

      {/* Confirm dialogs */}
      <ConfirmDialog
        open={!!confirmRemove}
        title="Remove Member"
        message={`Remove ${confirmRemove?.name} from your household? They will no longer see shared financial data.`}
        confirmText="Remove"
        onConfirm={() => confirmRemove && handleRemoveMember(confirmRemove)}
        onCancel={() => setConfirmRemove(null)}
      />
      <ConfirmDialog
        open={confirmLeave}
        title="Leave Household"
        message="Are you sure you want to leave this household? You will no longer see shared financial data."
        confirmText="Leave"
        onConfirm={handleLeave}
        onCancel={() => setConfirmLeave(false)}
      />
    </div>
  );
}
