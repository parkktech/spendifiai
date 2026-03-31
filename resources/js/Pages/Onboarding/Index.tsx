import { useState, useEffect, useCallback } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
  Building2, Mail, User, Rocket, CheckCircle2, ChevronRight,
  Loader2, SkipForward, ArrowRight, Briefcase,
} from 'lucide-react';
import PlaidLinkButton from '@/Components/SpendifiAI/PlaidLinkButton';
import axios from 'axios';

type Step = 'bank' | 'email' | 'profile' | 'processing';

const STEPS: { key: Step; label: string; icon: typeof Building2 }[] = [
  { key: 'bank', label: 'Connect Bank', icon: Building2 },
  { key: 'email', label: 'Connect Email', icon: Mail },
  { key: 'profile', label: 'Quick Profile', icon: User },
  { key: 'processing', label: 'Processing', icon: Rocket },
];

export default function OnboardingIndex() {
  const pageProps = usePage().props as Record<string, unknown>;
  const auth = pageProps.auth as { user?: { name?: string }; hasBankConnected?: boolean; hasEmailConnected?: boolean; isAccountant?: boolean; userType?: string };

  const [currentStep, setCurrentStep] = useState<Step>('bank');
  const [completedSteps, setCompletedSteps] = useState<Set<Step>>(new Set());
  const [animating, setAnimating] = useState(false);

  // Profile form state
  const [profileForm, setProfileForm] = useState({
    employment_type: '',
    tax_filing_status: '',
    has_home_office: false,
    housing_status: '',
  });
  const [profileSaving, setProfileSaving] = useState(false);

  // Email state
  const [emailConnecting, setEmailConnecting] = useState(false);

  // Auto-advance if bank/email already connected
  useEffect(() => {
    if (auth.hasBankConnected && currentStep === 'bank') {
      markComplete('bank');
    }
  }, [auth.hasBankConnected]);

  useEffect(() => {
    if (auth.hasEmailConnected && currentStep === 'email') {
      markComplete('email');
    }
  }, [auth.hasEmailConnected]);

  const markComplete = useCallback((step: Step) => {
    setCompletedSteps(prev => new Set([...prev, step]));
    setAnimating(true);
    setTimeout(() => {
      const idx = STEPS.findIndex(s => s.key === step);
      if (idx < STEPS.length - 1) {
        setCurrentStep(STEPS[idx + 1].key);
      }
      setAnimating(false);
    }, 1500);
  }, []);

  const skipStep = (step: Step) => {
    const idx = STEPS.findIndex(s => s.key === step);
    if (idx < STEPS.length - 1) {
      setCurrentStep(STEPS[idx + 1].key);
    }
  };

  const handleBankSuccess = () => {
    markComplete('bank');
  };

  const handleGmailConnect = async () => {
    setEmailConnecting(true);
    try {
      const res = await axios.post<{ redirect_url: string }>('/api/v1/email/connect/gmail');
      window.location.href = res.data.redirect_url;
    } catch {
      setEmailConnecting(false);
    }
  };

  const handleOutlookConnect = async () => {
    setEmailConnecting(true);
    try {
      const res = await axios.post<{ redirect_url: string }>('/api/v1/email/connect/outlook');
      window.location.href = res.data.redirect_url;
    } catch {
      setEmailConnecting(false);
    }
  };

  const handleProfileSubmit = async () => {
    setProfileSaving(true);
    try {
      await axios.post('/api/v1/profile/financial', {
        employment_type: profileForm.employment_type || null,
        tax_filing_status: profileForm.tax_filing_status || null,
        has_home_office: profileForm.has_home_office,
        housing_status: profileForm.housing_status || null,
      });
      markComplete('profile');
      // Dispatch onboarding pipeline
      try {
        await axios.post('/api/v1/onboarding/start');
      } catch {
        // Pipeline dispatch is best-effort
      }
    } catch {
      // Allow retry
    } finally {
      setProfileSaving(false);
    }
  };

  const handleGoToDashboard = async () => {
    try {
      await axios.post('/api/v1/onboarding/complete');
    } catch {
      // Best-effort — don't block navigation
    }
    router.visit('/dashboard');
  };

  const currentStepIndex = STEPS.findIndex(s => s.key === currentStep);

  return (
    <>
      <Head title="Get Started" />
      <div className="min-h-screen bg-sw-bg flex flex-col">
        {/* Header */}
        <div className="border-b border-sw-border bg-sw-card px-6 py-4">
          <div className="max-w-2xl mx-auto flex items-center justify-between">
            <h1 className="text-lg font-bold text-sw-text">SpendifiAI</h1>
            <span className="text-xs text-sw-muted">
              Welcome{auth.user?.name ? `, ${auth.user.name.split(' ')[0]}` : ''}!
            </span>
          </div>
        </div>

        {/* Progress bar */}
        <div className="border-b border-sw-border bg-sw-card px-6 py-3">
          <div className="max-w-2xl mx-auto">
            <div className="flex items-center gap-2">
              {STEPS.map((step, i) => (
                <div key={step.key} className="flex items-center gap-2 flex-1">
                  <div className={`
                    w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold shrink-0 transition-all duration-300
                    ${completedSteps.has(step.key)
                      ? 'bg-sw-success text-white'
                      : currentStep === step.key
                        ? 'bg-sw-accent text-white'
                        : 'bg-sw-surface text-sw-dim border border-sw-border'
                    }
                  `}>
                    {completedSteps.has(step.key) ? <CheckCircle2 size={14} /> : i + 1}
                  </div>
                  <span className={`text-xs font-medium hidden sm:block ${
                    currentStep === step.key ? 'text-sw-text' : 'text-sw-dim'
                  }`}>
                    {step.label}
                  </span>
                  {i < STEPS.length - 1 && (
                    <div className={`flex-1 h-0.5 rounded transition-all duration-300 ${
                      completedSteps.has(step.key) ? 'bg-sw-success' : 'bg-sw-border'
                    }`} />
                  )}
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Content */}
        <div className="flex-1 flex items-start justify-center px-6 py-10">
          <div className="w-full max-w-lg">

            {/* Accountant fast-track option */}
            {auth.isAccountant && !animating && currentStep === 'bank' && (
              <div className="mb-8 p-5 rounded-2xl border-2 border-sw-accent/30 bg-sw-accent-light">
                <div className="flex items-start gap-4">
                  <div className="w-11 h-11 rounded-xl bg-sw-accent/10 flex items-center justify-center shrink-0">
                    <Briefcase size={22} className="text-sw-accent" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <h3 className="text-sm font-bold text-sw-text mb-1">Here for client access?</h3>
                    <p className="text-xs text-sw-muted mb-3">
                      Skip onboarding and go straight to your client management dashboard. You can always come back to connect your own bank and profile later.
                    </p>
                    <button
                      onClick={handleGoToDashboard}
                      className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-sw-accent text-white font-semibold text-sm hover:bg-sw-accent-hover transition"
                    >
                      <Briefcase size={14} />
                      Skip to Client Dashboard
                      <ChevronRight size={14} />
                    </button>
                  </div>
                </div>
              </div>
            )}

            {/* Step completion animation overlay */}
            {animating && (
              <div className="text-center py-12">
                <CheckCircle2 size={48} className="mx-auto text-sw-success mb-3 animate-bounce" />
                <p className="text-sm font-semibold text-sw-success">Done!</p>
              </div>
            )}

            {/* Step 1: Connect Bank */}
            {!animating && currentStep === 'bank' && (
              <div className="space-y-6">
                <div className="text-center">
                  <div className="w-14 h-14 rounded-2xl bg-sw-accent-light flex items-center justify-center mx-auto mb-4">
                    <Building2 size={28} className="text-sw-accent" />
                  </div>
                  <h2 className="text-xl font-bold text-sw-text mb-2">Connect Your Bank</h2>
                  <p className="text-sm text-sw-muted max-w-sm mx-auto">
                    Securely link your bank to import transactions and download statements automatically.
                  </p>
                </div>

                <div className="flex flex-col items-center gap-4">
                  <PlaidLinkButton onSuccess={handleBankSuccess} />
                  <button
                    onClick={() => skipStep('bank')}
                    className="inline-flex items-center gap-1 text-xs text-sw-dim hover:text-sw-muted transition"
                  >
                    <SkipForward size={12} /> Skip for now
                  </button>
                </div>
              </div>
            )}

            {/* Step 2: Connect Email */}
            {!animating && currentStep === 'email' && (
              <div className="space-y-6">
                <div className="text-center">
                  <div className="w-14 h-14 rounded-2xl bg-sw-info/10 flex items-center justify-center mx-auto mb-4">
                    <Mail size={28} className="text-sw-info" />
                  </div>
                  <h2 className="text-xl font-bold text-sw-text mb-2">Connect Your Email</h2>
                  <p className="text-sm text-sw-muted max-w-sm mx-auto">
                    We'll scan for receipts to match against your transactions for better categorization.
                  </p>
                </div>

                <div className="flex flex-col items-center gap-3">
                  <button
                    onClick={handleGmailConnect}
                    disabled={emailConnecting}
                    className="inline-flex items-center gap-2.5 px-6 py-3 rounded-xl bg-white border border-sw-border text-sw-text font-semibold text-sm hover:bg-sw-bg transition w-64 justify-center disabled:opacity-50"
                  >
                    {emailConnecting ? <Loader2 size={18} className="animate-spin" /> : (
                      <svg viewBox="0 0 24 24" width="18" height="18"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                    )}
                    Connect Gmail
                  </button>
                  <button
                    onClick={handleOutlookConnect}
                    disabled={emailConnecting}
                    className="inline-flex items-center gap-2.5 px-6 py-3 rounded-xl bg-white border border-sw-border text-sw-text font-semibold text-sm hover:bg-sw-bg transition w-64 justify-center disabled:opacity-50"
                  >
                    {emailConnecting ? <Loader2 size={18} className="animate-spin" /> : (
                      <svg viewBox="0 0 24 24" width="18" height="18"><path d="M21.17 2H7.83A1.83 1.83 0 006 3.83v1.75l8 4.62 8-4.62V3.83A1.83 1.83 0 0021.17 2z" fill="#0364B8"/><path d="M22 7.24l-8 4.63V24h7.17A1.83 1.83 0 0023 22.17V8.17a1.62 1.62 0 00-1-.93z" fill="#0078D4"/><path d="M6 7.24V22.17A1.83 1.83 0 007.83 24H14V11.87L6 7.24z" fill="#28A8EA"/><path d="M14 0v5.58l-8-4.62A1.83 1.83 0 004.17 0H14z" fill="#50D9FF"/><path d="M1 5.58V18a2.15 2.15 0 002.15 2.15h.68V7.24L1 5.58z" fill="#0364B8"/></svg>
                    )}
                    Connect Outlook
                  </button>
                  <button
                    onClick={() => skipStep('email')}
                    className="inline-flex items-center gap-1 text-xs text-sw-dim hover:text-sw-muted transition mt-2"
                  >
                    <SkipForward size={12} /> Skip for now
                  </button>
                </div>
              </div>
            )}

            {/* Step 3: Quick Profile */}
            {!animating && currentStep === 'profile' && (
              <div className="space-y-6">
                <div className="text-center">
                  <div className="w-14 h-14 rounded-2xl bg-sw-success-light flex items-center justify-center mx-auto mb-4">
                    <User size={28} className="text-sw-success" />
                  </div>
                  <h2 className="text-xl font-bold text-sw-text mb-2">Quick Profile</h2>
                  <p className="text-sm text-sw-muted max-w-sm mx-auto">
                    A few questions to help us find tax deductions and personalize your experience.
                  </p>
                </div>

                <div className="space-y-4 max-w-sm mx-auto">
                  <div>
                    <label className="block text-xs font-medium text-sw-text mb-1.5">Employment Type</label>
                    <select
                      value={profileForm.employment_type}
                      onChange={(e) => setProfileForm({ ...profileForm, employment_type: e.target.value })}
                      className="w-full px-3 py-2.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm focus:outline-none focus:border-sw-accent"
                    >
                      <option value="">Select...</option>
                      <option value="w2_employee">W-2 Employee</option>
                      <option value="self_employed">Self-Employed</option>
                      <option value="1099_contractor">1099 Contractor</option>
                      <option value="business_owner">Business Owner</option>
                      <option value="retired">Retired</option>
                      <option value="student">Student</option>
                      <option value="unemployed">Not Currently Working</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-sw-text mb-1.5">Tax Filing Status</label>
                    <select
                      value={profileForm.tax_filing_status}
                      onChange={(e) => setProfileForm({ ...profileForm, tax_filing_status: e.target.value })}
                      className="w-full px-3 py-2.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm focus:outline-none focus:border-sw-accent"
                    >
                      <option value="">Select...</option>
                      <option value="single">Single</option>
                      <option value="married_joint">Married Filing Jointly</option>
                      <option value="married_separate">Married Filing Separately</option>
                      <option value="head_of_household">Head of Household</option>
                      <option value="qualifying_widow">Qualifying Surviving Spouse</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-sw-text mb-1.5">Housing</label>
                    <select
                      value={profileForm.housing_status}
                      onChange={(e) => setProfileForm({ ...profileForm, housing_status: e.target.value })}
                      className="w-full px-3 py-2.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm focus:outline-none focus:border-sw-accent"
                    >
                      <option value="">Select...</option>
                      <option value="own_mortgage">Own with mortgage</option>
                      <option value="own_outright">Own outright</option>
                      <option value="rent">Rent</option>
                      <option value="other">Other</option>
                    </select>
                  </div>

                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={profileForm.has_home_office}
                      onChange={(e) => setProfileForm({ ...profileForm, has_home_office: e.target.checked })}
                      className="w-4 h-4 rounded border-sw-border text-sw-accent focus:ring-sw-accent"
                    />
                    <span className="text-sm text-sw-text">I have a home office</span>
                  </label>

                  <div className="flex flex-col items-center gap-3 pt-2">
                    <button
                      onClick={handleProfileSubmit}
                      disabled={profileSaving}
                      className="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-sw-accent text-white font-semibold text-sm hover:bg-sw-accent-hover transition disabled:opacity-50 w-full justify-center"
                    >
                      {profileSaving ? <Loader2 size={16} className="animate-spin" /> : <ArrowRight size={16} />}
                      Continue
                    </button>
                    <button
                      onClick={() => skipStep('profile')}
                      className="inline-flex items-center gap-1 text-xs text-sw-dim hover:text-sw-muted transition"
                    >
                      <SkipForward size={12} /> Skip for now
                    </button>
                  </div>
                </div>
              </div>
            )}

            {/* Step 4: Processing */}
            {!animating && currentStep === 'processing' && (
              <div className="space-y-6">
                <div className="text-center">
                  <div className="w-14 h-14 rounded-2xl bg-sw-accent-light flex items-center justify-center mx-auto mb-4">
                    <Rocket size={28} className="text-sw-accent" />
                  </div>
                  <h2 className="text-xl font-bold text-sw-text mb-2">We're On It!</h2>
                  <p className="text-sm text-sw-muted max-w-sm mx-auto">
                    We're processing your financial data in the background. We'll email you when everything is ready.
                  </p>
                </div>

                <div className="max-w-sm mx-auto space-y-3">
                  {[
                    { label: 'Syncing bank transactions', done: completedSteps.has('bank') },
                    { label: 'Downloading bank statements', done: completedSteps.has('bank') },
                    { label: 'Scanning email for receipts', done: completedSteps.has('email') },
                    { label: 'Categorizing transactions with AI', done: false },
                    { label: 'Detecting subscriptions', done: false },
                    { label: 'Finding tax deductions', done: false },
                  ].map((item, i) => (
                    <div key={i} className="flex items-center gap-3 p-3 rounded-lg bg-sw-bg border border-sw-border">
                      {item.done ? (
                        <CheckCircle2 size={16} className="text-sw-success shrink-0" />
                      ) : (
                        <Loader2 size={16} className="text-sw-accent animate-spin shrink-0" />
                      )}
                      <span className={`text-sm ${item.done ? 'text-sw-muted' : 'text-sw-text'}`}>{item.label}</span>
                    </div>
                  ))}
                </div>

                <div className="text-center">
                  <p className="text-xs text-sw-dim mb-4">
                    Feel free to explore while we work. We'll send you an email when everything is ready!
                  </p>
                  <button
                    onClick={handleGoToDashboard}
                    className="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-sw-accent text-white font-semibold text-sm hover:bg-sw-accent-hover transition"
                  >
                    Go to Dashboard <ChevronRight size={16} />
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
