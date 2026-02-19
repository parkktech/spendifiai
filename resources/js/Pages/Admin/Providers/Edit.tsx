import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { Save, Sparkles, Loader2, X, Plus, Trash2 } from 'lucide-react';
import type { CancellationProvider } from '@/types/spendifiai';
import axios from 'axios';

interface FormData {
  company_name: string;
  aliases: string[];
  cancellation_url: string;
  cancellation_phone: string;
  cancellation_instructions: string;
  difficulty: 'easy' | 'medium' | 'hard';
  category: string;
  is_essential: boolean;
  is_verified: boolean;
}

const categories = ['Streaming', 'Music', 'Software', 'VPN/Security', 'Finance', 'Fitness/Health', 'Gaming', 'Shopping', 'Hosting/Dev', 'Phone', 'Internet', 'Insurance', 'Utilities'];

export default function EditProvider() {
  const { provider: providerId } = usePage().props as unknown as { provider: number };
  const [provider, setProvider] = useState<CancellationProvider | null>(null);
  const [form, setForm] = useState<FormData | null>(null);
  const [saving, setSaving] = useState(false);
  const [findingLink, setFindingLink] = useState(false);
  const [loadError, setLoadError] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    axios.get(`/api/admin/providers/${providerId}`).then((res) => {
      const p = res.data.provider as CancellationProvider;
      setProvider(p);
      setForm({
        company_name: p.company_name,
        aliases: p.aliases.length > 0 ? p.aliases : [''],
        cancellation_url: p.cancellation_url ?? '',
        cancellation_phone: p.cancellation_phone ?? '',
        cancellation_instructions: p.cancellation_instructions ?? '',
        difficulty: p.difficulty,
        category: p.category ?? '',
        is_essential: p.is_essential,
        is_verified: p.is_verified,
      });
    }).catch(() => setLoadError(true));
  }, [providerId]);

  if (loadError) {
    return (
      <AuthenticatedLayout header={<h1 className="text-xl font-bold text-sw-text">Provider Not Found</h1>}>
        <Head title="Edit Provider" />
        <p className="text-sm text-sw-muted">Could not load provider.</p>
      </AuthenticatedLayout>
    );
  }

  if (!form || !provider) {
    return (
      <AuthenticatedLayout header={<h1 className="text-xl font-bold text-sw-text">Edit Provider</h1>}>
        <Head title="Edit Provider" />
        <div className="animate-pulse space-y-4 max-w-2xl">
          {[1, 2, 3, 4].map((i) => <div key={i} className="h-10 rounded-lg bg-sw-border" />)}
        </div>
      </AuthenticatedLayout>
    );
  }

  const updateField = (field: keyof FormData, value: unknown) => {
    setForm((prev) => prev ? { ...prev, [field]: value } : prev);
  };

  const addAlias = () => setForm((prev) => prev ? { ...prev, aliases: [...prev.aliases, ''] } : prev);
  const removeAlias = (idx: number) => setForm((prev) => prev ? { ...prev, aliases: prev.aliases.filter((_, i) => i !== idx) } : prev);
  const updateAlias = (idx: number, val: string) => {
    setForm((prev) => {
      if (!prev) return prev;
      const aliases = [...prev.aliases];
      aliases[idx] = val;
      return { ...prev, aliases };
    });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      const payload = { ...form, aliases: form.aliases.filter(Boolean) };
      await axios.patch(`/api/admin/providers/${provider.id}`, payload);
      router.visit('/admin/providers');
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { errors?: Record<string, string[]> } } };
      if (axiosErr.response?.data?.errors) {
        setErrors(axiosErr.response.data.errors);
      }
    }
    setSaving(false);
  };

  const handleFindLink = async () => {
    setFindingLink(true);
    try {
      const res = await axios.post(`/api/admin/providers/${provider.id}/find-link`);
      const result = res.data.ai_result;
      if (result) {
        setForm((prev) => prev ? {
          ...prev,
          cancellation_url: result.cancellation_url || prev.cancellation_url,
          cancellation_phone: result.cancellation_phone || prev.cancellation_phone,
          cancellation_instructions: result.cancellation_instructions || prev.cancellation_instructions,
          difficulty: result.difficulty || prev.difficulty,
        } : prev);
      }
    } catch { /* ignore */ }
    setFindingLink(false);
  };

  const handleDelete = async () => {
    if (!confirm(`Delete "${provider.company_name}"? This cannot be undone.`)) return;
    try {
      await axios.delete(`/api/admin/providers/${provider.id}`);
      router.visit('/admin/providers');
    } catch { /* ignore */ }
  };

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-sw-text">Edit: {provider.company_name}</h1>
            <p className="text-xs text-sw-dim mt-0.5">Update cancellation provider details</p>
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
      <Head title={`Edit ${provider.company_name}`} />

      <form onSubmit={handleSubmit} className="max-w-2xl space-y-6">
        {/* Company Name */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Company Name</label>
          <input
            type="text"
            value={form.company_name}
            onChange={(e) => updateField('company_name', e.target.value)}
            className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
          />
          {errors.company_name && <p className="text-xs text-sw-danger mt-1">{errors.company_name[0]}</p>}
        </div>

        {/* Aliases */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Bank Statement Aliases</label>
          <div className="space-y-2">
            {form.aliases.map((alias, idx) => (
              <div key={idx} className="flex items-center gap-2">
                <input
                  type="text"
                  value={alias}
                  onChange={(e) => updateAlias(idx, e.target.value)}
                  placeholder="e.g., NETFLIX.COM"
                  className="flex-1 px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
                />
                {form.aliases.length > 1 && (
                  <button type="button" onClick={() => removeAlias(idx)} className="p-1.5 text-sw-muted hover:text-sw-danger transition">
                    <X size={14} />
                  </button>
                )}
              </div>
            ))}
          </div>
          <button type="button" onClick={addAlias} className="mt-2 inline-flex items-center gap-1 text-xs text-sw-accent hover:text-sw-accent-hover transition">
            <Plus size={12} /> Add alias
          </button>
        </div>

        {/* Category + Difficulty row */}
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
            <label className="block text-xs font-medium text-sw-text mb-1">Difficulty</label>
            <select
              value={form.difficulty}
              onChange={(e) => updateField('difficulty', e.target.value)}
              className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
            >
              <option value="easy">Easy</option>
              <option value="medium">Medium</option>
              <option value="hard">Hard</option>
            </select>
          </div>
        </div>

        {/* Cancellation URL */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Cancellation URL</label>
          <input
            type="url"
            value={form.cancellation_url}
            onChange={(e) => updateField('cancellation_url', e.target.value)}
            placeholder="https://..."
            className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
          />
        </div>

        {/* Phone */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Cancellation Phone</label>
          <input
            type="text"
            value={form.cancellation_phone}
            onChange={(e) => updateField('cancellation_phone', e.target.value)}
            placeholder="1-800-..."
            className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
          />
        </div>

        {/* Instructions */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Cancellation Instructions</label>
          <textarea
            value={form.cancellation_instructions}
            onChange={(e) => updateField('cancellation_instructions', e.target.value)}
            rows={3}
            placeholder="Step-by-step instructions..."
            className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent resize-none"
          />
        </div>

        {/* Toggles */}
        <div className="space-y-3">
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              checked={form.is_essential}
              onChange={(e) => updateField('is_essential', e.target.checked)}
              className="rounded border-sw-border text-sw-accent focus:ring-sw-accent"
            />
            <span className="text-xs text-sw-text">Essential bill</span>
          </label>
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              checked={form.is_verified}
              onChange={(e) => updateField('is_verified', e.target.checked)}
              className="rounded border-sw-border text-sw-accent focus:ring-sw-accent"
            />
            <span className="text-xs text-sw-text">Verified (URL confirmed working)</span>
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
          <button
            type="button"
            onClick={handleFindLink}
            disabled={findingLink}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-sw-accent text-sw-accent hover:bg-sw-accent/5 text-sm font-medium transition disabled:opacity-50"
          >
            {findingLink ? <Loader2 size={14} className="animate-spin" /> : <Sparkles size={14} />}
            Find Link with AI
          </button>
        </div>
      </form>
    </AuthenticatedLayout>
  );
}
