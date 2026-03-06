import { useEffect, useState, useCallback } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Plus, Search, CheckCircle, XCircle, ExternalLink, Pencil, Trash2, Loader2, Star } from 'lucide-react';
import type { CharitableOrganization, AdminCharitiesResponse } from '@/types/spendifiai';
import axios from 'axios';

export default function CharitiesIndex() {
  const [data, setData] = useState<AdminCharitiesResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [page, setPage] = useState(1);
  const [deleting, setDeleting] = useState<number | null>(null);

  const fetchCharities = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('per_page', '25');
    if (search) params.set('search', search);
    if (category) params.set('category', category);

    axios.get(`/api/admin/charities?${params.toString()}`).then((res) => {
      setData(res.data);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, [page, search, category]);

  useEffect(() => { fetchCharities(); }, [fetchCharities]);

  const handleDelete = async (id: number) => {
    if (!confirm('Delete this charity?')) return;
    setDeleting(id);
    try {
      await axios.delete(`/api/admin/charities/${id}`);
      fetchCharities();
    } catch { /* ignore */ }
    setDeleting(null);
  };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    fetchCharities();
  };

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-sw-text">Charitable Organizations</h1>
            <p className="text-xs text-sw-dim mt-0.5">{data?.meta.total ?? 0} organizations</p>
          </div>
          <Link
            href="/admin/charities/create"
            className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition"
          >
            <Plus size={14} /> Add Charity
          </Link>
        </div>
      }
    >
      <Head title="Charities" />

      {/* Search + filter */}
      <form onSubmit={handleSearch} className="flex items-center gap-3 mb-6">
        <div className="relative flex-1 max-w-sm">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-sw-dim" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search organization name..."
            className="w-full pl-9 pr-3 py-2 text-xs rounded-lg border border-sw-border bg-sw-card text-sw-text placeholder:text-sw-placeholder focus:outline-none focus:border-sw-accent"
          />
        </div>
        <select
          value={category}
          onChange={(e) => { setCategory(e.target.value); setPage(1); }}
          className="px-3 py-2 text-xs rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
        >
          <option value="">All Categories</option>
          {['Religious', 'Humanitarian', 'Health', 'Education', 'Environment', 'Community', 'Animal Welfare'].map((c) => (
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
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">Organization</th>
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">Category</th>
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">EIN</th>
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">Donate URL</th>
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">Featured</th>
                <th className="text-left px-4 py-2.5 text-sw-dim font-medium">Active</th>
                <th className="text-right px-4 py-2.5 text-sw-dim font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading && !data && (
                <tr><td colSpan={7} className="px-4 py-8 text-center text-sw-dim">Loading...</td></tr>
              )}
              {data?.charities.map((c: CharitableOrganization) => (
                <tr key={c.id} className="border-b border-sw-border last:border-b-0 hover:bg-sw-surface/50 transition">
                  <td className="px-4 py-2.5 text-sw-text font-medium">{c.name}</td>
                  <td className="px-4 py-2.5 text-sw-muted">{c.category ?? '—'}</td>
                  <td className="px-4 py-2.5 text-sw-muted font-mono text-[10px]">{c.ein ?? '—'}</td>
                  <td className="px-4 py-2.5">
                    {c.donate_url ? (
                      <a href={c.donate_url} target="_blank" rel="noopener noreferrer" className="text-sw-accent hover:underline inline-flex items-center gap-1">
                        <ExternalLink size={11} /> Link
                      </a>
                    ) : (
                      <span className="text-sw-dim">—</span>
                    )}
                  </td>
                  <td className="px-4 py-2.5">
                    {c.is_featured ? <Star size={14} className="text-amber-500 fill-amber-500" /> : <Star size={14} className="text-sw-dim" />}
                  </td>
                  <td className="px-4 py-2.5">
                    {c.is_active ? <CheckCircle size={14} className="text-sw-success" /> : <XCircle size={14} className="text-sw-dim" />}
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    <div className="flex items-center justify-end gap-1">
                      <Link
                        href={`/admin/charities/${c.id}/edit`}
                        className="p-1.5 rounded hover:bg-sw-surface text-sw-muted hover:text-sw-accent transition"
                      >
                        <Pencil size={13} />
                      </Link>
                      <button
                        onClick={() => handleDelete(c.id)}
                        disabled={deleting === c.id}
                        className="p-1.5 rounded hover:bg-sw-danger-light text-sw-muted hover:text-sw-danger transition disabled:opacity-50"
                      >
                        {deleting === c.id ? <Loader2 size={13} className="animate-spin" /> : <Trash2 size={13} />}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {!loading && data?.charities.length === 0 && (
                <tr><td colSpan={7} className="px-4 py-8 text-center text-sw-dim">No organizations found</td></tr>
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
