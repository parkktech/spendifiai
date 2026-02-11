import { ReactNode } from 'react';
import { ArrowUpRight, ArrowDownRight } from 'lucide-react';

interface StatCardProps {
  title: string;
  value: string | number;
  subtitle?: string;
  trend?: number;
  icon?: ReactNode;
}

export default function StatCard({ title, value, subtitle, trend, icon }: StatCardProps) {
  const trendPositive = trend !== undefined && trend > 0;
  const trendNegative = trend !== undefined && trend < 0;

  return (
    <div className="relative overflow-hidden rounded-2xl border border-sw-border bg-sw-card p-5 flex-1 sm:min-w-[200px] min-w-0">
      {/* Background glow */}
      <div className="absolute -top-5 -right-5 w-20 h-20 rounded-full bg-sw-accent/5 blur-xl" />

      <div className="flex items-center gap-2.5 mb-3.5">
        {icon && (
          <div className="w-9 h-9 rounded-lg flex items-center justify-center bg-sw-accent/10 border border-sw-accent/20 text-sw-accent">
            {icon}
          </div>
        )}
        <span className="text-xs text-sw-muted font-medium uppercase tracking-wider">{title}</span>
      </div>

      <div className="text-2xl font-bold text-sw-text tracking-tight">{value}</div>

      <div className="flex items-center gap-2 mt-1.5">
        {trend !== undefined && trend !== 0 && (
          <span className={`inline-flex items-center gap-0.5 text-xs font-semibold ${trendPositive ? 'text-sw-danger' : 'text-sw-accent'}`}>
            {trendPositive ? <ArrowUpRight size={13} /> : <ArrowDownRight size={13} />}
            {Math.abs(trend).toFixed(1)}%
          </span>
        )}
        {subtitle && <span className="text-xs text-sw-dim">{subtitle}</span>}
      </div>
    </div>
  );
}
