import PrimaryButton from '@/Components/PrimaryButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="Email Verification" />

            {/* Prominent verification required banner */}
            <div className="mb-6 rounded-xl border-2 border-amber-400 bg-amber-50 px-5 py-5 text-center">
                <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-amber-100">
                    <svg className="h-8 w-8 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                </div>
                <h2 className="text-lg font-bold text-amber-800">
                    Verify your email to continue
                </h2>
                <p className="mt-2 text-sm text-amber-700 leading-relaxed">
                    We sent a verification link to your email. You <strong>cannot access SpendifiAI</strong> until you click that link.
                </p>
            </div>

            {/* Steps */}
            <div className="mb-5 space-y-2.5 text-sm text-sw-text">
                <div className="flex items-start gap-3">
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sw-accent text-[11px] font-bold text-white">1</span>
                    <span>Open your email inbox</span>
                </div>
                <div className="flex items-start gap-3">
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sw-accent text-[11px] font-bold text-white">2</span>
                    <span>Find the email from <strong>noreply@spendifiai.com</strong></span>
                </div>
                <div className="flex items-start gap-3">
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sw-accent text-[11px] font-bold text-white">3</span>
                    <span>Click the <strong>"Verify Email Address"</strong> button</span>
                </div>
            </div>

            {/* Spam warning */}
            <div className="mb-5 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <svg className="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>
                    <strong>Can't find it?</strong> Check your <strong>Spam</strong> or <strong>Junk</strong> folder. Gmail users should also check the <strong>Promotions</strong> tab.
                </span>
            </div>

            {status === 'verification-link-sent' && (
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                    <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    A new verification link has been sent to your email address.
                </div>
            )}

            <form onSubmit={submit}>
                <PrimaryButton className="w-full justify-center py-3 text-sm" disabled={processing}>
                    {processing ? 'Sending...' : 'Resend Verification Email'}
                </PrimaryButton>

                <div className="mt-4 text-center">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="text-sm text-sw-muted underline hover:text-sw-text transition-colors"
                    >
                        Log Out
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
