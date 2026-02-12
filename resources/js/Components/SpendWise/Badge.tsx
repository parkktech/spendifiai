import { ReactNode } from 'react';

interface BadgeProps {
  children: ReactNode;
  variant: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
}

const variantClasses: Record<BadgeProps['variant'], string> = {
  success: 'text-sw-success bg-sw-success-light border-emerald-200',
  warning: 'text-sw-warning bg-sw-warning-light border-amber-200',
  danger: 'text-sw-danger bg-sw-danger-light border-red-200',
  info: 'text-sw-info bg-sw-info-light border-violet-200',
  neutral: 'text-sw-muted bg-slate-100 border-slate-200',
};

export default function Badge({ children, variant }: BadgeProps) {
  return (
    <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold tracking-wide border ${variantClasses[variant]}`}>
      {children}
    </span>
  );
}
