import { useState, useEffect } from 'react';
import { FileText, ChevronDown, ChevronRight, Save, Loader2, CheckCircle, Info } from 'lucide-react';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { UserFinancialProfile, UserFinancialProfileResponse, DerivedAccounts } from '@/types/spendifiai';

/** Valid IRA type tokens (matches backend enum). */
const IRA_TYPES = [
  { value: 'traditional', label: 'Traditional IRA' },
  { value: 'roth',        label: 'Roth IRA' },
  { value: 'sep',         label: 'SEP IRA' },
  { value: 'simple',      label: 'SIMPLE IRA' },
] as const;

type IraTypeValue = typeof IRA_TYPES[number]['value'];

/** Soft annotation note rendered under a field when document-derived info conflicts. */
function FactNote({ note }: { note: string }) {
  return (
    <p className="flex items-start gap-1 text-xs text-sw-info mt-0.5 ml-6 leading-relaxed">
      <Info size={11} className="mt-0.5 flex-shrink-0" />
      {note}
    </p>
  );
}

/** Derive a human-readable source label. */
function sourceLabel(source: string | null): string {
  if (!source) return 'your documents';
  if (source === 'your answers') return 'your previous answers';
  return source;
}

export default function EnhancedProfileSection() {
  const { data: profileData } = useApi<UserFinancialProfileResponse>('/api/v1/profile/financial');
  const profile = profileData?.profile ?? null;
  const derived: DerivedAccounts | undefined = profileData?.derived_accounts;
  const { submit: saveProfile, loading: saving } = useApiPost<unknown, Partial<UserFinancialProfile>>('/api/v1/profile/financial', 'POST');

  const [expanded, setExpanded] = useState<Record<string, boolean>>({});
  const [success, setSuccess] = useState(false);

  const [form, setForm] = useState({
    is_student: false,
    school_name: '',
    enrollment_status: '',
    spouse_name: '',
    spouse_employment_type: '',
    spouse_income: '',
    has_hsa: false,
    has_fsa: false,
    has_529_plan: false,
    has_ira: false,
    // Fix 1: multi-select IRA types
    ira_types: [] as IraTypeValue[],
    has_student_loans: false,
    has_childcare_expenses: false,
    childcare_annual_cost: '',
    is_military: false,
    has_rental_property: false,
    education_credits_eligible: false,
  });

  // Track whether the user has explicitly overridden a doc-derived value
  // (used to decide when to show reconciliation notes vs plain annotation).
  const [hsaOverridden, setHsaOverridden] = useState(false);
  const [iraOverridden, setIraOverridden] = useState(false);

  useEffect(() => {
    if (profile) {
      // Fix 1: Prefer ira_types array; fall back to legacy ira_type → singleton array
      let iraTypes: IraTypeValue[] = [];
      if (profile.ira_types && profile.ira_types.length > 0) {
        iraTypes = profile.ira_types as IraTypeValue[];
      } else if (profile.ira_type) {
        iraTypes = [profile.ira_type as IraTypeValue];
      }

      // Fix 2: If derived says IRA and the profile has none, pre-fill from docs.
      // Only pre-fill when profile has no explicit value yet (first-time annotation).
      let prefillHasIra = profile.has_ira ?? false;
      let prefillIraTypes = iraTypes;

      if (!profile.has_ira && derived?.ira?.value === 'yes') {
        prefillHasIra = true;
        // Pre-select derived types when profile has none
        if (iraTypes.length === 0) {
          const newTypes: IraTypeValue[] = [];
          if (derived.ira_types?.traditional?.value) newTypes.push('traditional');
          if (derived.ira_types?.roth?.value) newTypes.push('roth');
          if (newTypes.length > 0) prefillIraTypes = newTypes;
        }
      }

      let prefillHasHsa = profile.has_hsa ?? false;
      // Pre-fill HSA from derived only if no explicit profile value
      if (profile.has_hsa === null && derived?.hsa?.value === 'yes') {
        prefillHasHsa = true;
      }

      setForm({
        is_student: profile.is_student ?? false,
        school_name: profile.school_name ?? '',
        enrollment_status: profile.enrollment_status ?? '',
        spouse_name: profile.spouse_name ?? '',
        spouse_employment_type: profile.spouse_employment_type ?? '',
        spouse_income: profile.spouse_income ? String(profile.spouse_income) : '',
        has_hsa: prefillHasHsa,
        has_fsa: profile.has_fsa ?? false,
        has_529_plan: profile.has_529_plan ?? false,
        has_ira: prefillHasIra,
        ira_types: prefillIraTypes,
        has_student_loans: profile.has_student_loans ?? false,
        has_childcare_expenses: profile.has_childcare_expenses ?? false,
        childcare_annual_cost: profile.childcare_annual_cost ? String(profile.childcare_annual_cost) : '',
        is_military: profile.is_military ?? false,
        has_rental_property: profile.has_rental_property ?? false,
        education_credits_eligible: profile.education_credits_eligible ?? false,
      });
      setHsaOverridden(false);
      setIraOverridden(false);
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [profile]);

  const toggle = (key: string) => setExpanded((prev) => ({ ...prev, [key]: !prev[key] }));

  // Fix 1: Toggle an IRA type in the multi-select array.
  const toggleIraType = (type: IraTypeValue) => {
    setForm((prev) => {
      const next = prev.ira_types.includes(type)
        ? prev.ira_types.filter((t) => t !== type)
        : [...prev.ira_types, type];
      return { ...prev, ira_types: next };
    });
    setIraOverridden(true);
  };

  const handleSave = async () => {
    const payload: Record<string, unknown> = { ...form };
    if (payload.spouse_income) payload.spouse_income = Number(payload.spouse_income);
    else delete payload.spouse_income;
    if (payload.childcare_annual_cost) payload.childcare_annual_cost = Number(payload.childcare_annual_cost);
    else delete payload.childcare_annual_cost;
    if (!payload.enrollment_status) delete payload.enrollment_status;

    // Fix 1: send ira_types as the canonical field; remove legacy ira_type from payload
    // (the controller derives ira_type from ira_types[0] for backward compat).
    delete payload.ira_type;

    await saveProfile(payload as Partial<UserFinancialProfile>);
    setSuccess(true);
    setHsaOverridden(false);
    setIraOverridden(false);
    setTimeout(() => setSuccess(false), 3000);
  };

  const inputClass = 'w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sm text-sw-text focus:ring-1 focus:ring-sw-accent focus:border-sw-accent';
  const labelClass = 'block text-xs font-medium text-sw-text-secondary mb-1';
  const checkClass = 'rounded border-sw-border text-sw-accent focus:ring-sw-accent';

  const SectionHeader = ({ title, sectionKey }: { title: string; sectionKey: string }) => (
    <button
      onClick={() => toggle(sectionKey)}
      className="flex items-center gap-2 w-full text-left py-2 text-sm font-semibold text-sw-text-secondary hover:text-sw-text"
    >
      {expanded[sectionKey] ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
      {title}
    </button>
  );

  // ── Derived annotation helpers ──────────────────────────────────────────────

  /** HSA: show conflict note when derived says 'no' but user has HSA checked. */
  const hsaConflictNote = (() => {
    if (!derived?.hsa?.value) return null;
    const derivedNo = derived.hsa.value === 'no';
    const derivedYes = derived.hsa.value === 'yes';
    if (derivedNo && form.has_hsa && hsaOverridden) {
      return `Based on ${sourceLabel(derived.hsa.source)}, this appears to be "not applicable" — your selection will be saved.`;
    }
    if (derivedNo && !form.has_hsa) {
      return `Based on ${sourceLabel(derived.hsa.source)}, this appears to be not applicable to you.`;
    }
    if (derivedYes && form.has_hsa) return null; // agreement — no note needed
    return null;
  })();

  /** IRA: show annotation when derived info exists. */
  const iraSuggestionNote = (() => {
    if (!derived?.ira?.value || iraOverridden) return null;
    if (derived.ira.value === 'yes' && !profile?.has_ira) {
      const types: string[] = [];
      if (derived.ira_types?.traditional?.value) types.push('Traditional');
      if (derived.ira_types?.roth?.value) types.push('Roth');
      const typeStr = types.length > 0 ? ` (${types.join(' + ')})` : '';
      return `Based on ${sourceLabel(derived.ira.source)}, it looks like you have an IRA${typeStr}. We've pre-selected below — adjust if needed.`;
    }
    return null;
  })();

  return (
    <div className="bg-sw-card border border-sw-border rounded-xl p-6">
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2">
          <FileText size={18} className="text-sw-warning" />
          <h2 className="text-base font-semibold text-sw-text">Enhanced Tax Profile</h2>
        </div>
        {success && (
          <div className="flex items-center gap-1 text-sw-success text-xs font-medium">
            <CheckCircle size={14} /> Saved
          </div>
        )}
      </div>

      <p className="text-xs text-sw-muted mb-4">Additional details help us find more tax deductions and credits for you.</p>

      <div className="space-y-1">
        {/* Student Info */}
        <SectionHeader title="Student Information" sectionKey="student" />
        {expanded.student && (
          <div className="pl-6 pb-3 space-y-3">
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.is_student} onChange={(e) => setForm({ ...form, is_student: e.target.checked })} className={checkClass} /> I am a student
            </label>
            {form.is_student && (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className={labelClass}>School Name</label>
                  <input type="text" value={form.school_name} onChange={(e) => setForm({ ...form, school_name: e.target.value })} className={inputClass} />
                </div>
                <div>
                  <label className={labelClass}>Enrollment Status</label>
                  <select value={form.enrollment_status} onChange={(e) => setForm({ ...form, enrollment_status: e.target.value })} className={inputClass}>
                    <option value="">Select...</option>
                    <option value="full_time">Full-time</option>
                    <option value="half_time">Half-time</option>
                    <option value="less_than_half">Less than half-time</option>
                  </select>
                </div>
              </div>
            )}
          </div>
        )}

        {/* Spouse Info */}
        <SectionHeader title="Spouse Information" sectionKey="spouse" />
        {expanded.spouse && (
          <div className="pl-6 pb-3 space-y-3">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className={labelClass}>Spouse Name</label>
                <input type="text" value={form.spouse_name} onChange={(e) => setForm({ ...form, spouse_name: e.target.value })} className={inputClass} placeholder="Optional" />
              </div>
              <div>
                <label className={labelClass}>Spouse Employment</label>
                <select value={form.spouse_employment_type} onChange={(e) => setForm({ ...form, spouse_employment_type: e.target.value })} className={inputClass}>
                  <option value="">Select...</option>
                  <option value="employed">Employed</option>
                  <option value="self_employed">Self-employed</option>
                  <option value="retired">Retired</option>
                  <option value="student">Student</option>
                  <option value="unemployed">Unemployed</option>
                </select>
              </div>
              <div>
                <label className={labelClass}>Spouse Monthly Income</label>
                <input type="number" value={form.spouse_income} onChange={(e) => setForm({ ...form, spouse_income: e.target.value })} className={inputClass} placeholder="0.00" />
              </div>
            </div>
          </div>
        )}

        {/* Tax-Advantaged Accounts */}
        <SectionHeader title="Tax-Advantaged Accounts" sectionKey="accounts" />
        {expanded.accounts && (
          <div className="pl-6 pb-3 space-y-2">
            {/* HSA */}
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input
                type="checkbox"
                checked={form.has_hsa}
                onChange={(e) => {
                  setForm({ ...form, has_hsa: e.target.checked });
                  setHsaOverridden(true);
                }}
                className={checkClass}
              />
              Health Savings Account (HSA)
            </label>
            {hsaConflictNote && <FactNote note={hsaConflictNote} />}

            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.has_fsa} onChange={(e) => setForm({ ...form, has_fsa: e.target.checked })} className={checkClass} /> Flexible Spending Account (FSA)
            </label>
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.has_529_plan} onChange={(e) => setForm({ ...form, has_529_plan: e.target.checked })} className={checkClass} /> 529 Education Savings Plan
            </label>

            {/* IRA — checkbox + multi-type selector */}
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input
                type="checkbox"
                checked={form.has_ira}
                onChange={(e) => {
                  setForm({ ...form, has_ira: e.target.checked, ira_types: e.target.checked ? form.ira_types : [] });
                  setIraOverridden(true);
                }}
                className={checkClass}
              />
              IRA (Individual Retirement Account)
            </label>
            {iraSuggestionNote && <FactNote note={iraSuggestionNote} />}

            {/* Fix 1: Multi-select IRA type checkboxes */}
            {form.has_ira && (
              <div className="ml-6 mt-1 space-y-1">
                <p className="text-xs font-medium text-sw-text-secondary mb-1">IRA type(s) — select all that apply:</p>
                {IRA_TYPES.map(({ value, label }) => (
                  <label key={value} className="flex items-center gap-2 text-xs text-sw-text-secondary">
                    <input
                      type="checkbox"
                      checked={form.ira_types.includes(value)}
                      onChange={() => toggleIraType(value)}
                      className={checkClass}
                    />
                    {label}
                    {/* Fix 2: show doc-derived badge for traditional/roth */}
                    {(value === 'traditional' || value === 'roth') && derived?.ira_types?.[value]?.value && !iraOverridden && (
                      <span className="text-xs text-sw-info italic">(from {sourceLabel(derived.ira_types[value].source)})</span>
                    )}
                  </label>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Additional Deductions */}
        <SectionHeader title="Additional Deductions" sectionKey="deductions" />
        {expanded.deductions && (
          <div className="pl-6 pb-3 space-y-2">
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.has_student_loans} onChange={(e) => setForm({ ...form, has_student_loans: e.target.checked })} className={checkClass} /> Student loan payments
            </label>
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.has_childcare_expenses} onChange={(e) => setForm({ ...form, has_childcare_expenses: e.target.checked })} className={checkClass} /> Childcare / dependent care expenses
            </label>
            {form.has_childcare_expenses && (
              <div className="ml-6 mt-1 max-w-xs">
                <label className={labelClass}>Annual Childcare Cost</label>
                <input type="number" value={form.childcare_annual_cost} onChange={(e) => setForm({ ...form, childcare_annual_cost: e.target.value })} className={inputClass} placeholder="0.00" />
              </div>
            )}
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.is_military} onChange={(e) => setForm({ ...form, is_military: e.target.checked })} className={checkClass} /> Active duty or veteran military
            </label>
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.has_rental_property} onChange={(e) => setForm({ ...form, has_rental_property: e.target.checked })} className={checkClass} /> Own rental property
            </label>
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.education_credits_eligible} onChange={(e) => setForm({ ...form, education_credits_eligible: e.target.checked })} className={checkClass} /> Eligible for education credits (AOTC / LLC)
            </label>
          </div>
        )}
      </div>

      <div className="mt-4 pt-4 border-t border-sw-border">
        <button
          onClick={handleSave}
          disabled={saving}
          className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-medium hover:bg-sw-accent-hover disabled:opacity-50"
        >
          {saving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
          Save Enhanced Profile
        </button>
      </div>
    </div>
  );
}
