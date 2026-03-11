import { createContext, useContext, useState, useCallback, useEffect, type ReactNode } from 'react';
import axios from 'axios';

interface ConsentState {
  hasConsent: boolean;
  analytics: boolean;
  marketing: boolean;
  version: string;
  gtmId: string | null;
  ga4Id: string | null;
  region: 'eu' | 'california' | 'other' | null;
  requiresOptIn: boolean;
  requiresOptOutNotice: boolean;
  bannerVisible: boolean;
  customizeOpen: boolean;
}

interface ConsentContextValue extends ConsentState {
  acceptAll: () => Promise<void>;
  rejectAll: () => Promise<void>;
  savePreferences: (analytics: boolean, marketing: boolean) => Promise<void>;
  revokeConsent: () => Promise<void>;
  dismissBanner: () => void;
  openCustomize: () => void;
  closeCustomize: () => void;
}

const ConsentContext = createContext<ConsentContextValue | null>(null);

export function useConsent(): ConsentContextValue {
  const ctx = useContext(ConsentContext);
  if (!ctx) throw new Error('useConsent must be used within ConsentProvider');
  return ctx;
}

function updateGoogleConsent(analytics: boolean, marketing: boolean) {
  if (typeof window === 'undefined') return;

  const w = window as unknown as { gtag?: (...args: unknown[]) => void };
  if (!w.gtag) return;

  w.gtag('consent', 'update', {
    analytics_storage: analytics ? 'granted' : 'denied',
    ad_storage: marketing ? 'granted' : 'denied',
    ad_user_data: marketing ? 'granted' : 'denied',
    ad_personalization: marketing ? 'granted' : 'denied',
  });
}

function getCookie(name: string): string | null {
  const value = `; ${document.cookie}`;
  const parts = value.split(`; ${name}=`);
  if (parts.length === 2) return parts.pop()?.split(';').shift() || null;
  return null;
}

function readConsentCookie(): { hasConsent: boolean; analytics: boolean; marketing: boolean; version: string } {
  try {
    const raw = getCookie('sw_consent');
    if (!raw) return { hasConsent: false, analytics: false, marketing: false, version: '1.0' };
    const data = JSON.parse(decodeURIComponent(raw));
    return {
      hasConsent: true,
      analytics: !!data.a,
      marketing: !!data.m,
      version: data.v || '1.0',
    };
  } catch {
    return { hasConsent: false, analytics: false, marketing: false, version: '1.0' };
  }
}

// Read GTM/GA4 IDs from a meta tag set by the server, or from Inertia shared props
function getTrackingIds(): { gtmId: string | null; ga4Id: string | null } {
  // Try reading from Inertia's page data embedded in the DOM
  try {
    const pageEl = document.getElementById('app');
    if (pageEl) {
      const dataPage = pageEl.getAttribute('data-page');
      if (dataPage) {
        const parsed = JSON.parse(dataPage);
        const consent = parsed?.props?.consent;
        if (consent) {
          return {
            gtmId: consent.gtm_id || null,
            ga4Id: consent.ga4_id || null,
          };
        }
      }
    }
  } catch {
    // ignore
  }
  return { gtmId: null, ga4Id: null };
}

export function ConsentProvider({ children }: { children: ReactNode }) {
  const cookieState = readConsentCookie();
  const trackingIds = getTrackingIds();

  const [state, setState] = useState<ConsentState>({
    hasConsent: cookieState.hasConsent,
    analytics: cookieState.analytics,
    marketing: cookieState.marketing,
    version: cookieState.version,
    gtmId: trackingIds.gtmId,
    ga4Id: trackingIds.ga4Id,
    region: null,
    requiresOptIn: false,
    requiresOptOutNotice: false,
    bannerVisible: false,
    customizeOpen: false,
  });

  // Fetch region detection when no consent cookie exists
  useEffect(() => {
    if (state.hasConsent) return;

    axios.get('/api/v1/consent/config').then(({ data }) => {
      setState(prev => ({
        ...prev,
        region: data.region,
        requiresOptIn: data.requires_opt_in,
        requiresOptOutNotice: data.requires_opt_out_notice,
        bannerVisible: !data.has_consent,
        hasConsent: data.has_consent,
        analytics: data.current_preferences?.analytics ?? prev.analytics,
        marketing: data.current_preferences?.marketing ?? prev.marketing,
      }));
    }).catch(() => {
      // On error, show banner with "other" region defaults
      setState(prev => ({
        ...prev,
        region: 'other',
        bannerVisible: true,
      }));
    });
  }, [state.hasConsent]);

  const recordConsent = useCallback(async (analytics: boolean, marketing: boolean) => {
    try {
      await axios.post('/api/v1/consent', {
        analytics,
        marketing,
        region: state.region || 'other',
      });

      setState(prev => ({
        ...prev,
        hasConsent: true,
        analytics,
        marketing,
        bannerVisible: false,
        customizeOpen: false,
      }));

      updateGoogleConsent(analytics, marketing);
    } catch {
      // Silent fail — banner remains
    }
  }, [state.region]);

  const acceptAll = useCallback(async () => {
    await recordConsent(true, true);
  }, [recordConsent]);

  const rejectAll = useCallback(async () => {
    await recordConsent(false, false);
  }, [recordConsent]);

  const savePreferences = useCallback(async (analytics: boolean, marketing: boolean) => {
    const token = localStorage.getItem('auth_token');
    if (token) {
      try {
        await axios.put('/api/v1/consent/preferences', { analytics, marketing });
        setState(prev => ({
          ...prev,
          hasConsent: true,
          analytics,
          marketing,
          bannerVisible: false,
          customizeOpen: false,
        }));
        updateGoogleConsent(analytics, marketing);
        return;
      } catch {
        // Fall through to public endpoint
      }
    }
    await recordConsent(analytics, marketing);
  }, [recordConsent]);

  const revokeConsent = useCallback(async () => {
    try {
      await axios.delete('/api/v1/consent/preferences');
      setState(prev => ({
        ...prev,
        analytics: false,
        marketing: false,
      }));
      updateGoogleConsent(false, false);
    } catch {
      // Silent fail
    }
  }, []);

  const dismissBanner = useCallback(() => {
    setState(prev => ({ ...prev, bannerVisible: false }));
  }, []);

  const openCustomize = useCallback(() => {
    setState(prev => ({ ...prev, customizeOpen: true }));
  }, []);

  const closeCustomize = useCallback(() => {
    setState(prev => ({ ...prev, customizeOpen: false }));
  }, []);

  return (
    <ConsentContext.Provider value={{
      ...state,
      acceptAll,
      rejectAll,
      savePreferences,
      revokeConsent,
      dismissBanner,
      openCustomize,
      closeCustomize,
    }}>
      {children}
    </ConsentContext.Provider>
  );
}
