import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Menu, X } from 'lucide-react';
import ApplicationLogo from '@/Components/ApplicationLogo';

const navLinks = [
    { label: 'Features', href: '/features' },
    { label: 'How It Works', href: '/how-it-works' },
    { label: 'FAQ', href: '/faq' },
    { label: 'About', href: '/about' },
];

export default function Navbar() {
    const { auth } = usePage().props as { auth?: { user?: { name: string } } };
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <header className="sticky top-0 z-50 border-b border-sw-border/60 bg-white/80 backdrop-blur-lg">
            <nav className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <Link href="/" className="shrink-0">
                    <ApplicationLogo />
                </Link>

                {/* Desktop nav */}
                <div className="hidden items-center gap-8 md:flex">
                    {navLinks.map((link) => (
                        <Link
                            key={link.href}
                            href={link.href}
                            className="text-sm font-medium text-sw-muted transition-colors hover:text-sw-text"
                        >
                            {link.label}
                        </Link>
                    ))}
                </div>

                <div className="hidden items-center gap-3 md:flex">
                    {auth?.user ? (
                        <Link
                            href="/dashboard"
                            className="inline-flex items-center rounded-lg bg-sw-accent px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-sw-accent-hover"
                        >
                            Dashboard
                        </Link>
                    ) : (
                        <>
                            <Link
                                href="/login"
                                className="text-sm font-medium text-sw-muted transition-colors hover:text-sw-text"
                            >
                                Log in
                            </Link>
                            <Link
                                href="/register"
                                className="inline-flex items-center rounded-lg bg-sw-accent px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-sw-accent-hover"
                            >
                                Get Started Free
                            </Link>
                        </>
                    )}
                </div>

                {/* Mobile hamburger */}
                <button
                    className="md:hidden p-2 text-sw-muted hover:text-sw-text"
                    onClick={() => setMobileOpen(!mobileOpen)}
                    aria-label="Toggle menu"
                >
                    {mobileOpen ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
                </button>
            </nav>

            {/* Mobile menu */}
            {mobileOpen && (
                <div className="border-t border-sw-border bg-white px-6 pb-6 pt-4 md:hidden">
                    <div className="flex flex-col gap-4">
                        {navLinks.map((link) => (
                            <Link
                                key={link.href}
                                href={link.href}
                                className="text-base font-medium text-sw-muted transition-colors hover:text-sw-text"
                                onClick={() => setMobileOpen(false)}
                            >
                                {link.label}
                            </Link>
                        ))}
                        <hr className="border-sw-border" />
                        {auth?.user ? (
                            <Link
                                href="/dashboard"
                                className="inline-flex items-center justify-center rounded-lg bg-sw-accent px-5 py-2.5 text-sm font-semibold text-white shadow-sm"
                            >
                                Dashboard
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href="/login"
                                    className="text-base font-medium text-sw-muted"
                                >
                                    Log in
                                </Link>
                                <Link
                                    href="/register"
                                    className="inline-flex items-center justify-center rounded-lg bg-sw-accent px-5 py-2.5 text-sm font-semibold text-white shadow-sm"
                                >
                                    Get Started Free
                                </Link>
                            </>
                        )}
                    </div>
                </div>
            )}
        </header>
    );
}
