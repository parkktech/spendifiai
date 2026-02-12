import { PiggyBank } from 'lucide-react';
import type { ProjectedSavings } from '@/types/spendwise';

interface ProjectedSavingsBannerProps {
  projection: ProjectedSavings;
}

const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export default function ProjectedSavingsBanner({ projection }: ProjectedSavingsBannerProps) {
  if (projection.projected_monthly_savings <= 0) {
    return null;
  }

  const breakdownParts: string[] = [];
  if (projection.breakdown.recommendations > 0) {
    breakdownParts.push(`${fmt.format(projection.breakdown.recommendations)} from recommendations`);
  }
  if (projection.breakdown.cancelled_subscriptions > 0) {
    breakdownParts.push(`${fmt.format(projection.breakdown.cancelled_subscriptions)} from cancelled subscriptions`);
  }
  if (projection.breakdown.reduced_subscriptions > 0) {
    breakdownParts.push(`${fmt.format(projection.breakdown.reduced_subscriptions)} from reduced plans`);
  }

  return (
    <div className="bg-emerald-50 border border-emerald-200 rounded-xl p-4 flex items-center gap-4">
      <div className="w-10 h-10 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center shrink-0">
        <PiggyBank size={20} className="text-emerald-600" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="text-lg font-bold text-emerald-700">
          Next month you'll save {fmt.format(projection.projected_monthly_savings)}
        </div>
        {breakdownParts.length > 0 && (
          <p className="text-xs text-emerald-600 mt-0.5">
            {breakdownParts.join(' + ')}
          </p>
        )}
      </div>
      <div className="text-right shrink-0 hidden sm:block">
        <div className="text-xs text-emerald-600 font-medium">
          {fmt.format(projection.projected_annual_savings)}/yr
        </div>
        {projection.verification.verified > 0 && (
          <div className="text-[10px] text-emerald-500 mt-0.5">
            {projection.verification.verified} action{projection.verification.verified !== 1 ? 's' : ''} verified
          </div>
        )}
      </div>
    </div>
  );
}
