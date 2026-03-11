import { useState } from 'react';
import { useConsent } from '@/contexts/ConsentContext';
import { Cookie, Shield, ChevronDown, ChevronUp, X } from 'lucide-react';

export default function CookieConsentBanner() {
  const {
    bannerVisible,
    region,
    requiresOptIn,
    requiresOptOutNotice,
    customizeOpen,
    acceptAll,
    rejectAll,
    savePreferences,
    dismissBanner,
    openCustomize,
    closeCustomize,
  } = useConsent();

  const [analyticsChecked, setAnalyticsChecked] = useState(true);
  const [marketingChecked, setMarketingChecked] = useState(true);
  const [saving, setSaving] = useState(false);

  if (!bannerVisible) return null;

  const handleSaveCustom = async () => {
    setSaving(true);
    await savePreferences(analyticsChecked, marketingChecked);
    setSaving(false);
  };

  const handleAcceptAll = async () => {
    setSaving(true);
    await acceptAll();
    setSaving(false);
  };

  const handleRejectAll = async () => {
    setSaving(true);
    await rejectAll();
    setSaving(false);
  };

  // "Got It" for non-regulated regions: analytics on by default, marketing off
  const handleGotIt = async () => {
    setSaving(true);
    await savePreferences(true, false);
    setSaving(false);
  };

  const isEU = region === 'eu' || requiresOptIn;
  const isCCPA = region === 'california' || requiresOptOutNotice;

  return (
    <div className="fixed bottom-4 left-1/2 -translate-x-1/2 z-[100] w-[calc(100%-2rem)] max-w-2xl">
      <div className="rounded-2xl border border-sw-border bg-sw-card shadow-2xl p-5">
        {/* Header */}
        <div className="flex items-start justify-between gap-3 mb-3">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-sw-accent/10 flex items-center justify-center shrink-0">
              {isEU ? <Shield size={16} className="text-sw-accent" /> : <Cookie size={16} className="text-sw-accent" />}
            </div>
            <h3 className="text-sm font-semibold text-sw-text">
              {isEU ? 'Cookie Preferences' : 'We Use Cookies'}
            </h3>
          </div>
          {!isEU && (
            <button
              onClick={dismissBanner}
              aria-label="Close cookie banner"
              className="text-sw-dim hover:text-sw-muted transition-colors"
            >
              <X size={16} />
            </button>
          )}
        </div>

        {/* Description */}
        <p className="text-xs text-sw-muted leading-relaxed mb-4">
          {isEU
            ? 'We use cookies to improve your experience. You choose which types of cookies to allow. Your financial data is never shared with third parties for advertising.'
            : isCCPA
              ? 'We use cookies to enhance your experience and analyze site usage. Your financial data is protected and never sold to third parties.'
              : 'We use cookies to improve your experience and understand how our site is used. Your financial data stays private and secure.'}
          {' '}
          <a href="/privacy#section-9" className="text-sw-accent hover:underline">
            Privacy Policy
          </a>
        </p>

        {/* Customize section */}
        {customizeOpen && (
          <div className="mb-4 space-y-2.5 p-3 rounded-xl bg-sw-surface border border-sw-border">
            {/* Necessary — always on */}
            <label className="flex items-center justify-between">
              <div>
                <span className="text-xs font-medium text-sw-text">Necessary</span>
                <p className="text-[11px] text-sw-dim">Required for the site to work. Cannot be disabled.</p>
              </div>
              <input type="checkbox" checked disabled className="w-4 h-4 rounded border-sw-border accent-sw-accent" />
            </label>

            {/* Analytics */}
            <label className="flex items-center justify-between cursor-pointer">
              <div>
                <span className="text-xs font-medium text-sw-text">Analytics</span>
                <p className="text-[11px] text-sw-dim">Help us understand how visitors use SpendifiAI.</p>
              </div>
              <input
                type="checkbox"
                checked={analyticsChecked}
                onChange={(e) => setAnalyticsChecked(e.target.checked)}
                className="w-4 h-4 rounded border-sw-border accent-sw-accent cursor-pointer"
              />
            </label>

            {/* Marketing */}
            <label className="flex items-center justify-between cursor-pointer">
              <div>
                <span className="text-xs font-medium text-sw-text">Marketing</span>
                <p className="text-[11px] text-sw-dim">Used to show relevant ads and measure campaign effectiveness.</p>
              </div>
              <input
                type="checkbox"
                checked={marketingChecked}
                onChange={(e) => setMarketingChecked(e.target.checked)}
                className="w-4 h-4 rounded border-sw-border accent-sw-accent cursor-pointer"
              />
            </label>
          </div>
        )}

        {/* Buttons */}
        <div className="flex flex-wrap items-center gap-2">
          {isEU ? (
            <>
              <button
                onClick={handleAcceptAll}
                disabled={saving}
                className="px-4 py-2 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
              >
                Accept All
              </button>
              <button
                onClick={handleRejectAll}
                disabled={saving}
                className="px-4 py-2 rounded-lg border border-sw-border text-xs font-semibold text-sw-text hover:bg-sw-surface transition disabled:opacity-50"
              >
                Reject All
              </button>
            </>
          ) : isCCPA ? (
            <>
              <button
                onClick={handleAcceptAll}
                disabled={saving}
                className="px-4 py-2 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
              >
                Accept
              </button>
              <button
                onClick={handleRejectAll}
                disabled={saving}
                className="px-4 py-2 rounded-lg border border-sw-border text-xs font-semibold text-sw-text hover:bg-sw-surface transition disabled:opacity-50"
              >
                Do Not Sell My Info
              </button>
            </>
          ) : (
            <button
              onClick={handleGotIt}
              disabled={saving}
              className="px-4 py-2 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
            >
              Got It
            </button>
          )}

          {customizeOpen ? (
            <button
              onClick={handleSaveCustom}
              disabled={saving}
              className="px-4 py-2 rounded-lg bg-sw-success text-white text-xs font-semibold hover:opacity-90 transition disabled:opacity-50"
            >
              Save Preferences
            </button>
          ) : null}

          <button
            onClick={customizeOpen ? closeCustomize : openCustomize}
            className="px-3 py-2 text-xs font-medium text-sw-muted hover:text-sw-text transition inline-flex items-center gap-1"
          >
            {customizeOpen ? (
              <>Less <ChevronUp size={12} /></>
            ) : (
              <>{isEU || isCCPA ? 'Customize' : 'Manage'} <ChevronDown size={12} /></>
            )}
          </button>
        </div>
      </div>
    </div>
  );
}
