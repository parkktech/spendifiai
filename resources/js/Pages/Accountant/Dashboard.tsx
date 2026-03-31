import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import {
    Users,
    FileText,
    AlertCircle,
    Calendar,
    Copy,
    CheckCircle,
    Loader2,
    Building2,
    Clock,
    Eye,
    ChevronRight,
} from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import { useApi } from '@/hooks/useApi';
import { useApiPost } from '@/hooks/useApi';
import { useImpersonation } from '@/contexts/ImpersonationContext';
import type { AccountingFirm } from '@/types/spendifiai';

interface DashboardClient {
    id: number;
    name: string;
    email: string;
    document_count: number;
    completeness: number;
    last_activity: string | null;
    open_requests: number;
    status: 'active' | 'pending';
}

interface Deadline {
    date: string;
    label: string;
}

interface DashboardData {
    total_clients: number;
    documents_pending_review: number;
    open_requests: number;
    upcoming_deadlines: Deadline[];
    clients: DashboardClient[];
    firm: AccountingFirm | null;
}

function StatCard({ icon: Icon, label, value, accent }: { icon: typeof Users; label: string; value: string | number; accent?: boolean }) {
    return (
        <div className="rounded-xl border border-sw-border bg-sw-card p-4">
            <div className="flex items-center gap-2 text-sw-muted text-xs font-medium mb-1">
                <Icon size={14} className={accent ? 'text-sw-accent' : ''} /> {label}
            </div>
            <div className="text-2xl font-bold text-sw-text">{value}</div>
        </div>
    );
}

function FirmRegistrationForm({ onCreated }: { onCreated: () => void }) {
    const [name, setName] = useState('');
    const [address, setAddress] = useState('');
    const [phone, setPhone] = useState('');
    const [logoUrl, setLogoUrl] = useState('');
    const [primaryColor, setPrimaryColor] = useState('#0D9488');
    const { submit, loading, error } = useApiPost<{ firm: AccountingFirm }>('/api/v1/accountant/firm');

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        const result = await submit({ name, address, phone, logo_url: logoUrl, primary_color: primaryColor });
        if (result) {
            onCreated();
        }
    };

    return (
        <div className="rounded-xl border border-sw-border bg-sw-card p-6">
            <h3 className="text-sm font-semibold text-sw-text mb-1 flex items-center gap-2">
                <Building2 size={16} className="text-sw-accent" /> Register Your Firm
            </h3>
            <p className="text-xs text-sw-muted mb-4">Set up your accounting firm to start inviting clients.</p>

            {error && <p className="text-xs text-sw-danger mb-3">{error}</p>}

            <form onSubmit={handleSubmit} className="space-y-3">
                <div>
                    <label className="block text-xs font-medium text-sw-muted mb-1">Firm Name *</label>
                    <input
                        type="text"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        required
                        className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent transition"
                        placeholder="Acme Accounting LLC"
                    />
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-xs font-medium text-sw-muted mb-1">Address</label>
                        <input
                            type="text"
                            value={address}
                            onChange={(e) => setAddress(e.target.value)}
                            className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent transition"
                            placeholder="123 Main St, City, ST"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-sw-muted mb-1">Phone</label>
                        <input
                            type="tel"
                            value={phone}
                            onChange={(e) => setPhone(e.target.value)}
                            className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent transition"
                            placeholder="(555) 123-4567"
                        />
                    </div>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-xs font-medium text-sw-muted mb-1">Logo URL</label>
                        <input
                            type="url"
                            value={logoUrl}
                            onChange={(e) => setLogoUrl(e.target.value)}
                            className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent transition"
                            placeholder="https://example.com/logo.png"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-sw-muted mb-1">Brand Color</label>
                        <div className="flex items-center gap-2">
                            <input
                                type="color"
                                value={primaryColor}
                                onChange={(e) => setPrimaryColor(e.target.value)}
                                className="w-10 h-10 rounded-lg border border-sw-border cursor-pointer"
                            />
                            <input
                                type="text"
                                value={primaryColor}
                                onChange={(e) => setPrimaryColor(e.target.value)}
                                className="flex-1 px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent transition"
                            />
                        </div>
                    </div>
                </div>
                <button
                    type="submit"
                    disabled={loading || !name}
                    className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
                >
                    {loading ? <Loader2 size={14} className="animate-spin" /> : <Building2 size={14} />}
                    Register Firm
                </button>
            </form>
        </div>
    );
}

function formatRelativeTime(dateStr: string | null | undefined): string {
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
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function daysUntil(dateStr: string): number {
    const d = new Date(dateStr);
    const now = new Date();
    return Math.ceil((d.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
}

export default function Dashboard() {
    const { data, loading, refresh } = useApi<DashboardData>('/api/v1/accountant/dashboard');
    const { startImpersonation } = useImpersonation();
    const [copiedLink, setCopiedLink] = useState(false);
    const [toast, setToast] = useState('');

    const totalClients = data?.total_clients ?? 0;
    const docsPending = data?.documents_pending_review ?? 0;
    const openRequests = data?.open_requests ?? 0;
    const deadlines = data?.upcoming_deadlines ?? [];
    const clients = data?.clients ?? [];
    const firm = data?.firm ?? null;

    const nearestDeadline = deadlines.length > 0 ? deadlines[0] : null;
    const nearestDeadlineLabel = nearestDeadline
        ? new Date(nearestDeadline.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
        : 'None';

    const handleCopyInviteLink = async () => {
        try {
            const res = await fetch('/api/v1/accountant/firm/invite-link');
            const json = await res.json();
            if (json.url) {
                await navigator.clipboard.writeText(json.url);
                setCopiedLink(true);
                setToast('Invite link copied to clipboard');
                setTimeout(() => { setCopiedLink(false); setToast(''); }, 3000);
            }
        } catch {
            setToast('Failed to copy invite link');
            setTimeout(() => setToast(''), 3000);
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

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center gap-3">
                    <h1 className="text-xl font-bold text-sw-text tracking-tight">Accountant Dashboard</h1>
                    {firm && (
                        <span className="px-2 py-0.5 rounded-full bg-sw-accent/10 text-sw-accent text-xs font-bold">
                            {firm.name}
                        </span>
                    )}
                </div>
            }
        >
            <Head title="Accountant Dashboard" />

            <div className="max-w-6xl mx-auto space-y-6">
                {/* Toast */}
                {toast && (
                    <div aria-live="polite">
                        <div className="flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent/10 border border-sw-accent/30 text-sw-accent text-xs font-medium">
                            <CheckCircle size={14} /> {toast}
                        </div>
                    </div>
                )}

                {loading && (
                    <div className="flex items-center justify-center py-12">
                        <Loader2 size={24} className="animate-spin text-sw-accent" />
                    </div>
                )}

                {!loading && (
                    <>
                        {/* Stats Bar */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <StatCard icon={Users} label="Total Clients" value={totalClients} />
                            <StatCard icon={FileText} label="Documents Pending Review" value={docsPending} />
                            <StatCard icon={AlertCircle} label="Open Requests" value={openRequests} />
                            <StatCard icon={Calendar} label="Upcoming Deadline" value={nearestDeadlineLabel} accent />
                        </div>

                        {/* Firm Registration CTA if no firm */}
                        {!firm && <FirmRegistrationForm onCreated={refresh} />}

                        {/* Invite Link Generator */}
                        {firm && (
                            <div className="rounded-xl border border-sw-border bg-sw-card p-4 flex items-center justify-between">
                                <div>
                                    <h3 className="text-sm font-semibold text-sw-text">{firm.name}</h3>
                                    <p className="text-xs text-sw-muted mt-0.5">Share your invite link with clients to connect them to your firm.</p>
                                </div>
                                <button
                                    onClick={handleCopyInviteLink}
                                    className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition shrink-0"
                                >
                                    {copiedLink ? <CheckCircle size={14} /> : <Copy size={14} />}
                                    {copiedLink ? 'Copied!' : 'Copy Invite Link'}
                                </button>
                            </div>
                        )}

                        {/* Client List Table */}
                        <div className="rounded-xl border border-sw-border bg-sw-card overflow-hidden">
                            <div className="px-4 py-3 border-b border-sw-border">
                                <h3 className="text-sm font-semibold text-sw-text">Clients</h3>
                            </div>
                            {clients.length === 0 ? (
                                <div className="p-8 text-center">
                                    <Users size={32} className="mx-auto text-sw-dim mb-2" />
                                    <p className="text-sm text-sw-muted">No clients yet. Share your invite link to get started.</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-sw-border bg-sw-surface/50">
                                                <th className="text-left px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide">Name</th>
                                                <th className="text-left px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide hidden sm:table-cell">Documents</th>
                                                <th className="text-left px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide hidden md:table-cell">Completeness</th>
                                                <th className="text-left px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide hidden lg:table-cell">Last Activity</th>
                                                <th className="text-left px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide hidden sm:table-cell">Requests</th>
                                                <th className="text-left px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide">Status</th>
                                                <th className="text-right px-4 py-3 text-xs font-semibold text-sw-muted uppercase tracking-wide">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-sw-border">
                                            {clients.map((client) => (
                                                <tr
                                                    key={client.id}
                                                    className="hover:bg-sw-surface/30 transition cursor-pointer"
                                                    onClick={() => handleImpersonate(client.id)}
                                                >
                                                    <td className="px-4 py-3">
                                                        <div className="flex items-center gap-3">
                                                            <div className="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-indigo-500 flex items-center justify-center text-white text-xs font-bold shrink-0">
                                                                {client.name.charAt(0).toUpperCase()}
                                                            </div>
                                                            <div>
                                                                <div className="font-medium text-sw-text">{client.name}</div>
                                                                <div className="text-xs text-sw-dim">{client.email}</div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3 hidden sm:table-cell">
                                                        <span className="text-sw-text">{client.document_count}</span>
                                                    </td>
                                                    <td className="px-4 py-3 hidden md:table-cell">
                                                        <div className="flex items-center gap-2">
                                                            <div className="flex-1 max-w-[100px] h-2 rounded-full bg-sw-border overflow-hidden">
                                                                <div
                                                                    className="h-full rounded-full bg-sw-accent transition-all"
                                                                    style={{ width: `${Math.min(client.completeness, 100)}%` }}
                                                                />
                                                            </div>
                                                            <span className="text-xs text-sw-muted">{client.completeness}%</span>
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3 hidden lg:table-cell">
                                                        <span className="text-xs text-sw-muted flex items-center gap-1">
                                                            <Clock size={12} />
                                                            {formatRelativeTime(client.last_activity)}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 hidden sm:table-cell">
                                                        {client.open_requests > 0 ? (
                                                            <span className="inline-flex items-center gap-1 text-xs text-amber-600 font-medium">
                                                                <AlertCircle size={12} /> {client.open_requests}
                                                            </span>
                                                        ) : (
                                                            <span className="text-xs text-sw-dim">0</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant={client.status === 'active' ? 'success' : 'warning'}>
                                                            {client.status === 'active' ? 'Active' : 'Pending'}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <button
                                                            onClick={(e) => { e.stopPropagation(); handleImpersonate(client.id); }}
                                                            className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition"
                                                        >
                                                            <Eye size={12} /> View
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>

                        {/* Deadline Tracker */}
                        {deadlines.length > 0 && (
                            <div className="rounded-xl border border-sw-border bg-sw-card p-4">
                                <h3 className="text-sm font-semibold text-sw-text mb-3 flex items-center gap-2">
                                    <Calendar size={14} className="text-sw-accent" /> Upcoming Deadlines
                                </h3>
                                <div className="space-y-2">
                                    {deadlines.map((deadline, idx) => {
                                        const days = daysUntil(deadline.date);
                                        const isUrgent = days <= 30 && days >= 0;
                                        return (
                                            <div
                                                key={idx}
                                                className={`flex items-center justify-between px-3 py-2 rounded-lg border ${
                                                    isUrgent
                                                        ? 'border-amber-200 bg-amber-50'
                                                        : 'border-sw-border bg-sw-surface/30'
                                                }`}
                                            >
                                                <div className="flex items-center gap-3">
                                                    <Calendar size={14} className={isUrgent ? 'text-amber-600' : 'text-sw-muted'} />
                                                    <span className={`text-sm font-medium ${isUrgent ? 'text-amber-900' : 'text-sw-text'}`}>
                                                        {deadline.label}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <span className={`text-xs font-medium ${isUrgent ? 'text-amber-700' : 'text-sw-muted'}`}>
                                                        {new Date(deadline.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                                                    </span>
                                                    {isUrgent && (
                                                        <Badge variant="warning">{days}d</Badge>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
