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
                // Also set cookie for server-side requests (hard refresh, initial page load)
                // Calculate expiry: 24 hours from now
                const date = new Date();
                date.setTime(date.getTime() + (24 * 60 * 60 * 1000));
                const expires = `expires=${date.toUTCString()}`;
                document.cookie = `auth_token=${response.data.token}; ${expires}; path=/; secure; samesite=lax`;
                window.axios.defaults.headers.common['Authorization'] = `Bearer ${response.data.token}`;
                console.log('✅ Token stored in localStorage and cookie, auth header set after registration');
            }

            // Redirect to verify email page
            router.visit('/email-verification-notice');
        } catch (error: any) {
            setErrors(error.response?.data?.errors || { email: ['Registration failed'] });
        } finally {
            setProcessing(false);
        }
    };

    return (
        <GuestLayout>
            <Head title="Register" />

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="name" value="Name" />

                    <TextInput
                        id="name"
                        name="name"
                        value={name}
                        className="mt-1 block w-full"
                        autoComplete="name"
                        isFocused={true}
                        onChange={(e) => setName(e.target.value)}
                        required
                    />

                    <InputError message={errors.name?.[0]} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="email" value="Email" />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={email}
                        className="mt-1 block w-full"
                        autoComplete="username"
                        onChange={(e) => setEmail(e.target.value)}
                        required
                    />

                    <InputError message={errors.email?.[0]} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="password" value="Password" />

                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={password}
                        className="mt-1 block w-full"
                        autoComplete="new-password"
                        onChange={(e) => setPassword(e.target.value)}
                        required
                    />

                    <InputError message={errors.password?.[0]} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel
                        htmlFor="password_confirmation"
                        value="Confirm Password"
                    />

                    <TextInput
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={passwordConfirmation}
                        className="mt-1 block w-full"
                        autoComplete="new-password"
                        onChange={(e) =>
                            setPasswordConfirmation(e.target.value)
                        }
                        required
                    />

                    <InputError
                        message={errors.password_confirmation?.[0]}
                        className="mt-2"
                    />
                </div>

                <div className="mt-4 flex items-center justify-end">
                    <Link
                        href={route('login')}
                        className="rounded-md text-sm text-sw-muted underline hover:text-sw-text focus:outline-none focus:ring-2 focus:ring-sw-accent focus:ring-offset-2"
                    >
                        Already registered?
                    </Link>

                    <PrimaryButton className="ms-4" disabled={processing}>
                        Register
                    </PrimaryButton>
                </div>

                <div className="mt-6">
                    <div className="relative">
                        <div className="absolute inset-0 flex items-center">
                            <div className="w-full border-t border-sw-border"></div>
                        </div>
                        <div className="relative flex justify-center text-sm">
                            <span className="px-2 bg-sw-bg text-sw-muted">Or continue with</span>
                        </div>
                    </div>
                    <GoogleLoginButton />
                </div>

                <p className="mt-4 text-center text-sm text-sw-muted">
                    Already have an account?{' '}
                    <Link
                        href={route('login')}
                        className="text-sw-accent font-medium hover:text-sw-accent-hover"
                    >
                        Sign in
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
