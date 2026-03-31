interface ConfidenceBadgeProps {
  confidence: number;
  verified?: boolean;
  className?: string;
}

export default function ConfidenceBadge({ confidence, verified, className = '' }: ConfidenceBadgeProps) {
  if (verified) {
    return (
      <span className={`inline-flex items-center text-xs px-2 py-0.5 rounded-full bg-sw-accent/20 text-sw-accent font-medium ${className}`}>
        Verified
      </span>
    );
  }

  const pct = Math.round(confidence * 100);

  let colorClasses: string;
  if (confidence >= 0.85) {
    colorClasses = 'bg-emerald-500/20 text-emerald-400';
  } else if (confidence >= 0.60) {
    colorClasses = 'bg-amber-500/20 text-amber-400';
  } else {
    colorClasses = 'bg-red-500/20 text-red-400';
  }

  return (
    <span className={`inline-flex items-center text-xs px-2 py-0.5 rounded-full font-medium ${colorClasses} ${className}`}>
      {pct}%
    </span>
  );
}
