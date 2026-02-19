import { useEffect, useState, useCallback } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Plus, Search, CheckCircle, XCircle, ExternalLink, Pencil, Trash2, Loader2 } from 'lucide-react';
import type { CancellationProvider, AdminProvidersResponse } from '@/types/spendifiai';
import axios from 'axios';

const diffBadge: Record<string, string> = {
  easy: 'bg-green-500/10 text-green-600',
  medium: 'bg-yellow-500/10 text-yellow-600',
  hard: 'bg-red-500/10 text-red-600',
};

export default function ProvidersIndex() {
  const [data, setData] = useState<AdminProvidersResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [page, setPage] = useState(1);
  const [deleting, setDeleting] = useState<number | null>(null);

  const fetchProviders = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('per_page', '25');
    if (search) params.set('search', search);
    if (category) params.set('category', category);

    axios.get(`/api/admin/providers?${params.toString()}`).then((res) => {
      setData(res.data);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, [page, search, category]);

  useEffect(() => { fetchProviders(); }, [fetchProviders]);

  const handleDelete = async (id: number) => {
    if (!confirm('Delete this provider?')) return;
    setDeleting(id);
    try {
      await axios.delete(`/api/admin/providers/${id}`);
      fetchProviders();
    } catch { /* ignore */ }
    setDeleting(null);
  };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    fetchProviders();
  };

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-sw-text">Cancellation Providers</h1>
            <p className="text-xs text-sw-dim mt-0.5">{data?.meta.total ?? 0} providers</p>
          </div>
          <Link
            href="/admin/providers/create"
            className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition"
          >
            <Plus size={14} /> Add Provider
          </Link>
        </div>
      }
    >
      <Head title="Providers" />

      {/* Search + filter */}
      <form onSubmit={handleSearch} className="flex items-center gap-3 mb-6">
        <div className="relative flex-1 max-w-sm">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-sw-dim" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search company name..."
            className="w-full pl-9 pr-3 py-2 text-xs rounded-lg border border-sw-border bg-sw-card text-sw-text placeholder:text-sw-placeholder focus:outline-none focus:border-sw-accent"
          />
        </div>
        <select
          value={category}
          onChange={(e) => { setCategory(e.target.value); setPage(1); }}
          className="px-3 py-2 text-xs rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
        >
          <option value="">All Categories</option>
          {['Streaming', 'Music', 'Software', 'VPN/Security', 'Finance', 'Fitness/Health', 'Gaming', 'Shopping', 'Hosting/Dev', 'Phone', 'Internet', 'Insurance', 'Utilities'].map((c) => (
            <option key={c} value={c}>{c}</option>
          ))}
        </select>
        <button type="submit" className="px-3 py-2 text-xs rounded-lg bg-sw-accent text-white font-medium hover:bg-sw-accent-hover transition">
          Search
        </button>
      </form>

      {/* Table */}
      <div className="rounded-lg border border-sw-border bg-sw-card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-sw-border bg-sw-surface">
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">Company</th>
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">Category</th>
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">Difficulty</th>
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">Aliases</th>
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">URL</th>
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">Verified</th>
                <th className="text-right px-4 py-2.5 text-sw-dim font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading && !data && (
                <tr><td colSpan={7} className="px-4 py-8 text-center text-sw-dim">Loading...</td></tr>
              )}
              {data?.providers.map((p: CancellationProvider) => (
                <tr key={p.id} className="border-b border-sw-border last:border-b-0 hover:bg-sw-surface/50 transition">
                  <td className="px-4 py-2.5 text-sw-text font-medium">{p.company_name}</td>
                  <td className="px-4 py-2.5 text-sw-muted">{p.category ?? '—'}</td>
                  <td className="px-4 py-2.5">
                    <span className={`inline-flex text-[10px] font-semibold px-1.5 py-0.5 rounded ${diffBadge[p.difficulty]}`}>
                      {p.difficulty}
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-sw-muted">{p.aliases.length}</td>
                  <td className="px-4 py-2.5">
                    {p.cancellation_url ? (
                      <a href={p.cancellation_url} target="_blank" rel="noopener noreferrer" className="text-sw-accent hover:underline inline-flex items-center gap-1">
                        <ExternalLink size={11} /> Link
                      </a>
                    ) : (
                      <span className="text-sw-dim">—</span>
                    )}
                  </td>
                  <td className="px-4 py-2.5">
                    {p.is_verified ? <CheckCircle size={14} className="text-sw-success" /> : <XCircle size={14} className="text-sw-dim" />}
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    <div className="flex items-center justify-end gap-1">
                      <Link
                        href={`/admin/providers/${p.id}/edit`}
                        className="p-1.5 rounded hover:bg-sw-surface text-sw-muted hover:text-sw-accent transition"
                      >
                        <Pencil size={13} />
                      </Link>
                      <button
                        onClick={() => handleDelete(p.id)}
                        disabled={deleting === p.id}
                        className="p-1.5 rounded hover:bg-sw-danger-light text-sw-muted hover:text-sw-danger transition disabled:opacity-50"
                      >
                        {deleting === p.id ? <Loader2 size={13} className="animate-spin" /> : <Trash2 size={13} />}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {!loading && data?.providers.length === 0 && (
                <tr><td colSpan={7} className="px-4 py-8 text-center text-sw-dim">No providers found</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Pagination */}
      {data && data.meta.last_page > 1 && (
        <div className="flex items-center justify-between mt-4">
          <span className="text-xs text-sw-dim">
            Page {data.meta.current_page} of {data.meta.last_page}
          </span>
          <div className="flex items-center gap-2">
            <button
              onClick={() => setPage(Math.max(1, page - 1))}
              disabled={page === 1}
              className="px-3 py-1.5 text-xs rounded-lg border border-sw-border text-sw-muted hover:text-sw-text disabled:opacity-40 transition"
            >
              Previous
            </button>
            <button
              onClick={() => setPage(Math.min(data.meta.last_page, page + 1))}
              disabled={page === data.meta.last_page}
              className="px-3 py-1.5 text-xs rounded-lg border border-sw-border text-sw-muted hover:text-sw-text disabled:opacity-40 transition"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </AuthenticatedLayout>
  );
}
