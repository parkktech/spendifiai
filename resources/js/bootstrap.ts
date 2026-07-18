import axios from 'axios';
import { getCookie } from './utils/cookies';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;

// Cookie domain helper — always use bare domain to avoid www vs non-www duplicates
function getAuthCookieDomain(): string {
    return window.location.hostname.replace(/^www\./, '.');
}

// Helper to set auth cookie consistently
function setAuthCookie(token: string) {
    const date = new Date();
    date.setTime(date.getTime() + (30 * 24 * 60 * 60 * 1000));
    const secure = window.location.protocol === 'https:' ? ' secure;' : '';
    const domain = getAuthCookieDomain();
    document.cookie = `auth_token=${token}; expires=${date.toUTCString()}; path=/; domain=${domain};${secure} samesite=lax`;
}

// Helper to clear auth state
function clearAuthState() {
    localStorage.removeItem('auth_token');
    const domain = getAuthCookieDomain();
    const secure = window.location.protocol === 'https:' ? ' secure;' : '';
    // Clear on bare domain
    document.cookie = `auth_token=; path=/; domain=${domain}; max-age=0;${secure} samesite=lax`;
    // Also clear on exact hostname (legacy cookies)
    document.cookie = `auth_token=; path=/; max-age=0;${secure} samesite=lax`;
    delete window.axios.defaults.headers.common['Authorization'];
}

// Expose helpers for use in other files
(window as any).__setAuthCookie = setAuthCookie;
(window as any).__clearAuthState = clearAuthState;

// Check for token in localStorage or cookie and add to Authorization header
const tokenFromStorage = localStorage.getItem('auth_token');
const tokenFromCookie = getCookie('auth_token');
const token = tokenFromStorage || tokenFromCookie;

if (token) {
    window.axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    // Store in localStorage if it came from cookie
    if (!tokenFromStorage && tokenFromCookie) {
        localStorage.setItem('auth_token', tokenFromCookie);
    }
}

// Add interceptor to always check for token + method spoofing for PATCH/DELETE
// Apache/Cloudflare blocks PATCH/DELETE methods, so convert to POST with _method
window.axios.interceptors.request.use((config) => {
    const currentToken = localStorage.getItem('auth_token') || getCookie('auth_token');
    if (currentToken) {
        config.headers.Authorization = `Bearer ${currentToken}`;
    }

    // Method spoofing: convert PATCH/PUT/DELETE to POST with _method field
    const method = config.method?.toUpperCase();
    if (method && ['PATCH', 'PUT', 'DELETE'].includes(method)) {
        const originalMethod = method;
        config.method = 'POST';
        if (config.data && typeof config.data === 'object' && !(config.data instanceof FormData)) {
            config.data = { ...config.data, _method: originalMethod };
        } else if (!config.data) {
            config.data = { _method: originalMethod };
        }
    }

    return config;
});

// Track whether we're already redirecting to login to prevent multiple redirects
let isRedirectingToLogin = false;

// Response interceptor: handle 401 (expired/invalid token) and suppress expected 403s
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;

        if (status === 401) {
            // Token is invalid or expired — clear auth state and redirect to login
            // Skip if we're already redirecting, or if this is a login/register request
            const url = error.config?.url || '';
            const isAuthRequest = url.includes('/auth/login') || url.includes('/auth/register');

            if (!isRedirectingToLogin && !isAuthRequest) {
                isRedirectingToLogin = true;
                clearAuthState();

                // Only redirect if we're on an authenticated page (not already on login/register/welcome)
                const path = window.location.pathname;
                const publicPaths = ['/login', '/register', '/forgot-password', '/reset-password', '/', '/features', '/how-it-works', '/about', '/faq', '/contact', '/privacy', '/terms', '/data-retention', '/security-policy'];
                if (!publicPaths.includes(path)) {
                    window.location.href = '/login';
                }

                // Reset the flag after a short delay so future 401s can trigger redirect
                setTimeout(() => { isRedirectingToLogin = false; }, 3000);
            }

            return Promise.reject(error);
        }

        if (status === 403) {
            // Suppress 403 errors from console - they're expected when bank not connected
            return Promise.resolve(undefined);
        }

        // Suppress non-standard status codes (e.g. 469 from bot rate-limiting)
        if (status && (status < 400 || status > 451)) {
            return Promise.reject(error);
        }

        // Log other errors normally
        console.error('API Error:', status, error.response?.data?.message || error.message);
        return Promise.reject(error);
    }
);

// Suppress unhandled promise rejections for 403 errors
window.addEventListener('unhandledrejection', (event) => {
    const error = event.reason;
    if (error?.response?.status === 403) {
        event.preventDefault();
    }
});

// Also suppress console errors for 403
const originalError = console.error;
console.error = function(...args: any[]) {
    const message = args[0]?.toString() || '';
    if (message.includes('403') || message.includes('Forbidden')) {
        return;
    }
    originalError.apply(console, args);
};

// Set Authorization header globally for Inertia requests (initial page loads)
const pageToken = localStorage.getItem('auth_token') || getCookie('auth_token');
if (pageToken) {
    document.addEventListener('DOMContentLoaded', () => {
        // This ensures the header is set before any Inertia requests
        window.axios.defaults.headers.common['Authorization'] = `Bearer ${pageToken}`;
    });
}
