import { useState, useEffect } from 'react';
import { FileText, ChevronDown, ChevronRight, Save, Loader2, CheckCircle } from 'lucide-react';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { UserFinancialProfile, UserFinancialProfileResponse } from '@/types/spendifiai';

export default function EnhancedProfileSection() {
  const { data: profileData } = useApi<UserFinancialProfileResponse>('/api/v1/profile/financial');
  const profile = profileData?.profile ?? null;
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
    ira_type: '',
    has_student_loans: false,
    has_childcare_expenses: false,
    childcare_annual_cost: '',
    is_military: false,
    has_rental_property: false,
    education_credits_eligible: false,
  });

  useEffect(() => {
    if (profile) {
      setForm({
        is_student: profile.is_student ?? false,
        school_name: profile.school_name ?? '',
        enrollment_status: profile.enrollment_status ?? '',
        spouse_name: profile.spouse_name ?? '',
        spouse_employment_type: profile.spouse_employment_type ?? '',
        spouse_income: profile.spouse_income ? String(profile.spouse_income) : '',
        has_hsa: profile.has_hsa ?? false,
        has_fsa: profile.has_fsa ?? false,
        has_529_plan: profile.has_529_plan ?? false,
        has_ira: profile.has_ira ?? false,
        ira_type: profile.ira_type ?? '',
        has_student_loans: profile.has_student_loans ?? false,
        has_childcare_expenses: profile.has_childcare_expenses ?? false,
        childcare_annual_cost: profile.childcare_annual_cost ? String(profile.childcare_annual_cost) : '',
        is_military: profile.is_military ?? false,
        has_rental_property: profile.has_rental_property ?? false,
        education_credits_eligible: profile.education_credits_eligible ?? false,
      });
    }
  }, [profile]);

  const toggle = (key: string) => setExpanded((prev) => ({ ...prev, [key]: !prev[key] }));

  const handleSave = async () => {
    const payload: Record<string, unknown> = { ...form };
    if (payload.spouse_income) payload.spouse_income = Number(payload.spouse_income);
    else delete payload.spouse_income;
    if (payload.childcare_annual_cost) payload.childcare_annual_cost = Number(payload.childcare_annual_cost);
    else delete payload.childcare_annual_cost;
    if (!payload.ira_type) delete payload.ira_type;
    if (!payload.enrollment_status) delete payload.enrollment_status;

    await saveProfile(payload as Partial<UserFinancialProfile>);
    setSuccess(true);
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
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.has_hsa} onChange={(e) => setForm({ ...form, has_hsa: e.target.checked })} className={checkClass} /> Health Savings Account (HSA)
            </label>
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.has_fsa} onChange={(e) => setForm({ ...form, has_fsa: e.target.checked })} className={checkClass} /> Flexible Spending Account (FSA)
            </label>
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.has_529_plan} onChange={(e) => setForm({ ...form, has_529_plan: e.target.checked })} className={checkClass} /> 529 Education Savings Plan
            </label>
            <label className="flex items-center gap-2 text-xs text-sw-text-secondary">
              <input type="checkbox" checked={form.has_ira} onChange={(e) => setForm({ ...form, has_ira: e.target.checked })} className={checkClass} /> IRA (Individual Retirement Account)
            </label>
            {form.has_ira && (
              <div className="ml-6 mt-1">
                <label className={labelClass}>IRA Type</label>
                <select value={form.ira_type} onChange={(e) => setForm({ ...form, ira_type: e.target.value })} className={inputClass + ' max-w-xs'}>
                  <option value="">Select...</option>
                  <option value="traditional">Traditional IRA</option>
                  <option value="roth">Roth IRA</option>
                  <option value="sep">SEP IRA</option>
                  <option value="simple">SIMPLE IRA</option>
                </select>
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
