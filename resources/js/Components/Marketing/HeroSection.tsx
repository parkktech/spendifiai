import { Link } from '@inertiajs/react';
import { ReactNode } from 'react';

interface HeroSectionProps {
    title: string;
    subtitle: string;
    primaryCTA?: { label: string; href: string };
    secondaryCTA?: { label: string; href: string };
    children?: ReactNode;
}

export default function HeroSection({
    title,
    subtitle,
    primaryCTA,
    secondaryCTA,
    children,
}: HeroSectionProps) {
    return (
        <section className="relative overflow-hidden bg-gradient-to-b from-white to-sw-accent-light px-6 pb-20 pt-16 sm:pb-28 sm:pt-24">
            <div className="mx-auto max-w-4xl text-center">
                <h1 className="text-4xl font-extrabold tracking-tight text-sw-text sm:text-5xl lg:text-6xl">
                    {title}
                </h1>
                <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-sw-muted sm:text-xl">
                    {subtitle}
                </p>
                {(primaryCTA || secondaryCTA) && (
                    <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                        {primaryCTA && (
                            <Link
                                href={primaryCTA.href}
                                className="inline-flex items-center rounded-xl bg-sw-accent px-8 py-3.5 text-base font-semibold text-white shadow-lg shadow-sw-accent/25 transition-all duration-200 hover:bg-sw-accent-hover hover:shadow-xl hover:shadow-sw-accent/30"
                            >
                                {primaryCTA.label}
                            </Link>
                        )}
                        {secondaryCTA && (
                            <Link
                                href={secondaryCTA.href}
                                className="inline-flex items-center rounded-xl border border-sw-border px-8 py-3.5 text-base font-semibold text-sw-text-secondary shadow-sm transition-all duration-200 hover:bg-sw-card-hover"
                            >
                                {secondaryCTA.label}
                            </Link>
                        )}
                    </div>
                )}
            </div>
            {children}
        </section>
    );
}
