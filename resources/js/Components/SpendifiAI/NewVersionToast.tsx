/**
 * NewVersionToast — D24 Work 3: version-skew protection client affordance.
 *
 * Polls /api/v1/meta/build-version every 5 minutes and compares against the
 * version hash captured at page load. When a mismatch is detected (new
 * deployment), shows a small dismissable toast prompting the user to refresh.
 *
 * Inertia already forces a hard reload on SPA navigations when the server
 * returns a different X-Inertia-Version header — this toast is the additional
 * affordance for users who stay on the same page for a long time (e.g. the
 * interview or report page) without navigating away.
 *
 * Design constraints:
 *  - Non-intrusive: bottom-right, small, dismissable.
 *  - Educational tone: explains WHY, not just "error".
 *  - No user data involved — version hash is a public infrastructure signal.
 *  - Poll stops after finding a version change (one toast per session max).
 */

import { useEffect, useState, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { RefreshCw, X } from 'lucide-react';

const POLL_INTERVAL_MS = 5 * 60 * 1000; // 5 minutes

export default function NewVersionToast() {
  const { buildVersion } = usePage().props as { buildVersion?: string };
  const [showToast, setShowToast] = useState(false);
  const [dismissed, setDismissed] = useState(false);
  const baselineVersion = useRef<string | undefined>(buildVersion);
  const pollTimer = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    // Capture the version at mount time as our baseline.
    baselineVersion.current = buildVersion;

    const checkVersion = async () => {
      try {
        const res = await fetch('/api/v1/meta/build-version', {
          headers: { Accept: 'application/json' },
        });
        if (!res.ok) return;
        const data: { version?: string } = await res.json();

        if (
          data.version &&
          baselineVersion.current &&
          data.version !== baselineVersion.current
        ) {
          setShowToast(true);
          // Stop polling — one toast per session is enough.
          if (pollTimer.current) {
            clearInterval(pollTimer.current);
            pollTimer.current = null;
          }
        }
      } catch {
        // Silently ignore network errors — don't surface a "new version" false positive.
      }
    };

    // Start polling.
    pollTimer.current = setInterval(checkVersion, POLL_INTERVAL_MS);

    return () => {
      if (pollTimer.current) {
        clearInterval(pollTimer.current);
      }
    };
  }, []);

  if (!showToast || dismissed) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      style={{
        position: 'fixed',
        bottom: '1.25rem',
        right: '1.25rem',
        zIndex: 9999,
        maxWidth: '22rem',
        background: '#fff',
        border: '1px solid #e2e8f0',
        borderRadius: '0.75rem',
        padding: '0.875rem 1rem',
        boxShadow: '0 4px 16px rgba(0,0,0,0.10)',
        display: 'flex',
        alignItems: 'flex-start',
        gap: '0.75rem',
        animation: 'slideUpIn 0.2s ease-out',
      }}
    >
      <style>{`
        @keyframes slideUpIn {
          from { opacity: 0; transform: translateY(0.5rem); }
          to   { opacity: 1; transform: translateY(0); }
        }
      `}</style>
      <div
        style={{
          flexShrink: 0,
          width: '2rem',
          height: '2rem',
          background: '#eff6ff',
          borderRadius: '50%',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        <RefreshCw size={14} style={{ color: '#2563eb' }} />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <p style={{ fontWeight: 600, fontSize: '0.875rem', marginBottom: '0.25rem', color: '#0f172a' }}>
          A new version is available
        </p>
        <p style={{ fontSize: '0.8125rem', color: '#334155', lineHeight: 1.5 }}>
          We've released an update. Refresh to get the latest features and fixes.
        </p>
        <button
          onClick={() => window.location.reload()}
          style={{
            marginTop: '0.625rem',
            padding: '0.375rem 0.875rem',
            background: '#2563eb',
            color: '#fff',
            border: 'none',
            borderRadius: '0.375rem',
            fontSize: '0.8125rem',
            fontWeight: 600,
            cursor: 'pointer',
          }}
        >
          Refresh now
        </button>
      </div>
      <button
        onClick={() => setDismissed(true)}
        aria-label="Dismiss update notification"
        style={{
          flexShrink: 0,
          background: 'none',
          border: 'none',
          cursor: 'pointer',
          padding: '0.125rem',
          color: '#94a3b8',
          display: 'flex',
          alignItems: 'center',
        }}
      >
        <X size={16} />
      </button>
    </div>
  );
}
