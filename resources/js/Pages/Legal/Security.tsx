import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import { Shield } from 'lucide-react';

const sections = [
    'Our Commitment',
    'Encryption',
    'Authentication & Access Control',
    'Infrastructure',
    'Bank Integration Security',
    'AI Data Handling',
    'Incident Response',
    'Responsible Disclosure',
    'Contact',
];

export default function Security() {
    return (
        <PublicLayout title="Security Policy">
            <div className="bg-gradient-to-b from-white to-sw-accent-light px-6 py-16">
                <div className="mx-auto max-w-3xl text-center">
                    <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-sw-accent text-white">
                        <Shield className="h-7 w-7" />
                    </div>
                    <h1 className="text-4xl font-bold tracking-tight text-sw-text">Security Policy</h1>
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
                                    <a href={`#sec-${i + 1}`} className="text-sm text-sw-muted transition-colors hover:text-sw-accent">{s}</a>
                                </li>
                            ))}
                        </ul>
                    </nav>

                    <div className="min-w-0 flex-1 space-y-10">
                        <section id="sec-1">
                            <h2 className="text-2xl font-semibold text-sw-text">1. Our Commitment</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                Security is foundational to LedgerIQ. We handle sensitive financial data, and we take that responsibility seriously. We implement industry-standard security controls and continuously monitor our systems to protect your information.
                            </p>
                        </section>

                        <section id="sec-2">
                            <h2 className="text-2xl font-semibold text-sw-text">2. Encryption</h2>
                            <div className="mt-4 space-y-4">
                                <div className="rounded-xl border border-sw-border bg-sw-accent-light p-5">
                                    <h4 className="font-semibold text-sw-text">Data at Rest</h4>
                                    <p className="mt-1 text-sm text-sw-muted">All sensitive data is encrypted using <strong>AES-256-CBC</strong> encryption. This includes Plaid access tokens, financial profile data, email OAuth tokens, transaction metadata, and two-factor authentication secrets.</p>
                                </div>
                                <div className="rounded-xl border border-sw-border bg-white p-5">
                                    <h4 className="font-semibold text-sw-text">Password Security</h4>
                                    <p className="mt-1 text-sm text-sw-muted">Passwords are hashed using <strong>bcrypt</strong> with appropriate cost factors. We never store passwords in plaintext.</p>
                                </div>
                                <div className="rounded-xl border border-sw-border bg-white p-5">
                                    <h4 className="font-semibold text-sw-text">Data in Transit</h4>
                                    <p className="mt-1 text-sm text-sw-muted">All communications use <strong>HTTPS with TLS 1.2+</strong>. API requests, bank connections, and all user interactions are encrypted end-to-end.</p>
                                </div>
                            </div>
                        </section>

                        <section id="sec-3">
                            <h2 className="text-2xl font-semibold text-sw-text">3. Authentication & Access Control</h2>
                            <ul className="mt-4 list-disc space-y-2 pl-6 text-sw-muted">
                                <li><strong>Session-based authentication</strong> via Laravel Sanctum with secure, HTTP-only cookies</li>
                                <li><strong>Two-factor authentication</strong> — optional TOTP-based 2FA with recovery codes</li>
                                <li><strong>Google OAuth</strong> — secure third-party authentication via OAuth 2.0</li>
                                <li><strong>Account lockout</strong> — automatic lockout after 5 failed login attempts with a 15-minute cooldown</li>
                                <li><strong>reCAPTCHA v3</strong> — bot protection on login and registration forms</li>
                                <li><strong>Token-based API auth</strong> — all API requests require valid Sanctum bearer tokens</li>
                            </ul>
                        </section>

                        <section id="sec-4">
                            <h2 className="text-2xl font-semibold text-sw-text">4. Infrastructure</h2>
                            <ul className="mt-4 list-disc space-y-2 pl-6 text-sw-muted">
                                <li>Database access is restricted to application servers only</li>
                                <li>Regular security updates and dependency auditing</li>
                                <li><strong>Rate limiting</strong> on all API endpoints (120 requests/minute for authenticated users)</li>
                                <li><strong>CSRF protection</strong> on all form submissions</li>
                                <li><strong>XSS prevention</strong> via framework-level output escaping and Content Security Policy headers</li>
                                <li>SQL injection prevention via parameterized queries and Eloquent ORM</li>
                                <li>Security headers: HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy</li>
                            </ul>
                        </section>

                        <section id="sec-5">
                            <h2 className="text-2xl font-semibold text-sw-text">5. Bank Integration Security</h2>
                            <div className="mt-4 rounded-xl border border-sw-border bg-sw-accent-light p-6">
                                <p className="leading-relaxed text-sw-muted">
                                    All bank connections are handled by <strong>Plaid</strong>, a SOC 2 Type II certified financial data platform trusted by thousands of applications including Venmo, Robinhood, and Coinbase.
                                </p>
                                <ul className="mt-4 list-disc space-y-2 pl-6 text-sw-muted">
                                    <li><strong>LedgerIQ never sees or stores your bank credentials</strong> — they go directly to Plaid</li>
                                    <li>Plaid access tokens are <strong>encrypted with AES-256</strong> before database storage</li>
                                    <li>Users can <strong>revoke bank access at any time</strong> through the app</li>
                                    <li>Disconnection immediately revokes and deletes the Plaid access token</li>
                                </ul>
                            </div>
                        </section>

                        <section id="sec-6">
                            <h2 className="text-2xl font-semibold text-sw-text">6. AI Data Handling</h2>
                            <ul className="mt-4 list-disc space-y-2 pl-6 text-sw-muted">
                                <li>Only <strong>transaction descriptions</strong> (merchant names and amounts) are sent to the AI for categorization</li>
                                <li><strong>No account numbers, balances, or personally identifying information</strong> is sent to Anthropic</li>
                                <li>Per Anthropic&apos;s API terms, <strong>your data is not used to train their models</strong></li>
                                <li>AI requests use HTTPS with TLS encryption</li>
                            </ul>
                        </section>

                        <section id="sec-7">
                            <h2 className="text-2xl font-semibold text-sw-text">7. Incident Response</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                In the event of a data breach or security incident:
                            </p>
                            <ul className="mt-2 list-disc space-y-2 pl-6 text-sw-muted">
                                <li>We will <strong>notify affected users within 72 hours</strong> of discovering a breach</li>
                                <li>We will provide clear information about what data was affected</li>
                                <li>We will take immediate steps to contain and remediate the incident</li>
                                <li>We will notify relevant regulatory authorities as required by law</li>
                            </ul>
                        </section>

                        <section id="sec-8">
                            <h2 className="text-2xl font-semibold text-sw-text">8. Responsible Disclosure</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                If you discover a security vulnerability in LedgerIQ, we encourage responsible disclosure. Please report vulnerabilities to:
                            </p>
                            <p className="mt-2 font-medium text-sw-accent">security@ledgeriq.com</p>
                            <p className="mt-3 leading-relaxed text-sw-muted">
                                We ask that you give us reasonable time to address the issue before public disclosure. We will not take legal action against security researchers who act in good faith.
                            </p>
                        </section>

                        <section id="sec-9">
                            <h2 className="text-2xl font-semibold text-sw-text">9. Contact</h2>
                            <p className="mt-4 leading-relaxed text-sw-muted">
                                For security questions or concerns:
                            </p>
                            <p className="mt-2 font-medium text-sw-accent">security@ledgeriq.com</p>
                        </section>

                        <nav className="flex flex-wrap items-center gap-3 border-t border-sw-border pt-8 text-sm">
                            <span className="text-sw-dim">Legal pages:</span>
                            <Link href="/privacy" className="text-sw-accent hover:underline">Privacy Policy</Link>
                            <span className="text-sw-dim">|</span>
                            <Link href="/terms" className="text-sw-accent hover:underline">Terms of Service</Link>
                            <span className="text-sw-dim">|</span>
                            <Link href="/data-retention" className="text-sw-accent hover:underline">Data Retention</Link>
                            <span className="text-sw-dim">|</span>
                            <span className="font-medium text-sw-text">Security Policy</span>
                        </nav>
                    </div>
                </div>
            </div>
        </PublicLayout>
    );
}
