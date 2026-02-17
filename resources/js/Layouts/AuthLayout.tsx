import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';
import { CheckCircle2, Shield, Zap, TrendingUp, PiggyBank, Lock } from 'lucide-react';

const features = [
    { icon: Zap, text: 'AI-powered expense categorization' },
    { icon: Shield, text: 'Secure bank sync via Plaid' },
    { icon: TrendingUp, text: 'Tax deduction tracking & export' },
    { icon: PiggyBank, text: 'Subscription detection & savings' },
    { icon: Lock, text: '100% free, forever — no hidden fees' },
];

export default function AuthLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen">
            {/* Left branded panel — hidden on mobile */}
            <div className="hidden lg:flex lg:w-[44%] lg:flex-col lg:justify-between relative overflow-hidden">
                {/* Deep gradient background */}
                <div className="absolute inset-0 bg-gradient-to-br from-slate-900 via-blue-950 to-indigo-950" />

                {/* Subtle grid pattern overlay */}
                <div
                    className="absolute inset-0 opacity-[0.04]"
                    style={{
                        backgroundImage: `url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")`,
                    }}
                />

                {/* Glow effects */}
                <div className="absolute top-20 -left-20 w-80 h-80 bg-blue-500/20 rounded-full blur-[100px]" />
                <div className="absolute bottom-32 right-10 w-60 h-60 bg-indigo-500/15 rounded-full blur-[80px]" />

                {/* Content */}
                <div className="relative z-10 p-12 flex flex-col h-full">
                    {/* Logo */}
                    <Link href="/" className="flex items-center gap-3 group">
                        <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" className="h-11 w-11 transition-transform group-hover:scale-105">
                            <rect width="40" height="40" rx="10" fill="url(#auth-logo-grad)" />
                            <path d="M8 28.5 Q20 31 32 28.5" stroke="white" strokeWidth="2.2" strokeLinecap="round" fill="none" />
                            <rect x="12.5" y="22" width="3.5" height="6.5" rx="1.5" fill="white" fillOpacity="0.5" />
                            <rect x="18" y="17" width="3.5" height="11.5" rx="1.5" fill="white" fillOpacity="0.7" />
                            <rect x="23.5" y="12" width="3.5" height="16.5" rx="1.5" fill="white" fillOpacity="0.9" />
                            <circle cx="25.25" cy="9.5" r="1.6" fill="white" fillOpacity="0.8" />
                            <line x1="25.25" y1="6" x2="25.25" y2="7.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.6" />
                            <line x1="22.5" y1="9.5" x2="23.2" y2="9.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.6" />
                            <line x1="27.3" y1="9.5" x2="28" y2="9.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.6" />
                            <defs>
                                <linearGradient id="auth-logo-grad" x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse">
                                    <stop stopColor="#3b82f6" />
                                    <stop offset="1" stopColor="#6366f1" />
                                </linearGradient>
                            </defs>
                        </svg>
                        <div>
                            <span className="text-2xl font-bold text-white tracking-tight">
                                Spendifi<span className="text-blue-400">AI</span>
                            </span>
                            <div className="text-[10px] text-blue-300/70 font-medium tracking-widest uppercase">
                                Financial Intelligence
                            </div>
                        </div>
                    </Link>

                    {/* Headline */}
                    <div className="mt-16 flex-1">
                        <h2 className="text-[2.1rem] font-bold leading-[1.2] text-white tracking-tight">
                            Take control of
                            <br />
                            your finances with
                            <br />
                            <span className="bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">
                                AI intelligence
                            </span>
                        </h2>
                        <p className="mt-5 text-blue-200/60 text-[0.95rem] leading-relaxed max-w-sm">
                            Automatic categorization, subscription detection, and tax-ready exports — all in one place.
                        </p>

                        {/* Feature list */}
                        <ul className="mt-10 space-y-4">
                            {features.map(({ icon: Icon, text }) => (
                                <li key={text} className="flex items-center gap-3.5 group/item">
                                    <div className="flex items-center justify-center w-8 h-8 rounded-lg bg-white/[0.06] border border-white/[0.08] group-hover/item:bg-blue-500/10 group-hover/item:border-blue-500/20 transition-colors">
                                        <Icon className="h-4 w-4 text-blue-400/80" />
                                    </div>
                                    <span className="text-[0.9rem] text-blue-100/70">{text}</span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* Bottom */}
                    <div className="flex items-center justify-between">
                        <p className="text-xs text-blue-300/30">
                            &copy; {new Date().getFullYear()} SpendifiAI
                        </p>
                        <div className="flex items-center gap-1.5 text-xs text-blue-300/30">
                            <Lock className="h-3 w-3" />
                            <span>256-bit encrypted</span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Right form panel */}
            <div className="flex w-full flex-col items-center justify-center bg-sw-bg px-6 py-12 lg:w-[56%]">
                {/* Mobile logo */}
                <div className="mb-10 lg:hidden">
                    <Link href="/">
                        <ApplicationLogo iconSize={40} showTagline />
                    </Link>
                </div>

                <div className="w-full max-w-[420px]">
                    {children}
                </div>
            </div>
        </div>
    );
}
