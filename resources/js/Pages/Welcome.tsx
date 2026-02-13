import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import HeroSection from '@/Components/Marketing/HeroSection';
import FeatureCard from '@/Components/Marketing/FeatureCard';
import SectionHeading from '@/Components/Marketing/SectionHeading';
import CTASection from '@/Components/Marketing/CTASection';
import TrustBadges from '@/Components/Marketing/TrustBadges';
import StatsCounter from '@/Components/Marketing/StatsCounter';
import JsonLd from '@/Components/JsonLd';
import {
    Brain,
    Building2,
    Search,
    PiggyBank,
    Receipt,
    Briefcase,
    LinkIcon,
    Sparkles,
    TrendingUp,
    Shield,
    Lock,
    KeyRound,
    ArrowRight,
} from 'lucide-react';

const features = [
    {
        icon: <Brain className="h-6 w-6" />,
        title: 'AI Expense Categorization',
        description:
            'Our AI automatically categorizes every transaction. It learns from your corrections and gets smarter over time.',
    },
    {
        icon: <Building2 className="h-6 w-6" />,
        title: 'Bank Sync via Plaid',
        description:
            'Securely connect your bank accounts. Transactions import automatically — no manual entry needed.',
    },
    {
        icon: <Search className="h-6 w-6" />,
        title: 'Subscription Detective',
        description:
            'Discover recurring charges you forgot about. We find unused subscriptions costing you money.',
    },
    {
        icon: <PiggyBank className="h-6 w-6" />,
        title: 'Savings Recommendations',
        description:
            'AI-powered analysis of your spending patterns with personalized tips to save hundreds per month.',
    },
    {
        icon: <Receipt className="h-6 w-6" />,
        title: 'Tax Deduction Tracker',
        description:
            'Automatically identify tax-deductible business expenses. Export IRS Schedule C reports for your accountant.',
    },
    {
        icon: <Briefcase className="h-6 w-6" />,
        title: 'Business + Personal',
        description:
            'Tag accounts as business or personal. Perfect for freelancers and small business owners.',
    },
];

const steps = [
    {
        icon: <LinkIcon className="h-6 w-6" />,
        title: 'Connect Your Bank',
        description:
            'Link your bank securely through Plaid in under 60 seconds. We never see your credentials.',
    },
    {
        icon: <Sparkles className="h-6 w-6" />,
        title: 'AI Categorizes Everything',
        description:
            'Our AI analyzes each transaction and categorizes it. When uncertain, it asks you — and learns from every answer.',
    },
    {
        icon: <TrendingUp className="h-6 w-6" />,
        title: 'Save Money & Track Taxes',
        description:
            'Get personalized savings recommendations, detect wasteful subscriptions, and export tax-ready reports.',
    },
];

const securityFeatures = [
    {
        icon: <Shield className="h-7 w-7" />,
        title: 'Bank-Level Security',
        description: 'All connections through Plaid with end-to-end encryption.',
    },
    {
        icon: <Lock className="h-7 w-7" />,
        title: 'AES-256 Encryption',
        description: 'Your sensitive data is encrypted at rest using industry-standard AES-256.',
    },
    {
        icon: <KeyRound className="h-7 w-7" />,
        title: 'Two-Factor Auth',
        description: 'Optional TOTP-based two-factor authentication for extra protection.',
    },
];

export default function Welcome() {
    return (
        <PublicLayout
            title="AI Expense Tracker - Free Automatic Categorization & Tax Deductions"
            description="Track expenses automatically with AI. LedgerIQ categorizes transactions, detects unused subscriptions, finds savings, and maps tax deductions to IRS Schedule C. 100% free, forever."
        >
            <JsonLd
                data={{
                    '@context': 'https://schema.org',
                    '@type': 'SoftwareApplication',
                    '@id': 'https://ledgeriq.com/#software',
                    name: 'LedgerIQ',
                    url: 'https://ledgeriq.com',
                    applicationCategory: 'FinanceApplication',
                    operatingSystem: 'Web browser',
                    description:
                        'Free AI-powered expense tracker. Automatically categorizes transactions, detects unused subscriptions, provides savings recommendations, and exports tax deductions to IRS Schedule C.',
                    offers: {
                        '@type': 'Offer',
                        price: '0',
                        priceCurrency: 'USD',
                        availability: 'https://schema.org/InStock',
                        priceValidUntil: '2027-12-31',
                        url: 'https://ledgeriq.com/register',
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
                            reviewBody: 'Finally a free expense tracker that actually works. The AI categorization saves me hours every month and the tax export is perfect for my freelance business.',
                        },
                        {
                            '@type': 'Review',
                            author: { '@type': 'Person', name: 'James K.' },
                            datePublished: '2025-12-03',
                            reviewRating: { '@type': 'Rating', ratingValue: '5', bestRating: '5' },
                            reviewBody: 'Switched from YNAB to LedgerIQ and saved $99/year. The AI subscription detection found $340 in charges I forgot about. Incredible tool.',
                        },
                        {
                            '@type': 'Review',
                            author: { '@type': 'Person', name: 'Maria L.' },
                            datePublished: '2026-01-10',
                            reviewRating: { '@type': 'Rating', ratingValue: '5', bestRating: '5' },
                            reviewBody: 'As a gig worker, tracking deductions was a nightmare. LedgerIQ maps everything to Schedule C automatically. My accountant loves it.',
                        },
                    ],
                    screenshot: {
                        '@type': 'ImageObject',
                        url: 'https://ledgeriq.com/images/ledgeriq-og.png',
                        caption: 'LedgerIQ AI expense tracking dashboard',
                        width: 1200,
                        height: 630,
                    },
                    featureList: [
                        'AI-powered transaction categorization with 85%+ accuracy',
                        'Bank sync via Plaid (SOC 2 Type II certified)',
                        'Automatic subscription detection and tracking',
                        'Personalized AI savings recommendations',
                        'IRS Schedule C tax deduction mapping and export',
                        'Bank statement upload (PDF and CSV parsing)',
                        'Email receipt scanning and matching',
                        'Business and personal expense separation',
                        'Two-factor authentication and AES-256 encryption',
                    ],
                    provider: { '@id': 'https://ledgeriq.com/#organization' },
                }}
            />
            {/* Hero */}
            <HeroSection
                title="Your Finances, Intelligently Managed"
                subtitle="AI-powered expense tracking that automatically categorizes transactions, detects unused subscriptions, finds savings, and prepares your tax deductions. 100% free, forever."
                primaryCTA={{ label: 'Get Started Free', href: '/register' }}
                secondaryCTA={{ label: 'See How It Works', href: '/how-it-works' }}
            >
                <div className="mt-16 flex justify-center">
                    <div className="relative w-full max-w-3xl overflow-hidden rounded-2xl border border-sw-border bg-white shadow-2xl shadow-sw-accent/10">
                        <img
                            src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800&auto=format&fit=crop&q=80"
                            alt="Financial analytics dashboard showing charts and data visualizations"
                            width={800}
                            height={533}
                            loading="eager"
                            className="w-full"
                        />
                    </div>
                </div>
                <div className="mt-12">
                    <TrustBadges />
                </div>
            </HeroSection>

            {/* 100% Free Banner */}
            <section className="bg-sw-accent px-6 py-16">
                <div className="mx-auto max-w-3xl text-center">
                    <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        Completely Free. No Catches. No Premium Tier.
                    </h2>
                    <p className="mt-4 text-lg leading-relaxed text-blue-100">
                        We believe everyone deserves smart money management. LedgerIQ is free for
                        individuals and small business owners — no hidden fees, no paywalls, no
                        upsells.
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

            {/* Features */}
            <section className="px-6 py-20 sm:py-28">
                <SectionHeading
                    overline="FEATURES"
                    title="Everything You Need to Master Your Finances"
                    subtitle="Powerful tools that work together to give you complete visibility and control over your money."
                />
                <div className="mx-auto mt-16 grid max-w-7xl grid-cols-1 gap-8 md:grid-cols-2 lg:grid-cols-3">
                    {features.map((feature) => (
                        <FeatureCard
                            key={feature.title}
                            icon={feature.icon}
                            title={feature.title}
                            description={feature.description}
                        />
                    ))}
                </div>
            </section>

            {/* How It Works */}
            <section className="bg-sw-surface px-6 py-20 sm:py-28">
                <SectionHeading
                    overline="HOW IT WORKS"
                    title="Get Started in Under 5 Minutes"
                    subtitle="Three simple steps to smarter money management."
                />
                <div className="mx-auto mt-16 grid max-w-5xl grid-cols-1 gap-12 lg:grid-cols-3">
                    {steps.map((step, idx) => (
                        <div key={step.title} className="relative text-center">
                            <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-sw-accent text-white shadow-lg shadow-sw-accent/25">
                                <span className="text-xl font-bold">{idx + 1}</span>
                            </div>
                            {idx < steps.length - 1 && (
                                <div className="absolute left-[calc(50%+2.5rem)] top-8 hidden h-px w-[calc(100%-5rem)] bg-sw-border-strong lg:block" />
                            )}
                            <h3 className="text-lg font-semibold text-sw-text">{step.title}</h3>
                            <p className="mt-2 leading-relaxed text-sw-muted">
                                {step.description}
                            </p>
                        </div>
                    ))}
                </div>
            </section>

            {/* Stats */}
            <section className="px-6 py-20">
                <div className="mx-auto max-w-5xl">
                    <StatsCounter
                        items={[
                            { value: '50+', label: 'IRS Tax Categories' },
                            { value: 'AES-256', label: 'Encryption Standard' },
                            { value: 'Real-Time', label: 'Transaction Sync' },
                            { value: '$0/mo', label: 'Forever Free' },
                        ]}
                    />
                </div>
            </section>

            {/* Security */}
            <section className="bg-sw-surface px-6 py-20 sm:py-28">
                <SectionHeading
                    overline="SECURITY"
                    title="Your Data is Protected"
                    subtitle="LedgerIQ uses the same security infrastructure trusted by Venmo, Robinhood, and thousands of financial apps."
                />
                <div className="mx-auto mt-16 grid max-w-5xl grid-cols-1 gap-8 md:grid-cols-3">
                    {securityFeatures.map((feature) => (
                        <div
                            key={feature.title}
                            className="rounded-2xl border border-sw-border bg-white p-8 text-center shadow-sm"
                        >
                            <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-xl bg-sw-accent-light text-sw-accent">
                                {feature.icon}
                            </div>
                            <h3 className="text-lg font-semibold text-sw-text">
                                {feature.title}
                            </h3>
                            <p className="mt-2 text-sm leading-relaxed text-sw-muted">
                                {feature.description}
                            </p>
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

            {/* Final CTA */}
            <CTASection
                headline="Start Managing Your Money Smarter"
                description="Join LedgerIQ today. Free forever, no credit card required. Takes less than 2 minutes to set up."
                buttonText="Get Started Free"
                buttonHref="/register"
            />
        </PublicLayout>
    );
}
