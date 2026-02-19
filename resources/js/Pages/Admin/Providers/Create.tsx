import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { Save, Sparkles, Loader2, X, Plus } from 'lucide-react';
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
}

const categories = ['Streaming', 'Music', 'Software', 'VPN/Security', 'Finance', 'Fitness/Health', 'Gaming', 'Shopping', 'Hosting/Dev', 'Phone', 'Internet', 'Insurance', 'Utilities'];

export default function CreateProvider() {
  const [form, setForm] = useState<FormData>({
    company_name: '',
    aliases: [''],
    cancellation_url: '',
    cancellation_phone: '',
    cancellation_instructions: '',
    difficulty: 'easy',
    category: '',
    is_essential: false,
  });
  const [saving, setSaving] = useState(false);
  const [findingLink, setFindingLink] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const updateField = (field: keyof FormData, value: unknown) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const addAlias = () => setForm((prev) => ({ ...prev, aliases: [...prev.aliases, ''] }));
  const removeAlias = (idx: number) => setForm((prev) => ({ ...prev, aliases: prev.aliases.filter((_, i) => i !== idx) }));
  const updateAlias = (idx: number, val: string) => {
    setForm((prev) => {
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
      await axios.post('/api/admin/providers', payload);
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
    if (!form.company_name) return;
    setFindingLink(true);
    try {
      // First create the provider, then find the link
      const payload = { ...form, aliases: form.aliases.filter(Boolean).length > 0 ? form.aliases.filter(Boolean) : [form.company_name.toUpperCase()] };
      const createRes = await axios.post('/api/admin/providers', payload);
      const provider = createRes.data.provider;

      const res = await axios.post(`/api/admin/providers/${provider.id}/find-link`);
      const result = res.data.ai_result;
      if (result) {
        setForm((prev) => ({
          ...prev,
          cancellation_url: result.cancellation_url || prev.cancellation_url,
          cancellation_phone: result.cancellation_phone || prev.cancellation_phone,
          cancellation_instructions: result.cancellation_instructions || prev.cancellation_instructions,
          difficulty: result.difficulty || prev.difficulty,
        }));
      }
      // Navigate to edit since it was already created
      router.visit(`/admin/providers/${provider.id}/edit`);
    } catch { /* ignore */ }
    setFindingLink(false);
  };

  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text">Add Provider</h1>
          <p className="text-xs text-sw-dim mt-0.5">Create a new cancellation provider entry</p>
        </div>
      }
    >
      <Head title="Add Provider" />

      <form onSubmit={handleSubmit} className="max-w-2xl space-y-6">
        {/* Company Name */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Company Name *</label>
          <input
            type="text"
            value={form.company_name}
            onChange={(e) => updateField('company_name', e.target.value)}
            className="w-full px-3 py-2 text-sm rounded-lg border border-sw-border bg-sw-card text-sw-text focus:outline-none focus:border-sw-accent"
            required
          />
          {errors.company_name && <p className="text-xs text-sw-danger mt-1">{errors.company_name[0]}</p>}
        </div>

        {/* Aliases */}
        <div>
          <label className="block text-xs font-medium text-sw-text mb-1">Bank Statement Aliases *</label>
          <p className="text-[11px] text-sw-dim mb-2">How this company appears on bank statements (e.g., "NETFLIX", "NETFLIX.COM", "NETFLIX INC")</p>
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
          {errors.aliases && <p className="text-xs text-sw-danger mt-1">{errors.aliases[0]}</p>}
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
            <label className="block text-xs font-medium text-sw-text mb-1">Difficulty *</label>
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

        {/* Essential toggle */}
        <label className="flex items-center gap-2 cursor-pointer">
          <input
            type="checkbox"
            checked={form.is_essential}
            onChange={(e) => updateField('is_essential', e.target.checked)}
            className="rounded border-sw-border text-sw-accent focus:ring-sw-accent"
          />
          <span className="text-xs text-sw-text">Essential bill (phone, internet, insurance — not a cancellable subscription)</span>
        </label>

        {/* Actions */}
        <div className="flex items-center gap-3 pt-2">
          <button
            type="submit"
            disabled={saving}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-sm font-semibold transition disabled:opacity-50"
          >
            {saving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
            Save Provider
          </button>
          <button
            type="button"
            onClick={handleFindLink}
            disabled={findingLink || !form.company_name}
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
