import { Head, Link, usePage } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Building2, ArrowRight, Link2 } from 'lucide-react';
import { useApiPost } from '@/hooks/useApi';
import { useState } from 'react';

interface FirmInviteProps {
    firm: {
        name: string;
        logo_url?: string | null;
        primary_color: string;
    };
    token: string;
}

export default function FirmInvite({ firm, token }: FirmInviteProps) {
    const page = usePage();
    const user = (page.props as { auth?: { user?: { id: number; name: string } } }).auth?.user ?? null;
    const [linked, setLinked] = useState(false);
    const [error, setError] = useState('');

    const { submit, loading } = useApiPost('/api/v1/accountant/firm/accept-invite');

    const accentColor = firm.primary_color || '#0D9488';

    const handleLinkAccount = async () => {
        const result = await submit({ token });
        if (result) {
            setLinked(true);
        } else {
            setError('Failed to link your account. Please try again.');
        }
    };

    return (
        <>
            <Head title={`Join ${firm.name}`} />

            <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-50 to-slate-100 p-4">
                <div className="w-full max-w-md">
                    <div className="rounded-2xl border border-slate-200 bg-white shadow-xl p-8 text-center">
                        {/* Firm logo */}
                        {firm.logo_url ? (
                            <img
                                src={firm.logo_url}
                                alt={`${firm.name} logo`}
                                className="w-16 h-16 rounded-xl object-contain mx-auto mb-4"
                            />
                        ) : (
                            <div
                                className="w-16 h-16 rounded-xl flex items-center justify-center mx-auto mb-4"
                                style={{ backgroundColor: accentColor + '20' }}
                            >
                                <Building2 size={28} style={{ color: accentColor }} />
                            </div>
                        )}

                        {/* Firm name */}
                        <h1 className="text-2xl font-bold text-slate-900 mb-2">{firm.name}</h1>
                        <p className="text-sm text-slate-500 mb-6">
                            has invited you to connect on SpendifiAI for seamless tax document collaboration.
                        </p>

                        {/* Accent divider */}
                        <div className="w-12 h-1 rounded-full mx-auto mb-6" style={{ backgroundColor: accentColor }} />

                        {error && (
                            <p className="text-sm text-red-600 mb-4">{error}</p>
                        )}

                        {linked ? (
                            <div className="space-y-3">
                                <div className="flex items-center justify-center gap-2 text-emerald-600 text-sm font-medium">
                                    <Link2 size={16} /> Account linked successfully!
                                </div>
                                <Link
                                    href="/vault"
                                    className="inline-flex items-center gap-2 px-6 py-3 rounded-xl text-white text-sm font-semibold transition hover:opacity-90"
                                    style={{ backgroundColor: accentColor }}
                                >
                                    Go to Your Vault <ArrowRight size={16} />
                                </Link>
                            </div>
                        ) : user ? (
                            /* Logged in -- link to firm */
                            <div className="space-y-3">
                                <p className="text-sm text-slate-600">
                                    You are signed in as <strong>{user.name}</strong>.
                                </p>
                                <button
                                    onClick={handleLinkAccount}
                                    disabled={loading}
                                    className="inline-flex items-center gap-2 px-6 py-3 rounded-xl text-white text-sm font-semibold transition hover:opacity-90 disabled:opacity-50"
                                    style={{ backgroundColor: accentColor }}
                                >
                                    <Link2 size={16} />
                                    {loading ? 'Linking...' : `Link to ${firm.name}`}
                                </button>
                            </div>
                        ) : (
                            /* Not logged in -- register */
                            <div className="space-y-3">
                                <Link
                                    href={`/register?firm_token=${token}`}
                                    className="inline-flex items-center gap-2 px-6 py-3 rounded-xl text-white text-sm font-semibold transition hover:opacity-90 w-full justify-center"
                                    style={{ backgroundColor: accentColor }}
                                >
                                    Join {firm.name} on SpendifiAI <ArrowRight size={16} />
                                </Link>
                                <p className="text-xs text-slate-400">
                                    Already have an account?{' '}
                                    <Link href={`/login?redirect=/invite/${token}`} className="font-medium hover:underline" style={{ color: accentColor }}>
                                        Sign in
                                    </Link>
                                </p>
                            </div>
                        )}
                    </div>

                    {/* SpendifiAI branding */}
                    <p className="text-center text-xs text-slate-400 mt-4">
                        Powered by <Link href="/" className="font-medium hover:underline text-slate-500">SpendifiAI</Link>
                    </p>
                </div>
            </div>
        </>
    );
}
