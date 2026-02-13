import PublicLayout from '@/Layouts/PublicLayout';
import HeroSection from '@/Components/Marketing/HeroSection';
import CTASection from '@/Components/Marketing/CTASection';
import SectionHeading from '@/Components/Marketing/SectionHeading';
import JsonLd from '@/Components/JsonLd';
import {
    UserPlus,
    Building2,
    Settings2,
    Sparkles,
    BarChart3,
    CheckCircle2,
} from 'lucide-react';
import { ReactNode } from 'react';

interface StepProps {
    number: number;
    icon: ReactNode;
    title: string;
    description: string;
    details: string[];
}

function Step({ number, icon, title, description, details }: StepProps) {
    return (
        <div className="flex gap-6">
            <div className="flex shrink-0 flex-col items-center">
                <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-sw-accent text-white shadow-lg shadow-sw-accent/25">
                    <span className="text-lg font-bold">{number}</span>
                </div>
                <div className="mt-4 h-full w-px bg-sw-border" />
            </div>
            <div className="pb-16">
                <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-sw-accent-light text-sw-accent">
                    {icon}
                </div>
                <h3 className="text-xl font-bold text-sw-text">{title}</h3>
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

const steps: StepProps[] = [
    {
        number: 1,
        icon: <UserPlus className="h-5 w-5" />,
        title: 'Create Your Free Account',
        description: 'Sign up with email or Google OAuth in seconds. No credit card, no payment information, no trial period.',
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
            'Takes under 60 seconds to complete',
        ],
    },
    {
        number: 3,
        icon: <Settings2 className="h-5 w-5" />,
        title: 'Set Account Purposes',
        description: 'Mark each account as personal, business, or mixed. This is the strongest signal for AI categorization accuracy.',
        details: [
            'Personal, business, mixed, or investment',
            'Purpose cascades to all transactions',
            'Change anytime as your needs evolve',
        ],
    },
    {
        number: 4,
        icon: <Sparkles className="h-5 w-5" />,
        title: 'AI Categorizes Everything',
        description: 'Our AI processes your transactions, categorizing each with a confidence score. High confidence? Auto-categorized. Low confidence? You get a question.',
        details: [
            'Automatic categorization at 85%+ confidence',
            'Multiple-choice questions at 40-59% confidence',
            'AI learns from every correction you make',
        ],
    },
    {
        number: 5,
        icon: <BarChart3 className="h-5 w-5" />,
        title: 'Track, Save, and Export',
        description: 'View spending insights, get savings recommendations, detect unused subscriptions, and export tax-ready reports.',
        details: [
            'Real-time spending trends and category breakdowns',
            'AI-powered savings tips with difficulty ratings',
            'IRS Schedule C tax export (Excel, PDF, CSV)',
            'Email reports directly to your accountant',
        ],
    },
];

export default function HowItWorks() {
    return (
        <PublicLayout
            title="How It Works - Set Up AI Expense Tracking in 5 Minutes"
            description="Get started with LedgerIQ in under 5 minutes. Create an account, connect your bank via Plaid or upload statements, and let AI categorize your transactions automatically."
            breadcrumbs={[{ name: 'How It Works', url: '/how-it-works' }]}
        >
            <JsonLd
                data={{
                    '@context': 'https://schema.org',
                    '@type': 'HowTo',
                    name: 'How to Set Up AI Expense Tracking with LedgerIQ',
                    description: 'Get started with free AI-powered expense tracking in under 5 minutes. Connect your bank, let AI categorize transactions, and export tax deductions.',
                    totalTime: 'PT5M',
                    tool: { '@type': 'SoftwareApplication', '@id': 'https://ledgeriq.com/#software' },
                    step: [
                        {
                            '@type': 'HowToStep',
                            position: 1,
                            name: 'Create Your Free Account',
                            text: 'Sign up with email or Google OAuth. No credit card or payment information required.',
                            url: 'https://ledgeriq.com/register',
                        },
                        {
                            '@type': 'HowToStep',
                            position: 2,
                            name: 'Connect Your Bank',
                            text: 'Link your bank accounts securely through Plaid, or upload PDF/CSV bank statements manually.',
                            url: 'https://ledgeriq.com/how-it-works',
                        },
                        {
                            '@type': 'HowToStep',
                            position: 3,
                            name: 'Set Account Purposes',
                            text: 'Mark each account as personal, business, or mixed. This helps AI accurately categorize transactions.',
                            url: 'https://ledgeriq.com/how-it-works',
                        },
                        {
                            '@type': 'HowToStep',
                            position: 4,
                            name: 'AI Categorizes Everything',
                            text: 'Claude AI processes your transactions, auto-categorizing at 85%+ confidence. Lower confidence items generate questions for you to answer.',
                            url: 'https://ledgeriq.com/how-it-works',
                        },
                        {
                            '@type': 'HowToStep',
                            position: 5,
                            name: 'Track, Save, and Export Taxes',
                            text: 'View spending insights, get AI savings recommendations, detect unused subscriptions, and export IRS Schedule C tax-ready reports.',
                            url: 'https://ledgeriq.com/how-it-works',
                        },
                    ],
                }}
            />
            <HeroSection
                title="Get Set Up in Under 5 Minutes"
                subtitle="From signup to AI-powered insights in five simple steps. No learning curve, no complex configuration."
                primaryCTA={{ label: 'Get Started Free', href: '/register' }}
            />

            <section className="px-6 py-20 sm:py-28">
                <div className="mx-auto max-w-3xl">
                    {steps.map((step) => (
                        <Step key={step.number} {...step} />
                    ))}
                </div>
            </section>

            {/* What Makes Us Different */}
            <section className="bg-sw-surface px-6 py-20">
                <SectionHeading
                    overline="WHY LEDGERIQ"
                    title="What Makes LedgerIQ Different"
                />
                <div className="mx-auto mt-12 grid max-w-5xl grid-cols-1 gap-8 md:grid-cols-3">
                    <div className="rounded-2xl border border-sw-border bg-white p-8 text-center shadow-sm">
                        <div className="text-3xl font-bold text-sw-accent">$0</div>
                        <div className="mt-2 text-sm font-medium text-sw-muted">Completely free — no premium tiers or hidden fees</div>
                    </div>
                    <div className="rounded-2xl border border-sw-border bg-white p-8 text-center shadow-sm">
                        <div className="text-3xl font-bold text-sw-accent">AI</div>
                        <div className="mt-2 text-sm font-medium text-sw-muted">Claude-powered categorization that learns from you</div>
                    </div>
                    <div className="rounded-2xl border border-sw-border bg-white p-8 text-center shadow-sm">
                        <div className="text-3xl font-bold text-sw-accent">IRS</div>
                        <div className="mt-2 text-sm font-medium text-sw-muted">Schedule C mapped categories for tax-ready exports</div>
                    </div>
                </div>
            </section>

            <CTASection
                headline="Ready to Get Started?"
                description="Create your free account and connect your first bank in under 5 minutes."
                buttonText="Get Started Free"
                buttonHref="/register"
            />
        </PublicLayout>
    );
}
