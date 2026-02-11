import { ReactNode } from 'react';

interface BadgeProps {
  children: ReactNode;
  variant: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
}

const variantClasses: Record<BadgeProps['variant'], string> = {
  success: 'text-sw-accent bg-sw-accent/10 border-sw-accent/30',
  warning: 'text-sw-warning bg-sw-warning/10 border-sw-warning/30',
  danger: 'text-sw-danger bg-sw-danger/10 border-sw-danger/30',
  info: 'text-blue-400 bg-blue-400/10 border-blue-400/30',
  neutral: 'text-sw-muted bg-sw-muted/10 border-sw-muted/30',
};

export default function Badge({ children, variant }: BadgeProps) {
  return (
    <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold tracking-wide border ${variantClasses[variant]}`}>
      {children}
    </span>
  );
}
