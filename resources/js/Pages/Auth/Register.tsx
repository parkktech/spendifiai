import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GoogleLoginButton from '@/Components/GoogleLoginButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import axios from 'axios';

export default function Register() {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        setErrors({});
        setProcessing(true);

        try {
            const response = await axios.post('/api/auth/register', {
                name,
                email,
                password,
                password_confirmation: passwordConfirmation,
            });

            // Store token in localStorage and cookie
            if (response.data.token) {
                localStorage.setItem('auth_token', response.data.token);
                const date = new Date();
                date.setTime(date.getTime() + (24 * 60 * 60 * 1000));
                const expires = `expires=${date.toUTCString()}`;
                document.cookie = `auth_token=${response.data.token}; ${expires}; path=/; secure; samesite=lax`;
                window.axios.defaults.headers.common['Authorization'] = `Bearer ${response.data.token}`;
            }

            router.visit('/email-verification-notice');
        } catch (error: any) {
            setErrors(error.response?.data?.errors || { email: ['Registration failed'] });
        } finally {
            setProcessing(false);
        }
    };

    return (
        <GuestLayout>
            <Head title="Create Account" />

            <div className="mb-8">
                <h1 className="text-2xl font-bold tracking-tight text-sw-text">
                    Create your account
                </h1>
                <p className="mt-2 text-sm text-sw-muted">
                    Start tracking your finances in under 2 minutes
                </p>
            </div>

            {/* Google OAuth — fastest signup path */}
            <GoogleLoginButton label="Sign up with Google" />

            {/* Divider */}
            <div className="relative my-7">
                <div className="absolute inset-0 flex items-center">
                    <div className="w-full border-t border-sw-border"></div>
                </div>
                <div className="relative flex justify-center">
                    <span className="bg-sw-bg px-3 text-xs font-medium uppercase tracking-wider text-sw-dim">
                        or register with email
                    </span>
                </div>
            </div>

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="name" value="Full name" />
                    <TextInput
                        id="name"
                        name="name"
                        value={name}
                        className="mt-1.5 block w-full"
                        autoComplete="name"
                        isFocused={true}
                        onChange={(e) => setName(e.target.value)}
                        required
                    />
                    <InputError message={errors.name?.[0]} className="mt-2" />
                </div>

                <div className="mt-5">
                    <InputLabel htmlFor="email" value="Email address" />
                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={email}
                        className="mt-1.5 block w-full"
                        autoComplete="username"
                        onChange={(e) => setEmail(e.target.value)}
                        required
                    />
                    <InputError message={errors.email?.[0]} className="mt-2" />
                </div>

                <div className="mt-5">
                    <InputLabel htmlFor="password" value="Password" />
                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={password}
                        className="mt-1.5 block w-full"
                        autoComplete="new-password"
                        onChange={(e) => setPassword(e.target.value)}
                        required
                    />
                    <InputError message={errors.password?.[0]} className="mt-2" />
                </div>

                <div className="mt-5">
                    <InputLabel htmlFor="password_confirmation" value="Confirm password" />
                    <TextInput
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={passwordConfirmation}
                        className="mt-1.5 block w-full"
                        autoComplete="new-password"
                        onChange={(e) => setPasswordConfirmation(e.target.value)}
                        required
                    />
                    <InputError message={errors.password_confirmation?.[0]} className="mt-2" />
                </div>

                <PrimaryButton
                    className="mt-7 w-full justify-center py-3 text-sm"
                    disabled={processing}
                >
                    {processing ? (
                        <span className="flex items-center gap-2">
                            <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                            </svg>
                            Creating account...
                        </span>
                    ) : (
                        'Create account'
                    )}
                </PrimaryButton>

                <p className="mt-5 text-center text-xs text-sw-dim leading-relaxed">
                    By creating an account, you agree to our{' '}
                    <Link href="/terms" className="text-sw-muted underline hover:text-sw-text transition-colors">Terms</Link>
                    {' '}and{' '}
                    <Link href="/privacy" className="text-sw-muted underline hover:text-sw-text transition-colors">Privacy Policy</Link>
                </p>

                <p className="mt-6 text-center text-sm text-sw-muted">
                    Already have an account?{' '}
                    <Link
                        href={route('login')}
                        className="font-semibold text-sw-accent hover:text-sw-accent-hover transition-colors"
                    >
                        Sign in
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
