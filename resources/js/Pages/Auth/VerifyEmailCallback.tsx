import { useEffect } from 'react';
import { router } from '@inertiajs/react';

export default function VerifyEmailCallback() {
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const token = params.get('token');

        if (token) {
            // Store token in localStorage
            localStorage.setItem('auth_token', token);
            console.log('✅ Token stored:', token.substring(0, 20) + '...');

            // Small delay to ensure token is stored before navigation
            setTimeout(() => {
                window.location.href = '/dashboard';
            }, 100);
        } else {
            console.error('❌ No token found in URL');
            window.location.href = '/login';
        }
    }, []);

    return (
        <div className="flex items-center justify-center min-h-screen bg-sw-background">
            <div className="text-center">
                <div className="mb-4 inline-block animate-spin">
                    <div className="w-12 h-12 border-4 border-sw-primary border-t-transparent rounded-full"></div>
                </div>
                <p className="text-sw-foreground text-lg">Verifying your email...</p>
            </div>
        </div>
    );
}
