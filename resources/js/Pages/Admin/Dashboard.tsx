import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Database, CheckCircle, XCircle, Link2, ChevronRight, Heart, Star, Users, Activity, Landmark, ShieldCheck, HardDrive } from 'lucide-react';
import type { AdminStats, AdminCharityStats } from '@/types/spendifiai';
import axios from 'axios';

interface UserStats {
  total_users: number;
  verified_users: number;
  with_bank: number;
  recently_active: number;
  most_active: Array<{
    id: number;
    name: string;
    email: string;
    last_active_at: string | null;
    created_at: string;
  }>;
}

function timeAgo(dateStr: string): string {
  const now = Date.now();
  const then = new Date(dateStr).getTime();
  const diffMs = now - then;
  const mins = Math.floor(diffMs / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  const days = Math.floor(hrs / 24);
  if (days < 7) return `${days}d ago`;
  return new Date(dateStr).toLocaleDateString();
}

export default function AdminDashboard() {
  const [stats, setStats] = useState<AdminStats | null>(null);
  const [charityStats, setCharityStats] = useState<AdminCharityStats | null>(null);
  const [userStats, setUserStats] = useState<UserStats | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      axios.get('/api/admin/stats'),
      axios.get('/api/admin/charities/stats'),
      axios.get('/api/admin/user-stats'),
    ]).then(([statsRes, charityRes, userRes]) => {
      setStats(statsRes.data);
      setCharityStats(charityRes.data);
      setUserStats(userRes.data);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <AuthenticatedLayout header={<h1 className="text-xl font-bold text-sw-text">Admin Dashboard</h1>}>
        <Head title="Admin" />
        <div className="animate-pulse space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {[1, 2, 3, 4].map((i) => (
              <div key={i} className="h-24 rounded-lg bg-sw-card border border-sw-border" />
            ))}
          </div>
        </div>
      </AuthenticatedLayout>
    );
  }

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-sw-text">Admin Dashboard</h1>
            <p className="text-xs text-sw-dim mt-0.5">Manage providers and charitable organizations</p>
          </div>
          <div className="flex items-center gap-2">
            <Link
              href="/admin/storage"
              className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-sw-border text-sw-muted hover:bg-sw-accent/5 hover:text-sw-accent text-xs font-semibold transition"
            >
              <HardDrive size={14} /> Storage Settings
            </Link>
            <Link
              href="/admin/charities"
              className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-sw-accent text-sw-accent hover:bg-sw-accent/5 text-xs font-semibold transition"
            >
              Manage Charities <Heart size={14} />
            </Link>
            <Link
              href="/admin/providers"
              className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition"
            >
              Manage Providers <ChevronRight size={14} />
            </Link>
          </div>
        </div>
      }
    >
      <Head title="Admin" />

      {/* User Stats */}
      {userStats && (
        <div className="mb-8">
          <h2 className="text-sm font-semibold text-sw-text mb-3">Users</h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
            {[
              { label: 'Total Users', value: userStats.total_users, icon: <Users size={18} />, color: 'text-sw-accent' },
              { label: 'Email Verified', value: userStats.verified_users, icon: <ShieldCheck size={18} />, color: 'text-sw-success' },
              { label: 'Bank Connected', value: userStats.with_bank, icon: <Landmark size={18} />, color: 'text-sw-info' },
              { label: 'Active (7d)', value: userStats.recently_active, icon: <Activity size={18} />, color: 'text-emerald-600' },
            ].map((stat) => (
              <div key={stat.label} className="rounded-lg border border-sw-border bg-sw-card p-4">
                <div className="flex items-center gap-3">
                  <div className={`${stat.color}`}>{stat.icon}</div>
                  <div>
                    <div className="text-2xl font-bold text-sw-text">{stat.value}</div>
                    <div className="text-xs text-sw-dim">{stat.label}</div>
                  </div>
                </div>
              </div>
            ))}
          </div>

          {/* Most Active Users */}
          {userStats.most_active.length > 0 && (
            <div className="rounded-lg border border-sw-border bg-sw-card overflow-hidden">
              <div className="px-4 py-2.5 border-b border-sw-border bg-sw-surface">
                <span className="text-xs font-semibold text-sw-muted">Most Recently Active</span>
              </div>
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-sw-border">
                    <th className="text-left px-4 py-2 text-sw-dim font-medium">Name</th>
                    <th className="text-left px-4 py-2 text-sw-dim font-medium">Email</th>
                    <th className="text-left px-4 py-2 text-sw-dim font-medium">Last Active</th>
                    <th className="text-left px-4 py-2 text-sw-dim font-medium">Joined</th>
                  </tr>
                </thead>
                <tbody>
                  {userStats.most_active.map((u) => (
                    <tr key={u.id} className="border-b border-sw-border last:border-b-0 hover:bg-sw-surface/50">
                      <td className="px-4 py-2 text-sw-text font-medium">{u.name}</td>
                      <td className="px-4 py-2 text-sw-muted">{u.email}</td>
                      <td className="px-4 py-2 text-sw-muted">
                        {u.last_active_at ? timeAgo(u.last_active_at) : '—'}
                      </td>
                      <td className="px-4 py-2 text-sw-dim">
                        {new Date(u.created_at).toLocaleDateString()}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* Provider Stats */}
      <h2 className="text-sm font-semibold text-sw-text mb-3">Cancellation Providers</h2>
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        {[
          { label: 'Total Providers', value: stats?.total_providers ?? 0, icon: <Database size={18} />, color: 'text-sw-accent' },
          { label: 'Verified', value: stats?.verified_providers ?? 0, icon: <CheckCircle size={18} />, color: 'text-sw-success' },
          { label: 'Unverified', value: stats?.unverified_providers ?? 0, icon: <XCircle size={18} />, color: 'text-sw-warning' },
          { label: 'With Cancel URL', value: stats?.with_cancellation_url ?? 0, icon: <Link2 size={18} />, color: 'text-sw-info' },
        ].map((stat) => (
          <div key={stat.label} className="rounded-lg border border-sw-border bg-sw-card p-4">
            <div className="flex items-center gap-3">
              <div className={`${stat.color}`}>{stat.icon}</div>
              <div>
                <div className="text-2xl font-bold text-sw-text">{stat.value}</div>
                <div className="text-xs text-sw-dim">{stat.label}</div>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Charity Stats */}
      {charityStats && (
        <div className="mb-8">
          <h2 className="text-sm font-semibold text-sw-text mb-3">Charitable Organizations</h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {[
              { label: 'Total Charities', value: charityStats.total_charities, icon: <Heart size={18} />, color: 'text-emerald-600' },
              { label: 'Active', value: charityStats.active_charities, icon: <CheckCircle size={18} />, color: 'text-sw-success' },
              { label: 'Featured', value: charityStats.featured_charities, icon: <Star size={18} />, color: 'text-amber-500' },
              { label: 'With Donate URL', value: charityStats.with_donate_url, icon: <Link2 size={18} />, color: 'text-sw-info' },
            ].map((stat) => (
              <div key={stat.label} className="rounded-lg border border-sw-border bg-sw-card p-4">
                <div className="flex items-center gap-3">
                  <div className={`${stat.color}`}>{stat.icon}</div>
                  <div>
                    <div className="text-2xl font-bold text-sw-text">{stat.value}</div>
                    <div className="text-xs text-sw-dim">{stat.label}</div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Categories */}
      {stats?.categories && stats.categories.length > 0 && (
        <div className="mb-8">
          <h2 className="text-sm font-semibold text-sw-text mb-3">By Category</h2>
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
            {stats.categories.map((cat) => (
              <div key={cat.category} className="rounded-lg border border-sw-border bg-sw-card px-3 py-2 flex items-center justify-between">
                <span className="text-xs text-sw-text truncate">{cat.category}</span>
                <span className="text-xs font-bold text-sw-accent ml-2">{cat.count}</span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Recently Added */}
      {stats?.recently_added && stats.recently_added.length > 0 && (
        <div>
          <h2 className="text-sm font-semibold text-sw-text mb-3">Recently Added</h2>
          <div className="rounded-lg border border-sw-border bg-sw-card overflow-hidden">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-sw-border bg-sw-surface">
                  <th className="text-left px-4 py-2 text-sw-dim font-medium">Company</th>
                  <th className="text-left px-4 py-2 text-sw-dim font-medium">Category</th>
                  <th className="text-left px-4 py-2 text-sw-dim font-medium">Difficulty</th>
                  <th className="text-left px-4 py-2 text-sw-dim font-medium">Verified</th>
                </tr>
              </thead>
              <tbody>
                {stats.recently_added.map((p) => (
                  <tr key={p.id} className="border-b border-sw-border last:border-b-0">
                    <td className="px-4 py-2 text-sw-text font-medium">{p.company_name}</td>
                    <td className="px-4 py-2 text-sw-muted">{p.category ?? '—'}</td>
                    <td className="px-4 py-2">
                      <span className={`inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded ${
                        p.difficulty === 'easy' ? 'bg-green-500/10 text-green-600' :
                        p.difficulty === 'medium' ? 'bg-yellow-500/10 text-yellow-600' :
                        'bg-red-500/10 text-red-600'
                      }`}>{p.difficulty}</span>
                    </td>
                    <td className="px-4 py-2">{p.is_verified ? <CheckCircle size={14} className="text-sw-success" /> : <XCircle size={14} className="text-sw-dim" />}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </AuthenticatedLayout>
  );
}
