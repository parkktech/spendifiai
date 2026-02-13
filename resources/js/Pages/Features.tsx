import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import HeroSection from '@/Components/Marketing/HeroSection';
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
    CheckCircle2,
    Mail,
} from 'lucide-react';
import { ReactNode } from 'react';

interface FeatureDetailProps {
    icon: ReactNode;
    title: string;
    description: string;
    bullets: string[];
    reversed?: boolean;
    image: string;
    imageAlt: string;
}

function FeatureDetail({ icon, title, description, bullets, reversed, image, imageAlt }: FeatureDetailProps) {
    return (
        <div className={`flex flex-col items-center gap-12 lg:flex-row ${reversed ? 'lg:flex-row-reverse' : ''}`}>
            <div className="flex-1 space-y-6">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-sw-accent-light text-sw-accent">
                    {icon}
                </div>
                <h2 className="text-2xl font-bold text-sw-text">{title}</h2>
                <p className="text-lg leading-relaxed text-sw-muted">{description}</p>
                <ul className="space-y-3">
                    {bullets.map((bullet) => (
                        <li key={bullet} className="flex items-start gap-3 text-sw-muted">
                            <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-sw-success" />
                            <span>{bullet}</span>
                        </li>
                    ))}
                </ul>
            </div>
            <div className="flex-1">
                <div className="overflow-hidden rounded-2xl border border-sw-border shadow-lg">
                    <img
                        src={image}
                        alt={imageAlt}
                        width={800}
                        height={533}
                        loading="lazy"
                        className="w-full"
                    />
                </div>
            </div>
        </div>
    );
}

const featureDetails: FeatureDetailProps[] = [
    {
        icon: <Brain className="h-6 w-6" />,
        title: 'AI-Powered Categorization',
        description: 'Claude AI analyzes every transaction and categorizes it with confidence scoring. When it\'s unsure, it asks you — and learns from every correction.',
        bullets: [
            'Automatic categorization with 85%+ confidence',
            'Smart questions when AI is uncertain',
            'Learns from your corrections over time',
            '50+ IRS-mapped expense categories',
        ],
        image: 'https://images.unsplash.com/photo-1677442136019-21780ecad995?w=600&auto=format&fit=crop&q=80',
        imageAlt: 'AI-powered data analysis visualization',
    },
    {
        icon: <Building2 className="h-6 w-6" />,
        title: 'Secure Bank Integration',
        description: 'Connect all your bank accounts through Plaid\'s industry-leading infrastructure. Your credentials never touch our servers.',
        bullets: [
            'Plaid-secured bank connections (SOC 2 Type II)',
            'Real-time transaction syncing',
            'Multi-account and multi-bank support',
            'Disconnect anytime with one click',
        ],
        reversed: true,
        image: 'https://images.unsplash.com/photo-1563986768609-322da13575f2?w=600&auto=format&fit=crop&q=80',
        imageAlt: 'Secure banking interface on mobile device',
    },
    {
        icon: <Search className="h-6 w-6" />,
        title: 'Subscription Detective',
        description: 'Our algorithms scan your transaction patterns to find recurring charges — especially ones you may have forgotten about.',
        bullets: [
            'Automatic recurring charge detection',
            'Unused subscription flagging',
            'Annual cost calculations',
            'Easy cancel tracking',
        ],
        image: 'https://images.unsplash.com/photo-1554224155-6726b3ff858f?w=600&auto=format&fit=crop&q=80',
        imageAlt: 'Financial data analysis on screen',
    },
    {
        icon: <PiggyBank className="h-6 w-6" />,
        title: 'Smart Savings Engine',
        description: 'AI analyzes your 90-day spending history and generates personalized savings recommendations with actionable steps.',
        bullets: [
            'Personalized savings recommendations',
            'Difficulty-rated action plans',
            'Savings goal tracking with progress bars',
            'Weekly pulse checks on your progress',
        ],
        reversed: true,
        image: 'https://images.unsplash.com/photo-1579621970563-ebec7560ff3e?w=600&auto=format&fit=crop&q=80',
        imageAlt: 'Piggy bank savings concept',
    },
    {
        icon: <Receipt className="h-6 w-6" />,
        title: 'Tax Deduction Tracker',
        description: 'Automatically maps your business expenses to IRS Schedule C categories. Export tax-ready reports for your accountant in seconds.',
        bullets: [
            'IRS Schedule C category mapping',
            'Excel, PDF, and CSV export formats',
            'Email reports directly to your accountant',
            'Current and previous tax year support',
        ],
        image: 'https://images.unsplash.com/photo-1554224154-26032ffc0d07?w=600&auto=format&fit=crop&q=80',
        imageAlt: 'Tax documents and calculator',
    },
    {
        icon: <Mail className="h-6 w-6" />,
        title: 'Email Receipt Parsing',
        description: 'Connect your Gmail to automatically parse order confirmations and receipts, matching them to bank transactions for complete purchase tracking.',
        bullets: [
            'Gmail integration for receipts',
            'AI-powered receipt data extraction',
            'Automatic bank transaction matching',
            'Product-level expense tracking',
        ],
        reversed: true,
        image: 'https://images.unsplash.com/photo-1596526131083-e8c633c948d2?w=600&auto=format&fit=crop&q=80',
        imageAlt: 'Email inbox on laptop',
    },
];

export default function Features() {
    return (
        <PublicLayout
            title="AI Expense Tracking Features - Bank Sync & Tax Export"
            description="LedgerIQ features: AI categorization, Plaid bank sync, subscription detection, savings tips, Schedule C tax export, and receipt parsing. All free."
            breadcrumbs={[{ name: 'Features', url: '/features' }]}
        >
            <JsonLd
                data={{
                    '@context': 'https://schema.org',
                    '@type': 'ItemList',
                    name: 'LedgerIQ Features',
                    description: 'Complete list of AI expense tracking features available for free',
                    numberOfItems: 9,
                    itemListElement: [
                        { '@type': 'ListItem', position: 1, name: 'AI Transaction Categorization', description: 'Claude AI automatically categorizes transactions with 85%+ accuracy' },
                        { '@type': 'ListItem', position: 2, name: 'Plaid Bank Sync', description: 'Connect 12,000+ banks securely via Plaid SOC 2 Type II' },
                        { '@type': 'ListItem', position: 3, name: 'Bank Statement Upload', description: 'Upload PDF or CSV statements when Plaid is unavailable' },
                        { '@type': 'ListItem', position: 4, name: 'Subscription Detection', description: 'Find recurring charges and unused subscriptions automatically' },
                        { '@type': 'ListItem', position: 5, name: 'AI Savings Recommendations', description: 'Personalized tips to save money based on spending analysis' },
                        { '@type': 'ListItem', position: 6, name: 'Tax Deduction Export', description: 'IRS Schedule C mapped reports in Excel, PDF, and CSV' },
                        { '@type': 'ListItem', position: 7, name: 'Email Receipt Matching', description: 'Gmail and IMAP integration for automatic receipt matching' },
                        { '@type': 'ListItem', position: 8, name: 'Business/Personal Split', description: 'Tag accounts as business, personal, mixed, or investment' },
                        { '@type': 'ListItem', position: 9, name: 'Budget Dashboard', description: 'Waterfall charts, monthly bills, home affordability, where to cut' },
                    ],
                }}
            />
            <HeroSection
                title="Powerful Features, Zero Cost"
                subtitle="Everything you need to track expenses, find savings, and prepare for tax season — all powered by AI and completely free."
                primaryCTA={{ label: 'Get Started Free', href: '/register' }}
            />

            <section className="px-6 py-20">
                <div className="mx-auto max-w-6xl space-y-28">
                    {featureDetails.map((feature) => (
                        <FeatureDetail key={feature.title} {...feature} />
                    ))}
                </div>
            </section>

            <CTASection
                headline="Ready to Take Control of Your Finances?"
                description="Create your free account and start tracking smarter today."
                buttonText="Get Started Free"
                buttonHref="/register"
            />
        </PublicLayout>
    );
}
