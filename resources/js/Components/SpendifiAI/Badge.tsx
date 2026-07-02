import { ReactNode } from 'react';

export interface BadgeProps {
  children: ReactNode;
  variant: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
  className?: string;
}

const variantClasses: Record<BadgeProps['variant'], string> = {
  success: 'bg-gradient-to-br from-sw-icon-success to-emerald-100/60 text-sw-success ring-1 ring-emerald-200/60 shadow-[0_1px_2px_rgba(5,150,105,0.12)]',
  warning: 'bg-gradient-to-br from-sw-icon-warning to-amber-100/60 text-sw-warning ring-1 ring-amber-200/60 shadow-[0_1px_2px_rgba(217,119,6,0.12)]',
  danger:  'bg-sw-danger-light text-sw-danger ring-1 ring-red-200/60 shadow-[0_1px_2px_rgba(220,38,38,0.12)]',
  info:    'bg-gradient-to-br from-sw-info-light to-violet-100/60 text-sw-info ring-1 ring-violet-200/60 shadow-[0_1px_2px_rgba(124,58,237,0.12)]',
  neutral: 'bg-gradient-to-br from-sw-icon-neutral to-slate-100/60 text-sw-muted ring-1 ring-sw-border shadow-[0_1px_2px_rgba(15,23,42,0.06)]',
};

export default function Badge({ children, variant, className = '' }: BadgeProps) {
  return (
    <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold tracking-wide ${variantClasses[variant]} ${className}`}>
      {children}
    </span>
  );
}
