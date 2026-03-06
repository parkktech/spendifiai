import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { Save, Loader2 } from 'lucide-react';
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

export default function CreateCharity() {
  const [form, setForm] = useState<FormData>({
    name: '',
    description: '',
    website_url: '',
    donate_url: '',
    category: '',
    ein: '',
    is_featured: true,
    is_active: true,
    sort_order: 0,
  });
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const updateField = (field: keyof FormData, value: unknown) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await axios.post('/api/admin/charities', form);
      router.visit('/admin/charities');
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { errors?: Record<string, string[]> } } };
      if (axiosErr.response?.data?.errors) {
        setErrors(axiosErr.response.data.errors);
      }
    }
    setSaving(false);
  };

  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text">Add Charitable Organization</h1>
          <p className="text-xs text-sw-dim mt-0.5">Add a new 501(c)(3) organization recommendation</p>
        </div>
      }
    >
      <Head title="Add Charity" />

      <form onSubmit={handleSubmit} className="max-w-2xl space-y-6">
        {/* Name */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Organization Name *</label>
          <input
            type="text"
            value={form.name}
            onChange={(e) => updateField('name', e.target.value)}
            className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
            required
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
            Save Organization
          </button>
        </div>
      </form>
    </AuthenticatedLayout>
  );
}
