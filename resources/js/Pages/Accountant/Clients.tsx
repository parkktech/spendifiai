import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
    Users,
    UserPlus,
    RefreshCw,
    Download,
    Eye,
    Loader2,
    CheckCircle,
    Search,
    X,
    Building2,
    Calendar,
    Clock,
    FileText,
    AlertCircle,
    Send,
} from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import { useApi } from '@/hooks/useApi';
import { useImpersonation } from '@/contexts/ImpersonationContext';
import type { AccountantClient, AccountantInvite } from '@/types/spendifiai';
import axios from 'axios';

function SuccessToast({ message }: { message: string }) {
    return (
        <div className="flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent/10 border border-sw-accent/30 text-sw-accent text-xs font-medium">
            <CheckCircle size={14} /> {message}
        </div>
    );
}

function TaxDownloadDropdown({ clientId, clientName, onClose }: { clientId: number; clientName: string; onClose: () => void }) {
    const [year, setYear] = useState(new Date().getFullYear());
    const [format, setFormat] = useState('xlsx');
    const [downloading, setDownloading] = useState(false);

    const handleDownload = async () => {
        setDownloading(true);
        try {
            const response = await axios.get(
                `/api/v1/accountant/clients/${clientId}/tax/${year}/download/${format}`,
                { responseType: 'blob' }
            );
            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            const ext = format === 'qbo_csv' ? 'csv' : format;
            const safeName = clientName.replace(/[^a-zA-Z0-9]/g, '_');
            link.download = `${safeName}_Tax_${year}.${ext}`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
            onClose();
        } catch {
            // ignore
        } finally {
            setDownloading(false);
        }
    };

    const currentYear = new Date().getFullYear();

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-black/20" onClick={onClose} />
            <div className="relative w-64 rounded-xl border border-sw-border bg-sw-card shadow-xl p-4">
                <h3 className="text-sm font-semibold text-sw-text mb-3 flex items-center gap-2">
                    <Download size={14} /> Download Tax Export
                </h3>
                <div className="space-y-3">
                    <div>
                        <label className="block text-xs font-medium text-sw-muted mb-1">Year</label>
                        <select
                            value={year}
                            onChange={(e) => setYear(Number(e.target.value))}
                            className="w-full px-2.5 py-1.5 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent"
                        >
                            {[currentYear, currentYear - 1, currentYear - 2].map((y) => (
                                <option key={y} value={y}>{y}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-sw-muted mb-1">Format</label>
                        <select
                            value={format}
                            onChange={(e) => setFormat(e.target.value)}
                            className="w-full px-2.5 py-1.5 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent"
                        >
                            <option value="xlsx">Excel (.xlsx)</option>
                            <option value="pdf">PDF Summary</option>
                            <option value="csv">CSV</option>
                            <option value="txf">TXF (TurboTax)</option>
                            <option value="qbo_csv">QuickBooks CSV</option>
                            <option value="ofx">OFX</option>
                        </select>
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={handleDownload}
                            disabled={downloading}
                            className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
                        >
                            {downloading ? <Loader2 size={12} className="animate-spin" /> : <Download size={12} />}
                            Download
                        </button>
                        <button
                            onClick={onClose}
                            className="px-2.5 py-1.5 rounded-lg border border-sw-border text-sw-muted text-xs hover:bg-sw-surface transition"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Clients() {
    const { data, loading, refresh } = useApi<{ clients: AccountantClient[] }>('/api/v1/accountant/clients');
    const clients = data?.clients ?? [];
    const { startImpersonation } = useImpersonation();

    const [searchQuery, setSearchQuery] = useState('');
    const [inviteModalOpen, setInviteModalOpen] = useState(false);
    const [inviteEmail, setInviteEmail] = useState('');
    const [inviting, setInviting] = useState(false);
    const [toast, setToast] = useState('');
    const [taxDropdownClientId, setTaxDropdownClientId] = useState<number | null>(null);
    const [refreshingClientId, setRefreshingClientId] = useState<number | null>(null);

    // Pending invites
    const { data: invitesData, refresh: refreshInvites } = useApi<{ invites: AccountantInvite[] }>('/api/v1/accountant/invites');
    const invites = invitesData?.invites ?? [];
    const pendingInvites = invites.filter(inv => inv.can_respond);

    const filteredClients = clients.filter((c) => {
        if (!searchQuery) return true;
        const q = searchQuery.toLowerCase();
        return (
            c.client.name.toLowerCase().includes(q) ||
            c.client.email.toLowerCase().includes(q) ||
            (c.client.company_name && c.client.company_name.toLowerCase().includes(q))
        );
    });

    const activeClients = clients.filter((c) => c.status === 'active');
    const pendingClients = clients.filter((c) => c.status === 'pending');

    const handleInvite = async () => {
        if (!inviteEmail) return;
        setInviting(true);
        try {
            const res = await axios.post('/api/v1/accountant/clients/invite', { email: inviteEmail });
            setToast(res.data.message || 'Invitation sent');
            setInviteEmail('');
            setInviteModalOpen(false);
            refresh();
            setTimeout(() => setToast(''), 3000);
        } catch (err: unknown) {
            const error = err as { response?: { data?: { message?: string } } };
            setToast(error.response?.data?.message || 'Failed to send invitation');
            setTimeout(() => setToast(''), 3000);
        } finally {
            setInviting(false);
        }
    };

    const handleRefresh = async (clientId: number) => {
        setRefreshingClientId(clientId);
        try {
            await axios.post(`/api/v1/accountant/clients/${clientId}/refresh`);
            setToast('Sync initiated');
            setTimeout(() => setToast(''), 3000);
        } catch {
            setToast('Failed to sync');
            setTimeout(() => setToast(''), 3000);
        } finally {
            setRefreshingClientId(null);
        }
    };

    const handleImpersonate = async (clientId: number) => {
        try {
            await startImpersonation(clientId);
        } catch {
            setToast('Failed to start impersonation');
            setTimeout(() => setToast(''), 3000);
        }
    };

    const respondToInvite = async (inviteId: number, action: 'accept' | 'decline') => {
        try {
            await axios.post(`/api/v1/accountant/invites/${inviteId}/respond`, { action });
            setToast(action === 'accept' ? 'Client added' : 'Invitation declined');
            refresh();
            refreshInvites();
            setTimeout(() => setToast(''), 3000);
        } catch {
            // ignore
        }
    };

    const resendInvite = async (clientId: number) => {
        try {
            await axios.post(`/api/v1/accountant/clients/${clientId}/resend`);
            setToast('Invitation email resent');
            setTimeout(() => setToast(''), 3000);
        } catch (err: unknown) {
            const error = err as { response?: { data?: { message?: string } } };
            setToast(error.response?.data?.message || 'Failed to resend');
            setTimeout(() => setToast(''), 3000);
        }
    };

    const removeClient = async (clientId: number) => {
        try {
            await axios.delete(`/api/v1/accountant/clients/${clientId}`);
            setToast('Client removed');
            refresh();
            setTimeout(() => setToast(''), 3000);
        } catch {
            // ignore
        }
    };

    const formatDate = (dateStr: string | undefined | null) => {
        if (!dateStr) return '—';
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const formatRelativeTime = (dateStr: string | undefined | null) => {
        if (!dateStr) return 'Never';
        const d = new Date(dateStr);
        const now = new Date();
        const diffMs = now.getTime() - d.getTime();
        const diffMins = Math.floor(diffMs / 60000);
        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        const diffHours = Math.floor(diffMins / 60);
        if (diffHours < 24) return `${diffHours}h ago`;
        const diffDays = Math.floor(diffHours / 24);
        if (diffDays < 7) return `${diffDays}d ago`;
        return formatDate(dateStr);
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center gap-3">
                    <h1 className="text-xl font-bold text-sw-text tracking-tight">My Clients</h1>
                    <span className="px-2 py-0.5 rounded-full bg-sw-accent/10 text-sw-accent text-xs font-bold">
                        {activeClients.length}
                    </span>
                </div>
            }
        >
            <Head title="Clients" />

            <div className="max-w-5xl space-y-6">
                {/* Toast */}
                {toast && <div aria-live="polite"><SuccessToast message={toast} /></div>}

                {/* Stat Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div className="rounded-xl border border-sw-border bg-sw-card p-4">
                        <div className="flex items-center gap-2 text-sw-muted text-xs font-medium mb-1">
                            <Users size={14} /> Total Clients
                        </div>
                        <div className="text-2xl font-bold text-sw-text">{activeClients.length}</div>
                    </div>
                    <div className="rounded-xl border border-sw-border bg-sw-card p-4">
                        <div className="flex items-center gap-2 text-sw-muted text-xs font-medium mb-1">
                            <CheckCircle size={14} /> With Bank Data
                        </div>
                        <div className="text-2xl font-bold text-sw-text">
                            {activeClients.filter((c) => c.has_bank).length}
                        </div>
                    </div>
                    <div className="rounded-xl border border-sw-border bg-sw-card p-4">
                        <div className="flex items-center gap-2 text-sw-muted text-xs font-medium mb-1">
                            <Clock size={14} /> Pending Invites
                        </div>
                        <div className="text-2xl font-bold text-sw-text">{pendingClients.length + pendingInvites.length}</div>
                    </div>
                </div>

                {/* Search + Invite bar */}
                <div className="flex items-center gap-3">
                    <div className="relative flex-1">
                        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-sw-dim" />
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            placeholder="Search clients..."
                            className="w-full pl-8 pr-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent transition"
                        />
                    </div>
                    <button
                        onClick={() => setInviteModalOpen(true)}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition shrink-0"
                    >
                        <UserPlus size={14} /> Invite Client
                    </button>
                </div>

                {/* Pending invites (from clients requesting this accountant) */}
                {pendingInvites.length > 0 && (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <h3 className="text-sm font-semibold text-amber-900 mb-3 flex items-center gap-2">
                            <AlertCircle size={14} /> Pending Client Requests
                        </h3>
                        <div className="space-y-2">
                            {pendingInvites.map((invite) => (
                                <div key={invite.id} className="flex items-center justify-between rounded-lg bg-white border border-amber-200 px-3 py-2">
                                    <div>
                                        <div className="text-sm font-medium text-sw-text">{invite.client.name}</div>
                                        <div className="text-xs text-sw-dim">{invite.client.email}</div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <button
                                            onClick={() => respondToInvite(invite.id, 'accept')}
                                            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-sw-success text-white text-xs font-semibold hover:bg-emerald-700 transition"
                                        >
                                            <CheckCircle size={12} /> Accept
                                        </button>
                                        <button
                                            onClick={() => respondToInvite(invite.id, 'decline')}
                                            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md border border-sw-border text-sw-muted text-xs font-semibold hover:bg-sw-surface transition"
                                        >
                                            <X size={12} /> Decline
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Clients Table */}
                {loading ? (
                    <div className="flex items-center justify-center py-12">
                        <Loader2 size={24} className="animate-spin text-sw-accent" />
                    </div>
                ) : filteredClients.length === 0 ? (
                    <div className="rounded-xl border border-sw-border bg-sw-card p-8 text-center">
                        <Users size={40} className="mx-auto text-sw-dim mb-3" />
                        <h3 className="text-lg font-semibold text-sw-text mb-1">
                            {clients.length === 0 ? 'No clients yet' : 'No matching clients'}
                        </h3>
                        <p className="text-sm text-sw-muted">
                            {clients.length === 0
                                ? 'Invite clients by email to start managing their financial data.'
                                : 'Try adjusting your search query.'}
                        </p>
                    </div>
                ) : (
                    <div className="rounded-xl border border-sw-border bg-sw-card overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-sw-border bg-sw-surface/50">
                                        <th className="text-left px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide">Client</th>
                                        <th className="text-left px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide">Status</th>
                                        <th className="text-left px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide hidden md:table-cell">Data Range</th>
                                        <th className="text-left px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide hidden lg:table-cell">Last Sync</th>
                                        <th className="text-right px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sw-border">
                                    {filteredClients.map((client) => (
                                        <tr key={client.id} className="hover:bg-sw-surface/30 transition">
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-3">
                                                    <div className="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-indigo-500 flex items-center justify-center text-white text-xs font-bold shrink-0">
                                                        {client.client.name.charAt(0).toUpperCase()}
                                                    </div>
                                                    <div>
                                                        <div className="font-medium text-sw-text">{client.client.name}</div>
                                                        <div className="text-xs text-sw-dim">{client.client.email}</div>
                                                        {client.client.company_name && (
                                                            <div className="text-xs text-sw-muted flex items-center gap-1 mt-0.5">
                                                                <Building2 size={10} /> {client.client.company_name}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-col gap-1">
                                                    <Badge variant={client.status === 'active' ? 'success' : 'warning'}>
                                                        {client.status === 'active' ? 'Active' : 'Pending'}
                                                    </Badge>
                                                    {client.status === 'active' && (
                                                        <Badge variant={client.has_bank ? 'info' : 'neutral'}>
                                                            {client.has_bank ? 'Bank Connected' : 'No Bank'}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 hidden md:table-cell">
                                                {client.transaction_range ? (
                                                    <div className="flex items-center gap-1 text-xs text-sw-muted">
                                                        <Calendar size={12} />
                                                        {formatDate(client.transaction_range.start)} – {formatDate(client.transaction_range.end)}
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-sw-dim">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 hidden lg:table-cell">
                                                <span className="text-xs text-sw-muted">{formatRelativeTime(client.last_sync)}</span>
                                            </td>
                                            <td className="px-4 py-3">
                                                {client.status === 'active' ? (
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        {/* Tax Download */}
                                                        <div className="relative">
                                                            <button
                                                                onClick={() => setTaxDropdownClientId(
                                                                    taxDropdownClientId === client.client.id ? null : client.client.id
                                                                )}
                                                                title="Download Tax Export"
                                                                className="w-8 h-8 rounded-lg border border-sw-border flex items-center justify-center text-sw-muted hover:text-sw-accent hover:border-sw-accent transition"
                                                            >
                                                                <FileText size={14} />
                                                            </button>
                                                            {taxDropdownClientId === client.client.id && (
                                                                <TaxDownloadDropdown
                                                                    clientId={client.client.id}
                                                                    clientName={client.client.name}
                                                                    onClose={() => setTaxDropdownClientId(null)}
                                                                />
                                                            )}
                                                        </div>

                                                        {/* Refresh / Sync */}
                                                        {client.has_bank && (
                                                            <button
                                                                onClick={() => handleRefresh(client.client.id)}
                                                                disabled={refreshingClientId === client.client.id}
                                                                title="Refresh Data"
                                                                className="w-8 h-8 rounded-lg border border-sw-border flex items-center justify-center text-sw-muted hover:text-sw-accent hover:border-sw-accent transition disabled:opacity-50"
                                                            >
                                                                <RefreshCw size={14} className={refreshingClientId === client.client.id ? 'animate-spin' : ''} />
                                                            </button>
                                                        )}

                                                        {/* View Dashboard (Impersonate) */}
                                                        {client.has_bank && (
                                                            <button
                                                                onClick={() => handleImpersonate(client.client.id)}
                                                                title="View Dashboard"
                                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition"
                                                            >
                                                                <Eye size={12} /> View
                                                            </button>
                                                        )}

                                                        {/* Remove */}
                                                        <button
                                                            onClick={() => removeClient(client.client.id)}
                                                            title="Remove Client"
                                                            className="w-8 h-8 rounded-lg border border-sw-border flex items-center justify-center text-sw-dim hover:text-sw-danger hover:border-sw-danger transition"
                                                        >
                                                            <X size={14} />
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <div className="flex items-center justify-end gap-2">
                                                        <span className="text-xs text-sw-dim italic">Awaiting response</span>
                                                        <button
                                                            onClick={() => resendInvite(client.client.id)}
                                                            title="Resend Invitation Email"
                                                            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border border-sw-border text-sw-muted text-xs font-semibold hover:text-sw-accent hover:border-sw-accent transition"
                                                        >
                                                            <Send size={12} /> Resend
                                                        </button>
                                                        <button
                                                            onClick={() => removeClient(client.client.id)}
                                                            title="Rescind Invitation"
                                                            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border border-sw-border text-sw-muted text-xs font-semibold hover:text-sw-danger hover:border-sw-danger transition"
                                                        >
                                                            <X size={12} /> Rescind
                                                        </button>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>

            {/* Invite Modal */}
            {inviteModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center">
                    <div className="absolute inset-0 bg-black/30 backdrop-blur-sm" onClick={() => setInviteModalOpen(false)} />
                    <div className="relative w-full max-w-md rounded-2xl border border-sw-border bg-sw-card shadow-xl p-6">
                        <button
                            onClick={() => setInviteModalOpen(false)}
                            className="absolute top-4 right-4 text-sw-dim hover:text-sw-text transition"
                        >
                            <X size={18} />
                        </button>

                        <h2 className="text-lg font-bold text-sw-text mb-1">Invite Client</h2>
                        <p className="text-sm text-sw-muted mb-5">
                            Enter your client's email address to send them an invitation.
                        </p>

                        <div>
                            <label className="block text-xs font-medium text-sw-muted mb-1.5">Client Email</label>
                            <input
                                type="email"
                                value={inviteEmail}
                                onChange={(e) => setInviteEmail(e.target.value)}
                                placeholder="client@example.com"
                                className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent transition"
                                autoFocus
                            />
                        </div>

                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                onClick={() => setInviteModalOpen(false)}
                                className="px-4 py-2 rounded-lg border border-sw-border text-sw-muted text-sm font-semibold hover:bg-sw-surface transition"
                            >
                                Cancel
                            </button>
                            <button
                                onClick={handleInvite}
                                disabled={inviting || !inviteEmail}
                                className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
                            >
                                {inviting ? <Loader2 size={14} className="animate-spin" /> : <UserPlus size={14} />}
                                Send Invitation
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
