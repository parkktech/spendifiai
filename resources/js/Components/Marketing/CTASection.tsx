import { Link } from '@inertiajs/react';

interface CTASectionProps {
    headline: string;
    description: string;
    buttonText: string;
    buttonHref: string;
}

export default function CTASection({ headline, description, buttonText, buttonHref }: CTASectionProps) {
    return (
        <section className="bg-sw-accent px-6 py-20">
            <div className="mx-auto max-w-3xl text-center">
                <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                    {headline}
                </h2>
                <p className="mt-4 text-lg leading-relaxed text-blue-100">
                    {description}
                </p>
                <Link
                    href={buttonHref}
                    className="mt-8 inline-flex items-center rounded-xl bg-white px-8 py-3.5 text-base font-semibold text-sw-accent shadow-sm transition-all duration-200 hover:bg-blue-50"
                >
                    {buttonText}
                </Link>
            </div>
        </section>
    );
}
