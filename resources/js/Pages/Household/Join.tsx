import { useState, useEffect } from 'react';
import { Head, usePage, Link } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Users, Loader2, CheckCircle, XCircle, UserPlus } from 'lucide-react';
import axios from 'axios';

interface JoinProps {
  token: string;
}

interface InviteInfo {
  household_name: string;
  invited_by: string;
  email: string | null;
  expires_at: string;
}

export default function Join({ token }: JoinProps) {
  const pageProps = usePage().props;
  const authUser = (pageProps.auth as { user?: { name: string; email: string } })?.user;

  const [inviteInfo, setInviteInfo] = useState<InviteInfo | null>(null);
  const [loading, setLoading] = useState(true);
  const [accepting, setAccepting] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);

  useEffect(() => {
    const validateToken = async () => {
      try {
        const res = await axios.get(`/api/v1/household/invite/${token}`);
        setInviteInfo(res.data);
      } catch {
        setError('This invitation is invalid or has expired.');
      } finally {
        setLoading(false);
      }
    };
    validateToken();
  }, [token]);

  const handleAccept = async () => {
    setAccepting(true);
    setError('');
    try {
      const authToken = localStorage.getItem('auth_token');
      await axios.post(`/api/v1/household/invite/${token}/accept`, {}, {
        headers: authToken ? { Authorization: `Bearer ${authToken}` } : {},
      });
      setSuccess(true);
      setTimeout(() => {
        window.location.href = '/dashboard';
      }, 2000);
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } };
      setError(e.response?.data?.message || 'Failed to accept invitation.');
      setAccepting(false);
    }
  };

  if (loading) {
    return (
      <GuestLayout>
        <Head title="Join Household" />
        <div className="flex flex-col items-center justify-center py-12">
          <Loader2 size={24} className="animate-spin text-sw-accent" />
          <p className="text-sm text-sw-muted mt-3">Validating invitation...</p>
        </div>
      </GuestLayout>
    );
  }

  if (error && !inviteInfo) {
    return (
      <GuestLayout>
        <Head title="Invalid Invitation" />
        <div className="flex flex-col items-center justify-center py-12 text-center">
          <div className="w-12 h-12 rounded-full bg-sw-danger/10 flex items-center justify-center mb-4">
            <XCircle size={24} className="text-sw-danger" />
          </div>
          <h2 className="text-lg font-semibold text-sw-text mb-2">Invalid Invitation</h2>
          <p className="text-sm text-sw-muted mb-6">{error}</p>
          <Link href="/login" className="text-sm text-sw-accent hover:underline">
            Go to Login
          </Link>
        </div>
      </GuestLayout>
    );
  }

  if (success) {
    return (
      <GuestLayout>
        <Head title="Joined Household" />
        <div className="flex flex-col items-center justify-center py-12 text-center">
          <div className="w-12 h-12 rounded-full bg-sw-success-light flex items-center justify-center mb-4">
            <CheckCircle size={24} className="text-sw-success" />
          </div>
          <h2 className="text-lg font-semibold text-sw-text mb-2">Welcome to the household!</h2>
          <p className="text-sm text-sw-muted">Redirecting to your dashboard...</p>
        </div>
      </GuestLayout>
    );
  }

  return (
    <GuestLayout>
      <Head title="Join Household" />
      <div className="flex flex-col items-center justify-center py-8">
        <div className="w-14 h-14 rounded-full bg-sw-accent/10 flex items-center justify-center mb-5">
          <Users size={28} className="text-sw-accent" />
        </div>

        <h2 className="text-xl font-bold text-sw-text mb-1">You've been invited!</h2>
        <p className="text-sm text-sw-muted mb-6 text-center">
          <strong>{inviteInfo?.invited_by}</strong> invited you to join their household on SpendifiAI.
        </p>

        <div className="w-full max-w-sm rounded-xl border border-sw-border bg-sw-surface p-5 mb-6">
          <div className="text-center">
            <p className="text-sm font-medium text-sw-text">{inviteInfo?.household_name}</p>
            <p className="text-xs text-sw-muted mt-1">
              Joining lets you share bank accounts, transactions, and subscriptions.
            </p>
          </div>
        </div>

        {error && (
          <div className="w-full max-w-sm mb-4 flex items-center gap-2 px-3 py-2 rounded-lg bg-sw-danger/10 border border-sw-danger/30 text-sw-danger text-xs font-medium">
            <XCircle size={14} /> {error}
          </div>
        )}

        {authUser ? (
          <div className="w-full max-w-sm space-y-3">
            <p className="text-xs text-sw-muted text-center">
              Signed in as <strong>{authUser.email}</strong>
            </p>
            <button
              onClick={handleAccept}
              disabled={accepting}
              className="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
            >
              {accepting ? <Loader2 size={16} className="animate-spin" /> : <UserPlus size={16} />}
              Join Household
            </button>
          </div>
        ) : (
          <div className="w-full max-w-sm space-y-3 text-center">
            <p className="text-xs text-sw-muted">Create an account to join this household.</p>
            <Link
              href={`/register?household_invite=${token}`}
              className="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition"
            >
              <UserPlus size={16} /> Create Account & Join
            </Link>
            <p className="text-xs text-sw-dim">
              Already have an account?{' '}
              <Link href={`/login?household_invite=${token}`} className="text-sw-accent hover:underline font-medium">
                Sign in
              </Link>
            </p>
          </div>
        )}
      </div>
    </GuestLayout>
  );
}
