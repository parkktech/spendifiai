import { ReactNode } from 'react';
import { ArrowUpRight, ArrowDownRight } from 'lucide-react';

type IconVariant = 'accent' | 'success' | 'danger' | 'warning' | 'neutral';

interface StatCardProps {
  title: string;
  value: string | number;
  subtitle?: string;
  trend?: number;
  icon?: ReactNode;
  iconVariant?: IconVariant;
}

const iconVariantClasses: Record<IconVariant, string> = {
  accent:  'bg-gradient-to-br from-sw-icon-accent to-blue-100/80 text-sw-accent ring-1 ring-sw-accent-muted/60',
  success: 'bg-gradient-to-br from-sw-icon-success to-emerald-100/80 text-sw-success ring-1 ring-emerald-200/60',
  danger:  'bg-gradient-to-br from-sw-icon-danger to-red-100/80 text-sw-danger ring-1 ring-red-200/60',
  warning: 'bg-gradient-to-br from-sw-icon-warning to-amber-100/80 text-sw-warning ring-1 ring-amber-200/60',
  neutral: 'bg-gradient-to-br from-sw-icon-neutral to-slate-100/80 text-sw-muted ring-1 ring-sw-border',
};

export default function StatCard({ title, value, subtitle, trend, icon, iconVariant = 'accent' }: StatCardProps) {
  const trendPositive = trend !== undefined && trend > 0;

  return (
    /* Outer frame — the "bezel" wrapper */
    <div className="relative overflow-hidden rounded-xl p-px bg-gradient-to-b from-sw-border/80 to-sw-border/40 shadow-sw-1 flex-1 sm:min-w-[200px] min-w-0 card-lift">
      {/* Inner core */}
      <div className="rounded-[calc(0.75rem-1px)] bg-gradient-to-b from-white to-slate-50/60 p-5 h-full shadow-sw-inset">
        <div className="flex items-center gap-2.5 mb-3.5">
          {icon && (
            <div className={`w-10 h-10 rounded-xl flex items-center justify-center shadow-[inset_0_1px_0_rgba(255,255,255,0.9)] ${iconVariantClasses[iconVariant]}`}>
              {icon}
            </div>
          )}
          <span className="text-[11px] text-sw-muted font-medium tracking-[0.02em]">{title}</span>
        </div>

        <div className="text-[28px] font-[800] text-sw-text tracking-[-0.035em] leading-none font-tabular mt-1">{value}</div>

        <div className="flex items-center gap-2 mt-1.5">
          {trend !== undefined && trend !== 0 && (
            <span className={`inline-flex items-center gap-0.5 text-xs font-semibold ${trendPositive ? 'text-sw-danger' : 'text-sw-success'}`}>
              {trendPositive ? <ArrowUpRight size={13} /> : <ArrowDownRight size={13} />}
              {Math.abs(trend).toFixed(1)}%
            </span>
          )}
          {subtitle && <span className="text-xs text-sw-dim">{subtitle}</span>}
        </div>
      </div>
    </div>
  );
}
