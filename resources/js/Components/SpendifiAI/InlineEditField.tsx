import { useState } from 'react';
import { Pencil, Check, X, Loader2 } from 'lucide-react';
import ConfidenceBadge from '@/Components/SpendifiAI/ConfidenceBadge';
import type { ExtractedField } from '@/types/spendifiai';

interface InlineEditFieldProps {
  fieldName: string;
  field: ExtractedField;
  onSave: (fieldName: string, value: string) => Promise<void>;
  disabled?: boolean;
}

function formatFieldLabel(fieldName: string): string {
  return fieldName
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function InlineEditField({ fieldName, field, onSave, disabled = false }: InlineEditFieldProps) {
  const [editing, setEditing] = useState(false);
  const [editValue, setEditValue] = useState(field.value ?? '');
  const [saving, setSaving] = useState(false);

  const handleStartEdit = () => {
    if (disabled) return;
    setEditValue(field.value ?? '');
    setEditing(true);
  };

  const handleCancel = () => {
    setEditing(false);
    setEditValue(field.value ?? '');
  };

  const handleSave = async () => {
    setSaving(true);
    try {
      await onSave(fieldName, editValue);
      setEditing(false);
    } finally {
      setSaving(false);
    }
  };

  if (editing) {
    return (
      <div className="flex items-center gap-2 py-2">
        <span className="text-xs text-sw-muted w-36 shrink-0">{formatFieldLabel(fieldName)}</span>
        <input
          type="text"
          value={editValue}
          onChange={(e) => setEditValue(e.target.value)}
          className="flex-1 text-sm bg-sw-bg border border-sw-border rounded px-2 py-1 text-sw-text focus:outline-none focus:ring-1 focus:ring-sw-accent"
          autoFocus
          disabled={saving}
          onKeyDown={(e) => {
            if (e.key === 'Enter') handleSave();
            if (e.key === 'Escape') handleCancel();
          }}
        />
        <button
          onClick={handleSave}
          disabled={saving}
          className="p-1 text-emerald-400 hover:text-emerald-300 disabled:opacity-50"
          title="Save"
        >
          {saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
        </button>
        <button
          onClick={handleCancel}
          disabled={saving}
          className="p-1 text-sw-muted hover:text-sw-text"
          title="Cancel"
        >
          <X size={14} />
        </button>
      </div>
    );
  }

  return (
    <div className="flex items-center gap-2 py-2 group">
      <span className="text-xs text-sw-muted w-36 shrink-0">{formatFieldLabel(fieldName)}</span>
      <span className="flex-1 text-sm text-sw-text truncate">
        {field.value ?? <span className="text-sw-dim italic">Not detected</span>}
      </span>
      <ConfidenceBadge confidence={field.confidence} verified={field.verified} />
      {!disabled && (
        <button
          onClick={handleStartEdit}
          className="p-1 text-sw-dim opacity-0 group-hover:opacity-100 hover:text-sw-accent transition-opacity"
          title="Edit"
        >
          <Pencil size={12} />
        </button>
      )}
    </div>
  );
}
