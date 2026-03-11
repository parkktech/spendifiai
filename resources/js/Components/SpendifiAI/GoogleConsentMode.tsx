import { useEffect, useRef } from 'react';
import { useConsent } from '@/contexts/ConsentContext';

declare global {
  interface Window {
    dataLayer: unknown[];
    gtag: (...args: unknown[]) => void;
  }
}

export default function GoogleConsentMode() {
  const { analytics, marketing, hasConsent, gtmId, ga4Id } = useConsent();
  const initialized = useRef(false);

  // Initialize consent defaults + load GTM/GA4 on mount
  useEffect(() => {
    if (initialized.current) return;
    if (!gtmId && !ga4Id) return;

    initialized.current = true;

    // Initialize dataLayer and gtag
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () {
      // eslint-disable-next-line prefer-rest-params
      window.dataLayer.push(arguments);
    };

    // Set consent defaults — all denied until user consents
    window.gtag('consent', 'default', {
      analytics_storage: 'denied',
      ad_storage: 'denied',
      ad_user_data: 'denied',
      ad_personalization: 'denied',
      functionality_storage: 'granted',
      security_storage: 'granted',
      wait_for_update: 500,
    });

    // If user has existing consent, immediately update
    if (hasConsent) {
      window.gtag('consent', 'update', {
        analytics_storage: analytics ? 'granted' : 'denied',
        ad_storage: marketing ? 'granted' : 'denied',
        ad_user_data: marketing ? 'granted' : 'denied',
        ad_personalization: marketing ? 'granted' : 'denied',
      });
    }

    // Load GTM or GA4
    if (gtmId) {
      const script = document.createElement('script');
      script.async = true;
      script.src = `https://www.googletagmanager.com/gtm.js?id=${gtmId}`;
      document.head.appendChild(script);

      window.dataLayer.push({
        'gtm.start': new Date().getTime(),
        event: 'gtm.js',
      });
    } else if (ga4Id) {
      const script = document.createElement('script');
      script.async = true;
      script.src = `https://www.googletagmanager.com/gtag/js?id=${ga4Id}`;
      document.head.appendChild(script);

      window.gtag('js', new Date());
      window.gtag('config', ga4Id);
    }
  }, [gtmId, ga4Id, hasConsent, analytics, marketing]);

  // React to consent changes
  useEffect(() => {
    if (!initialized.current) return;
    if (!window.gtag) return;

    window.gtag('consent', 'update', {
      analytics_storage: analytics ? 'granted' : 'denied',
      ad_storage: marketing ? 'granted' : 'denied',
      ad_user_data: marketing ? 'granted' : 'denied',
      ad_personalization: marketing ? 'granted' : 'denied',
    });
  }, [analytics, marketing]);

  return null;
}
