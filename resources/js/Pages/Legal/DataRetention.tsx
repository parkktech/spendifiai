import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';

const sections = [
    'Overview',
    'What Data We Retain',
    'Data Deletion',
    'How to Request Deletion',
    'What Happens After Deletion',
    'Legal Requirements',
    'Contact',
];

export default function DataRetention() {
    return (
        <PublicLayout
            title="Data Retention Policy"
            description="SpendifiAI data retention policy. How long we keep your financial data, transaction records, AI analysis results, and what happens when you delete your account."
            breadcrumbs={[{ name: 'Legal', url: '/data-retention' }, { name: 'Data Retention', url: '/data-retention' }]}
        >
            <div className="bg-gradient-to-b from-white to-sw-accent-light px-6 py-16">
                <div className="mx-auto max-w-3xl text-center">
                    <h1 className="text-4xl font-bold tracking-tight text-sw-text">Data Retention Policy</h1>
                    <p className="mt-3 text-sw-muted">Last Updated: February 2026</p>
                </div>
            </div>

            <div className="mx-auto max-w-5xl px-6 py-16">
                <div className="flex flex-col gap-12 lg:flex-row">
                    <nav className="shrink-0 lg:sticky lg:top-24 lg:w-56 lg:self-start">
                        <h3 className="mb-3 text-xs font-semibold uppercase tracking-widest text-sw-dim">On this page</h3>
                        <ul className="space-y-2">
                            {sections.map((s, i) => (
                                <li key={i}>
                                    <a href={`#dr-${i + 1}`} className="text-sm text-sw-muted transition-colors hover:text-sw-accent">{s}</a>
                                </li>
                            ))}
                        </ul>
                    </nav>

                    <div className="min-w-0 flex-1 space-y-10">
                        <section id="dr-1">
                            <h2 className="text-2xl font-semibold text-sw-text">1. Overview</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                SpendifiAI is committed to retaining your data only as long as necessary to provide our services. This policy describes what data we retain, for how long, and how you can request deletion. We believe in data minimization — we only keep what we need, and we delete it when it&apos;s no longer required.
                            </p>
                        </section>

                        <section id="dr-2">
                            <h2 className="text-2xl font-semibold text-sw-text">2. What Data We Retain</h2>
                            <div className="mt-4 overflow-hidden rounded-xl border border-sw-border">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-sw-surface text-sw-text-secondary">
                                        <tr>
                                            <th className="px-6 py-3 font-semibold">Data Type</th>
                                            <th className="px-6 py-3 font-semibold">Retention Period</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-sw-border text-sw-muted">
                                        <tr><td className="px-6 py-3">Account data (name, email, profile)</td><td className="px-6 py-3">While account is active</td></tr>
                                        <tr className="bg-sw-surface/50"><td className="px-6 py-3">Transaction data</td><td className="px-6 py-3">While account is active</td></tr>
                                        <tr><td className="px-6 py-3">AI categorization data</td><td className="px-6 py-3">While account is active</td></tr>
                                        <tr className="bg-sw-surface/50"><td className="px-6 py-3">Subscription data</td><td className="px-6 py-3">While account is active</td></tr>
                                        <tr><td className="px-6 py-3">Savings recommendations</td><td className="px-6 py-3">While account is active</td></tr>
                                        <tr className="bg-sw-surface/50"><td className="px-6 py-3">Tax reports</td><td className="px-6 py-3">Current + 1 previous tax year</td></tr>
                                        <tr><td className="px-6 py-3">Email/receipt data</td><td className="px-6 py-3">While account is active & email connected</td></tr>
                                        <tr className="bg-sw-surface/50"><td className="px-6 py-3">Plaid access tokens</td><td className="px-6 py-3">Encrypted; while bank connection is active</td></tr>
                                        <tr><td className="px-6 py-3">Session & auth tokens</td><td className="px-6 py-3">Until logout or expiration</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section id="dr-3">
                            <h2 className="text-2xl font-semibold text-sw-text">3. Data Deletion</h2>
                            <h3 className="mt-4 text-lg font-medium text-sw-text-secondary">Account Deletion</h3>
                            <p className="mt-2 leading-relaxed text-sw-muted">
                                When you delete your account, <strong>all personal data is permanently deleted within 30 days</strong>. This includes your profile, transactions, categorizations, subscriptions, savings data, tax reports, and any connected service tokens.
                            </p>

                            <h3 className="mt-6 text-lg font-medium text-sw-text-secondary">Bank Disconnection</h3>
                            <p className="mt-2 leading-relaxed text-sw-muted">
                                When you disconnect a bank account, the Plaid access token is <strong>immediately revoked and deleted</strong>. Existing transaction data from that account remains available until you delete your account.
                            </p>

                            <h3 className="mt-6 text-lg font-medium text-sw-text-secondary">Email Disconnection</h3>
                            <p className="mt-2 leading-relaxed text-sw-muted">
                                When you disconnect your email, OAuth tokens are <strong>immediately revoked</strong> and parsed email data is deleted.
                            </p>

                            <h3 className="mt-6 text-lg font-medium text-sw-text-secondary">Partial Deletion</h3>
                            <p className="mt-2 leading-relaxed text-sw-muted">
                                You can disconnect individual bank accounts or email connections without deleting your entire account.
                            </p>
                        </section>

                        <section id="dr-4">
                            <h2 className="text-2xl font-semibold text-sw-text">4. How to Request Deletion</h2>
                            <div className="mt-4 space-y-4">
                                <div className="rounded-xl border border-sw-border bg-white p-5">
                                    <h4 className="font-semibold text-sw-text">In-App</h4>
                                    <p className="mt-1 text-sm text-sw-muted">Settings &gt; Danger Zone &gt; Delete Account</p>
                                </div>
                                <div className="rounded-xl border border-sw-border bg-white p-5">
                                    <h4 className="font-semibold text-sw-text">Via Email</h4>
                                    <p className="mt-1 text-sm text-sw-muted">Send a request to <span className="font-medium text-sw-accent">privacy@spendifiai.com</span> from the email associated with your account.</p>
                                </div>
                            </div>
                        </section>

                        <section id="dr-5">
                            <h2 className="text-2xl font-semibold text-sw-text">5. What Happens After Deletion</h2>
                            <ul className="mt-4 list-disc space-y-2 pl-6 text-sw-muted">
                                <li>All personal data is permanently removed from our production databases within <strong>30 days</strong></li>
                                <li>Plaid access tokens are revoked immediately</li>
                                <li>Email OAuth tokens are revoked immediately</li>
                                <li>Database backups containing your data are purged within <strong>90 days</strong></li>
                                <li>Anonymized, aggregated analytics data (which cannot identify you) may be retained</li>
                            </ul>
                        </section>

                        <section id="dr-6">
                            <h2 className="text-2xl font-semibold text-sw-text">6. Legal Requirements</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                In certain circumstances, we may be required to retain data longer than specified in this policy to comply with applicable laws, regulations, court orders, or legal processes. In such cases, we will retain only the minimum data required and will delete it once the legal obligation has been fulfilled.
                            </p>
                        </section>

                        <section id="dr-7">
                            <h2 className="text-2xl font-semibold text-sw-text">7. Contact</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                For questions about data retention or to request deletion, contact:
                            </p>
                            <p className="mt-2 font-medium text-sw-accent">privacy@spendifiai.com</p>
                        </section>

                        <nav className="flex flex-wrap items-center gap-3 border-t border-sw-border pt-8 text-sm">
                            <span className="text-sw-dim">Legal pages:</span>
                            <Link href="/privacy" className="text-sw-accent hover:underline">Privacy Policy</Link>
                            <span className="text-sw-dim">|</span>
                            <Link href="/terms" className="text-sw-accent hover:underline">Terms of Service</Link>
                            <span className="text-sw-dim">|</span>
                            <span className="font-medium text-sw-text">Data Retention</span>
                            <span className="text-sw-dim">|</span>
                            <Link href="/security-policy" className="text-sw-accent hover:underline">Security Policy</Link>
                        </nav>
                    </div>
                </div>
            </div>
        </PublicLayout>
    );
}
