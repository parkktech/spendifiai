import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { Shield } from 'lucide-react';

const footerLinks = {
    Product: [
        { label: 'Features', href: '/features' },
        { label: 'How It Works', href: '/how-it-works' },
        { label: 'FAQ', href: '/faq' },
        { label: 'Security', href: '/security-policy' },
    ],
    Company: [
        { label: 'About', href: '/about' },
        { label: 'Contact', href: '/contact' },
    ],
    Legal: [
        { label: 'Privacy Policy', href: '/privacy' },
        { label: 'Terms of Service', href: '/terms' },
        { label: 'Data Retention', href: '/data-retention' },
        { label: 'Security Policy', href: '/security-policy' },
    ],
};

export default function Footer() {
    return (
        <footer className="border-t border-sw-border bg-white">
            <div className="mx-auto max-w-7xl px-6 py-16">
                <div className="grid grid-cols-2 gap-8 lg:grid-cols-4">
                    {/* Brand column */}
                    <div className="col-span-2 lg:col-span-1">
                        <ApplicationLogo />
                        <p className="mt-4 max-w-xs text-sm leading-relaxed text-sw-muted">
                            AI-powered expense tracking that helps you save money, track taxes, and take control of your finances. 100% free.
                        </p>
                        <div className="mt-4 flex items-center gap-2 text-sm text-sw-dim">
                            <Shield className="h-4 w-4" />
                            <span>Secured by Plaid</span>
                        </div>
                    </div>

                    {/* Link columns */}
                    {Object.entries(footerLinks).map(([title, links]) => (
                        <div key={title}>
                            <h3 className="text-sm font-semibold text-sw-text">{title}</h3>
                            <ul className="mt-4 space-y-3">
                                {links.map((link) => (
                                    <li key={link.href}>
                                        <Link
                                            href={link.href}
                                            className="text-sm text-sw-muted transition-colors hover:text-sw-text"
                                        >
                                            {link.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            </div>

            {/* Bottom bar */}
            <div className="border-t border-sw-border">
                <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-6 py-6 sm:flex-row">
                    <p className="text-sm text-sw-dim">
                        &copy; {new Date().getFullYear()} LedgerIQ. All rights reserved.
                    </p>
                    <div className="flex items-center gap-6">
                        <Link href="/privacy" className="text-sm text-sw-dim hover:text-sw-muted">
                            Privacy
                        </Link>
                        <Link href="/terms" className="text-sm text-sw-dim hover:text-sw-muted">
                            Terms
                        </Link>
                    </div>
                </div>
            </div>
        </footer>
    );
}
