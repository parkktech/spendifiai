import { createContext, useContext, useState, useCallback, PropsWithChildren } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';

interface ImpersonationState {
    isImpersonating: boolean;
    clientName: string | null;
    clientId: number | null;
    startImpersonation: (clientId: number) => Promise<void>;
    stopImpersonation: () => Promise<void>;
}

const ImpersonationContext = createContext<ImpersonationState>({
    isImpersonating: false,
    clientName: null,
    clientId: null,
    startImpersonation: async () => {},
    stopImpersonation: async () => {},
});

function getCookie(name: string): string | null {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop()?.split(';').shift() || null;
    return null;
}

function setCookie(name: string, value: string, days: number) {
    const date = new Date();
    date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
    const expires = `expires=${date.toUTCString()}`;
    const secure = window.location.protocol === 'https:' ? ' secure;' : '';
    document.cookie = `${name}=${value}; ${expires}; path=/;${secure} samesite=lax`;
}

function deleteCookie(name: string) {
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;`;
}

export function ImpersonationProvider({ children }: PropsWithChildren) {
    // Detect impersonation from cookie presence (no Inertia context needed)
    const [isImpersonating, setIsImpersonating] = useState(
        () => !!getCookie('sw_accountant_token')
    );
    const [clientName, setClientName] = useState<string | null>(null);
    const [clientId, setClientId] = useState<number | null>(null);

    const startImpersonation = useCallback(async (targetClientId: number) => {
        try {
            // Store current accountant token before swapping
            const currentToken = localStorage.getItem('auth_token') || getCookie('auth_token');
            if (currentToken) {
                setCookie('sw_accountant_token', currentToken, 1); // 1 day expiry
            }

            const response = await axios.post(`/api/v1/accountant/impersonate/${targetClientId}`);
            const { token, client } = response.data;

            // Swap to client token
            localStorage.setItem('auth_token', token);
            setCookie('auth_token', token, 30);
            window.axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

            setIsImpersonating(true);
            setClientName(client.name);
            setClientId(client.id);

            // Redirect to client dashboard
            router.visit('/dashboard');
        } catch (error) {
            console.error('Failed to start impersonation:', error);
            throw error;
        }
    }, []);

    const stopImpersonation = useCallback(async () => {
        try {
            // Call stop endpoint with current (impersonation) token
            await axios.post('/api/v1/accountant/impersonate/stop');
        } catch {
            // Token may already be invalidated, continue with restore
        }

        // Restore accountant token
        const accountantToken = getCookie('sw_accountant_token');
        if (accountantToken) {
            localStorage.setItem('auth_token', accountantToken);
            setCookie('auth_token', accountantToken, 30);
            window.axios.defaults.headers.common['Authorization'] = `Bearer ${accountantToken}`;
            deleteCookie('sw_accountant_token');
        }

        setIsImpersonating(false);
        setClientName(null);
        setClientId(null);

        // Redirect back to accountant clients page
        router.visit('/accountant/clients');
    }, []);

    return (
        <ImpersonationContext.Provider
            value={{
                isImpersonating,
                clientName,
                clientId,
                startImpersonation,
                stopImpersonation,
            }}
        >
            {children}
        </ImpersonationContext.Provider>
    );
}

export function useImpersonation() {
    return useContext(ImpersonationContext);
}
