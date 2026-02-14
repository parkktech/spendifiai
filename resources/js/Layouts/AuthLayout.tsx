import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';
import { CheckCircle2 } from 'lucide-react';

const features = [
    'AI-powered expense categorization',
    'Secure bank sync via Plaid',
    'Tax deduction tracking & export',
    'Subscription detection',
    '100% free, forever',
];

export default function AuthLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen">
            {/* Left branded panel — hidden on mobile */}
            <div className="hidden bg-gradient-to-br from-sw-accent to-violet-600 lg:flex lg:w-2/5 lg:flex-col lg:justify-between lg:p-12">
                <div>
                    <Link href="/" className="flex items-center gap-3">
                        <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" className="h-10 w-10">
                            <rect width="40" height="40" rx="10" fill="rgba(255,255,255,0.15)" />
                            <path d="M8 28.5 Q20 31 32 28.5" stroke="white" strokeWidth="2.2" strokeLinecap="round" fill="none" />
                            <rect x="12.5" y="22" width="3.5" height="6.5" rx="1.5" fill="white" fillOpacity="0.5" />
                            <rect x="18" y="17" width="3.5" height="11.5" rx="1.5" fill="white" fillOpacity="0.7" />
                            <rect x="23.5" y="12" width="3.5" height="16.5" rx="1.5" fill="white" fillOpacity="0.9" />
                            <circle cx="25.25" cy="9.5" r="1.6" fill="white" fillOpacity="0.8" />
                            <line x1="25.25" y1="6" x2="25.25" y2="7.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.6" />
                            <line x1="22.5" y1="9.5" x2="23.2" y2="9.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.6" />
                            <line x1="27.3" y1="9.5" x2="28" y2="9.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.6" />
                        </svg>
                        <div>
                            <span className="text-2xl font-bold text-white">
                                Spendifi<span className="text-blue-200">AI</span>
                            </span>
                            <div className="text-[10px] text-blue-200 font-medium tracking-wide">
                                AI-Powered Financial Intelligence
                            </div>
                        </div>
                    </Link>
                    <h2 className="mt-12 text-3xl font-bold leading-tight text-white">
                        Smart expense tracking,<br />powered by AI
                    </h2>
                    <ul className="mt-8 space-y-4">
                        {features.map((feature) => (
                            <li key={feature} className="flex items-center gap-3 text-blue-100">
                                <CheckCircle2 className="h-5 w-5 shrink-0 text-blue-200" />
                                <span>{feature}</span>
                            </li>
                        ))}
                    </ul>
                </div>
                <p className="text-sm text-blue-200">
                    &copy; {new Date().getFullYear()} SpendifiAI. All rights reserved.
                </p>
            </div>

            {/* Right form panel */}
            <div className="flex w-full flex-col items-center justify-center bg-sw-bg px-6 py-12 lg:w-3/5">
                {/* Mobile logo */}
                <div className="mb-8 lg:hidden">
                    <Link href="/">
                        <ApplicationLogo />
                    </Link>
                </div>

                <div className="w-full max-w-md">
                    {children}
                </div>
            </div>
        </div>
    );
}
