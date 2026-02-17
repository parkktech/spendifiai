import { Link } from '@inertiajs/react';
import { Link2, ArrowRight } from 'lucide-react';

interface ConnectBankPromptProps {
  feature: string;
  description?: string;
}

export default function ConnectBankPrompt({ feature, description }: ConnectBankPromptProps) {
  return (
    <div className="rounded-2xl border border-sw-border/50 bg-gradient-to-br from-sw-accent/5 to-sw-bg p-8 text-center">
      <div className="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-lg bg-sw-accent/10 border border-sw-accent/30">
        <Link2 size={24} className="text-sw-accent" />
      </div>
      <h3 className="text-lg font-semibold text-sw-text mb-2">Connect Your Bank</h3>
      <p className="text-sm text-sw-muted mb-6">
        {description || `Link your bank account to see your ${feature}`}
      </p>
      <Link
        href="/connect"
        className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition"
      >
        Get Started
        <ArrowRight size={14} />
      </Link>
    </div>
  );
}
