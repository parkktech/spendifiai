import { ReactNode } from 'react';

interface FeatureCardProps {
    icon: ReactNode;
    title: string;
    description: string;
}

export default function FeatureCard({ icon, title, description }: FeatureCardProps) {
    return (
        <div className="group rounded-2xl border border-sw-border bg-sw-card p-8 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-md">
            <div className="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-sw-accent-light text-sw-accent transition-colors group-hover:bg-sw-accent group-hover:text-white">
                {icon}
            </div>
            <h3 className="text-lg font-semibold text-sw-text">{title}</h3>
            <p className="mt-2 leading-relaxed text-sw-muted">{description}</p>
        </div>
    );
}
