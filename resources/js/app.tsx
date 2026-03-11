import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { ConsentProvider } from '@/contexts/ConsentContext';
import { ImpersonationProvider } from '@/contexts/ImpersonationContext';
import GoogleConsentMode from '@/Components/SpendifiAI/GoogleConsentMode';
import CookieConsentBanner from '@/Components/SpendifiAI/CookieConsentBanner';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Helper to get cookie value
function getCookie(name: string): string | null {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop()?.split(';').shift() || null;
    return null;
}

// Ensure token is set before any requests
const token = localStorage.getItem('auth_token') || getCookie('auth_token');
if (token) {
    window.axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
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
