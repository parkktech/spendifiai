import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

const footerLinks = {
    Product: [
        { label: 'Features', href: '/features' },
        { label: 'How It Works', href: '/how-it-works' },
        { label: 'FAQ', href: '/faq' },
        { label: 'Security', href: '/security-policy' },
    ],
    Resources: [
        { label: 'Blog', href: '/blog' },
        { label: 'Tax Guides', href: '/blog/tax' },
        { label: 'Comparisons', href: '/blog/comparison' },
        { label: 'How-To Guides', href: '/blog/guide' },
        { label: 'Sitemap', href: '/sitemap.xml' },
    ],
    Company: [
        { label: 'About', href: '/about' },
        { label: 'Contact', href: '/contact' },
        { label: 'Privacy Policy', href: '/privacy' },
        { label: 'Terms of Service', href: '/terms' },
        { label: 'Data Retention', href: '/data-retention' },
    ],
};

function StarIcon() {
    return (
        <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
        </svg>
    );
}

export default function Footer() {
    return (
        <footer className="border-t border-slate-800 bg-slate-900 text-slate-400">
            <div className="mx-auto max-w-7xl px-6 py-16">
                <div className="grid gap-12 sm:grid-cols-2 lg:grid-cols-5">
                    {/* Brand column */}
                    <div className="lg:col-span-2">
                        <a href="/">
                            <ApplicationLogo dark gradientId="footer-logo-grad" />
                        </a>
                        <p className="mt-4 max-w-xs text-sm leading-relaxed text-slate-400">
                            AI-powered expense tracking that helps you save money, track taxes, and take control of your finances. 100% free, forever.
                        </p>
                        <div className="mt-6 flex items-center gap-2 rounded-lg bg-slate-800 px-3 py-2 text-xs text-slate-300">
                            <svg className="h-4 w-4 text-green-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                            </svg>
                            <span>Bank-level security via Plaid</span>
                        </div>
                    </div>

                    {/* Link columns */}
                    {Object.entries(footerLinks).map(([title, links]) => (
                        <div key={title}>
                            <h3 className="text-xs font-semibold uppercase tracking-widest text-slate-300">
                                {title}
                            </h3>
                            <ul className="mt-4 space-y-3">
                                {links.map((link) => (
                                    <li key={link.href}>
                                        {link.href.startsWith('/blog') || link.href.startsWith('/sitemap') ? (
                                            <a
                                                href={link.href}
                                                className="text-sm text-slate-400 transition-colors hover:text-white"
                                            >
                                                {link.label}
                                            </a>
                                        ) : (
                                            <Link
                                                href={link.href}
                                                className="text-sm text-slate-400 transition-colors hover:text-white"
                                            >
                                                {link.label}
                                            </Link>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            </div>

            {/* Bottom bar */}
            <div className="border-t border-slate-800">
                <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-6 py-6 sm:flex-row">
                    <p className="text-sm text-slate-500">
                        &copy; {new Date().getFullYear()} SpendifiAI. All rights reserved.
                    </p>
                    <div className="flex items-center gap-1 text-sm text-slate-500">
                        <span>Rated</span>
                        <div className="flex text-amber-400">
                            {[...Array(5)].map((_, i) => (
                                <StarIcon key={i} />
                            ))}
                        </div>
                        <span>4.8/5 from 247 users</span>
                    </div>
                </div>
            </div>
        </footer>
    );
}
