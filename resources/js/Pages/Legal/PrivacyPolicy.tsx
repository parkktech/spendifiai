import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';

const sections = [
    'Introduction',
    'Information We Collect',
    'How We Use Your Information',
    'Third-Party Services',
    'Data Sharing',
    'Data Security',
    'Your Rights',
    'Data Retention',
    'Cookies',
    'Changes to This Policy',
    'Contact Us',
];

export default function PrivacyPolicy() {
    return (
        <PublicLayout
            title="Privacy Policy"
            description="LedgerIQ privacy policy. Learn how we collect, use, and protect your personal and financial data. Plaid integration disclosures, data retention, and your rights explained."
            breadcrumbs={[{ name: 'Legal', url: '/privacy' }, { name: 'Privacy Policy', url: '/privacy' }]}
        >
            <div className="bg-gradient-to-b from-white to-sw-accent-light px-6 py-16">
                <div className="mx-auto max-w-3xl text-center">
                    <h1 className="text-4xl font-bold tracking-tight text-sw-text">Privacy Policy</h1>
                    <p className="mt-3 text-sw-muted">Last Updated: February 2026</p>
                </div>
            </div>

            <div className="mx-auto max-w-5xl px-6 py-16">
                <div className="flex flex-col gap-12 lg:flex-row">
                    {/* Table of Contents */}
                    <nav className="shrink-0 lg:sticky lg:top-24 lg:w-56 lg:self-start">
                        <h3 className="mb-3 text-xs font-semibold uppercase tracking-widest text-sw-dim">On this page</h3>
                        <ul className="space-y-2">
                            {sections.map((s, i) => (
                                <li key={i}>
                                    <a href={`#section-${i + 1}`} className="text-sm text-sw-muted transition-colors hover:text-sw-accent">
                                        {s}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </nav>

                    {/* Content */}
                    <div className="min-w-0 flex-1 space-y-10">
                        <section id="section-1">
                            <h2 className="text-2xl font-semibold text-sw-text">1. Introduction</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                LedgerIQ (&quot;we,&quot; &quot;us,&quot; or &quot;our&quot;) provides an AI-powered financial tracking platform that helps individuals and small businesses manage expenses, track subscriptions, find savings, and prepare tax reports. This Privacy Policy explains how we collect, use, store, and protect your personal information when you use our services.
                            </p>
                            <p className="mt-3 leading-relaxed text-sw-muted">
                                By using LedgerIQ, you consent to the data practices described in this policy. If you do not agree with this policy, please do not use our services.
                            </p>
                        </section>

                        <section id="section-2">
                            <h2 className="text-2xl font-semibold text-sw-text">2. Information We Collect</h2>
                            <h3 className="mt-4 text-lg font-medium text-sw-text-secondary">Account Information</h3>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li>Name, email address, and password</li>
                                <li>Google account information (if you use Google OAuth)</li>
                                <li>Two-factor authentication setup data</li>
                            </ul>

                            <h3 className="mt-6 text-lg font-medium text-sw-text-secondary">Financial Data via Plaid</h3>
                            <p className="mt-2 leading-relaxed text-sw-muted">
                                When you connect your bank accounts through <strong>Plaid Inc.</strong>, we receive the following data:
                            </p>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li>Account balances and account type information</li>
                                <li>Transaction history (merchant name, amount, date, category)</li>
                                <li>Account and routing numbers (used for identification purposes only)</li>
                            </ul>
                            <p className="mt-2 leading-relaxed text-sw-muted">
                                Your bank login credentials are provided directly to Plaid and are never seen or stored by LedgerIQ.
                            </p>

                            <h3 className="mt-6 text-lg font-medium text-sw-text-secondary">Financial Profile Information</h3>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li>Employment type and tax filing status</li>
                                <li>Monthly income range</li>
                                <li>Business type (if applicable)</li>
                            </ul>

                            <h3 className="mt-6 text-lg font-medium text-sw-text-secondary">Email Data</h3>
                            <p className="mt-2 leading-relaxed text-sw-muted">
                                If you connect your Gmail account, we access only order confirmation and receipt emails for the purpose of matching purchases to bank transactions. We do not read or store other email content.
                            </p>

                            <h3 className="mt-6 text-lg font-medium text-sw-text-secondary">Usage Data</h3>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li>Pages visited and features used within LedgerIQ</li>
                                <li>Device type and browser information</li>
                            </ul>
                        </section>

                        <section id="section-3">
                            <h2 className="text-2xl font-semibold text-sw-text">3. How We Use Your Information</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">We use the information we collect to:</p>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li>Provide AI-powered transaction categorization</li>
                                <li>Detect recurring subscriptions and identify unused services</li>
                                <li>Generate personalized savings recommendations</li>
                                <li>Track tax-deductible expenses and generate tax reports</li>
                                <li>Match email receipts to bank transactions</li>
                                <li>Authenticate your identity and secure your account</li>
                                <li>Improve our AI models and overall service quality</li>
                            </ul>
                        </section>

                        <section id="section-4">
                            <h2 className="text-2xl font-semibold text-sw-text">4. Third-Party Services</h2>

                            <div className="mt-4 rounded-xl border border-sw-border bg-sw-accent-light p-6">
                                <h3 className="text-lg font-semibold text-sw-text">Plaid</h3>
                                <p className="mt-2 leading-relaxed text-sw-muted">
                                    We use <strong>Plaid Inc.</strong> to connect your bank accounts and retrieve financial data. When you connect a bank account, Plaid collects and shares your financial information with us in accordance with their policies. Plaid&apos;s handling of your data is governed by the{' '}
                                    <a
                                        href="https://plaid.com/legal/#end-user-privacy-policy"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="font-medium text-sw-accent underline"
                                    >
                                        Plaid End User Privacy Policy
                                    </a>.
                                </p>
                            </div>

                            <div className="mt-4 rounded-xl border border-sw-border bg-white p-6">
                                <h3 className="text-lg font-semibold text-sw-text">Anthropic (Claude AI)</h3>
                                <p className="mt-2 leading-relaxed text-sw-muted">
                                    Transaction descriptions (merchant names and amounts) are sent to Anthropic&apos;s Claude AI for categorization and analysis. We do <strong>not</strong> send account numbers, balances, or personally identifying information to Anthropic. Per Anthropic&apos;s API terms, your data is not used to train their models.
                                </p>
                            </div>

                            <div className="mt-4 rounded-xl border border-sw-border bg-white p-6">
                                <h3 className="text-lg font-semibold text-sw-text">Google</h3>
                                <p className="mt-2 leading-relaxed text-sw-muted">
                                    If you use Google OAuth to sign in, Google shares your name and email address with us. If you connect your Gmail account for receipt parsing, we access only order confirmation and receipt emails through Google&apos;s OAuth system.
                                </p>
                            </div>
                        </section>

                        <section id="section-5">
                            <h2 className="text-2xl font-semibold text-sw-text">5. Data Sharing</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                <strong>We do not sell your personal information.</strong> We share data only with:
                            </p>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li><strong>Plaid</strong> — to establish and maintain bank connections</li>
                                <li><strong>Anthropic</strong> — anonymized transaction descriptions for AI categorization only</li>
                                <li><strong>Google</strong> — if you use Google OAuth login or Gmail integration</li>
                                <li><strong>Law enforcement</strong> — only when required by applicable law, court order, or legal process</li>
                            </ul>
                        </section>

                        <section id="section-6">
                            <h2 className="text-2xl font-semibold text-sw-text">6. Data Security</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">We implement robust security measures to protect your data:</p>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li><strong>AES-256 encryption</strong> for all sensitive data at rest</li>
                                <li><strong>Bcrypt hashing</strong> for passwords (never stored in plaintext)</li>
                                <li><strong>HTTPS with TLS 1.2+</strong> for all data in transit</li>
                                <li>Optional <strong>two-factor authentication</strong> (TOTP)</li>
                                <li>API rate limiting and CSRF protection</li>
                                <li>Account lockout after failed login attempts</li>
                            </ul>
                            <p className="mt-3 leading-relaxed text-sw-muted">
                                For complete details, see our <Link href="/security-policy" className="font-medium text-sw-accent hover:underline">Security Policy</Link>.
                            </p>
                        </section>

                        <section id="section-7">
                            <h2 className="text-2xl font-semibold text-sw-text">7. Your Rights</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">You have the right to:</p>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li><strong>Access</strong> your personal data stored in LedgerIQ</li>
                                <li><strong>Export</strong> your financial data via tax reports (Excel, PDF, CSV)</li>
                                <li><strong>Request deletion</strong> of your account and all associated data</li>
                                <li><strong>Disconnect</strong> bank accounts at any time, immediately revoking Plaid access</li>
                                <li><strong>Revoke</strong> email access at any time</li>
                                <li><strong>Disable</strong> two-factor authentication</li>
                            </ul>
                            <p className="mt-3 leading-relaxed text-sw-muted">
                                To exercise your deletion rights, visit Settings &gt; Delete Account, or email us at <span className="font-medium text-sw-accent">privacy@ledgeriq.com</span>.
                            </p>
                        </section>

                        <section id="section-8">
                            <h2 className="text-2xl font-semibold text-sw-text">8. Data Retention</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                We retain your data only while your account is active. Upon account deletion, all personal data is permanently removed within 30 days. For complete details on retention periods and deletion procedures, see our{' '}
                                <Link href="/data-retention" className="font-medium text-sw-accent hover:underline">Data Retention Policy</Link>.
                            </p>
                        </section>

                        <section id="section-9">
                            <h2 className="text-2xl font-semibold text-sw-text">9. Cookies</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                LedgerIQ uses <strong>essential cookies only</strong> for session management and CSRF protection. We do not use advertising cookies, tracking cookies, or third-party analytics cookies.
                            </p>
                        </section>

                        <section id="section-10">
                            <h2 className="text-2xl font-semibold text-sw-text">10. Changes to This Policy</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                We may update this Privacy Policy from time to time. We will notify you of material changes via email or in-app notification. Your continued use of LedgerIQ after changes constitutes acceptance of the updated policy.
                            </p>
                        </section>

                        <section id="section-11">
                            <h2 className="text-2xl font-semibold text-sw-text">11. Contact Us</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                If you have questions about this Privacy Policy or our data practices, contact us at:
                            </p>
                            <p className="mt-2 font-medium text-sw-accent">privacy@ledgeriq.com</p>
                        </section>

                        {/* Navigation */}
                        <nav className="flex flex-wrap items-center gap-3 border-t border-sw-border pt-8 text-sm">
                            <span className="text-sw-dim">Legal pages:</span>
                            <span className="font-medium text-sw-text">Privacy Policy</span>
                            <span className="text-sw-dim">|</span>
                            <Link href="/terms" className="text-sw-accent hover:underline">Terms of Service</Link>
                            <span className="text-sw-dim">|</span>
                            <Link href="/data-retention" className="text-sw-accent hover:underline">Data Retention</Link>
                            <span className="text-sw-dim">|</span>
                            <Link href="/security-policy" className="text-sw-accent hover:underline">Security Policy</Link>
                        </nav>
                    </div>
                </div>
            </div>
        </PublicLayout>
    );
}
