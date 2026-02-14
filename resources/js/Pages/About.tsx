import PublicLayout from '@/Layouts/PublicLayout';
import HeroSection from '@/Components/Marketing/HeroSection';
import CTASection from '@/Components/Marketing/CTASection';
import SectionHeading from '@/Components/Marketing/SectionHeading';
import { Eye, Shield, Heart, Cpu, Building2, Brain } from 'lucide-react';

const values = [
    {
        icon: <Eye className="h-6 w-6" />,
        title: 'Transparency',
        description: 'We believe you should know exactly how your data is used. No hidden terms, no surprise fees, no selling your information.',
    },
    {
        icon: <Shield className="h-6 w-6" />,
        title: 'Security',
        description: 'Your financial data is protected with the same encryption standards used by banks. We never see your bank credentials.',
    },
    {
        icon: <Heart className="h-6 w-6" />,
        title: 'Accessibility',
        description: 'Smart financial tools shouldn\'t cost money. SpendifiAI is 100% free because managing your money well is a right, not a privilege.',
    },
];

const techStack = [
    {
        icon: <Brain className="h-6 w-6" />,
        title: 'AI by Anthropic',
        description: 'Claude AI powers our transaction categorization, savings analysis, and receipt parsing — learning from every interaction.',
    },
    {
        icon: <Building2 className="h-6 w-6" />,
        title: 'Banking by Plaid',
        description: 'Plaid securely connects to 12,000+ financial institutions, ensuring your bank credentials never touch our servers.',
    },
    {
        icon: <Cpu className="h-6 w-6" />,
        title: 'Built for Speed',
        description: 'Modern stack with Laravel, React, and PostgreSQL — delivering fast, reliable performance you can count on.',
    },
];

export default function About() {
    return (
        <PublicLayout
            title="About SpendifiAI - AI-Powered Personal Finance for Everyone"
            description="SpendifiAI is a free AI-powered personal finance platform built for freelancers, small business owners, and individuals. Automatic expense tracking, tax deductions, and savings insights."
            breadcrumbs={[{ name: 'About', url: '/about' }]}
        >
            <HeroSection
                title="Built for People Who Want Smarter Money Management"
                subtitle="SpendifiAI was created with a simple belief: everyone deserves intelligent financial tools, regardless of their budget."
            />

            {/* Mission */}
            <section className="px-6 py-20">
                <div className="mx-auto max-w-3xl text-center">
                    <h2 className="text-3xl font-bold text-sw-text">Our Mission</h2>
                    <p className="mt-6 text-lg leading-relaxed text-sw-muted">
                        We started SpendifiAI because we were frustrated with the state of personal finance tools.
                        Most charge monthly fees for basic features. Others sell your financial data to advertisers.
                        Many require accounting knowledge just to set up.
                    </p>
                    <p className="mt-4 text-lg leading-relaxed text-sw-muted">
                        We built something different: an AI-powered expense tracker that&apos;s genuinely free, genuinely
                        smart, and genuinely respects your privacy. No ads, no data selling, no premium tiers.
                        Just intelligent money management for everyone.
                    </p>
                </div>
            </section>

            {/* Values */}
            <section className="bg-sw-surface px-6 py-20">
                <SectionHeading
                    overline="OUR VALUES"
                    title="What We Stand For"
                />
                <div className="mx-auto mt-12 grid max-w-5xl grid-cols-1 gap-8 md:grid-cols-3">
                    {values.map((value) => (
                        <div key={value.title} className="rounded-2xl border border-sw-border bg-white p-8 shadow-sm">
                            <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-sw-accent-light text-sw-accent">
                                {value.icon}
                            </div>
                            <h3 className="text-lg font-semibold text-sw-text">{value.title}</h3>
                            <p className="mt-2 leading-relaxed text-sw-muted">{value.description}</p>
                        </div>
                    ))}
                </div>
            </section>

            {/* Technology */}
            <section className="px-6 py-20">
                <SectionHeading
                    overline="TECHNOLOGY"
                    title="Powered by the Best"
                    subtitle="We leverage industry-leading technology to deliver the smartest, most secure financial tools possible."
                />
                <div className="mx-auto mt-12 grid max-w-5xl grid-cols-1 gap-8 md:grid-cols-3">
                    {techStack.map((tech) => (
                        <div key={tech.title} className="text-center">
                            <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-sw-accent-light text-sw-accent">
                                {tech.icon}
                            </div>
                            <h3 className="text-lg font-semibold text-sw-text">{tech.title}</h3>
                            <p className="mt-2 text-sm leading-relaxed text-sw-muted">{tech.description}</p>
                        </div>
                    ))}
                </div>
            </section>

            <CTASection
                headline="Join the SpendifiAI Community"
                description="Start managing your finances smarter today. It's free, forever."
                buttonText="Get Started Free"
                buttonHref="/register"
            />
        </PublicLayout>
    );
}
