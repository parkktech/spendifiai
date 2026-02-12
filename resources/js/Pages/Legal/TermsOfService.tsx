import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';

const sections = [
    'Acceptance of Terms',
    'Description of Service',
    'Account Registration',
    'Acceptable Use',
    'Third-Party Services',
    'Intellectual Property',
    'Data and Privacy',
    'Disclaimer of Warranties',
    'Limitation of Liability',
    'Account Termination',
    'Governing Law',
    'Changes to Terms',
    'Contact',
];

export default function TermsOfService() {
    return (
        <PublicLayout title="Terms of Service">
            <div className="bg-gradient-to-b from-white to-sw-accent-light px-6 py-16">
                <div className="mx-auto max-w-3xl text-center">
                    <h1 className="text-4xl font-bold tracking-tight text-sw-text">Terms of Service</h1>
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
                                    <a href={`#tos-${i + 1}`} className="text-sm text-sw-muted transition-colors hover:text-sw-accent">{s}</a>
                                </li>
                            ))}
                        </ul>
                    </nav>

                    <div className="min-w-0 flex-1 space-y-10">
                        <section id="tos-1">
                            <h2 className="text-2xl font-semibold text-sw-text">1. Acceptance of Terms</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                By accessing or using LedgerIQ (&quot;the Service&quot;), you agree to be bound by these Terms of Service. If you do not agree to these terms, you may not use the Service. These terms constitute a legally binding agreement between you and LedgerIQ.
                            </p>
                        </section>

                        <section id="tos-2">
                            <h2 className="text-2xl font-semibold text-sw-text">2. Description of Service</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                LedgerIQ is a free AI-powered expense tracking platform that provides:
                            </p>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li>Bank account integration via Plaid for automatic transaction import</li>
                                <li>AI-powered transaction categorization using Claude by Anthropic</li>
                                <li>Subscription detection and unused service identification</li>
                                <li>Personalized savings recommendations and goal tracking</li>
                                <li>Tax deduction tracking and IRS Schedule C report generation</li>
                                <li>Email receipt parsing and transaction matching</li>
                            </ul>
                            <p className="mt-3 leading-relaxed text-sw-muted">
                                The Service is provided free of charge. We reserve the right to modify, suspend, or discontinue any part of the Service at any time.
                            </p>
                        </section>

                        <section id="tos-3">
                            <h2 className="text-2xl font-semibold text-sw-text">3. Account Registration</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">To use LedgerIQ, you must:</p>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li>Be at least 18 years of age</li>
                                <li>Provide accurate and complete registration information</li>
                                <li>Maintain the security of your account credentials</li>
                                <li>Notify us immediately of any unauthorized use of your account</li>
                            </ul>
                            <p className="mt-3 leading-relaxed text-sw-muted">
                                You are responsible for all activity that occurs under your account. LedgerIQ is not liable for any loss or damage arising from unauthorized access to your account.
                            </p>
                        </section>

                        <section id="tos-4">
                            <h2 className="text-2xl font-semibold text-sw-text">4. Acceptable Use</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">You agree not to:</p>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li>Use the Service for any illegal purpose</li>
                                <li>Attempt to gain unauthorized access to our systems or other users&apos; data</li>
                                <li>Use automated systems or bots to access the Service (except through our official API)</li>
                                <li>Reverse engineer, decompile, or disassemble any part of the Service</li>
                                <li>Interfere with or disrupt the Service or its infrastructure</li>
                                <li>Resell, redistribute, or sublicense the Service</li>
                            </ul>
                        </section>

                        <section id="tos-5">
                            <h2 className="text-2xl font-semibold text-sw-text">5. Third-Party Services</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                LedgerIQ integrates with third-party services including:
                            </p>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li><strong>Plaid Inc.</strong> — for bank account connections and financial data retrieval. Your use of Plaid is subject to <a href="https://plaid.com/legal/" target="_blank" rel="noopener noreferrer" className="text-sw-accent hover:underline">Plaid&apos;s Terms</a>.</li>
                                <li><strong>Anthropic</strong> — for AI-powered transaction analysis and categorization</li>
                                <li><strong>Google</strong> — for OAuth authentication and optional Gmail integration</li>
                            </ul>
                            <p className="mt-3 leading-relaxed text-sw-muted">
                                We are not responsible for the availability, accuracy, or practices of third-party services. Service interruptions from third-party providers may affect LedgerIQ functionality.
                            </p>
                        </section>

                        <section id="tos-6">
                            <h2 className="text-2xl font-semibold text-sw-text">6. Intellectual Property</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                LedgerIQ and its original content, features, and functionality are owned by LedgerIQ and are protected by copyright, trademark, and other intellectual property laws. You retain ownership of your personal financial data. By using the Service, you grant us a limited license to process your data as necessary to provide the Service.
                            </p>
                        </section>

                        <section id="tos-7">
                            <h2 className="text-2xl font-semibold text-sw-text">7. Data and Privacy</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                Your use of the Service is also governed by our <Link href="/privacy" className="text-sw-accent hover:underline">Privacy Policy</Link>, which describes how we collect, use, and protect your data. By using the Service, you consent to our data practices as described in the Privacy Policy.
                            </p>
                        </section>

                        <section id="tos-8">
                            <h2 className="text-2xl font-semibold text-sw-text">8. Disclaimer of Warranties</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                THE SERVICE IS PROVIDED &quot;AS IS&quot; AND &quot;AS AVAILABLE&quot; WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS OR IMPLIED. LedgerIQ does not warrant that the Service will be uninterrupted, error-free, or secure.
                            </p>
                            <p className="mt-3 rounded-xl border border-sw-warning/30 bg-sw-warning-light p-4 leading-relaxed text-sw-warning">
                                <strong>Important:</strong> LedgerIQ is not a licensed financial advisor, accountant, or tax professional. The Service provides tools for expense tracking and categorization only. AI-generated categories and recommendations should not be considered financial or tax advice. Always consult a qualified professional for financial, tax, or investment decisions.
                            </p>
                        </section>

                        <section id="tos-9">
                            <h2 className="text-2xl font-semibold text-sw-text">9. Limitation of Liability</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                To the maximum extent permitted by law, LedgerIQ shall not be liable for any indirect, incidental, special, consequential, or punitive damages, including but not limited to:
                            </p>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-sw-muted">
                                <li>Financial losses resulting from reliance on AI categorization or recommendations</li>
                                <li>Tax filing errors based on our reports or category mappings</li>
                                <li>Bank connection issues or data synchronization failures</li>
                                <li>Loss of data due to system failures</li>
                                <li>Investment decisions influenced by our savings recommendations</li>
                            </ul>
                        </section>

                        <section id="tos-10">
                            <h2 className="text-2xl font-semibold text-sw-text">10. Account Termination</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                We may suspend or terminate your account if you violate these Terms. You may delete your account at any time through Settings &gt; Delete Account. Upon termination, all your data will be permanently deleted in accordance with our <Link href="/data-retention" className="text-sw-accent hover:underline">Data Retention Policy</Link>.
                            </p>
                        </section>

                        <section id="tos-11">
                            <h2 className="text-2xl font-semibold text-sw-text">11. Governing Law</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                These Terms shall be governed by and construed in accordance with the laws of the United States, without regard to conflict of law provisions. Any disputes arising from these Terms or the Service shall be resolved in the appropriate courts.
                            </p>
                        </section>

                        <section id="tos-12">
                            <h2 className="text-2xl font-semibold text-sw-text">12. Changes to Terms</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                We reserve the right to modify these Terms at any time. We will provide notice of material changes via email or in-app notification at least 30 days before the changes take effect. Your continued use of the Service after changes constitute acceptance of the revised Terms.
                            </p>
                        </section>

                        <section id="tos-13">
                            <h2 className="text-2xl font-semibold text-sw-text">13. Contact</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                For questions about these Terms, contact us at:
                            </p>
                            <p className="mt-2 font-medium text-sw-accent">legal@ledgeriq.com</p>
                        </section>

                        <nav className="flex flex-wrap items-center gap-3 border-t border-sw-border pt-8 text-sm">
                            <span className="text-sw-dim">Legal pages:</span>
                            <Link href="/privacy" className="text-sw-accent hover:underline">Privacy Policy</Link>
                            <span className="text-sw-dim">|</span>
                            <span className="font-medium text-sw-text">Terms of Service</span>
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
