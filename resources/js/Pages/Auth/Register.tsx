import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GoogleLoginButton from '@/Components/GoogleLoginButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import { User as UserIcon, Briefcase } from 'lucide-react';
import axios from 'axios';

export default function Register() {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [userType, setUserType] = useState<'personal' | 'accountant'>('personal');
    const [companyName, setCompanyName] = useState('');
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
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                user_type: userType,
                ...(companyName ? { company_name: companyName } : {}),
            });

            // Store token in localStorage and cookie
            if (response.data.token) {
                localStorage.setItem('auth_token', response.data.token);
                const date = new Date();
                date.setTime(date.getTime() + (30 * 24 * 60 * 60 * 1000)); // 30 days
                const expires = `expires=${date.toUTCString()}`;
                const secure = window.location.protocol === 'https:' ? ' secure;' : '';
                document.cookie = `auth_token=${response.data.token}; ${expires}; path=/;${secure} samesite=lax`;
                window.axios.defaults.headers.common['Authorization'] = `Bearer ${response.data.token}`;
            }

            router.visit('/verify-email');
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

            {/* User Type Selector — applies to both Google and email signup */}
            <div className="mb-6">
                <InputLabel value="Account type" />
                <div className="mt-1.5 grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        onClick={() => setUserType('personal')}
                        className={`relative flex flex-col items-center gap-1.5 rounded-xl border-2 px-3 py-3.5 text-center transition cursor-pointer ${
                            userType === 'personal'
                                ? 'border-sw-accent bg-sw-accent/5 shadow-sm'
                                : 'border-sw-border bg-sw-bg hover:border-sw-border-strong'
                        }`}
                    >
                        <UserIcon size={20} className={userType === 'personal' ? 'text-sw-accent' : 'text-sw-muted'} />
                        <span className={`text-sm font-semibold ${userType === 'personal' ? 'text-sw-accent' : 'text-sw-text'}`}>Personal</span>
                        <span className="text-[10px] text-sw-dim leading-tight">Track finances, detect subscriptions, export taxes</span>
                    </button>
                    <button
                        type="button"
                        onClick={() => setUserType('accountant')}
                        className={`relative flex flex-col items-center gap-1.5 rounded-xl border-2 px-3 py-3.5 text-center transition cursor-pointer ${
                            userType === 'accountant'
                                ? 'border-sw-accent bg-sw-accent/5 shadow-sm'
                                : 'border-sw-border bg-sw-bg hover:border-sw-border-strong'
                        }`}
                    >
                        <Briefcase size={20} className={userType === 'accountant' ? 'text-sw-accent' : 'text-sw-muted'} />
                        <span className={`text-sm font-semibold ${userType === 'accountant' ? 'text-sw-accent' : 'text-sw-text'}`}>Accountant</span>
                        <span className="text-[10px] text-sw-dim leading-tight">Manage client finances, download tax exports</span>
                    </button>
                </div>
            </div>

            {/* Company Name (shown for accountants) */}
            {userType === 'accountant' && (
                <div className="mb-6">
                    <InputLabel htmlFor="company_name" value="Company / Firm Name (optional)" />
                    <TextInput
                        id="company_name"
                        name="company_name"
                        value={companyName}
                        className="mt-1.5 block w-full"
                        onChange={(e) => setCompanyName(e.target.value)}
                        placeholder="e.g., Smith & Associates CPA"
                    />
                </div>
            )}

            {/* Google OAuth — stashes user type selection before redirect */}
            <GoogleLoginButton
                label="Sign up with Google"
                onBeforeRedirect={() => {
                    localStorage.setItem('pending_user_type', userType);
                    if (companyName) {
                        localStorage.setItem('pending_company_name', companyName);
                    } else {
                        localStorage.removeItem('pending_company_name');
                    }
                }}
            />

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
