import { useState } from 'react';
import { CheckCheck, Loader2 } from 'lucide-react';
import { useApiPost } from '@/hooks/useApi';
import ConfidenceBadge from '@/Components/SpendifiAI/ConfidenceBadge';
import InlineEditField from '@/Components/SpendifiAI/InlineEditField';
import type { ExtractedField } from '@/types/spendifiai';

interface ExtractionPanelProps {
  fields: Record<string, ExtractedField> | undefined;
  overallConfidence: number | undefined;
  documentId: number;
  onFieldUpdated?: () => void;
}

/** Field name patterns for grouping */
const IDENTITY_PATTERNS = ['name', 'ssn', 'ein', 'tin', 'employer', 'payer', 'recipient'];
const LOCATION_PATTERNS = ['state', 'address', 'city', 'zip', 'country'];

function getFieldGroup(fieldName: string): 'identity' | 'financial' | 'location' {
  const lower = fieldName.toLowerCase();
  if (IDENTITY_PATTERNS.some((p) => lower.includes(p))) return 'identity';
  if (LOCATION_PATTERNS.some((p) => lower.includes(p))) return 'location';
  return 'financial';
}

function groupFields(fields: Record<string, ExtractedField>): {
  identity: [string, ExtractedField][];
  financial: [string, ExtractedField][];
  location: [string, ExtractedField][];
} {
  const groups = { identity: [] as [string, ExtractedField][], financial: [] as [string, ExtractedField][], location: [] as [string, ExtractedField][] };
  for (const [name, field] of Object.entries(fields)) {
    groups[getFieldGroup(name)].push([name, field]);
  }
  return groups;
}

const GROUP_LABELS: Record<string, string> = {
  identity: 'Identity & Payer',
  financial: 'Financial',
  location: 'Location',
};

export default function ExtractionPanel({ fields, overallConfidence, documentId, onFieldUpdated }: ExtractionPanelProps) {
  const [acceptingAll, setAcceptingAll] = useState(false);

  const { submit: submitField } = useApiPost<unknown, { _method: string; field: string; value: string }>(
    `/api/v1/tax-vault/documents/${documentId}/fields`,
  );

  const { submit: submitAcceptAll } = useApiPost(
    `/api/v1/tax-vault/documents/${documentId}/accept-all`,
  );

  const handleFieldUpdate = async (fieldName: string, newValue: string) => {
    await submitField({ _method: 'PATCH', field: fieldName, value: newValue });
    onFieldUpdated?.();
  };

  const handleAcceptAll = async () => {
    setAcceptingAll(true);
    try {
      await submitAcceptAll();
      onFieldUpdated?.();
    } finally {
      setAcceptingAll(false);
    }
  };

  if (!fields || Object.keys(fields).length === 0) {
    return (
      <div className="flex flex-col items-center justify-center h-full text-center p-8">
        <p className="text-sm text-sw-dim">No extraction data available.</p>
        <p className="text-xs text-sw-muted mt-1">Extraction may still be in progress.</p>
      </div>
    );
  }

  const grouped = groupFields(fields);
  const allVerified = Object.values(fields).every((f) => f.verified);

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="flex items-center justify-between px-4 py-3 border-b border-sw-border">
        <div className="flex items-center gap-2">
          <h3 className="text-sm font-semibold text-sw-text">Extracted Fields</h3>
          {overallConfidence !== undefined && (
            <ConfidenceBadge confidence={overallConfidence} />
          )}
        </div>
        <span className="text-xs text-sw-dim">{Object.keys(fields).length} fields</span>
      </div>

      {/* Field groups */}
      <div className="flex-1 overflow-y-auto px-4 py-2 space-y-4">
        {(['identity', 'financial', 'location'] as const).map((group) => {
          const entries = grouped[group];
          if (entries.length === 0) return null;
          return (
            <div key={group}>
              <h4 className="text-xs font-medium text-sw-muted uppercase tracking-wider mb-1">
                {GROUP_LABELS[group]}
              </h4>
              <div className="divide-y divide-sw-border/50">
                {entries.map(([name, field]) => (
                  <InlineEditField
                    key={name}
                    fieldName={name}
                    field={field}
                    onSave={handleFieldUpdate}
                  />
                ))}
              </div>
            </div>
          );
        })}
      </div>

      {/* Accept All */}
      <div className="px-4 py-3 border-t border-sw-border">
        <button
          onClick={handleAcceptAll}
          disabled={acceptingAll || allVerified}
          className="w-full flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-sw-accent text-white hover:bg-sw-accent/90 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          {acceptingAll ? (
            <Loader2 size={16} className="animate-spin" />
          ) : (
            <CheckCheck size={16} />
          )}
          {allVerified ? 'All Fields Verified' : 'Accept All'}
        </button>
      </div>
    </div>
  );
}
