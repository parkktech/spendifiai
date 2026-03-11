import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { Save, Loader2, Trash2 } from 'lucide-react';
import type { CharitableOrganization } from '@/types/spendifiai';
import axios from 'axios';

interface FormData {
  name: string;
  description: string;
  website_url: string;
  donate_url: string;
  category: string;
  ein: string;
  is_featured: boolean;
  is_active: boolean;
  sort_order: number;
}

const categories = ['Religious', 'Humanitarian', 'Health', 'Education', 'Environment', 'Community', 'Animal Welfare'];

export default function EditCharity() {
  const charityProp = (usePage().props as unknown as { charity: number | { id: number } }).charity;
  const charityId = typeof charityProp === 'object' ? charityProp.id : charityProp;
  const [charity, setCharity] = useState<CharitableOrganization | null>(null);
  const [form, setForm] = useState<FormData | null>(null);
  const [saving, setSaving] = useState(false);
  const [loadError, setLoadError] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    axios.get(`/api/admin/charities/${charityId}`).then((res) => {
      const c = res.data.charity as CharitableOrganization;
      setCharity(c);
      setForm({
        name: c.name,
        description: c.description ?? '',
        website_url: c.website_url ?? '',
        donate_url: c.donate_url ?? '',
        category: c.category ?? '',
        ein: c.ein ?? '',
        is_featured: c.is_featured,
        is_active: c.is_active,
        sort_order: c.sort_order,
      });
    }).catch(() => setLoadError(true));
  }, [charityId]);

  if (loadError) {
    return (
      <AuthenticatedLayout header={<h1 className="text-xl font-bold text-sw-text">Organization Not Found</h1>}>
        <Head title="Edit Charity" />
        <p className="text-sm text-sw-muted">Could not load organization.</p>
      </AuthenticatedLayout>
    );
  }

  if (!form || !charity) {
    return (
      <AuthenticatedLayout header={<h1 className="text-xl font-bold text-sw-text">Edit Organization</h1>}>
        <Head title="Edit Charity" />
        <div className="animate-pulse space-y-4 max-w-2xl">
          {[1, 2, 3, 4].map((i) => <div key={i} className="h-10 rounded-lg bg-sw-border" />)}
        </div>
      </AuthenticatedLayout>
    );
  }

  const updateField = (field: keyof FormData, value: unknown) => {
    setForm((prev) => prev ? { ...prev, [field]: value } : prev);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await axios.patch(`/api/admin/charities/${charity.id}`, form);
      router.visit('/admin/charities');
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { errors?: Record<string, string[]> } } };
      if (axiosErr.response?.data?.errors) {
        setErrors(axiosErr.response.data.errors);
      }
    }
    setSaving(false);
  };

  const handleDelete = async () => {
    if (!confirm(`Delete "${charity.name}"? This cannot be undone.`)) return;
    try {
      await axios.delete(`/api/admin/charities/${charity.id}`);
      router.visit('/admin/charities');
    } catch { /* ignore */ }
  };

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-sw-text">Edit: {charity.name}</h1>
            <p className="text-xs text-sw-dim mt-0.5">Update charitable organization details</p>
          </div>
          <button
            onClick={handleDelete}
            className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-sw-danger/30 text-sw-danger hover:bg-sw-danger/5 text-xs font-medium transition"
          >
            <Trash2 size={14} /> Delete
          </button>
        </div>
      }
    >
      <Head title={`Edit ${charity.name}`} />

      <form onSubmit={handleSubmit} className="max-w-2xl space-y-6">
        {/* Name */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Organization Name</label>
          <input
            type="text"
            value={form.name}
            onChange={(e) => updateField('name', e.target.value)}
            className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
          />
          {errors.name && <p className="text-xs text-sw-danger mt-1">{errors.name[0]}</p>}
        </div>

        {/* Description */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Description</label>
          <textarea
            value={form.description}
            onChange={(e) => updateField('description', e.target.value)}
            rows={3}
            placeholder="Brief description of the organization's mission..."
            className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent resize-none"
          />
        </div>

        {/* Category + EIN row */}
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-xs font-medium text-sw-text mb-1">Category</label>
            <select
              value={form.category}
              onChange={(e) => updateField('category', e.target.value)}
              className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
            >
              <option value="">Select...</option>
              {categories.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-sw-text mb-1">EIN (Tax ID)</label>
            <input
              type="text"
              value={form.ein}
              onChange={(e) => updateField('ein', e.target.value)}
              placeholder="XX-XXXXXXX"
              className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
            />
          </div>
        </div>

        {/* Website URL */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Website URL</label>
          <input
            type="url"
            value={form.website_url}
            onChange={(e) => updateField('website_url', e.target.value)}
            placeholder="https://..."
            className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
          />
        </div>

        {/* Donate URL */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Donate URL</label>
          <input
            type="url"
            value={form.donate_url}
            onChange={(e) => updateField('donate_url', e.target.value)}
            placeholder="https://..."
            className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
          />
        </div>

        {/* Sort order */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Sort Order</label>
          <input
            type="number"
            value={form.sort_order}
            onChange={(e) => updateField('sort_order', parseInt(e.target.value) || 0)}
            min={0}
            className="w-32 px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
          />
        </div>

        {/* Toggles */}
        <div className="space-y-3">
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              checked={form.is_featured}
              onChange={(e) => updateField('is_featured', e.target.checked)}
              className="rounded border-sw-border text-sw-accent focus:ring-sw-accent"
            />
            <span className="text-xs text-sw-text">Featured (shown on dashboard when user has no donations)</span>
          </label>
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(e) => updateField('is_active', e.target.checked)}
              className="rounded border-sw-border text-sw-accent focus:ring-sw-accent"
            />
            <span className="text-xs text-sw-text">Active</span>
          </label>
        </div>

        {/* Actions */}
        <div className="flex items-center gap-3 pt-2">
          <button
            type="submit"
            disabled={saving}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-sm font-semibold transition disabled:opacity-50"
          >
            {saving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
            Save Changes
          </button>
        </div>
      </form>
    </AuthenticatedLayout>
  );
}
