import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import HeroSection from '@/Components/Marketing/HeroSection';
import FAQAccordion from '@/Components/Marketing/FAQAccordion';
import JsonLd from '@/Components/JsonLd';
import { ArrowRight } from 'lucide-react';

const faqGroups = [
    {
        title: 'General',
        items: [
            {
                question: 'What is LedgerIQ?',
                answer: 'LedgerIQ is an AI-powered expense tracking platform that automatically categorizes your transactions, detects unused subscriptions, provides personalized savings recommendations, and generates tax-ready reports. It connects to your bank accounts through Plaid and uses Claude AI for intelligent categorization.',
            },
            {
                question: 'Is LedgerIQ really free?',
                answer: 'Yes, 100% free. There are no premium tiers, no trial periods, no hidden fees, and no credit card required. We believe smart financial tools should be accessible to everyone.',
            },
            {
                question: 'Who is LedgerIQ for?',
                answer: 'LedgerIQ is designed for individuals, freelancers, and small business owners who want to track expenses, find savings, and prepare for tax season. It\'s especially useful if you need to separate business and personal expenses.',
            },
            {
                question: 'How does LedgerIQ make money?',
                answer: 'LedgerIQ is currently offered as a free service. We may introduce optional premium features in the future, but the core functionality will always remain free.',
            },
        ],
    },
    {
        title: 'Security',
        items: [
            {
                question: 'How is my data protected?',
                answer: 'All sensitive data is encrypted at rest using AES-256-CBC encryption. Passwords are hashed with bcrypt. All connections use HTTPS with TLS 1.2+. We also offer optional two-factor authentication for additional account security.',
            },
            {
                question: 'Does LedgerIQ store my bank credentials?',
                answer: 'No. Bank connections are handled entirely by Plaid, a SOC 2 Type II certified financial data platform. Your bank username and password go directly to Plaid — LedgerIQ never sees or stores them.',
            },
            {
                question: 'What is Plaid?',
                answer: 'Plaid is a financial technology company that provides the secure connection between LedgerIQ and your bank. It\'s the same service trusted by Venmo, Robinhood, Coinbase, and thousands of other financial apps. Plaid is SOC 2 Type II certified.',
            },
            {
                question: 'Can I delete my data?',
                answer: 'Yes. You can delete your entire account and all associated data from the Settings page. You can also disconnect individual bank accounts at any time. All data is permanently removed within 30 days of deletion. See our Data Retention Policy for details.',
            },
        ],
    },
    {
        title: 'Features',
        items: [
            {
                question: 'How does AI categorization work?',
                answer: 'When transactions are imported, our AI (Claude by Anthropic) analyzes each one and assigns a category with a confidence score. Transactions with 85%+ confidence are auto-categorized. Lower confidence transactions generate questions for you to answer, and the AI learns from your responses.',
            },
            {
                question: 'Can I separate business and personal expenses?',
                answer: 'Absolutely. You can tag each bank account as personal, business, mixed, or investment. This purpose cascades to all transactions in that account and is the strongest signal for AI categorization accuracy.',
            },
            {
                question: 'What tax export formats are available?',
                answer: 'LedgerIQ generates a comprehensive tax package including an Excel workbook (5 tabs), a PDF cover sheet, and a CSV file. All expenses are mapped to IRS Schedule C categories. You can download the package or email it directly to your accountant.',
            },
            {
                question: 'How does subscription detection work?',
                answer: 'Our algorithms scan your transaction history for recurring charge patterns — same merchant, similar amounts, regular intervals. We flag subscriptions and calculate your annual cost. We also identify subscriptions that appear unused based on engagement patterns.',
            },
        ],
    },
    {
        title: 'Account',
        items: [
            {
                question: 'How do I delete my account?',
                answer: 'Go to Settings and scroll to the Danger Zone section. Click "Delete Account" and confirm with your password. All your data will be permanently deleted within 30 days.',
            },
            {
                question: 'Can I export my data?',
                answer: 'Yes. The Tax Center provides full export functionality (Excel, PDF, CSV). For transaction data, you can export through the tax package which includes all categorized transactions.',
            },
            {
                question: 'What happens to my data if I leave?',
                answer: 'When you delete your account, all personal data is permanently removed from our databases within 30 days. Plaid access tokens are immediately revoked. Backups containing your data are purged within 90 days. See our Data Retention Policy for complete details.',
            },
            {
                question: 'Can I connect multiple bank accounts?',
                answer: 'Yes. You can connect multiple accounts from multiple banks. Each account can be independently tagged as personal, business, mixed, or investment for accurate categorization.',
            },
        ],
    },
];

// Build FAQPage JSON-LD from all Q&A items
const allFaqItems = faqGroups.flatMap((g) => g.items);
const faqSchema = {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: allFaqItems.map((item) => ({
        '@type': 'Question',
        name: item.question,
        acceptedAnswer: {
            '@type': 'Answer',
            text: item.answer,
        },
    })),
};

const softwareSchema = {
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    name: 'LedgerIQ',
    applicationCategory: 'FinanceApplication',
    operatingSystem: 'Web browser',
    offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' },
    description: 'Free AI-powered expense tracker with automatic categorization, subscription detection, savings recommendations, and IRS Schedule C tax export.',
    featureList: [
        'AI-powered transaction categorization',
        'Plaid bank sync',
        'Subscription detection',
        'Savings recommendations',
        'IRS Schedule C tax export',
        'Bank statement upload (PDF/CSV)',
        'Email receipt parsing',
        'Two-factor authentication',
    ],
};

export default function FAQ() {
    return (
        <PublicLayout
            title="FAQ - AI Expense Tracking Questions Answered"
            description="Answers to common questions about LedgerIQ: security, AI categorization accuracy, bank connections, tax exports, Plaid integration, subscription detection, and account management."
            breadcrumbs={[{ name: 'FAQ', url: '/faq' }]}
        >
            <JsonLd data={faqSchema} />
            <JsonLd data={softwareSchema} />
            <HeroSection
                title="Frequently Asked Questions"
                subtitle="Find answers to common questions about LedgerIQ, security, features, and your account."
            />

            <section className="px-6 py-20">
                <div className="mx-auto max-w-3xl space-y-12">
                    {faqGroups.map((group) => (
                        <div key={group.title}>
                            <h2 className="mb-6 text-xl font-bold text-sw-text">{group.title}</h2>
                            <FAQAccordion items={group.items} />
                        </div>
                    ))}
                </div>
            </section>

            <section className="bg-sw-surface px-6 py-16">
                <div className="mx-auto max-w-2xl text-center">
                    <h2 className="text-2xl font-bold text-sw-text">Still have questions?</h2>
                    <p className="mt-3 text-sw-muted">We&apos;d love to hear from you.</p>
                    <Link
                        href="/contact"
                        className="mt-6 inline-flex items-center gap-2 rounded-xl bg-sw-accent px-6 py-3 text-sm font-semibold text-white shadow-sm transition-all hover:bg-sw-accent-hover"
                    >
                        Contact Us
                        <ArrowRight className="h-4 w-4" />
                    </Link>
                </div>
            </section>
        </PublicLayout>
    );
}
