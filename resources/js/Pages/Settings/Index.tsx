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
} from 'lucide-react';
import ConfirmDialog from '@/Components/SpendifiAI/ConfirmDialog';
import Badge from '@/Components/SpendifiAI/Badge';
import { useApi, useApiPost } from '@/hooks/useApi';
import type { UserFinancialProfile, UserFinancialProfileResponse } from '@/types/spendifiai';
import axios from 'axios';

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

        {/* Section 2: Security */}
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

        {/* Section 3: Delete Account */}
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
