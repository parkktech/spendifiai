import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { ConsentProvider } from '@/contexts/ConsentContext';
import { ImpersonationProvider } from '@/contexts/ImpersonationContext';
import GoogleConsentMode from '@/Components/SpendifiAI/GoogleConsentMode';
import CookieConsentBanner from '@/Components/SpendifiAI/CookieConsentBanner';
import { getCookie } from '@/utils/cookies';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Ensure token is set before any requests
const tokenFromStorage = localStorage.getItem('auth_token');
const tokenFromCookie = getCookie('auth_token');
const token = tokenFromStorage || tokenFromCookie;

if (token) {
    window.axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

    // Sync: if token is in localStorage but missing from cookie, restore it
    // Use bare domain (.spendifiai.com) to cover both www and non-www
    if (tokenFromStorage && !tokenFromCookie) {
        const date = new Date();
        date.setTime(date.getTime() + (30 * 24 * 60 * 60 * 1000));
        const secure = window.location.protocol === 'https:' ? ' secure;' : '';
        const domain = window.location.hostname.replace(/^www\./, '.');
        document.cookie = `auth_token=${tokenFromStorage}; expires=${date.toUTCString()}; path=/; domain=${domain};${secure} samesite=lax`;
    }
    // Sync: if token is in cookie but missing from localStorage, restore it
    if (tokenFromCookie && !tokenFromStorage) {
        localStorage.setItem('auth_token', tokenFromCookie);
    }
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ).catch(() => {
            console.warn(`Page component "${name}" not found, redirecting...`);
            window.location.href = '/';
            // Return a minimal component to prevent the null error while redirecting
            return { default: () => null };
        }),
    setup({ el, App, props }) {
        const root = createRoot(el);
        root.render(
            <ConsentProvider>
                <GoogleConsentMode />
                <ImpersonationProvider>
                    <App {...props} />
                </ImpersonationProvider>
                <CookieConsentBanner />
            </ConsentProvider>
        );
    },
    progress: {
        color: '#2563eb',
    },
});
