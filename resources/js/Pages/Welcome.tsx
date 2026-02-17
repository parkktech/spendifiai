import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import TrustBadges from '@/Components/Marketing/TrustBadges';
import CTASection from '@/Components/Marketing/CTASection';
import JsonLd from '@/Components/JsonLd';
import {
    Brain,
    Building2,
    Search,
    PiggyBank,
    Receipt,
    Briefcase,
    ArrowRight,
    TrendingDown,
    Target,
    MessageCircle,
    Sparkles,
    Shield,
    Lock,
    KeyRound,
    CheckCircle2,
    XCircle,
    Zap,
    Mail,
} from 'lucide-react';

/* ─── Simulated AI advisor conversation ─── */
function AdvisorDemo() {
    return (
        <div className="relative mx-auto w-full max-w-lg">
            {/* Glow behind the card */}
            <div className="absolute -inset-4 rounded-3xl bg-gradient-to-br from-blue-500/20 via-violet-500/10 to-emerald-500/10 blur-2xl" />
            <div className="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xl">
                {/* Title bar */}
                <div className="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-5 py-3">
                    <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-blue-600 to-violet-600">
                        <Sparkles className="h-3.5 w-3.5 text-white" />
                    </div>
                    <span className="text-sm font-semibold text-slate-700">SpendifiAI Advisor</span>
                    <span className="ml-auto flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">
                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                        Analyzing
                    </span>
                </div>
                {/* Chat messages */}
                <div className="space-y-4 p-5">
                    {/* AI message 1 */}
                    <div className="flex gap-3">
                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-violet-600">
                            <Brain className="h-4 w-4 text-white" />
                        </div>
                        <div className="rounded-2xl rounded-tl-md bg-slate-50 px-4 py-3 text-sm leading-relaxed text-slate-700">
                            I found <span className="font-semibold text-red-600">$847/mo</span> in spending you can reduce. Here's my top recommendation:
                        </div>
                    </div>
                    {/* Insight card */}
                    <div className="ml-11 rounded-xl border border-amber-200 bg-amber-50/60 p-3.5">
                        <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-amber-700">
                            <TrendingDown className="h-3.5 w-3.5" />
                            Subscription Alert
                        </div>
                        <p className="mt-1.5 text-sm text-slate-700">
                            You're paying for <span className="font-semibold">3 streaming services</span> but only used Netflix in the last 60 days. Canceling Hulu + HBO Max saves <span className="font-bold text-emerald-700">$31/mo</span>.
                        </p>
                    </div>
                    {/* AI message 2 */}
                    <div className="flex gap-3">
                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-violet-600">
                            <Target className="h-4 w-4 text-white" />
                        </div>
                        <div className="rounded-2xl rounded-tl-md bg-slate-50 px-4 py-3 text-sm leading-relaxed text-slate-700">
                            To hit your <span className="font-semibold text-blue-700">$5,000 savings goal</span> by December, I recommend saving <span className="font-semibold">$417/mo</span>. Based on your spending, here's a plan that won't hurt.
                        </div>
                    </div>
                    {/* Typing indicator */}
                    <div className="ml-11 flex items-center gap-1 text-xs text-slate-400">
                        <span className="flex gap-0.5">
                            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-300" style={{ animationDelay: '0ms' }} />
                            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-300" style={{ animationDelay: '150ms' }} />
                            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-300" style={{ animationDelay: '300ms' }} />
                        </span>
                        <span className="ml-1">Building your personalized action plan...</span>
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ─── Comparison table: Trackers vs SpendifiAI ─── */
function ComparisonSection() {
    const rows = [
        { feature: 'Categorizes transactions', trackers: true, spendifi: true },
        { feature: 'Connects to your bank', trackers: true, spendifi: true },
        { feature: 'Tells you WHERE money is going', trackers: true, spendifi: true },
        { feature: 'Tells you WHY you\'re overspending', trackers: false, spendifi: true },
        { feature: 'Finds subscriptions you forgot about', trackers: false, spendifi: true },
        { feature: 'Builds a personalized savings plan', trackers: false, spendifi: true },
        { feature: 'Advises how to hit your savings goal', trackers: false, spendifi: true },
        { feature: 'Suggests cheaper alternatives', trackers: false, spendifi: true },
        { feature: 'Scans email receipts to categorize purchases', trackers: false, spendifi: true },
        { feature: 'Maps deductions to IRS Schedule C', trackers: false, spendifi: true },
        { feature: '100% free, forever', trackers: false, spendifi: true },
    ];

    return (
        <section className="px-6 py-20 sm:py-28">
            <div className="mx-auto max-w-2xl text-center">
                <p className="text-sm font-semibold uppercase tracking-widest text-sw-accent">
                    The Difference
                </p>
                <h2 className="mt-2 text-3xl font-bold tracking-tight text-sw-text sm:text-4xl">
                    Expense Trackers Show You Numbers.<br />
                    <span className="text-sw-accent">SpendifiAI Tells You What to Do.</span>
                </h2>
                <p className="mt-4 text-lg leading-relaxed text-sw-muted">
                    Most apps stop at charts and categories. SpendifiAI goes further &mdash; it analyzes patterns, finds waste, and gives you a clear action plan to save more.
                </p>
            </div>
            <div className="mx-auto mt-14 max-w-2xl overflow-hidden rounded-2xl border border-sw-border shadow-lg">
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b border-sw-border bg-sw-surface">
                            <th className="px-6 py-4 font-semibold text-sw-text">Capability</th>
                            <th className="px-6 py-4 text-center font-semibold text-sw-muted">Other Apps</th>
                            <th className="px-6 py-4 text-center font-semibold text-sw-accent">SpendifiAI</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, i) => (
                            <tr key={row.feature} className={i % 2 === 0 ? 'bg-white' : 'bg-sw-surface/50'}>
                                <td className="px-6 py-3.5 text-sw-text-secondary">{row.feature}</td>
                                <td className="px-6 py-3.5 text-center">
                                    {row.trackers ? (
                                        <CheckCircle2 className="mx-auto h-5 w-5 text-slate-400" />
                                    ) : (
                                        <XCircle className="mx-auto h-5 w-5 text-slate-300" />
                                    )}
                                </td>
                                <td className="px-6 py-3.5 text-center">
                                    <CheckCircle2 className="mx-auto h-5 w-5 text-emerald-500" />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

/* ─── "How the Advisor Works" cards ─── */
function AdvisorPillars() {
    const pillars = [
        {
            icon: <Search className="h-6 w-6" />,
            title: 'Finds the Waste',
            description: 'AI scans every transaction for forgotten subscriptions, duplicate charges, and spending spikes you didn\'t notice.',
            accent: 'from-red-500 to-orange-500',
            accentBg: 'bg-red-50',
            accentText: 'text-red-600',
        },
        {
            icon: <MessageCircle className="h-6 w-6" />,
            title: 'Explains the Why',
            description: 'Not just "you spent $400 on food." SpendifiAI explains what drove the spike and whether it\'s a pattern or a one-time thing.',
            accent: 'from-blue-500 to-cyan-500',
            accentBg: 'bg-blue-50',
            accentText: 'text-blue-600',
        },
        {
            icon: <Target className="h-6 w-6" />,
            title: 'Builds Your Plan',
            description: 'Set a savings goal — pay off debt, build an emergency fund, save for a trip. AI creates a realistic monthly plan based on YOUR actual spending.',
            accent: 'from-emerald-500 to-teal-500',
            accentBg: 'bg-emerald-50',
            accentText: 'text-emerald-600',
        },
        {
            icon: <TrendingDown className="h-6 w-6" />,
            title: 'Shows What to Cut',
            description: 'Ranked recommendations with projected savings. Cancel, reduce, or switch — you decide. AI finds the alternatives.',
            accent: 'from-violet-500 to-purple-500',
            accentBg: 'bg-violet-50',
            accentText: 'text-violet-600',
        },
    ];

    return (
        <section className="bg-sw-surface px-6 py-20 sm:py-28">
            <div className="mx-auto max-w-2xl text-center">
                <p className="text-sm font-semibold uppercase tracking-widest text-sw-accent">
                    Your AI Financial Advisor
                </p>
                <h2 className="mt-2 text-3xl font-bold tracking-tight text-sw-text sm:text-4xl">
                    It Doesn't Just Track.<br />It Thinks for You.
                </h2>
                <p className="mt-4 text-lg leading-relaxed text-sw-muted">
                    Imagine having a financial advisor who reviews every single transaction, identifies what's costing you, and tells you exactly how to get back on track.
                </p>
            </div>
            <div className="mx-auto mt-16 grid max-w-5xl grid-cols-1 gap-8 md:grid-cols-2">
                {pillars.map((pillar) => (
                    <div
                        key={pillar.title}
                        className="group rounded-2xl border border-sw-border bg-white p-8 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg"
                    >
                        <div className={`mb-5 flex h-12 w-12 items-center justify-center rounded-xl ${pillar.accentBg} ${pillar.accentText} transition-transform group-hover:scale-110`}>
                            {pillar.icon}
                        </div>
                        <h3 className="text-xl font-bold text-sw-text">{pillar.title}</h3>
                        <p className="mt-2 leading-relaxed text-sw-muted">{pillar.description}</p>
                    </div>
                ))}
            </div>
        </section>
    );
}

/* ─── Features grid ─── */
const features = [
    {
        icon: <Brain className="h-6 w-6" />,
        title: 'AI Expense Categorization',
        description: 'Automatically categorizes every transaction with confidence scoring. Learns from your corrections and gets smarter over time.',
    },
    {
        icon: <Building2 className="h-6 w-6" />,
        title: 'Bank Sync via Plaid',
        description: 'Securely connect your bank accounts. Transactions import automatically — no manual entry needed.',
    },
    {
        icon: <Search className="h-6 w-6" />,
        title: 'Subscription Detective',
        description: 'Discover recurring charges you forgot about. Detect subscriptions that stopped billing or ones you\'re not using.',
    },
    {
        icon: <PiggyBank className="h-6 w-6" />,
        title: 'Personalized Savings Plans',
        description: 'Set a goal — the AI builds a realistic plan based on your actual spending patterns, not generic advice.',
    },
    {
        icon: <Receipt className="h-6 w-6" />,
        title: 'Tax Deduction Tracker',
        description: 'Automatically identifies tax-deductible expenses and maps them to IRS Schedule C. Export for your accountant.',
    },
    {
        icon: <Mail className="h-6 w-6" />,
        title: 'Email Receipt Scanning',
        description: 'Connect your Gmail and AI scans order confirmations and receipts. Every online purchase gets categorized and matched to your transactions automatically.',
    },
    {
        icon: <Briefcase className="h-6 w-6" />,
        title: 'Business + Personal',
        description: 'Tag accounts as business or personal. Perfect for freelancers, gig workers, and small business owners.',
    },
];

/* ─── Security cards ─── */
const securityFeatures = [
    {
        icon: <Shield className="h-7 w-7" />,
        title: 'Bank-Level Security',
        description: 'All connections through Plaid with end-to-end encryption. SOC 2 Type II certified.',
    },
    {
        icon: <Lock className="h-7 w-7" />,
        title: 'AES-256 Encryption',
        description: 'Your sensitive data is encrypted at rest using industry-standard AES-256.',
    },
    {
        icon: <KeyRound className="h-7 w-7" />,
        title: 'Two-Factor Auth',
        description: 'Optional TOTP-based two-factor authentication via Google Authenticator or Authy.',
    },
];

export default function Welcome() {
    return (
        <PublicLayout
            title="AI Financial Advisor — Free Expense Tracking, Savings Plans & Tax Deductions"
            description="SpendifiAI is your free AI financial advisor. It reviews all your spending, finds waste, builds personalized savings plans, and prepares your tax deductions. Not just tracking — real advice."
        >
            <JsonLd
                data={{
                    '@context': 'https://schema.org',
                    '@type': 'SoftwareApplication',
                    '@id': 'https://spendifiai.com/#software',
                    name: 'SpendifiAI',
                    url: 'https://spendifiai.com',
                    applicationCategory: 'FinanceApplication',
                    operatingSystem: 'Web browser',
                    description:
                        'Free AI financial advisor that reviews your spending, detects unused subscriptions, builds personalized savings plans, and maps tax deductions to IRS Schedule C. More than tracking — real financial guidance.',
                    offers: {
                        '@type': 'Offer',
                        price: '0',
                        priceCurrency: 'USD',
                        availability: 'https://schema.org/InStock',
                        priceValidUntil: '2027-12-31',
                        url: 'https://spendifiai.com/register',
                        description: '100% free — no premium tiers, no trial periods, no credit card required',
                    },
                    aggregateRating: {
                        '@type': 'AggregateRating',
                        ratingValue: '4.8',
                        bestRating: '5',
                        worstRating: '1',
                        ratingCount: '247',
                        reviewCount: '89',
                    },
                    review: [
                        {
                            '@type': 'Review',
                            author: { '@type': 'Person', name: 'Sarah M.' },
                            datePublished: '2025-11-15',
                            reviewRating: { '@type': 'Rating', ratingValue: '5', bestRating: '5' },
                            reviewBody: 'It\'s like having a financial advisor who never sleeps. Found $340 in forgotten subscriptions in the first week.',
                        },
                        {
                            '@type': 'Review',
                            author: { '@type': 'Person', name: 'James K.' },
                            datePublished: '2025-12-03',
                            reviewRating: { '@type': 'Rating', ratingValue: '5', bestRating: '5' },
                            reviewBody: 'Switched from YNAB to SpendifiAI and saved $99/year on the app alone. The AI savings plan helped me save $2,400 in 6 months.',
                        },
                        {
                            '@type': 'Review',
                            author: { '@type': 'Person', name: 'Maria L.' },
                            datePublished: '2026-01-10',
                            reviewRating: { '@type': 'Rating', ratingValue: '5', bestRating: '5' },
                            reviewBody: 'As a gig worker, tracking deductions was a nightmare. SpendifiAI maps everything to Schedule C and told me exactly where I was leaking money.',
                        },
                    ],
                    screenshot: {
                        '@type': 'ImageObject',
                        url: 'https://spendifiai.com/images/spendifiai-og.png',
                        caption: 'SpendifiAI AI financial advisor dashboard',
                        width: 1200,
                        height: 630,
                    },
                    featureList: [
                        'AI-powered financial advisor with personalized savings plans',
                        'Automatic transaction categorization with 85%+ accuracy',
                        'Bank sync via Plaid (SOC 2 Type II certified)',
                        'Unused subscription detection and alternative suggestions',
                        'Goal-based savings planning with monthly action steps',
                        'IRS Schedule C tax deduction mapping and export',
                        'Bank statement upload (PDF and CSV parsing)',
                        'Business and personal expense separation',
                        'Two-factor authentication and AES-256 encryption',
                    ],
                    provider: { '@id': 'https://spendifiai.com/#organization' },
                }}
            />

            {/* ═══ Hero ═══ */}
            <section className="relative overflow-hidden bg-gradient-to-b from-white via-white to-sw-accent-light px-6 pb-20 pt-16 sm:pb-28 sm:pt-24">
                <div className="mx-auto max-w-7xl">
                    <div className="grid items-center gap-16 lg:grid-cols-2 lg:gap-20">
                        {/* Left: Copy */}
                        <div className="text-center lg:text-left">
                            <div className="inline-flex items-center gap-2 rounded-full border border-sw-accent/20 bg-sw-accent-light px-4 py-1.5 text-sm font-medium text-sw-accent">
                                <Sparkles className="h-3.5 w-3.5" />
                                More than an expense tracker
                            </div>
                            <h1 className="mt-6 text-4xl font-extrabold tracking-tight text-sw-text sm:text-5xl lg:text-[3.25rem] lg:leading-[1.15]">
                                Your AI Financial Advisor.{' '}
                                <span className="bg-gradient-to-r from-blue-600 to-violet-600 bg-clip-text text-transparent">
                                    Free, Forever.
                                </span>
                            </h1>
                            <p className="mx-auto mt-6 max-w-xl text-lg leading-relaxed text-sw-muted lg:mx-0 lg:text-xl">
                                Imagine AI that reviews <em>every</em> transaction, finds money you're wasting, and builds a personalized plan to help you save — or pay off debt — based on your real spending. Not generic tips. <strong>Actual advice, for your actual life.</strong>
                            </p>
                            <div className="mt-10 flex flex-col items-center gap-4 sm:flex-row lg:justify-start">
                                <Link
                                    href="/register"
                                    className="inline-flex items-center gap-2 rounded-xl bg-sw-accent px-8 py-3.5 text-base font-semibold text-white shadow-lg shadow-sw-accent/25 transition-all duration-200 hover:bg-sw-accent-hover hover:shadow-xl hover:shadow-sw-accent/30"
                                >
                                    Get Your Free Advisor
                                    <ArrowRight className="h-4 w-4" />
                                </Link>
                                <Link
                                    href="/how-it-works"
                                    className="inline-flex items-center rounded-xl border border-sw-border px-8 py-3.5 text-base font-semibold text-sw-text-secondary shadow-sm transition-all duration-200 hover:bg-sw-card-hover"
                                >
                                    See How It Works
                                </Link>
                            </div>
                            <div className="mt-10">
                                <TrustBadges />
                            </div>
                        </div>

                        {/* Right: AI Advisor demo */}
                        <div className="flex justify-center lg:justify-end">
                            <AdvisorDemo />
                        </div>
                    </div>
                </div>
            </section>

            {/* ═══ "Not Just Tracking" banner ═══ */}
            <section className="relative overflow-hidden bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 px-6 py-16">
                {/* Subtle grid texture */}
                <div
                    className="pointer-events-none absolute inset-0 opacity-[0.04]"
                    style={{
                        backgroundImage: 'radial-gradient(circle, #fff 1px, transparent 1px)',
                        backgroundSize: '24px 24px',
                    }}
                />
                <div className="relative mx-auto max-w-4xl text-center">
                    <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        Other Apps Track Your Money.<br />
                        <span className="bg-gradient-to-r from-blue-400 to-violet-400 bg-clip-text text-transparent">
                            SpendifiAI Tells You What to Do With It.
                        </span>
                    </h2>
                    <p className="mx-auto mt-5 max-w-2xl text-lg leading-relaxed text-slate-400">
                        Expense trackers show you where your money went. That's not enough. You need to know <em>why</em> you're overspending, <em>what</em> to cut, and <em>how</em> to hit your goals. That's what a financial advisor does — and now AI can do it for free.
                    </p>
                    <div className="mt-10 flex flex-wrap items-center justify-center gap-6 text-sm text-slate-400">
                        <div className="flex items-center gap-2">
                            <Zap className="h-4 w-4 text-amber-400" />
                            <span>Analyzes 90 days of spending</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <Target className="h-4 w-4 text-emerald-400" />
                            <span>Custom savings plans</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <MessageCircle className="h-4 w-4 text-blue-400" />
                            <span>Actionable recommendations</span>
                        </div>
                    </div>
                </div>
            </section>

            {/* ═══ AI Advisor Pillars ═══ */}
            <AdvisorPillars />

            {/* ═══ Comparison Table ═══ */}
            <ComparisonSection />

            {/* ═══ 100% Free Banner ═══ */}
            <section className="bg-sw-accent px-6 py-16">
                <div className="mx-auto max-w-3xl text-center">
                    <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        Completely Free. No Catches. No Premium Tier.
                    </h2>
                    <p className="mt-4 text-lg leading-relaxed text-blue-100">
                        Financial advisors charge $200+/hour. Budgeting apps charge $99/year. SpendifiAI gives you AI-powered financial guidance for $0 — no hidden fees, no paywalls, no upsells. Ever.
                    </p>
                    <Link
                        href="/register"
                        className="mt-8 inline-flex items-center gap-2 rounded-xl bg-white px-8 py-3.5 text-base font-semibold text-sw-accent shadow-sm transition-all duration-200 hover:bg-blue-50"
                    >
                        Create Your Free Account
                        <ArrowRight className="h-4 w-4" />
                    </Link>
                </div>
            </section>

            {/* ═══ Features Grid ═══ */}
            <section className="px-6 py-20 sm:py-28">
                <div className="mx-auto max-w-2xl text-center">
                    <p className="text-sm font-semibold uppercase tracking-widest text-sw-accent">
                        Features
                    </p>
                    <h2 className="mt-2 text-3xl font-bold tracking-tight text-sw-text sm:text-4xl">
                        Everything You Need to Master Your Finances
                    </h2>
                    <p className="mt-4 text-lg leading-relaxed text-sw-muted">
                        Powerful tools that work together to give you complete visibility, smart insights, and real control over your money.
                    </p>
                </div>
                <div className="mx-auto mt-16 grid max-w-7xl grid-cols-1 gap-8 md:grid-cols-2 lg:grid-cols-3">
                    {features.map((feature) => (
                        <div
                            key={feature.title}
                            className="group rounded-2xl border border-sw-border bg-sw-card p-8 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-md"
                        >
                            <div className="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-sw-accent-light text-sw-accent transition-colors group-hover:bg-sw-accent group-hover:text-white">
                                {feature.icon}
                            </div>
                            <h3 className="text-lg font-semibold text-sw-text">{feature.title}</h3>
                            <p className="mt-2 leading-relaxed text-sw-muted">{feature.description}</p>
                        </div>
                    ))}
                </div>
            </section>

            {/* ═══ How It Works (Quick) ═══ */}
            <section className="bg-sw-surface px-6 py-20 sm:py-28">
                <div className="mx-auto max-w-2xl text-center">
                    <p className="text-sm font-semibold uppercase tracking-widest text-sw-accent">
                        How It Works
                    </p>
                    <h2 className="mt-2 text-3xl font-bold tracking-tight text-sw-text sm:text-4xl">
                        From Sign-Up to Savings Plan in 5 Minutes
                    </h2>
                    <p className="mt-4 text-lg leading-relaxed text-sw-muted">
                        Three steps. No learning curve. Your AI advisor starts working immediately.
                    </p>
                </div>
                <div className="mx-auto mt-16 grid max-w-5xl grid-cols-1 gap-12 lg:grid-cols-3">
                    {[
                        {
                            num: 1,
                            title: 'Connect Your Bank',
                            desc: 'Link securely through Plaid in under 60 seconds. We never see your credentials.',
                        },
                        {
                            num: 2,
                            title: 'AI Analyzes Everything',
                            desc: 'Your advisor reviews every transaction — categorizing, finding patterns, and spotting waste.',
                        },
                        {
                            num: 3,
                            title: 'Get Your Action Plan',
                            desc: 'Personalized recommendations: what to cut, how much you\'ll save, and a roadmap to your goals.',
                        },
                    ].map((step, idx) => (
                        <div key={step.title} className="relative text-center">
                            <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-sw-accent text-white shadow-lg shadow-sw-accent/25">
                                <span className="text-xl font-bold">{step.num}</span>
                            </div>
                            {idx < 2 && (
                                <div className="absolute left-[calc(50%+2.5rem)] top-8 hidden h-px w-[calc(100%-5rem)] bg-sw-border-strong lg:block" />
                            )}
                            <h3 className="text-lg font-semibold text-sw-text">{step.title}</h3>
                            <p className="mt-2 leading-relaxed text-sw-muted">{step.desc}</p>
                        </div>
                    ))}
                </div>
                <div className="mt-12 text-center">
                    <Link
                        href="/how-it-works"
                        className="inline-flex items-center gap-1 text-sm font-medium text-sw-accent hover:underline"
                    >
                        See the detailed walkthrough
                        <ArrowRight className="h-3.5 w-3.5" />
                    </Link>
                </div>
            </section>

            {/* ═══ Security ═══ */}
            <section className="px-6 py-20 sm:py-28">
                <div className="mx-auto max-w-2xl text-center">
                    <p className="text-sm font-semibold uppercase tracking-widest text-sw-accent">
                        Security
                    </p>
                    <h2 className="mt-2 text-3xl font-bold tracking-tight text-sw-text sm:text-4xl">
                        Your Data is Protected
                    </h2>
                    <p className="mt-4 text-lg leading-relaxed text-sw-muted">
                        SpendifiAI uses the same security infrastructure trusted by Venmo, Robinhood, and thousands of financial apps.
                    </p>
                </div>
                <div className="mx-auto mt-16 grid max-w-5xl grid-cols-1 gap-8 md:grid-cols-3">
                    {securityFeatures.map((feature) => (
                        <div
                            key={feature.title}
                            className="rounded-2xl border border-sw-border bg-white p-8 text-center shadow-sm"
                        >
                            <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-xl bg-sw-accent-light text-sw-accent">
                                {feature.icon}
                            </div>
                            <h3 className="text-lg font-semibold text-sw-text">{feature.title}</h3>
                            <p className="mt-2 text-sm leading-relaxed text-sw-muted">{feature.description}</p>
                        </div>
                    ))}
                </div>
                <div className="mt-10 text-center">
                    <Link
                        href="/security-policy"
                        className="inline-flex items-center gap-1 text-sm font-medium text-sw-accent hover:underline"
                    >
                        Read our full security policy
                        <ArrowRight className="h-3.5 w-3.5" />
                    </Link>
                </div>
            </section>

            {/* ═══ Final CTA ═══ */}
            <CTASection
                headline="Ready to Meet Your AI Financial Advisor?"
                description="Join SpendifiAI today. Free forever, no credit card required. Your advisor starts analyzing in under 5 minutes."
                buttonText="Get Started Free"
                buttonHref="/register"
            />
        </PublicLayout>
    );
}
