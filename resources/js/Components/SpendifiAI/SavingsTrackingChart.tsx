import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts';
import { TrendingUp } from 'lucide-react';
import type { SavingsHistoryEntry } from '@/types/spendifiai';

interface SavingsTrackingChartProps {
  data: SavingsHistoryEntry[];
  projectedMonthly: number;
}

function formatCurrency(value: number): string {
  if (value >= 1000) return `$${(value / 1000).toFixed(1)}k`;
  return `$${value}`;
}

export default function SavingsTrackingChart({ data, projectedMonthly }: SavingsTrackingChartProps) {
  // Append a projected point for the current/next month if we have data
  const chartData = [...data];
  if (chartData.length > 0 && projectedMonthly > 0) {
    const lastMonth = chartData[chartData.length - 1];
    const nextMonthDate = new Date();
    nextMonthDate.setMonth(nextMonthDate.getMonth() + 1);
    const nextMonthLabel = nextMonthDate.toLocaleDateString('en-US', { month: 'short' });

    // Only add projected if it's not already in the data
    if (lastMonth.month !== nextMonthLabel) {
      chartData.push({
        month: nextMonthLabel,
        total_savings: lastMonth.total_savings + projectedMonthly,
        actions_count: lastMonth.actions_count,
        verified_savings: lastMonth.verified_savings,
        subscription_savings: lastMonth.subscription_savings,
        recommendation_savings: lastMonth.recommendation_savings,
      });
    }
  }

  return (
    <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
      <div className="flex items-center gap-3 mb-5">
        <div className="w-10 h-10 rounded-xl bg-violet-50 border border-violet-200 flex items-center justify-center">
          <TrendingUp size={20} className="text-violet-600" />
        </div>
        <div>
          <h3 className="text-[15px] font-semibold text-sw-text">Savings Over Time</h3>
          <p className="text-xs text-sw-dim mt-0.5">
            {data.length > 0
              ? `${data.length} month${data.length !== 1 ? 's' : ''} tracked`
              : 'Tracking will begin when you respond to actions'}
          </p>
        </div>
      </div>

      {data.length > 0 ? (
        <ResponsiveContainer width="100%" height={200}>
          <AreaChart data={chartData}>
            <defs>
              <linearGradient id="savingsViolet" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="#7c3aed" stopOpacity={0.15} />
                <stop offset="95%" stopColor="#f5f3ff" stopOpacity={0.05} />
              </linearGradient>
              <linearGradient id="savingsEmerald" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="#059669" stopOpacity={0.15} />
                <stop offset="95%" stopColor="#ecfdf5" stopOpacity={0.05} />
              </linearGradient>
            </defs>
            <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
            <XAxis
              dataKey="month"
              tick={{ fill: '#64748b', fontSize: 11 }}
              axisLine={false}
              tickLine={false}
            />
            <YAxis
              tick={{ fill: '#64748b', fontSize: 11 }}
              axisLine={false}
              tickLine={false}
              tickFormatter={formatCurrency}
            />
            <Tooltip
              contentStyle={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 10,
                fontSize: 12,
                color: '#0f172a',
                boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)',
              }}
              formatter={(value: number | undefined, name?: string) => {
                const label = name === 'total_savings' ? 'Total Savings' : 'Verified Savings';
                return [`$${(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}`, label];
              }}
            />
            <Area
              type="monotone"
              dataKey="total_savings"
              stroke="#7c3aed"
              strokeWidth={2}
              fill="url(#savingsViolet)"
              dot={{ fill: '#7c3aed', r: 3 }}
            />
            <Area
              type="monotone"
              dataKey="verified_savings"
              stroke="#059669"
              strokeWidth={2}
              fill="url(#savingsEmerald)"
              dot={{ fill: '#059669', r: 3 }}
            />
          </AreaChart>
        </ResponsiveContainer>
      ) : (
        <div className="flex items-center justify-center h-[200px] text-center">
          <div>
            <TrendingUp size={32} className="mx-auto text-sw-dim mb-2" />
            <p className="text-sm text-sw-muted">Start responding to savings actions to see your progress charted here.</p>
          </div>
        </div>
      )}
    </div>
  );
}
