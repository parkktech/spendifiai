<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #333; line-height: 1.6; margin: 0; padding: 0; background: #f0f4f8; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
        .header { background: #1a5276; color: white; padding: 28px 30px; border-radius: 10px 10px 0 0; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 700; }
        .header p { margin: 6px 0 0; opacity: 0.8; font-size: 13px; }
        .content { background: #ffffff; border: 1px solid #e0e0e0; border-top: none; padding: 28px 30px; border-radius: 0 0 10px 10px; }
        .stat-row { margin: 20px 0; }
        .stat-row table { width: 100%; border-collapse: collapse; }
        .stat-box { background: #f5f7fa; border-radius: 8px; padding: 16px; text-align: center; width: 30%; }
        .stat-number { font-size: 22px; font-weight: 700; color: #1a5276; }
        .stat-label { font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
        .trend-up { color: #dc2626; }
        .trend-down { color: #059669; }
        .trend-flat { color: #64748b; }
        .section { margin: 24px 0; }
        .section-title { font-size: 14px; font-weight: 700; color: #1a5276; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 2px solid #f0f4f8; }
        .insight-card { padding: 12px 16px; border-radius: 8px; margin-bottom: 8px; font-size: 13px; line-height: 1.5; }
        .insight-win { background: #ecfdf5; border-left: 3px solid #059669; color: #065f46; }
        .insight-watch { background: #fffbeb; border-left: 3px solid #d97706; color: #92400e; }
        .insight-neutral { background: #f0f4f8; border-left: 3px solid #64748b; color: #334155; }
        .sub-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .sub-table td { padding: 8px 12px; border-bottom: 1px solid #f0f4f8; }
        .sub-table .new-badge { background: #dbeafe; color: #1d4ed8; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .sub-table .cancelled-badge { background: #fee2e2; color: #dc2626; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .action-list { margin: 0; padding: 0; list-style: none; }
        .action-item { display: flex; align-items: flex-start; gap: 8px; padding: 8px 0; font-size: 13px; color: #475569; }
        .action-dot { width: 6px; height: 6px; border-radius: 50%; background: #2563eb; margin-top: 7px; flex-shrink: 0; }
        .cta-button { display: inline-block; background: #2563eb; color: #ffffff; padding: 14px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; margin: 8px 0; }
        .cta-button:hover { background: #1d4ed8; }
        .category-bar { height: 6px; border-radius: 3px; margin-bottom: 4px; }
        .milestone-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; margin: 16px 0; font-size: 13px; color: #1e40af; }
        .footer { text-align: center; padding: 20px 0; font-size: 11px; color: #94a3b8; }
        .footer a { color: #64748b; text-decoration: underline; }
        .comparison { font-size: 13px; color: #475569; }
        .comparison .amount { font-weight: 700; font-size: 18px; }
        .comparison .label { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.3px; }
    </style>
</head>
<body>
    <div class="wrapper">
        {{-- Header --}}
        <div class="header">
            <h1>{{ $summary['ai']['headline'] ?? 'Your Financial Update' }}</h1>
            <p>{{ config('app.name') }} &middot; {{ $summary['month_name'] ?? now()->format('F Y') }} Sync Digest</p>
        </div>

        <div class="content">
            {{-- Greeting --}}
            <p style="margin-top: 0;">Hi {{ $user->name }},</p>
            <p style="font-size: 14px; color: #475569;">Here's what happened since your last sync:</p>

            {{-- Sync Stats --}}
            <div class="stat-row">
                <table>
                    <tr>
                        <td class="stat-box" style="padding: 16px;">
                            <div class="stat-number">{{ $summary['sync']['added'] ?? 0 }}</div>
                            <div class="stat-label">New Transactions</div>
                        </td>
                        <td style="width: 3%;"></td>
                        <td class="stat-box" style="padding: 16px;">
                            <div class="stat-number">${{ number_format($summary['spending']['current_month'] ?? 0, 0) }}</div>
                            <div class="stat-label">Spent This Month</div>
                        </td>
                        <td style="width: 3%;"></td>
                        <td class="stat-box" style="padding: 16px;">
                            @php
                                $current = $summary['spending']['current_month'] ?? 0;
                                $last = $summary['spending']['last_month'] ?? 0;
                                $pctChange = $last > 0 ? round((($current - $last) / $last) * 100) : 0;
                                $trendClass = $pctChange < -3 ? 'trend-down' : ($pctChange > 3 ? 'trend-up' : 'trend-flat');
                                $arrow = $pctChange < -3 ? '&#8595;' : ($pctChange > 3 ? '&#8593;' : '&#8596;');
                            @endphp
                            <div class="stat-number {{ $trendClass }}">{{ $arrow }} {{ abs($pctChange) }}%</div>
                            <div class="stat-label">vs Last Month</div>
                        </td>
                    </tr>
                </table>
            </div>

            {{-- AI Insights --}}
            @if(!empty($summary['ai']['insights']))
            <div class="section">
                <div class="section-title">Spending Spotlight</div>
                @foreach($summary['ai']['insights'] as $i => $insight)
                    @php
                        $trend = $summary['spending']['trend'] ?? 'flat';
                        $cls = $i === 0 && $trend === 'down' ? 'insight-win' : ($i === 0 && $trend === 'up' ? 'insight-watch' : 'insight-neutral');
                    @endphp
                    <div class="insight-card {{ $cls }}">{{ $insight }}</div>
                @endforeach
            </div>
            @endif

            {{-- Subscription Changes --}}
            @if(!empty($summary['subscriptions']['has_changes']))
            <div class="section">
                <div class="section-title">Subscription Changes</div>
                <table class="sub-table">
                    @foreach($summary['subscriptions']['new'] ?? [] as $sub)
                    <tr>
                        <td><span class="new-badge">NEW</span></td>
                        <td>{{ $sub['name'] }}</td>
                        <td style="text-align: right; font-weight: 600;">${{ number_format($sub['amount'], 2) }}/mo</td>
                    </tr>
                    @endforeach
                    @foreach($summary['subscriptions']['cancelled'] ?? [] as $sub)
                    <tr>
                        <td><span class="cancelled-badge">ENDED</span></td>
                        <td style="text-decoration: line-through; color: #94a3b8;">{{ $sub['name'] }}</td>
                        <td style="text-align: right; color: #059669; font-weight: 600;">-${{ number_format($sub['amount'], 2) }}/mo</td>
                    </tr>
                    @endforeach
                </table>
            </div>
            @endif

            {{-- Month-over-Month --}}
            @if(($summary['spending']['three_month_avg'] ?? 0) > 0)
            <div class="section">
                <div class="section-title">Month-over-Month</div>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td class="comparison" style="padding: 8px 0; width: 50%;">
                            <div class="label">This Month</div>
                            <div class="amount">${{ number_format($summary['spending']['current_month'] ?? 0, 0) }}</div>
                        </td>
                        <td class="comparison" style="padding: 8px 0; width: 50%; text-align: right;">
                            <div class="label">3-Month Average</div>
                            <div class="amount" style="color: #94a3b8;">${{ number_format($summary['spending']['three_month_avg'] ?? 0, 0) }}</div>
                        </td>
                    </tr>
                </table>
            </div>
            @endif

            {{-- AI Recommendation --}}
            @if(!empty($summary['ai']['recommendation']))
            <div class="insight-card insight-neutral" style="margin-top: 20px;">
                <strong style="color: #1a5276;">Tip:</strong> {{ $summary['ai']['recommendation'] }}
            </div>
            @endif

            {{-- Action Items --}}
            @php
                $actions = [];
                if(($summary['pending_actions']['questions'] ?? 0) > 0) {
                    $actions[] = ($summary['pending_actions']['questions']) . ' AI question' . ($summary['pending_actions']['questions'] > 1 ? 's' : '') . ' need your input';
                }
                if(($summary['pending_actions']['unused_subscriptions'] ?? 0) > 0) {
                    $actions[] = ($summary['pending_actions']['unused_subscriptions']) . ' unused subscription' . ($summary['pending_actions']['unused_subscriptions'] > 1 ? 's' : '') . ' costing $' . number_format($summary['pending_actions']['unused_wasted_monthly'] ?? 0, 2) . '/mo';
                }
            @endphp
            @if(count($actions) > 0)
            <div class="section">
                <div class="section-title">Action Items</div>
                <ul class="action-list">
                    @foreach($actions as $action)
                    <li class="action-item">
                        <span class="action-dot"></span>
                        <span>{{ $action }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Milestone Reminders --}}
            @if(!empty($summary['milestones']))
            <div class="milestone-box">
                @if(in_array('tax_season', $summary['milestones']))
                    Tax season is here! Make sure your deductions are categorized. Export your tax report anytime from the Tax Center.
                @elseif(in_array('year_end_planning', $summary['milestones']))
                    Year-end is approaching. Great time to review your spending and set goals for next year.
                @elseif(in_array('mid_year_check', $summary['milestones']))
                    Mid-year check-in: How are your financial goals tracking? Review your progress on the dashboard.
                @endif
            </div>
            @endif

            {{-- Closing + CTA --}}
            @if(!empty($summary['ai']['closing']))
            <p style="font-size: 13px; color: #475569; margin: 20px 0 8px;">{{ $summary['ai']['closing'] }}</p>
            @endif

            <div style="text-align: center; margin: 24px 0 8px;">
                <a href="{{ config('app.url') }}/dashboard" class="cta-button">Log in to review</a>
            </div>
        </div>

        {{-- Footer --}}
        <div class="footer">
            <p>
                You're receiving this because you have bank accounts connected to {{ config('app.name') }}.
            </p>
            <p>{{ config('app.name') }} &middot; AI-Powered Personal Finance</p>
        </div>
    </div>
</body>
</html>
