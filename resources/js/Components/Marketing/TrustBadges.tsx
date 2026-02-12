import { Shield, Lock, Brain, CreditCard } from 'lucide-react';

const badges = [
    { icon: Lock, label: 'Bank-level encryption' },
    { icon: Shield, label: 'Powered by Plaid' },
    { icon: Brain, label: 'AI by Claude' },
    { icon: CreditCard, label: 'No credit card needed' },
];

export default function TrustBadges() {
    return (
        <div className="flex flex-wrap items-center justify-center gap-6 sm:gap-10">
            {badges.map((badge) => (
                <div key={badge.label} className="flex items-center gap-2 text-sm text-sw-dim">
                    <badge.icon className="h-4 w-4" />
                    <span>{badge.label}</span>
                </div>
            ))}
        </div>
    );
}
