import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Loader2 } from 'lucide-react';

export default function GoogleCallback() {
  const [error, setError] = useState<string | null>(null);
  const [isProcessing, setIsProcessing] = useState(true);

  useEffect(() => {
    // Extract token and new flag from URL fragment
    const hash = window.location.hash.substring(1);
    const params = new URLSearchParams(hash);
    const token = params.get('token');
    const isNewUser = params.get('new') === 'true';

    if (!token) {
      setError('No authentication token received. Please try again.');
      setIsProcessing(false);
      return;
    }

    try {
      // Store token in localStorage and cookie
      localStorage.setItem('auth_token', token);

      // Set secure cookie (24 hours)
      const date = new Date();
      date.setTime(date.getTime() + (24 * 60 * 60 * 1000));
      const expires = `expires=${date.toUTCString()}`;
      const secure = window.location.protocol === 'https:' ? ' secure;' : '';
      document.cookie = `auth_token=${token}; ${expires}; path=/;${secure} samesite=lax`;

      // Set Authorization header
      window.axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

      // Clear the URL fragment for security
      window.history.replaceState({}, document.title, window.location.pathname);

      // Redirect to appropriate page
      const destination = isNewUser ? '/email-verification-notice' : '/dashboard';
      router.visit(destination);
    } catch (err) {
      setError('Failed to complete authentication. Please try again.');
      setIsProcessing(false);
    }
  }, []);

  if (error) {
    return (
      <GuestLayout>
        <div className="text-center">
          <h2 className="text-lg font-semibold text-sw-danger mb-2">Authentication Failed</h2>
          <p className="text-sm text-sw-muted mb-4">{error}</p>
          <button
            onClick={() => router.visit('/login')}
            className="inline-flex items-center px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition"
          >
            Back to Login
          </button>
        </div>
      </GuestLayout>
    );
  }

  return (
    <GuestLayout>
      <div className="flex items-center justify-center py-12">
        <div className="text-center">
          <Loader2 className="animate-spin mx-auto mb-4 text-sw-accent" size={32} />
          <p className="text-sm text-sw-muted">Completing your authentication...</p>
        </div>
      </div>
    </GuestLayout>
  );
}
