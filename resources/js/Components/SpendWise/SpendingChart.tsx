import {
  AreaChart,
  Area,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts';

interface SpendingChartProps {
  data: Array<{ month: string; total: number }>;
  categories?: Array<{ category: string; total: number }>;
}

const CHART_COLORS = [
  '#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444',
  '#06b6d4', '#ec4899', '#f97316', '#a855f7', '#14b8a6',
];

function formatCurrency(value: number): string {
  if (value >= 1000) return `$${(value / 1000).toFixed(1)}k`;
  return `$${value}`;
}

export default function SpendingChart({ data, categories }: SpendingChartProps) {
  return (
    <div aria-label="Monthly spending chart" role="img" className="grid grid-cols-1 lg:grid-cols-2 gap-5">
      {/* Spending Trend - Area Chart */}
      <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
        <div className="mb-5">
          <h3 className="text-[15px] font-semibold text-sw-text">Spending Trends</h3>
          <p className="text-xs text-sw-dim mt-1">{data.length}-month overview</p>
        </div>
        <ResponsiveContainer width="100%" height={220}>
          <AreaChart data={data}>
            <defs>
              <linearGradient id="gradientAccent" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="#10b981" stopOpacity={0.3} />
                <stop offset="100%" stopColor="#10b981" stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid strokeDasharray="3 3" stroke="#1e293b" />
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
                background: '#111827',
                border: '1px solid #1e293b',
                borderRadius: 10,
                fontSize: 12,
                color: '#f1f5f9',
              }}
              formatter={(value: number | undefined) => [`$${(value ?? 0).toLocaleString()}`, 'Spending']}
            />
            <Area
              type="monotone"
              dataKey="total"
              stroke="#10b981"
              fill="url(#gradientAccent)"
              strokeWidth={2}
            />
          </AreaChart>
        </ResponsiveContainer>
      </div>

      {/* Category Breakdown - Pie/List */}
      {categories && categories.length > 0 && (
        <div className="rounded-2xl border border-sw-border bg-sw-card p-6">
          <h3 className="text-[15px] font-semibold text-sw-text mb-4">Where Your Money Goes</h3>
          <div className="flex items-start gap-4">
            <div className="w-[140px] shrink-0">
              <ResponsiveContainer width="100%" height={140}>
                <PieChart>
                  <Pie
                    data={categories.slice(0, 8)}
                    cx="50%"
                    cy="50%"
                    innerRadius={35}
                    outerRadius={60}
                    paddingAngle={3}
                    dataKey="total"
                    nameKey="category"
                  >
                    {categories.slice(0, 8).map((_, i) => (
                      <Cell key={i} fill={CHART_COLORS[i % CHART_COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip
                    contentStyle={{
                      background: '#111827',
                      border: '1px solid #1e293b',
                      borderRadius: 8,
                      fontSize: 12,
                      color: '#f1f5f9',
                    }}
                    formatter={(value: number | undefined) => `$${(value ?? 0).toLocaleString()}`}
                  />
                </PieChart>
              </ResponsiveContainer>
            </div>
            <div className="flex-1 flex flex-col gap-1.5 max-h-[200px] overflow-y-auto">
              {categories.map((cat, i) => {
                const maxTotal = categories[0]?.total || 1;
                const pct = (cat.total / maxTotal) * 100;
                return (
                  <div key={cat.category} className="flex items-center gap-2.5 py-1">
                    <div
                      className="w-2.5 h-2.5 rounded-full shrink-0"
                      style={{ backgroundColor: CHART_COLORS[i % CHART_COLORS.length] }}
                    />
                    <div className="flex-1 min-w-0">
                      <div className="flex justify-between text-xs mb-1">
                        <span className="text-sw-text truncate">{cat.category}</span>
                        <span className="text-sw-text font-semibold">${cat.total.toLocaleString()}</span>
                      </div>
                      <div className="h-1 bg-sw-border rounded-full overflow-hidden">
                        <div
                          className="h-full rounded-full transition-all duration-1000"
                          style={{
                            width: `${pct}%`,
                            backgroundColor: CHART_COLORS[i % CHART_COLORS.length],
                          }}
                        />
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
