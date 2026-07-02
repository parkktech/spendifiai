/**
 * FamilyHouseholdSection — Phase 12-05 Task 3 (D5, UI-03).
 *
 * Read-only additive section surfacing spouse / dependent / household facts
 * from existing data (HouseholdSection data + DependentsSection data).
 * Does NOT modify HouseholdSection or DependentsSection (additive composition only).
 *
 * Links to Settings for editing — the actual edit UI lives in Settings/Index.tsx.
 *
 * DESIGN: sw-* tokens; Inter; shadow-sm; 4px/8px rhythm.
 * EDUCATIONAL FRAMING (UI-03): Inline disclaimer.
 */

import { Link } from '@inertiajs/react';
import {
  Users,
  Baby,
  ChevronDown,
  ChevronUp,
  ExternalLink,
  Info,
} from 'lucide-react';
import { useState } from 'react';
import Badge from './Badge';
import { useApi } from '@/hooks/useApi';
import type { HouseholdResponse, Dependent } from '@/types/spendifiai';

interface DependentsApiResponse {
  dependents: Dependent[];
}

export default function FamilyHouseholdSection() {
  const [expanded, setExpanded] = useState(false);

  const { data: householdData, loading: householdLoading } = useApi<HouseholdResponse>('/api/v1/household');
  const { data: dependentsData, loading: dependentsLoading } = useApi<DependentsApiResponse>('/api/v1/dependents');

  const household = householdData?.household;
  const members = householdData?.members ?? [];
  const dependents = dependentsData?.dependents ?? [];

  const totalPeople = members.length + dependents.length;
  const loading = householdLoading || dependentsLoading;

  return (
    <section aria-labelledby="family-household-heading">
      <button
        id="family-household-heading"
        onClick={() => setExpanded(!expanded)}
        className="w-full flex items-center justify-between py-3 text-left"
        aria-expanded={expanded}
      >
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-lg bg-sw-info-light border border-violet-200 flex items-center justify-center">
            <Users size={15} className="text-sw-info" />
          </div>
          <div>
            <p className="text-[14px] font-semibold text-sw-text">Family & Household</p>
            <p className="text-[11px] text-sw-dim">Household members and dependents</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {totalPeople > 0 && (
            <Badge variant="neutral">{totalPeople} member{totalPeople !== 1 ? 's' : ''}</Badge>
          )}
          {expanded ? <ChevronUp size={16} className="text-sw-dim" /> : <ChevronDown size={16} className="text-sw-dim" />}
        </div>
      </button>

      {expanded && (
        <div className="space-y-4 pb-2">
          {/* Educational note (UI-03) */}
          <div className="flex items-start gap-2 rounded-xl bg-sw-surface border border-sw-border/60 px-3.5 py-2.5">
            <Info size={12} className="text-sw-dim shrink-0 mt-0.5" />
            <p className="text-[11px] text-sw-dim leading-relaxed">
              Family composition may affect your filing status, credits (CTC, EITC, dependent care),
              and deduction eligibility. Consider consulting a tax professional for specific guidance.
            </p>
          </div>

          {loading ? (
            <div className="text-[12px] text-sw-dim">Loading family data...</div>
          ) : (
            <>
              {/* Household members */}
              {members.length > 0 && (
                <div className="space-y-2">
                  <p className="text-[11px] font-semibold text-sw-text-secondary uppercase tracking-wide">Household Members</p>
                  {members.map((m) => (
                    <div key={m.id} className="flex items-center gap-3 py-2 px-3 rounded-lg border border-sw-border bg-sw-card">
                      <div className="w-7 h-7 rounded-full bg-sw-surface border border-sw-border flex items-center justify-center">
                        <Users size={13} className="text-sw-muted" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-[13px] font-medium text-sw-text truncate">{m.name}</p>
                        <p className="text-[11px] text-sw-dim">{m.email ?? ''}</p>
                      </div>
                      {m.role && <Badge variant="neutral">{m.role}</Badge>}
                    </div>
                  ))}
                </div>
              )}

              {/* Dependents */}
              {dependents.length > 0 && (
                <div className="space-y-2">
                  <p className="text-[11px] font-semibold text-sw-text-secondary uppercase tracking-wide">Dependents</p>
                  {dependents.map((dep) => (
                    <div key={dep.id} className="flex items-center gap-3 py-2 px-3 rounded-lg border border-sw-border bg-sw-card">
                      <div className="w-7 h-7 rounded-full bg-sw-surface border border-sw-border flex items-center justify-center">
                        <Baby size={13} className="text-sw-muted" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-[13px] font-medium text-sw-text truncate">{dep.name}</p>
                        <p className="text-[11px] text-sw-dim">{dep.relationship ?? ''}{dep.date_of_birth ? ` · DOB ${dep.date_of_birth}` : ''}</p>
                      </div>
                      {dep.qualifies_for_child_tax_credit && <Badge variant="success">Qualifying</Badge>}
                    </div>
                  ))}
                </div>
              )}

              {/* Empty state */}
              {members.length === 0 && dependents.length === 0 && (
                <div className="text-center py-4">
                  <p className="text-[12px] text-sw-muted mb-2">No household members or dependents on record.</p>
                  <Link
                    href="/settings"
                    className="inline-flex items-center gap-1.5 text-[12px] font-medium text-sw-accent hover:text-sw-accent-hover transition"
                  >
                    <ExternalLink size={12} /> Add in Settings
                  </Link>
                </div>
              )}

              {/* Link to settings for editing */}
              {(members.length > 0 || dependents.length > 0) && (
                <Link
                  href="/settings"
                  className="inline-flex items-center gap-1.5 text-[12px] font-medium text-sw-accent hover:text-sw-accent-hover transition mt-1"
                >
                  <ExternalLink size={12} /> Edit household in Settings
                </Link>
              )}
            </>
          )}
        </div>
      )}
    </section>
  );
}
