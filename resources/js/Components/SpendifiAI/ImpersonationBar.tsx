import { ArrowLeft, Eye } from 'lucide-react';
import { useImpersonation } from '@/contexts/ImpersonationContext';
import { usePage } from '@inertiajs/react';

export default function ImpersonationBar() {
    const { isImpersonating, clientName, stopImpersonation } = useImpersonation();
    const page = usePage();
    const auth = page.props.auth as Record<string, unknown> | undefined;

    // Use Inertia shared props as fallback for client name
    const isImpersonatingFromServer = (auth?.isImpersonating as boolean) ?? false;
    const displayName = clientName
        || (isImpersonatingFromServer ? (auth?.user as { name: string } | null)?.name : null)
        || 'Client';

    if (!isImpersonating && !isImpersonatingFromServer) return null;

    return (
        <div className="fixed bottom-4 left-4 z-[90] flex items-center gap-3 rounded-xl bg-amber-500/95 backdrop-blur-sm px-4 py-2.5 shadow-lg border border-amber-400/50">
            <Eye size={16} className="text-amber-950 shrink-0" />
            <div className="text-sm font-medium text-amber-950">
                Viewing as: <span className="font-bold">{displayName}</span>
            </div>
            <button
                onClick={stopImpersonation}
                className="ml-1 inline-flex items-center gap-1.5 rounded-lg bg-amber-950/20 px-3 py-1.5 text-xs font-semibold text-amber-950 hover:bg-amber-950/30 transition cursor-pointer"
            >
                <ArrowLeft size={12} />
                Return to My Account
            </button>
        </div>
    );
}
