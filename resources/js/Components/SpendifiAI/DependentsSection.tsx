import { useState } from 'react';
import { Baby, Plus, Trash2, Edit2, Loader2, CheckCircle, XCircle, Save } from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import ConfirmDialog from '@/Components/SpendifiAI/ConfirmDialog';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { Dependent } from '@/types/spendifiai';
import axios from 'axios';

const RELATIONSHIPS = [
  { value: 'child', label: 'Child' },
  { value: 'stepchild', label: 'Stepchild' },
  { value: 'foster_child', label: 'Foster Child' },
  { value: 'sibling', label: 'Sibling' },
  { value: 'parent', label: 'Parent' },
  { value: 'grandparent', label: 'Grandparent' },
  { value: 'grandchild', label: 'Grandchild' },
  { value: 'other', label: 'Other' },
];

const emptyForm = {
  name: '',
  date_of_birth: '',
  relationship: 'child',
  is_student: false,
  is_disabled: false,
  lives_with_you: true,
  months_lived_with_you: 12,
  is_claimed: true,
  tax_year: new Date().getFullYear(),
};

export default function DependentsSection() {
  const { data, loading, refresh } = useApi<{ dependents: Dependent[] }>('/api/v1/dependents');
  const { submit: addDependent, loading: adding } = useApiPost('/api/v1/dependents', 'POST');

  const [showForm, setShowForm] = useState(false);
  const [editId, setEditId] = useState<number | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [success, setSuccess] = useState('');
  const [error, setError] = useState('');
  const [confirmDelete, setConfirmDelete] = useState<Dependent | null>(null);

  const dependents = data?.dependents ?? [];

  const handleSubmit = async () => {
    try {
      if (editId) {
        const token = localStorage.getItem('auth_token');
        await axios.patch(`/api/v1/dependents/${editId}`, form, {
          headers: { Authorization: `Bearer ${token}` },
        });
        setSuccess('Dependent updated');
      } else {
        await addDependent(form);
        setSuccess('Dependent added');
      }
      setShowForm(false);
      setEditId(null);
      setForm(emptyForm);
      refresh();
      setTimeout(() => setSuccess(''), 3000);
    } catch {
      setError('Failed to save dependent');
      setTimeout(() => setError(''), 3000);
    }
  };

  const handleEdit = (dep: Dependent) => {
    setForm({
      name: dep.name,
      date_of_birth: dep.date_of_birth,
      relationship: dep.relationship,
      is_student: dep.is_student,
      is_disabled: dep.is_disabled,
      lives_with_you: dep.lives_with_you,
      months_lived_with_you: dep.months_lived_with_you,
      is_claimed: dep.is_claimed,
      tax_year: dep.tax_year,
    });
    setEditId(dep.id);
    setShowForm(true);
  };

  const handleDelete = async (dep: Dependent) => {
    try {
      const token = localStorage.getItem('auth_token');
      await axios.delete(`/api/v1/dependents/${dep.id}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      refresh();
      setConfirmDelete(null);
      setSuccess('Dependent removed');
      setTimeout(() => setSuccess(''), 3000);
    } catch {
      setError('Failed to delete dependent');
    }
  };

  const inputClass = 'w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sm text-sw-text focus:ring-1 focus:ring-sw-accent focus:border-sw-accent';
  const labelClass = 'block text-xs font-medium text-sw-text-secondary mb-1';

  return (
    <div className="bg-sw-card border border-sw-border rounded-xl p-6">
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2">
          <Baby size={18} className="text-sw-info" />
          <h2 className="text-base font-semibold text-sw-text">Dependents</h2>
        </div>
        <button
          onClick={() => { setShowForm(!showForm); setEditId(null); setForm(emptyForm); }}
          className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-medium hover:bg-sw-accent-hover"
        >
          <Plus size={12} /> Add Dependent
        </button>
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

      {/* Form */}
      {showForm && (
        <div className="mb-4 p-4 rounded-lg bg-sw-surface border border-sw-border space-y-3">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className={labelClass}>Name</label>
              <input type="text" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputClass} placeholder="Full name" />
            </div>
            <div>
              <label className={labelClass}>Date of Birth</label>
              <input type="date" value={form.date_of_birth} onChange={(e) => setForm({ ...form, date_of_birth: e.target.value })} className={inputClass} />
            </div>
            <div>
              <label className={labelClass}>Relationship</label>
              <select value={form.relationship} onChange={(e) => setForm({ ...form, relationship: e.target.value })} className={inputClass}>
                {RELATIONSHIPS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
              </select>
            </div>
            <div>
              <label className={labelClass}>Tax Year</label>
              <input type="number" value={form.tax_year} onChange={(e) => setForm({ ...form, tax_year: parseInt(e.target.value) })} className={inputClass} />
            </div>
            <div>
              <label className={labelClass}>Months Lived With You</label>
              <input type="number" value={form.months_lived_with_you} min={0} max={12} onChange={(e) => setForm({ ...form, months_lived_with_you: parseInt(e.target.value) })} className={inputClass} />
            </div>
          </div>
          <div className="flex flex-wrap gap-4">
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.is_student} onChange={(e) => setForm({ ...form, is_student: e.target.checked })} className="rounded border-sw-border text-sw-accent focus:ring-sw-accent" /> Student
            </label>
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.is_disabled} onChange={(e) => setForm({ ...form, is_disabled: e.target.checked })} className="rounded border-sw-border text-sw-accent focus:ring-sw-accent" /> Disabled
            </label>
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.lives_with_you} onChange={(e) => setForm({ ...form, lives_with_you: e.target.checked })} className="rounded border-sw-border text-sw-accent focus:ring-sw-accent" /> Lives with you
            </label>
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.is_claimed} onChange={(e) => setForm({ ...form, is_claimed: e.target.checked })} className="rounded border-sw-border text-sw-accent focus:ring-sw-accent" /> Claiming as dependent
            </label>
          </div>
          <div className="flex gap-2 pt-2">
            <button onClick={handleSubmit} disabled={adding || !form.name || !form.date_of_birth} className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-medium hover:bg-sw-accent-hover disabled:opacity-50">
              {adding ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
              {editId ? 'Update' : 'Add'} Dependent
            </button>
            <button onClick={() => { setShowForm(false); setEditId(null); }} className="px-4 py-2 rounded-lg border border-sw-border text-sm text-sw-text-secondary hover:bg-sw-surface">
              Cancel
            </button>
          </div>
        </div>
      )}

      {/* Dependents list */}
      {loading ? (
        <div className="flex items-center gap-2 text-sw-muted text-sm py-4">
          <Loader2 size={14} className="animate-spin" /> Loading...
        </div>
      ) : dependents.length === 0 ? (
        <p className="text-sm text-sw-muted py-4 text-center">No dependents added yet. Add dependents to maximize your tax credits.</p>
      ) : (
        <div className="space-y-2">
          {dependents.map((dep) => (
            <div key={dep.id} className="flex items-center justify-between py-3 px-4 rounded-lg bg-sw-surface">
              <div>
                <span className="text-sm font-medium text-sw-text">{dep.name}</span>
                <span className="text-xs text-sw-muted ml-2">Age {dep.age}</span>
                <Badge variant={dep.qualifies_for_child_tax_credit ? 'success' : 'neutral'} className="ml-2">
                  {dep.qualifies_for_child_tax_credit ? 'CTC Eligible' : dep.relationship}
                </Badge>
                {dep.is_student && <Badge variant="info" className="ml-1">Student</Badge>}
              </div>
              <div className="flex items-center gap-1">
                <button onClick={() => handleEdit(dep)} className="p-1.5 text-sw-muted hover:text-sw-accent" title="Edit">
                  <Edit2 size={14} />
                </button>
                <button onClick={() => setConfirmDelete(dep)} className="p-1.5 text-sw-muted hover:text-sw-danger" title="Delete">
                  <Trash2 size={14} />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      <ConfirmDialog
        open={!!confirmDelete}
        title="Remove Dependent"
        message={`Remove ${confirmDelete?.name} from your dependents?`}
        confirmText="Remove"
        onConfirm={() => confirmDelete && handleDelete(confirmDelete)}
        onCancel={() => setConfirmDelete(null)}
      />
    </div>
  );
}
