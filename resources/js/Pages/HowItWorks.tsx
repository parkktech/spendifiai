import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import CTASection from '@/Components/Marketing/CTASection';
import JsonLd from '@/Components/JsonLd';
import {
    UserPlus,
    Building2,
    Settings2,
    Sparkles,
    BarChart3,
    CheckCircle2,
    Brain,
    Target,
    TrendingDown,
    MessageCircle,
    ArrowRight,
    PiggyBank,
    Receipt,
    Search,
    Zap,
    Mail,
} from 'lucide-react';
import { ReactNode } from 'react';

/* ─── Step component ─── */
interface StepProps {
    number: number;
    icon: ReactNode;
    title: string;
    description: string;
    details: string[];
    isLast?: boolean;
}

function Step({ number, icon, title, description, details, isLast }: StepProps) {
    return (
        <div className="flex gap-6">
            <div className="flex shrink-0 flex-col items-center">
                <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-sw-accent text-white shadow-lg shadow-sw-accent/25">
                    <span className="text-lg font-bold">{number}</span>
                </div>
                {!isLast && <div className="mt-4 h-full w-px bg-sw-border" />}
            </div>
            <div className={isLast ? '' : 'pb-16'}>
                <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-sw-accent-light text-sw-accent">
                    {icon}
                </div>
                <h2 className="text-xl font-bold text-sw-text">{title}</h2>
                <p className="mt-2 text-lg leading-relaxed text-sw-muted">{description}</p>
                <ul className="mt-4 space-y-2">
                    {details.map((detail) => (
                        <li key={detail} className="flex items-start gap-2 text-sm text-sw-muted">
                            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-sw-success" />
                            <span>{detail}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

/* ─── Setup steps ─── */
const steps: StepProps[] = [
    {
        number: 1,
        icon: <UserPlus className="h-5 w-5" />,
        title: 'Create Your Free Account',
        description: 'Sign up with email or Google in seconds. No credit card, no payment information, no trial period.',
        details: [
            'Email or Google sign-up',
            'Optional two-factor authentication',
            'Set your financial profile (employment, tax filing status)',
        ],
    },
    {
        number: 2,
        icon: <Building2 className="h-5 w-5" />,
        title: 'Connect Your Bank',
        description: 'Link your bank accounts securely through Plaid. Your login credentials go directly to Plaid — we never see them.',
        details: [
            'Connects to 12,000+ financial institutions',
            'Bank-level encryption and security',
            'Or upload PDF/CSV bank statements manually',
        ],
    },
    {
        number: 3,
        icon: <Settings2 className="h-5 w-5" />,
        title: 'Set Account Purposes',
        description: 'Mark each account as personal, business, or mixed. This is the strongest signal for your AI advisor\'s accuracy.',
        details: [
            'Personal, business, mixed, or investment',
            'Purpose cascades to all transactions',
            'Change anytime as your needs evolve',
        ],
    },
    {
        number: 4,
        icon: <Sparkles className="h-5 w-5" />,
        title: 'Your AI Advisor Gets to Work',
        description: 'This is where SpendifiAI is different. Your AI advisor doesn\'t just categorize — it analyzes your entire financial picture and starts building recommendations.',
        details: [
            'Auto-categorizes transactions at 85%+ confidence',
            'Asks smart questions when uncertain — and learns from your answers',
            'Scans 90 days of history for patterns, waste, and opportunities',
        ],
    },
    {
        number: 5,
        icon: <BarChart3 className="h-5 w-5" />,
        title: 'Get Your Personalized Action Plan',
        description: 'Your advisor delivers real, actionable guidance — not just charts. Savings recommendations, subscription audits, tax deductions, and a roadmap to your financial goals.',
        details: [
            'Ranked savings recommendations with projected monthly savings',
            'Forgotten subscription detection with alternatives',
            'IRS Schedule C tax export (Excel, PDF, CSV)',
            'Goal-based savings plans with monthly action steps',
        ],
    },
];

/* ─── "What your advisor does" section ─── */
function AdvisorCapabilities() {
    const capabilities = [
        {
            icon: <Search className="h-6 w-6" />,
            title: 'Finds Money You\'re Wasting',
            description: 'Scans every transaction for forgotten subscriptions, duplicate charges, price increases, and spending categories that spiked. Most users find $200–$800/mo in potential savings.',
            accent: 'bg-red-50 text-red-600',
        },
        {
            icon: <MessageCircle className="h-6 w-6" />,
            title: 'Explains What\'s Happening',
            description: 'Not just "you spent $400 on food." Your advisor explains what drove the increase, whether it\'s a trend, and how it compares to your normal patterns.',
            accent: 'bg-blue-50 text-blue-600',
        },
        {
            icon: <Target className="h-6 w-6" />,
            title: 'Builds Your Savings Plan',
            description: 'Tell it your goal — emergency fund, vacation, paying off a credit card. It calculates exactly how much to save monthly and shows you where to find the money.',
            accent: 'bg-emerald-50 text-emerald-600',
        },
        {
            icon: <TrendingDown className="h-6 w-6" />,
            title: 'Recommends What to Cut',
            description: 'Ranked recommendations with difficulty levels. Cancel this subscription, switch to a cheaper plan, reduce dining out by 20%. You decide — the AI provides the options.',
            accent: 'bg-violet-50 text-violet-600',
        },
        {
            icon: <PiggyBank className="h-6 w-6" />,
            title: 'Tracks Your Progress',
            description: 'Monthly savings tracking with projections. See how much you\'ve saved, how close you are to your goal, and whether you\'re on track or need to adjust.',
            accent: 'bg-amber-50 text-amber-600',
        },
        {
            icon: <Mail className="h-6 w-6" />,
            title: 'Connects to Your Email',
            description: 'Link your Gmail or email account and AI scans your receipts and order confirmations. Every online purchase gets automatically categorized and matched to transactions.',
            accent: 'bg-pink-50 text-pink-600',
        },
        {
            icon: <Receipt className="h-6 w-6" />,
            title: 'Handles Your Taxes',
            description: 'Every business expense gets mapped to IRS Schedule C categories automatically. Export a tax-ready report and email it directly to your accountant.',
            accent: 'bg-cyan-50 text-cyan-600',
        },
    ];

    return (
        <section className="px-6 py-20 sm:py-28">
            <div className="mx-auto max-w-2xl text-center">
                <p className="text-sm font-semibold uppercase tracking-widest text-sw-accent">
                    What Your Advisor Does
                </p>
                <h2 className="mt-2 text-3xl font-bold tracking-tight text-sw-text sm:text-4xl">
                    It Reviews Everything.<br />
                    <span className="text-sw-accent">Then Tells You Exactly What to Do.</span>
                </h2>
                <p className="mt-4 text-lg leading-relaxed text-sw-muted">
                    Think of it as a financial advisor who never sleeps, never judges, and works for free. Here's what happens after you connect your bank.
                </p>
            </div>
            <div className="mx-auto mt-16 grid max-w-5xl grid-cols-1 gap-8 md:grid-cols-2 lg:grid-cols-3">
                {capabilities.map((cap) => (
                    <div
                        key={cap.title}
                        className="group rounded-2xl border border-sw-border bg-white p-8 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg"
                    >
                        <div className={`mb-5 flex h-12 w-12 items-center justify-center rounded-xl ${cap.accent} transition-transform group-hover:scale-110`}>
                            {cap.icon}
                        </div>
                        <h3 className="text-lg font-bold text-sw-text">{cap.title}</h3>
                        <p className="mt-2 text-sm leading-relaxed text-sw-muted">{cap.description}</p>
                    </div>
                ))}
            </div>
        </section>
    );
}

/* ─── Simulated advisor insight ─── */
function AdvisorInsight() {
    return (
        <section className="relative overflow-hidden bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 px-6 py-20">
            <div
                className="pointer-events-none absolute inset-0 opacity-[0.04]"
                style={{
                    backgroundImage: 'radial-gradient(circle, #fff 1px, transparent 1px)',
                    backgroundSize: '24px 24px',
                }}
            />
            <div className="relative mx-auto max-w-4xl">
                <div className="mb-8 text-center">
                    <p className="text-sm font-semibold uppercase tracking-widest text-blue-400">
                        See It In Action
                    </p>
                    <h2 className="mt-2 text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        What Your First Week Looks Like
                    </h2>
                </div>
                {/* Simulated timeline */}
                <div className="space-y-6">
                    {/* Day 1 */}
                    <div className="rounded-xl border border-slate-700 bg-slate-800/50 p-6">
                        <div className="mb-3 flex items-center gap-3">
                            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600/20 text-xs font-bold text-blue-400">D1</span>
                            <span className="text-sm font-semibold text-slate-300">Day 1 — You connect your bank</span>
                        </div>
                        <div className="ml-11 space-y-2 text-sm text-slate-400">
                            <div className="flex items-start gap-2">
                                <Zap className="mt-0.5 h-4 w-4 shrink-0 text-amber-400" />
                                <span>AI processes 90 days of transactions in minutes</span>
                            </div>
                            <div className="flex items-start gap-2">
                                <Brain className="mt-0.5 h-4 w-4 shrink-0 text-violet-400" />
                                <span>Categorizes 85%+ automatically, asks you about the rest</span>
                            </div>
                        </div>
                    </div>
                    {/* Day 2 */}
                    <div className="rounded-xl border border-slate-700 bg-slate-800/50 p-6">
                        <div className="mb-3 flex items-center gap-3">
                            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-600/20 text-xs font-bold text-emerald-400">D2</span>
                            <span className="text-sm font-semibold text-slate-300">Day 2 — Your advisor delivers its first report</span>
                        </div>
                        <div className="ml-11 space-y-2 text-sm text-slate-400">
                            <div className="flex items-start gap-2">
                                <Search className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                                <span>Finds 3 subscriptions you forgot about — <span className="text-emerald-400 font-medium">$47/mo in savings</span></span>
                            </div>
                            <div className="flex items-start gap-2">
                                <TrendingDown className="mt-0.5 h-4 w-4 shrink-0 text-orange-400" />
                                <span>Flags dining spending up 40% vs your 3-month average</span>
                            </div>
                        </div>
                    </div>
                    {/* Day 3-7 */}
                    <div className="rounded-xl border border-slate-700 bg-slate-800/50 p-6">
                        <div className="mb-3 flex items-center gap-3">
                            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-600/20 text-xs font-bold text-violet-400">D7</span>
                            <span className="text-sm font-semibold text-slate-300">Day 7 — You set a savings goal</span>
                        </div>
                        <div className="ml-11 space-y-2 text-sm text-slate-400">
                            <div className="flex items-start gap-2">
                                <Target className="mt-0.5 h-4 w-4 shrink-0 text-blue-400" />
                                <span>You tell it: "I want to save $5,000 by December"</span>
                            </div>
                            <div className="flex items-start gap-2">
                                <PiggyBank className="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" />
                                <span>AI builds a custom plan: <span className="text-white font-medium">$417/mo</span> — and shows exactly where to find it</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

export default function HowItWorks() {
    return (
        <PublicLayout
            title="How It Works — Set Up Your AI Financial Advisor in 5 Minutes"
            description="Get started with SpendifiAI in under 5 minutes. Connect your bank, and your AI financial advisor starts analyzing spending, finding savings, and building your personalized action plan."
            breadcrumbs={[{ name: 'How It Works', url: '/how-it-works' }]}
        >
            <JsonLd
                data={{
                    '@context': 'https://schema.org',
                    '@type': 'HowTo',
                    name: 'How to Set Up Your AI Financial Advisor with SpendifiAI',
                    description: 'Get your free AI financial advisor in under 5 minutes. Connect your bank and receive personalized savings plans, subscription audits, and tax deduction mapping.',
                    totalTime: 'PT5M',
                    tool: { '@type': 'SoftwareApplication', '@id': 'https://spendifiai.com/#software' },
                    step: [
                        {
                            '@type': 'HowToStep',
                            position: 1,
                            name: 'Create Your Free Account',
                            text: 'Sign up with email or Google OAuth. No credit card or payment information required.',
                            url: 'https://spendifiai.com/register',
                        },
                        {
                            '@type': 'HowToStep',
                            position: 2,
                            name: 'Connect Your Bank',
                            text: 'Link your bank accounts securely through Plaid, or upload PDF/CSV bank statements manually.',
                            url: 'https://spendifiai.com/how-it-works',
                        },
                        {
                            '@type': 'HowToStep',
                            position: 3,
                            name: 'Set Account Purposes',
                            text: 'Mark each account as personal, business, or mixed to help your AI advisor categorize accurately.',
                            url: 'https://spendifiai.com/how-it-works',
                        },
                        {
                            '@type': 'HowToStep',
                            position: 4,
                            name: 'Your AI Advisor Analyzes Everything',
                            text: 'The AI reviews 90 days of transactions, categorizes spending, detects patterns, and identifies savings opportunities.',
                            url: 'https://spendifiai.com/how-it-works',
                        },
                        {
                            '@type': 'HowToStep',
                            position: 5,
                            name: 'Get Your Personalized Action Plan',
                            text: 'Receive AI-powered savings recommendations, subscription audits, and IRS Schedule C tax-ready exports.',
                            url: 'https://spendifiai.com/how-it-works',
                        },
                    ],
                }}
            />

            {/* ═══ Hero ═══ */}
            <section className="relative overflow-hidden bg-gradient-to-b from-white to-sw-accent-light px-6 pb-20 pt-16 sm:pb-28 sm:pt-24">
                <div className="mx-auto max-w-4xl text-center">
                    <div className="inline-flex items-center gap-2 rounded-full border border-sw-accent/20 bg-sw-accent-light px-4 py-1.5 text-sm font-medium text-sw-accent">
                        <Sparkles className="h-3.5 w-3.5" />
                        5 minutes to your AI advisor
                    </div>
                    <h1 className="mt-6 text-4xl font-extrabold tracking-tight text-sw-text sm:text-5xl lg:text-6xl">
                        Connect Your Bank.<br />
                        <span className="bg-gradient-to-r from-blue-600 to-violet-600 bg-clip-text text-transparent">
                            Your AI Advisor Does the Rest.
                        </span>
                    </h1>
                    <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-sw-muted sm:text-xl">
                        In five steps, you go from sign-up to having an AI that reviews every transaction, finds where you're losing money, and builds a personalized plan to hit your savings goals.
                    </p>
                    <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                        <Link
                            href="/register"
                            className="inline-flex items-center gap-2 rounded-xl bg-sw-accent px-8 py-3.5 text-base font-semibold text-white shadow-lg shadow-sw-accent/25 transition-all duration-200 hover:bg-sw-accent-hover hover:shadow-xl hover:shadow-sw-accent/30"
                        >
                            Get Started Free
                            <ArrowRight className="h-4 w-4" />
                        </Link>
                    </div>
                </div>
            </section>

            {/* ═══ Setup Steps ═══ */}
            <section className="px-6 py-20 sm:py-28">
                <div className="mx-auto max-w-3xl">
                    {steps.map((step, idx) => (
                        <Step key={step.number} {...step} isLast={idx === steps.length - 1} />
                    ))}
                </div>
            </section>

            {/* ═══ "What Your First Week Looks Like" ═══ */}
            <AdvisorInsight />

            {/* ═══ What Your Advisor Does ═══ */}
            <AdvisorCapabilities />

            {/* ═══ Why SpendifiAI Is Different ═══ */}
            <section className="bg-sw-surface px-6 py-20">
                <div className="mx-auto max-w-2xl text-center">
                    <p className="text-sm font-semibold uppercase tracking-widest text-sw-accent">
                        Why SpendifiAI
                    </p>
                    <h2 className="mt-2 text-3xl font-bold tracking-tight text-sw-text sm:text-4xl">
                        Not Just Another Expense Tracker
                    </h2>
                    <p className="mt-4 text-lg leading-relaxed text-sw-muted">
                        Most apps show you where your money went. SpendifiAI tells you what to do about it.
                    </p>
                </div>
                <div className="mx-auto mt-12 grid max-w-5xl grid-cols-1 gap-8 md:grid-cols-3">
                    <div className="rounded-2xl border border-sw-border bg-white p-8 text-center shadow-sm">
                        <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-xl bg-blue-50">
                            <Brain className="h-7 w-7 text-blue-600" />
                        </div>
                        <div className="text-2xl font-bold text-sw-text">AI Advisor</div>
                        <div className="mt-2 text-sm font-medium text-sw-muted">Not just tracking — real financial guidance powered by AI that learns your patterns</div>
                    </div>
                    <div className="rounded-2xl border border-sw-border bg-white p-8 text-center shadow-sm">
                        <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-xl bg-emerald-50">
                            <PiggyBank className="h-7 w-7 text-emerald-600" />
                        </div>
                        <div className="text-2xl font-bold text-sw-text">$0 Forever</div>
                        <div className="mt-2 text-sm font-medium text-sw-muted">No premium tiers, no hidden fees, no "free trial" that expires. Free means free.</div>
                    </div>
                    <div className="rounded-2xl border border-sw-border bg-white p-8 text-center shadow-sm">
                        <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-xl bg-violet-50">
                            <Receipt className="h-7 w-7 text-violet-600" />
                        </div>
                        <div className="text-2xl font-bold text-sw-text">Tax Ready</div>
                        <div className="mt-2 text-sm font-medium text-sw-muted">IRS Schedule C mapped categories with one-click export. Your accountant will thank you.</div>
                    </div>
                </div>
            </section>

            {/* ═══ Final CTA ═══ */}
            <CTASection
                headline="Ready to Meet Your AI Financial Advisor?"
                description="Create your free account and connect your first bank in under 5 minutes. No credit card, no catches."
                buttonText="Get Started Free"
                buttonHref="/register"
            />
        </PublicLayout>
    );
}
