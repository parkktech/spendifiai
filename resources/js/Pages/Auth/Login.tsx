import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GoogleLoginButton from '@/Components/GoogleLoginButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import axios from 'axios';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        setErrors({});
        setProcessing(true);

        try {
            const response = await axios.post('/api/auth/login', {
                email,
                password,
                remember,
            });

            // Store token in localStorage and cookie
            if (response.data.token) {
                localStorage.setItem('auth_token', response.data.token);
                const date = new Date();
                date.setTime(date.getTime() + (24 * 60 * 60 * 1000));
                const expires = `expires=${date.toUTCString()}`;
                const secure = window.location.protocol === 'https:' ? ' secure;' : '';
                document.cookie = `auth_token=${response.data.token}; ${expires}; path=/;${secure} samesite=lax`;
                window.axios.defaults.headers.common['Authorization'] = `Bearer ${response.data.token}`;
            }

            router.visit('/dashboard');
        } catch (error: any) {
            setErrors(error.response?.data?.errors || { email: 'Invalid credentials' });
        } finally {
            setProcessing(false);
        }
    };

    return (
        <GuestLayout>
            <Head title="Log in" />

            <div className="mb-8">
                <h1 className="text-2xl font-bold tracking-tight text-sw-text">
                    Welcome back
                </h1>
                <p className="mt-2 text-sm text-sw-muted">
                    Sign in to your account to continue
                </p>
            </div>

            {status && (
                <div className="mb-5 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                    <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {status}
                </div>
            )}

            {/* Google OAuth — primary action */}
            <GoogleLoginButton />

            {/* Divider */}
            <div className="relative my-7">
                <div className="absolute inset-0 flex items-center">
                    <div className="w-full border-t border-sw-border"></div>
                </div>
                <div className="relative flex justify-center">
                    <span className="bg-sw-bg px-3 text-xs font-medium uppercase tracking-wider text-sw-dim">
                        or sign in with email
                    </span>
                </div>
            </div>

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="email" value="Email address" />
                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={email}
                        className="mt-1.5 block w-full"
                        autoComplete="username"
                        isFocused={true}
                        onChange={(e) => setEmail(e.target.value)}
                    />
                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div className="mt-5">
                    <div className="flex items-center justify-between">
                        <InputLabel htmlFor="password" value="Password" />
                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="text-xs font-medium text-sw-accent hover:text-sw-accent-hover transition-colors"
                            >
                                Forgot password?
                            </Link>
                        )}
                    </div>
                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={password}
                        className="mt-1.5 block w-full"
                        autoComplete="current-password"
                        onChange={(e) => setPassword(e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="mt-5 flex items-center justify-between">
                    <label className="flex items-center cursor-pointer">
                        <Checkbox
                            name="remember"
                            checked={remember}
                            onChange={(e) => setRemember(e.target.checked)}
                        />
                        <span className="ms-2 text-sm text-sw-muted select-none">
                            Remember me
                        </span>
                    </label>
                </div>

                <PrimaryButton
                    className="mt-6 w-full justify-center py-3 text-sm"
                    disabled={processing}
                >
                    {processing ? (
                        <span className="flex items-center gap-2">
                            <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                            </svg>
                            Signing in...
                        </span>
                    ) : (
                        'Sign in'
                    )}
                </PrimaryButton>

                <p className="mt-8 text-center text-sm text-sw-muted">
                    Don't have an account?{' '}
                    <Link
                        href={route('register')}
                        className="font-semibold text-sw-accent hover:text-sw-accent-hover transition-colors"
                    >
                        Create free account
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
