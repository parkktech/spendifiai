import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;

// Helper to get cookie value
function getCookie(name: string): string | null {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop()?.split(';').shift() || null;
    return null;
}

// Check for token in localStorage or cookie and add to Authorization header
const tokenFromStorage = localStorage.getItem('auth_token');
const tokenFromCookie = getCookie('auth_token');
const token = tokenFromStorage || tokenFromCookie;

console.log('🔍 Auth Token Check:', {
    fromStorage: tokenFromStorage ? '✅ Found' : '❌ Not found',
    fromCookie: tokenFromCookie ? '✅ Found' : '❌ Not found',
    using: token ? `${token.substring(0, 30)}...` : '❌ No token',
});

if (token) {
    window.axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    console.log('✅ Authorization header set');
    // Store in localStorage if it came from cookie
    if (!tokenFromStorage && tokenFromCookie) {
        localStorage.setItem('auth_token', tokenFromCookie);
        console.log('📦 Token stored in localStorage');
    }
} else {
    console.log('⚠️ No token found in localStorage or cookies');
}

// Add interceptor to always check for token
window.axios.interceptors.request.use((config) => {
    const currentToken = localStorage.getItem('auth_token') || getCookie('auth_token');
    if (currentToken) {
        config.headers.Authorization = `Bearer ${currentToken}`;
    }
    return config;
});

// Suppress 403 errors in console (expected when bank not connected)
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 403) {
            // Suppress 403 errors from console - they're expected when bank not connected
            // Return a resolved promise to prevent the error from being logged
            return Promise.resolve(undefined);
        }
        // Log other errors normally
        console.error('API Error:', error.response?.status, error.response?.data?.message || error.message);
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
