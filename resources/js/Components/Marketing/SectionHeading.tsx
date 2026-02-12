interface SectionHeadingProps {
    overline?: string;
    title: string;
    subtitle?: string;
}

export default function SectionHeading({ overline, title, subtitle }: SectionHeadingProps) {
    return (
        <div className="mx-auto max-w-2xl text-center">
            {overline && (
                <p className="text-sm font-semibold uppercase tracking-widest text-sw-accent">
                    {overline}
                </p>
            )}
            <h2 className="mt-2 text-3xl font-bold tracking-tight text-sw-text sm:text-4xl">
                {title}
            </h2>
            {subtitle && (
                <p className="mt-4 text-lg leading-relaxed text-sw-muted">
                    {subtitle}
                </p>
            )}
        </div>
    );
}
