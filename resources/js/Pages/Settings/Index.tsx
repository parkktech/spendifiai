import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import {
  User,
  Shield,
  Trash2,
  Save,
  Loader2,
  AlertTriangle,
  CheckCircle,
  Lock,
  Smartphone,
  Globe,
  Cookie,
  Briefcase,
  Search,
  Plus,
  X as XIcon,
  Check,
} from 'lucide-react';
import ConfirmDialog from '@/Components/SpendifiAI/ConfirmDialog';
import Badge from '@/Components/SpendifiAI/Badge';
import { useApi, useApiPost } from '@/hooks/useApi';
import { useConsent } from '@/contexts/ConsentContext';
import type { UserFinancialProfile, UserFinancialProfileResponse, AccountantSearchResult, MyAccountant, AccountantInvite } from '@/types/spendifiai';
import { US_TIMEZONES, getAllTimezones } from '@/utils/timezones';
import axios from 'axios';
import HouseholdSection from '@/Components/SpendifiAI/HouseholdSection';
import DependentsSection from '@/Components/SpendifiAI/DependentsSection';
import EnhancedProfileSection from '@/Components/SpendifiAI/EnhancedProfileSection';

function SuccessToast({ message }: { message: string }) {
  return (
    <div className="flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent/10 border border-sw-accent/30 text-sw-accent text-xs font-medium">
      <CheckCircle size={14} /> {message}
    </div>
  );
}

export default function SettingsIndex() {
  const pageProps = usePage().props;
  const authUser = pageProps.auth.user as { name: string; email: string; two_factor_enabled?: boolean; google_connected?: boolean };
  const isAccountant = (pageProps.auth as Record<string, unknown>).isAccountant as boolean;

  // Financial profile - API returns { profile: ... }
  const { data: profileData, loading: profileLoading } = useApi<UserFinancialProfileResponse>('/api/v1/profile/financial');
  const profile = profileData?.profile ?? null;
  const { submit: saveProfile, loading: savingProfile } = useApiPost<unknown, Partial<UserFinancialProfile>>('/api/v1/profile/financial', 'POST');

  const [profileForm, setProfileForm] = useState({
    employment_type: '',
    tax_filing_status: '',
    monthly_income: '',
    business_type: '',
    has_home_office: false,
    housing_status: '',
  });
  const [profileSuccess, setProfileSuccess] = useState(false);

  // Timezone
  const authTimezone = (pageProps.auth as { timezone?: string }).timezone ?? 'America/New_York';
  const [timezone, setTimezone] = useState(authTimezone);
  const [timezoneLoading, setTimezoneLoading] = useState(false);
  const [timezoneSuccess, setTimezoneSuccess] = useState(false);
  const allTimezones = getAllTimezones();

  const handleTimezoneSave = async () => {
    setTimezoneLoading(true);
    try {
      await axios.patch('/api/v1/profile/timezone', { timezone });
      setTimezoneSuccess(true);
      setTimeout(() => setTimezoneSuccess(false), 3000);
    } catch {
      // ignore
    } finally {
      setTimezoneLoading(false);
    }
  };

  useEffect(() => {
    if (profile) {
      setProfileForm({
        employment_type: profile.employment_type || '',
        tax_filing_status: profile.tax_filing_status || '',
        monthly_income: profile.monthly_income !== null ? String(profile.monthly_income) : '',
        business_type: profile.business_type || '',
        has_home_office: profile.has_home_office ?? false,
        housing_status: profile.housing_status || '',
      });
    }
  }, [profile]);

  const handleProfileSave = async () => {
    await saveProfile({
      employment_type: profileForm.employment_type || null,
      tax_filing_status: profileForm.tax_filing_status || null,
      monthly_income: profileForm.monthly_income ? Number(profileForm.monthly_income) : null,
      business_type: profileForm.business_type || null,
      has_home_office: profileForm.has_home_office,
      housing_status: profileForm.housing_status || null,
    });
    setProfileSuccess(true);
    setTimeout(() => setProfileSuccess(false), 3000);
  };

  // Cookie consent
  const consent = useConsent();
  const [cookieAnalytics, setCookieAnalytics] = useState(consent.analytics);
  const [cookieMarketing, setCookieMarketing] = useState(consent.marketing);
  const [cookieSaving, setCookieSaving] = useState(false);
  const [cookieSuccess, setCookieSuccess] = useState(false);
  const [cookieRevoking, setCookieRevoking] = useState(false);

  useEffect(() => {
    setCookieAnalytics(consent.analytics);
    setCookieMarketing(consent.marketing);
  }, [consent.analytics, consent.marketing]);

  const handleCookieSave = async () => {
    setCookieSaving(true);
    await consent.savePreferences(cookieAnalytics, cookieMarketing);
    setCookieSaving(false);
    setCookieSuccess(true);
    setTimeout(() => setCookieSuccess(false), 3000);
  };

  const handleCookieRevoke = async () => {
    setCookieRevoking(true);
    await consent.revokeConsent();
    setCookieRevoking(false);
    setCookieAnalytics(false);
    setCookieMarketing(false);
    setCookieSuccess(true);
    setTimeout(() => setCookieSuccess(false), 3000);
  };

  // Accountant search & management
  const [accountantSearch, setAccountantSearch] = useState('');
  const [accountantResults, setAccountantResults] = useState<AccountantSearchResult[]>([]);
  const [accountantSearching, setAccountantSearching] = useState(false);
  const [myAccountants, setMyAccountants] = useState<MyAccountant[]>([]);
  const [accountantInvites, setAccountantInvites] = useState<AccountantInvite[]>([]);
  const [accountantLoading, setAccountantLoading] = useState(false);
  const [accountantSuccess, setAccountantSuccess] = useState('');

  const loadAccountants = async () => {
    try {
      const [accRes, invRes] = await Promise.all([
        axios.get('/api/v1/accountant/my-accountants'),
        axios.get('/api/v1/accountant/invites'),
      ]);
      setMyAccountants(accRes.data.accountants || []);
      setAccountantInvites(invRes.data.invites || []);
    } catch {
      // ignore
    }
  };

  useEffect(() => {
    if (!isAccountant) {
      loadAccountants();
    }
  }, [isAccountant]);

  const searchAccountants = async (query: string) => {
    setAccountantSearch(query);
    if (query.length < 2) {
      setAccountantResults([]);
      return;
    }
    setAccountantSearching(true);
    try {
      const res = await axios.get(`/api/v1/accountant/search?q=${encodeURIComponent(query)}`);
      setAccountantResults(res.data.accountants || []);
    } catch {
      setAccountantResults([]);
    } finally {
      setAccountantSearching(false);
    }
  };

  const addAccountant = async (accountantId: number) => {
    setAccountantLoading(true);
    try {
      const res = await axios.post('/api/v1/accountant/add', { accountant_id: accountantId });
      setAccountantSuccess(res.data.message || 'Request sent');
      setAccountantSearch('');
      setAccountantResults([]);
      await loadAccountants();
      setTimeout(() => setAccountantSuccess(''), 3000);
    } catch {
      // ignore
    } finally {
      setAccountantLoading(false);
    }
  };

  const removeAccountant = async (accountantUserId: number) => {
    try {
      await axios.delete(`/api/v1/accountant/${accountantUserId}`);
      await loadAccountants();
    } catch {
      // ignore
    }
  };

  const respondToInvite = async (inviteId: number, action: 'accept' | 'decline') => {
    try {
      await axios.post(`/api/v1/accountant/invites/${inviteId}/respond`, { action });
      await loadAccountants();
      setAccountantSuccess(action === 'accept' ? 'Invitation accepted' : 'Invitation declined');
      setTimeout(() => setAccountantSuccess(''), 3000);
    } catch {
      // ignore
    }
  };

  // Password change
  const [passwordForm, setPasswordForm] = useState({
    current_password: '',
    password: '',
    password_confirmation: '',
  });
  const [passwordLoading, setPasswordLoading] = useState(false);
  const [passwordSuccess, setPasswordSuccess] = useState(false);
  const [passwordError, setPasswordError] = useState('');

  const handlePasswordChange = async () => {
    setPasswordLoading(true);
    setPasswordError('');
    try {
      await axios.post('/api/auth/change-password', passwordForm);
      setPasswordSuccess(true);
      setPasswordForm({ current_password: '', password: '', password_confirmation: '' });
      setTimeout(() => setPasswordSuccess(false), 3000);
    } catch (err: unknown) {
      const error = err as { response?: { data?: { message?: string } } };
      setPasswordError(error.response?.data?.message || 'Failed to change password');
    } finally {
      setPasswordLoading(false);
    }
  };

  // 2FA
  const [twoFactorEnabled, setTwoFactorEnabled] = useState(authUser.two_factor_enabled || false);
  const [twoFactorLoading, setTwoFactorLoading] = useState(false);

  const toggle2FA = async () => {
    setTwoFactorLoading(true);
    try {
      if (twoFactorEnabled) {
        await axios.post('/api/auth/two-factor/disable');
        setTwoFactorEnabled(false);
      } else {
        await axios.post('/api/auth/two-factor/enable');
        setTwoFactorEnabled(true);
      }
    } catch {
      // ignore
    } finally {
      setTwoFactorLoading(false);
    }
  };

  // Delete account
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deletePassword, setDeletePassword] = useState('');
  const [deleteLoading, setDeleteLoading] = useState(false);

  const handleDeleteAccount = async () => {
    setDeleteLoading(true);
    try {
      await axios.delete('/api/v1/account', { data: { password: deletePassword } });
      window.location.href = '/';
    } catch {
      // ignore
    } finally {
      setDeleteLoading(false);
    }
  };

  const inputClasses =
    'w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent transition';
  const labelClasses = 'block text-xs font-medium text-sw-muted mb-1.5';

  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text tracking-tight">Settings</h1>
          <p className="text-xs text-sw-dim mt-0.5">Manage your profile, security, and preferences</p>
        </div>
      }
    >
      <Head title="Settings" />

      <div className="max-w-2xl space-y-6">
        {/* Section 1: Financial Profile */}
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
          <div className="flex items-center gap-3 mb-5">
            <div className="w-9 h-9 rounded-lg bg-sw-accent/10 border border-sw-accent/20 flex items-center justify-center">
              <User size={18} className="text-sw-accent" />
            </div>
            <div>
              <h3 className="text-[15px] font-semibold text-sw-text">Financial Profile</h3>
              <p className="text-xs text-sw-dim">Help us categorize your expenses more accurately</p>
            </div>
          </div>

          {profileSuccess && <div aria-live="polite" className="mb-4"><SuccessToast message="Profile saved successfully" /></div>}

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className={labelClasses}>Employment Type</label>
              <select
                value={profileForm.employment_type}
                onChange={(e) => setProfileForm({ ...profileForm, employment_type: e.target.value })}
                className={inputClasses}
              >
                <option value="">Select...</option>
                <option value="employed">Employed</option>
                <option value="self_employed">Self-Employed</option>
                <option value="freelancer">Freelancer</option>
                <option value="retired">Retired</option>
                <option value="student">Student</option>
                <option value="other">Other</option>
              </select>
            </div>

            <div>
              <label className={labelClasses}>Filing Status</label>
              <select
                value={profileForm.tax_filing_status}
                onChange={(e) => setProfileForm({ ...profileForm, tax_filing_status: e.target.value })}
                className={inputClasses}
              >
                <option value="">Select...</option>
                <option value="single">Single</option>
                <option value="married">Married</option>
                <option value="head_of_household">Head of Household</option>
              </select>
            </div>

            <div>
              <label className={labelClasses}>Monthly Income</label>
              <input
                type="number"
                value={profileForm.monthly_income}
                onChange={(e) => setProfileForm({ ...profileForm, monthly_income: e.target.value })}
                placeholder="5000"
                className={inputClasses}
              />
            </div>

            <div>
              <label className={labelClasses}>Business Type</label>
              <input
                type="text"
                value={profileForm.business_type}
                onChange={(e) => setProfileForm({ ...profileForm, business_type: e.target.value })}
                placeholder="e.g., Software Development"
                className={inputClasses}
              />
            </div>

            <div>
              <label className={labelClasses}>Housing</label>
              <select
                value={profileForm.housing_status}
                onChange={(e) => setProfileForm({ ...profileForm, housing_status: e.target.value })}
                className={inputClasses}
              >
                <option value="">Select...</option>
                <option value="own">Own</option>
                <option value="rent">Rent</option>
                <option value="other">Other</option>
              </select>
            </div>

            <div>
              <label className={labelClasses}>Home Office</label>
              <label className="flex items-center gap-2 mt-1.5 cursor-pointer">
                <input
                  type="checkbox"
                  checked={profileForm.has_home_office}
                  onChange={(e) => setProfileForm({ ...profileForm, has_home_office: e.target.checked })}
                  className="w-4 h-4 rounded border-sw-border bg-sw-bg text-sw-accent focus:ring-sw-accent focus:ring-offset-0"
                />
                <span className="text-sm text-sw-text">I have a dedicated home office</span>
              </label>
              <p className="text-[11px] text-sw-dim mt-1">Enables home office deduction tracking (Schedule C Line 30)</p>
            </div>

          </div>

          <div className="mt-5 flex justify-end">
            <button
              onClick={handleProfileSave}
              disabled={savingProfile}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
            >
              {savingProfile ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
              Save Profile
            </button>
          </div>
        </div>

        {/* Household Sharing */}
        <HouseholdSection />

        {/* Dependents */}
        <DependentsSection />

        {/* Enhanced Tax Profile */}
        <EnhancedProfileSection />

        {/* Section 2: My Accountants (for personal users) */}
        {!isAccountant && (
          <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
            <div className="flex items-center gap-3 mb-2">
              <div className="w-9 h-9 rounded-lg bg-indigo-50 border border-indigo-200 flex items-center justify-center">
                <Briefcase size={18} className="text-indigo-600" />
              </div>
              <div>
                <h3 className="text-[15px] font-semibold text-sw-text">My Accountants</h3>
                <p className="text-xs text-sw-dim">Manage who has access to your financial data</p>
              </div>
            </div>

            {/* Access description */}
            <div className="mb-5 rounded-lg bg-indigo-50/50 border border-indigo-100 px-4 py-3">
              <p className="text-xs text-indigo-900 leading-relaxed">
                Adding an accountant grants them <strong>read access</strong> to your transactions, categories, and tax data.
                They can also download tax exports and recategorize expenses on your behalf. You can revoke access at any time.
              </p>
            </div>

            {accountantSuccess && <div aria-live="polite" className="mb-4"><SuccessToast message={accountantSuccess} /></div>}

            {/* Pending invites from accountants */}
            {accountantInvites.filter(inv => inv.can_respond).length > 0 && (
              <div className="mb-5">
                <h4 className="text-xs font-semibold text-amber-800 uppercase tracking-wide mb-2">Pending Invitations</h4>
                <div className="space-y-2">
                  {accountantInvites.filter(inv => inv.can_respond).map((invite) => (
                    <div key={invite.id} className="flex items-center justify-between rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                      <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-full bg-gradient-to-br from-amber-400 to-orange-400 flex items-center justify-center text-white text-sm font-bold shrink-0">
                          {invite.accountant.name.charAt(0).toUpperCase()}
                        </div>
                        <div>
                          <div className="text-sm font-medium text-sw-text">{invite.accountant.name}</div>
                          <div className="text-xs text-sw-dim">
                            {invite.accountant.company_name ? `${invite.accountant.company_name} · ` : ''}{invite.accountant.email}
                          </div>
                          <div className="text-[10px] text-amber-700 mt-0.5">Wants access to your financial data</div>
                        </div>
                      </div>
                      <div className="flex items-center gap-2 shrink-0">
                        <button
                          onClick={() => respondToInvite(invite.id, 'accept')}
                          className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-sw-success text-white text-xs font-semibold hover:bg-emerald-700 transition"
                        >
                          <Check size={12} /> Accept
                        </button>
                        <button
                          onClick={() => respondToInvite(invite.id, 'decline')}
                          className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-sw-border text-sw-muted text-xs font-semibold hover:bg-sw-surface transition"
                        >
                          <XIcon size={12} /> Decline
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Current accountants list */}
            {myAccountants.length > 0 && (
              <div className="mb-5">
                <h4 className="text-xs font-semibold text-sw-muted uppercase tracking-wide mb-2">
                  Accountants with Access ({myAccountants.filter(r => r.status === 'active').length})
                </h4>
                <div className="space-y-2">
                  {myAccountants.map((rel) => (
                    <div key={rel.id} className="flex items-center justify-between rounded-xl border border-sw-border px-4 py-3">
                      <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-500 flex items-center justify-center text-white text-sm font-bold shrink-0">
                          {rel.accountant.name.charAt(0).toUpperCase()}
                        </div>
                        <div>
                          <div className="text-sm font-medium text-sw-text">{rel.accountant.name}</div>
                          <div className="text-xs text-sw-dim">
                            {rel.accountant.company_name ? `${rel.accountant.company_name} · ` : ''}{rel.accountant.email}
                          </div>
                          <div className="text-[10px] text-sw-dim mt-0.5">
                            Added {new Date(rel.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                            {rel.status === 'active' && ' · Can view transactions, download tax exports, recategorize expenses'}
                          </div>
                        </div>
                      </div>
                      <div className="flex items-center gap-2 shrink-0">
                        <Badge variant={rel.status === 'active' ? 'success' : 'warning'}>
                          {rel.status === 'active' ? 'Active' : 'Pending'}
                        </Badge>
                        <button
                          onClick={() => removeAccountant(rel.accountant.id)}
                          className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-sw-border text-sw-dim text-xs font-semibold hover:text-sw-danger hover:border-sw-danger transition"
                          title="Revoke access"
                        >
                          <XIcon size={12} /> Remove
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Search for accountant */}
            <div className="relative">
              <label className={labelClasses}>Add an accountant</label>
              <div className="relative">
                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-sw-dim" />
                <input
                  type="text"
                  value={accountantSearch}
                  onChange={(e) => searchAccountants(e.target.value)}
                  placeholder="Search by name, company, or email..."
                  className={`${inputClasses} pl-8`}
                />
                {accountantSearching && (
                  <Loader2 size={14} className="absolute right-3 top-1/2 -translate-y-1/2 animate-spin text-sw-dim" />
                )}
              </div>

              {/* Search results dropdown */}
              {accountantResults.length > 0 && (
                <div className="absolute z-20 mt-1 w-full rounded-xl border border-sw-border bg-sw-card shadow-lg max-h-48 overflow-y-auto">
                  {accountantResults.map((acct) => {
                    const isActive = myAccountants.some(r => r.accountant.id === acct.id);
                    const isPending = accountantInvites.some(inv => inv.accountant.id === acct.id);
                    return (
                      <div key={acct.id} className="flex items-center justify-between px-4 py-2.5 hover:bg-sw-surface transition">
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-indigo-500 flex items-center justify-center text-white text-xs font-bold shrink-0">
                            {acct.name.charAt(0).toUpperCase()}
                          </div>
                          <div>
                            <div className="text-sm font-medium text-sw-text">{acct.name}</div>
                            <div className="text-xs text-sw-dim">
                              {acct.company_name ? `${acct.company_name} · ` : ''}{acct.email}
                            </div>
                          </div>
                        </div>
                        {isActive ? (
                          <Badge variant="success">Active</Badge>
                        ) : isPending ? (
                          <Badge variant="warning">Pending</Badge>
                        ) : (
                          <button
                            onClick={() => addAccountant(acct.id)}
                            disabled={accountantLoading}
                            className="shrink-0 inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
                          >
                            <Plus size={12} /> Add
                          </button>
                        )}
                      </div>
                    );
                  })}
                </div>
              )}

              {myAccountants.length === 0 && accountantInvites.filter(inv => inv.can_respond).length === 0 && (
                <p className="text-xs text-sw-dim mt-2">No accountants linked yet. Search above to find and add your accountant.</p>
              )}
            </div>
          </div>
        )}

        {/* Section 3: Preferences */}
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
          <div className="flex items-center gap-3 mb-5">
            <div className="w-9 h-9 rounded-lg bg-sw-accent/10 border border-sw-accent/20 flex items-center justify-center">
              <Globe size={18} className="text-sw-accent" />
            </div>
            <div>
              <h3 className="text-[15px] font-semibold text-sw-text">Preferences</h3>
              <p className="text-xs text-sw-dim">Display settings for your account</p>
            </div>
          </div>

          {timezoneSuccess && <div aria-live="polite" className="mb-4"><SuccessToast message="Timezone updated" /></div>}

          <div className="max-w-sm">
            <label className={labelClasses}>Timezone</label>
            <select
              value={timezone}
              onChange={(e) => setTimezone(e.target.value)}
              className={inputClasses}
            >
              <optgroup label="United States">
                {US_TIMEZONES.map((tz) => (
                  <option key={tz.value} value={tz.value}>{tz.label}</option>
                ))}
              </optgroup>
              <optgroup label="All Timezones">
                {allTimezones.filter((tz) => !US_TIMEZONES.some((us) => us.value === tz.value)).map((tz) => (
                  <option key={tz.value} value={tz.value}>{tz.label}</option>
                ))}
              </optgroup>
            </select>
          </div>

          <div className="mt-5 flex justify-end">
            <button
              onClick={handleTimezoneSave}
              disabled={timezoneLoading || timezone === authTimezone}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
            >
              {timezoneLoading ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
              Save Timezone
            </button>
          </div>
        </div>

        {/* Section 4: Cookie Preferences */}
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
          <div className="flex items-center gap-3 mb-5">
            <div className="w-9 h-9 rounded-lg bg-sw-accent/10 border border-sw-accent/20 flex items-center justify-center">
              <Cookie size={18} className="text-sw-accent" />
            </div>
            <div>
              <h3 className="text-[15px] font-semibold text-sw-text">Cookie Preferences</h3>
              <p className="text-xs text-sw-dim">Manage how we use cookies on your account</p>
            </div>
          </div>

          {cookieSuccess && <div aria-live="polite" className="mb-4"><SuccessToast message="Cookie preferences updated" /></div>}

          <div className="space-y-3">
            {/* Necessary */}
            <div className="flex items-center justify-between py-2">
              <div>
                <span className="text-sm font-medium text-sw-text">Necessary Cookies</span>
                <p className="text-xs text-sw-dim mt-0.5">Required for the site to function properly</p>
              </div>
              <Badge variant="success">Always Active</Badge>
            </div>

            {/* Analytics */}
            <div className="flex items-center justify-between py-2 border-t border-sw-border">
              <div>
                <span className="text-sm font-medium text-sw-text">Analytics Cookies</span>
                <p className="text-xs text-sw-dim mt-0.5">Help us understand how visitors use SpendifiAI</p>
              </div>
              <label className="relative inline-flex items-center cursor-pointer">
                <input
                  type="checkbox"
                  checked={cookieAnalytics}
                  onChange={(e) => setCookieAnalytics(e.target.checked)}
                  className="sr-only peer"
                />
                <div className="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-sw-accent"></div>
              </label>
            </div>

            {/* Marketing */}
            <div className="flex items-center justify-between py-2 border-t border-sw-border">
              <div>
                <span className="text-sm font-medium text-sw-text">Marketing Cookies</span>
                <p className="text-xs text-sw-dim mt-0.5">Used to show relevant ads and measure campaign effectiveness</p>
              </div>
              <label className="relative inline-flex items-center cursor-pointer">
                <input
                  type="checkbox"
                  checked={cookieMarketing}
                  onChange={(e) => setCookieMarketing(e.target.checked)}
                  className="sr-only peer"
                />
                <div className="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-sw-accent"></div>
              </label>
            </div>
          </div>

          <div className="mt-5 flex items-center justify-between">
            <button
              onClick={handleCookieRevoke}
              disabled={cookieRevoking}
              className="text-xs font-medium text-sw-danger hover:underline transition disabled:opacity-50"
            >
              {cookieRevoking ? 'Revoking...' : 'Revoke All Consent'}
            </button>
            <button
              onClick={handleCookieSave}
              disabled={cookieSaving}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
            >
              {cookieSaving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
              Save Preferences
            </button>
          </div>
        </div>

        {/* Section 5: Security */}
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
          <div className="flex items-center gap-3 mb-5">
            <div className="w-9 h-9 rounded-lg bg-sw-accent-light border border-blue-200 flex items-center justify-center">
              <Shield size={18} className="text-sw-accent" />
            </div>
            <div>
              <h3 className="text-[15px] font-semibold text-sw-text">Security</h3>
              <p className="text-xs text-sw-dim">Password, two-factor authentication, and connected accounts</p>
            </div>
          </div>

          {/* Change Password */}
          <div className="mb-6">
            <h4 className="text-sm font-medium text-sw-text mb-3 flex items-center gap-2">
              <Lock size={14} /> Change Password
            </h4>

            {passwordSuccess && <div aria-live="polite" className="mb-3"><SuccessToast message="Password changed successfully" /></div>}
            {passwordError && (
              <div aria-live="polite" className="mb-3 px-4 py-2 rounded-lg bg-sw-danger/10 border border-sw-danger/30 text-sw-danger text-xs">
                {passwordError}
              </div>
            )}

            <form onSubmit={(e) => { e.preventDefault(); handlePasswordChange(); }} className="max-w-sm">
              {/* Hidden username field for accessibility/autofill */}
              <input type="text" value={authUser.email} readOnly hidden aria-hidden="true" autoComplete="username" />
              <div className="space-y-3">
                <input
                  type="password"
                  value={passwordForm.current_password}
                  onChange={(e) => setPasswordForm({ ...passwordForm, current_password: e.target.value })}
                  placeholder="Current password"
                  aria-label="Current password"
                  className={inputClasses}
                  autoComplete="current-password"
                />
                <input
                  type="password"
                  value={passwordForm.password}
                  onChange={(e) => setPasswordForm({ ...passwordForm, password: e.target.value })}
                  placeholder="New password"
                  aria-label="New password"
                  className={inputClasses}
                  autoComplete="new-password"
                />
                <input
                  type="password"
                  value={passwordForm.password_confirmation}
                  onChange={(e) => setPasswordForm({ ...passwordForm, password_confirmation: e.target.value })}
                  placeholder="Confirm new password"
                  aria-label="Confirm new password"
                  className={inputClasses}
                  autoComplete="new-password"
                />
                <button
                  type="submit"
                  disabled={passwordLoading || !passwordForm.current_password || !passwordForm.password}
                  className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
                >
                  {passwordLoading ? <Loader2 size={14} className="animate-spin" /> : <Lock size={14} />}
                  Update Password
                </button>
              </div>
            </form>
          </div>

          {/* 2FA */}
          <div className="pt-5 border-t border-sw-border">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <Smartphone size={16} className="text-sw-muted" />
                <div>
                  <span className="text-sm font-medium text-sw-text">Two-Factor Authentication</span>
                  <p className="text-xs text-sw-dim mt-0.5">
                    Add an extra layer of security to your account
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                <Badge variant={twoFactorEnabled ? 'success' : 'neutral'}>
                  {twoFactorEnabled ? 'Enabled' : 'Disabled'}
                </Badge>
                <button
                  onClick={toggle2FA}
                  disabled={twoFactorLoading}
                  className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition ${
                    twoFactorEnabled
                      ? 'border border-sw-danger/30 text-sw-danger hover:bg-sw-danger/10'
                      : 'bg-sw-accent text-white hover:bg-sw-accent-hover'
                  }`}
                >
                  {twoFactorLoading ? <Loader2 size={12} className="animate-spin" /> : twoFactorEnabled ? 'Disable' : 'Enable'}
                </button>
              </div>
            </div>
          </div>

          {/* Google connection status */}
          {authUser.google_connected !== undefined && (
            <div className="pt-5 mt-5 border-t border-sw-border flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="w-5 h-5 rounded bg-white flex items-center justify-center text-[10px] font-bold text-gray-700">G</div>
                <span className="text-sm text-sw-text">Google Account</span>
              </div>
              <Badge variant={authUser.google_connected ? 'success' : 'neutral'}>
                {authUser.google_connected ? 'Connected' : 'Not Connected'}
              </Badge>
            </div>
          )}
        </div>

        {/* Section 5: Delete Account */}
        <div className="rounded-2xl border border-sw-danger/30 bg-sw-card p-6">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-9 h-9 rounded-lg bg-sw-danger/10 border border-sw-danger/20 flex items-center justify-center">
              <Trash2 size={18} className="text-sw-danger" />
            </div>
            <div>
              <h3 className="text-[15px] font-semibold text-sw-text">Danger Zone</h3>
              <p className="text-xs text-sw-dim">Permanently delete your account and all data</p>
            </div>
          </div>
          <button
            onClick={() => setDeleteOpen(true)}
            className="px-4 py-2 rounded-lg bg-sw-danger text-white text-sm font-semibold hover:bg-red-600 transition"
          >
            Delete Account
          </button>
        </div>

        {/* Delete confirmation */}
        <ConfirmDialog
          open={deleteOpen}
          onConfirm={handleDeleteAccount}
          onCancel={() => {
            setDeleteOpen(false);
            setDeletePassword('');
          }}
          title="Delete Account"
          message="This action is permanent and cannot be undone. All your data, transactions, and connected accounts will be permanently deleted."
          confirmText={deleteLoading ? 'Deleting...' : 'Delete My Account'}
          variant="danger"
        />
      </div>
    </AuthenticatedLayout>
  );
}
