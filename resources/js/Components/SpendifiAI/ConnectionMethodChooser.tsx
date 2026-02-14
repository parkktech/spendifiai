import { Building2, Upload, Zap, Shield, Clock, CheckCircle2 } from 'lucide-react';

interface ConnectionMethodChooserProps {
  onChoosePlaid: () => void;
  onChooseUpload: () => void;
}

interface MethodCardProps {
  title: string;
  description: string;
  icon: React.ReactNode;
  features: string[];
  buttonLabel: string;
  onClick: () => void;
  recommended?: boolean;
}

function MethodCard({
  title,
  description,
  icon,
  features,
  buttonLabel,
  onClick,
  recommended,
}: MethodCardProps) {
  return (
    <div
      className={`relative flex flex-col rounded-2xl border p-6 transition hover:shadow-md ${
        recommended
          ? 'border-sw-accent/40 bg-sw-accent-light/30'
          : 'border-sw-border bg-sw-card hover:border-sw-border-strong'
      }`}
    >
      {recommended && (
        <span className="absolute -top-2.5 left-5 inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-sw-accent text-white text-[10px] font-bold uppercase tracking-wide">
          <Zap size={10} /> Recommended
        </span>
      )}

      <div className="flex items-center gap-3 mb-3">
        <div
          className={`w-11 h-11 rounded-xl flex items-center justify-center shrink-0 ${
            recommended
              ? 'bg-sw-accent text-white'
              : 'bg-sw-surface border border-sw-border text-sw-muted'
          }`}
        >
          {icon}
        </div>
        <div>
          <h3 className="text-[15px] font-semibold text-sw-text">{title}</h3>
          <p className="text-xs text-sw-muted">{description}</p>
        </div>
      </div>

      <ul className="space-y-2 mb-5 flex-1">
        {features.map((feature) => (
          <li key={feature} className="flex items-start gap-2">
            <CheckCircle2 size={14} className="text-sw-success shrink-0 mt-0.5" />
            <span className="text-xs text-sw-muted leading-relaxed">{feature}</span>
          </li>
        ))}
      </ul>

      <button
        onClick={onClick}
        className={`w-full py-2.5 rounded-lg text-sm font-semibold transition ${
          recommended
            ? 'bg-sw-accent text-white hover:bg-sw-accent-hover'
            : 'bg-sw-card border border-sw-border text-sw-text hover:bg-sw-card-hover hover:border-sw-border-strong'
        }`}
      >
        {buttonLabel}
      </button>
    </div>
  );
}

export default function ConnectionMethodChooser({
  onChoosePlaid,
  onChooseUpload,
}: ConnectionMethodChooserProps) {
  return (
    <div className="space-y-5">
      <div className="text-center mb-2">
        <h3 className="text-[15px] font-semibold text-sw-text">
          How would you like to connect your bank?
        </h3>
        <p className="text-xs text-sw-muted mt-1">
          Both options give you the same AI-powered analysis. Choose what works best for
          you.
        </p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <MethodCard
          title="Link Your Bank"
          description="Automatic sync via Plaid"
          icon={<Building2 size={20} />}
          features={[
            'Automatic daily transaction sync',
            'Real-time balance updates',
            'Supports 12,000+ institutions',
            'Bank-level 256-bit encryption',
          ]}
          buttonLabel="Connect with Plaid"
          onClick={onChoosePlaid}
          recommended
        />

        <MethodCard
          title="Upload Statements"
          description="Manual PDF or CSV upload"
          icon={<Upload size={20} />}
          features={[
            'Works with any bank worldwide',
            'No credentials shared with third parties',
            'AI extracts transactions automatically',
            'Same categorization and insights',
          ]}
          buttonLabel="Upload a Statement"
          onClick={onChooseUpload}
        />
      </div>

      <div className="flex items-center justify-center gap-2 pt-2">
        <Shield size={14} className="text-sw-dim" />
        <p className="text-[11px] text-sw-dim text-center">
          Your financial data is encrypted at rest and in transit. We never sell your
          data.
        </p>
      </div>
    </div>
  );
}
