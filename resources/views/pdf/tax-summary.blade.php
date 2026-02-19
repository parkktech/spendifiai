<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 9.5px;
            color: #1e293b;
            line-height: 1.5;
            margin: 0;
            padding: 0;
        }

        /* ─── Masthead ─── */
        .masthead {
            background-color: #0c1b33;
            color: #ffffff;
            padding: 32px 48px 28px;
            position: relative;
        }

        .masthead-rule {
            height: 3px;
            background-color: #0d7a3f;
        }

        .masthead .brand {
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 6px;
        }

        .masthead .doc-title {
            font-size: 26px;
            font-weight: bold;
            color: #ffffff;
            letter-spacing: -0.3px;
            margin-bottom: 2px;
        }

        .masthead .doc-subtitle {
            font-size: 10px;
            color: #94a3b8;
            line-height: 1.5;
        }

        .masthead .doc-meta {
            position: absolute;
            top: 32px;
            right: 48px;
            text-align: right;
        }

        .masthead .doc-meta .year-badge {
            font-size: 36px;
            font-weight: bold;
            color: #ffffff;
            letter-spacing: -1px;
            line-height: 1;
        }

        .masthead .doc-meta .generated {
            font-size: 8.5px;
            color: #64748b;
            margin-top: 4px;
        }

        /* ─── Page header (pages 2+) ─── */
        .page-header {
            background-color: #0c1b33;
            color: #ffffff;
            padding: 16px 48px;
            position: relative;
        }

        .page-header .brand {
            font-size: 8px;
            font-weight: bold;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #64748b;
            float: left;
        }

        .page-header .page-title {
            font-size: 16px;
            font-weight: bold;
            color: #ffffff;
            clear: both;
        }

        .page-header .page-subtitle {
            font-size: 9px;
            color: #94a3b8;
            margin-top: 2px;
        }

        .page-header .year-tag {
            position: absolute;
            top: 16px;
            right: 48px;
            font-size: 16px;
            font-weight: bold;
            color: #64748b;
        }

        .page-header-rule {
            height: 2px;
            background-color: #0d7a3f;
        }

        /* ─── Content area ─── */
        .content {
            padding: 28px 48px 32px;
        }

        /* ─── Hero metrics ─── */
        .hero-metrics {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        .hero-metrics td {
            width: 25%;
            text-align: center;
            padding: 18px 12px 14px;
            background-color: #f8faf8;
            border: 1px solid #e8ece8;
        }

        .hero-metrics td:first-child {
            border-right: none;
        }

        .hero-metrics td:last-child {
            border-left: none;
        }

        .hero-metrics td.mid-cell {
            border-left: none;
            border-right: none;
        }

        .hero-metrics td.hl {
            background-color: #f0f7f1;
        }

        .hero-value {
            font-size: 22px;
            font-weight: bold;
            color: #0d7a3f;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }

        .hero-value.neutral {
            color: #0c1b33;
        }

        .hero-value.blue {
            color: #1e40af;
        }

        .hero-value.emerald {
            color: #047857;
        }

        .hero-label {
            font-size: 8px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 4px;
        }

        /* ─── Section headings ─── */
        .section-label {
            font-size: 8.5px;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 2.5px;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 2px solid #0c1b33;
        }

        .section-desc {
            font-size: 9px;
            color: #64748b;
            margin-bottom: 10px;
            line-height: 1.5;
        }

        .section-spacer {
            height: 22px;
        }

        .section-spacer-sm {
            height: 12px;
        }

        /* ─── Data tables ─── */
        table.report {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        table.report th {
            background-color: #0c1b33;
            color: #e2e8f0;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: left;
            padding: 7px 10px;
        }

        table.report th.right {
            text-align: right;
        }

        table.report td {
            font-size: 9px;
            padding: 6px 10px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
        }

        table.report td.right {
            text-align: right;
        }

        table.report tr.striped td {
            background-color: #f8fafc;
        }

        table.report td.amount {
            font-weight: bold;
            color: #0d7a3f;
            font-family: 'Courier', monospace;
            font-size: 9px;
        }

        table.report td.amount-zero {
            font-family: 'Courier', monospace;
            font-size: 9px;
            color: #94a3b8;
        }

        table.report td.bold-label {
            font-weight: bold;
            color: #0c1b33;
        }

        table.report tr.total-row td {
            background-color: #f0f7f1;
            font-weight: bold;
            color: #0c1b33;
            border-bottom: 2px solid #0c1b33;
            border-top: 2px solid #0c1b33;
            padding: 8px 10px;
        }

        table.report tr.total-row td.amount {
            color: #0d7a3f;
            font-size: 10px;
        }

        table.report tr.subtotal-row td {
            background-color: #f8fafc;
            font-weight: bold;
            color: #334155;
            border-top: 1px solid #94a3b8;
            padding: 6px 10px;
        }

        table.report tr.meals-note td {
            color: #b45309;
            font-style: italic;
        }

        table.report tr.line-header td {
            background-color: #f1f5f9;
            font-weight: bold;
            color: #0c1b33;
            border-top: 1px solid #94a3b8;
            padding: 8px 10px;
            font-size: 9.5px;
        }

        table.report td.note {
            font-size: 8px;
            color: #b45309;
            font-style: italic;
        }

        /* ─── Profile table ─── */
        table.profile-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        table.profile-grid td {
            font-size: 9.5px;
            padding: 5px 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        table.profile-grid td.pf-label {
            font-weight: bold;
            color: #0c1b33;
            width: 130px;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        table.profile-grid td.pf-value {
            color: #334155;
        }

        /* ─── Footer / Disclaimer ─── */
        .disclaimer-bar {
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid #cbd5e1;
        }

        .disclaimer-text {
            font-size: 7.5px;
            color: #94a3b8;
            line-height: 1.6;
        }

        .disclaimer-text strong {
            color: #64748b;
        }

        .footer-brand {
            margin-top: 8px;
            font-size: 7.5px;
            color: #cbd5e1;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        /* ─── Utility ─── */
        .mono {
            font-family: 'Courier', monospace;
        }

        .page-break {
            page-break-before: always;
        }

        .avoid-break {
            page-break-inside: avoid;
        }

        .text-amber {
            color: #b45309;
        }

        .text-muted {
            color: #94a3b8;
        }

        .irs-note {
            font-size: 8px;
            color: #94a3b8;
            font-style: italic;
            margin-top: 6px;
            line-height: 1.5;
        }
    </style>
</head>
<body>

    {{-- ════════════════════════════════════════════════════════════════ --}}
    {{-- PAGE 1: COVER + SUMMARY                                        --}}
    {{-- ════════════════════════════════════════════════════════════════ --}}

    <div class="masthead">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="vertical-align: top; width: 65%;">
                    @if(!empty($logo_base64))
                        <img src="{{ $logo_base64 }}" alt="SpendifiAI" style="height: 28px; width: 28px; vertical-align: middle; margin-right: 8px; margin-bottom: 4px;">
                    @endif
                    <span class="brand" style="vertical-align: middle;">SpendifiAI</span>
                    <div class="doc-title" style="margin-top: 6px;">Tax Deduction Report</div>
                    <div class="doc-subtitle">
                        Prepared for {{ $user['name'] }} &middot; {{ $user['email'] }}
                    </div>
                </td>
                <td style="vertical-align: top; text-align: right;">
                    <div class="year-badge" style="font-size: 36px; font-weight: bold; color: #ffffff; letter-spacing: -1px; line-height: 1;">{{ $year }}</div>
                    <div style="font-size: 8.5px; color: #64748b; margin-top: 4px;">{{ substr($summary['generated_at'], 0, 10) }}</div>
                </td>
            </tr>
        </table>
    </div>
    <div class="masthead-rule"></div>

    <div class="content">

        {{-- ── Hero Metrics (4 boxes) ── --}}
        <table class="hero-metrics">
            <tr>
                <td class="hl">
                    <div class="hero-value">${{ number_format($summary['grand_total_deductible'], 2) }}</div>
                    <div class="hero-label">Total Deductible</div>
                </td>
                <td class="mid-cell">
                    <div class="hero-value blue">${{ number_format($summary['schedule_c_total'] ?? 0, 2) }}</div>
                    <div class="hero-label">Schedule C (Business)</div>
                </td>
                <td class="mid-cell">
                    <div class="hero-value emerald">${{ number_format($summary['schedule_a_total'] ?? 0, 2) }}</div>
                    <div class="hero-label">Schedule A (Personal)</div>
                </td>
                <td>
                    <div class="hero-value">${{ number_format($summary['estimated_tax_savings'], 2) }}</div>
                    <div class="hero-label">Est. Tax Savings ({{ $profile['tax_bracket'] ?? 22 }}%)</div>
                </td>
            </tr>
        </table>

        {{-- ── Taxpayer Profile ── --}}
        <div class="section-label">Taxpayer Profile</div>
        <table class="profile-grid">
            <tr>
                <td class="pf-label">Employment</td>
                <td class="pf-value">{{ str_replace('_', ' ', ucwords($profile['employment_type'] ?? '—', '_')) }}</td>
                <td class="pf-label">Filing Status</td>
                <td class="pf-value">{{ str_replace('_', ' ', ucwords($profile['filing_status'] ?? '—', '_')) }}</td>
            </tr>
            <tr>
                <td class="pf-label">Business Type</td>
                <td class="pf-value">{{ $profile['business_type'] ?? '—' }}</td>
                <td class="pf-label">Home Office</td>
                <td class="pf-value">{{ ($profile['has_home_office'] ?? false) ? 'Yes' : 'No' }}</td>
            </tr>
        </table>

        <div class="section-spacer"></div>

        {{-- ── Deductions by Category (summary) ── --}}
        <div class="section-label">Deductions by Category</div>
        <table class="report">
            <thead>
                <tr>
                    <th>Category</th>
                    <th class="right" style="width: 100px;">Amount</th>
                    <th class="right" style="width: 50px;">Count</th>
                    <th style="width: 150px;">Date Range</th>
                </tr>
            </thead>
            <tbody>
                @foreach($deductions_by_category as $i => $cat)
                    <tr @class(['striped' => $i % 2 === 0])>
                        <td>{{ $cat['tax_category'] }}</td>
                        <td class="right amount">${{ number_format($cat['total'], 2) }}</td>
                        <td class="right">{{ $cat['item_count'] }}</td>
                        <td>{{ substr($cat['first_date'] ?? '', 0, 10) }} to {{ substr($cat['last_date'] ?? '', 0, 10) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="section-spacer"></div>

        {{-- ── Linked Accounts ── --}}
        <div class="section-label">Linked Financial Accounts</div>
        <table class="report">
            <thead>
                <tr>
                    <th>Institution</th>
                    <th>Account</th>
                    <th>Type</th>
                    <th>Purpose</th>
                    <th>Business / EIN</th>
                </tr>
            </thead>
            <tbody>
                @foreach($accounts as $i => $acct)
                    @php
                        $biz = $acct['business_name'] ?? '';
                        if (!empty($acct['ein'])) $biz .= ' (EIN: ' . $acct['ein'] . ')';
                    @endphp
                    <tr @class(['striped' => $i % 2 === 0])>
                        <td class="bold-label">{{ $acct['institution'] ?? '' }}</td>
                        <td>{{ $acct['account'] ?? '' }} <span class="mono">&middot;&middot;&middot;&middot;{{ $acct['mask'] ?? '' }}</span></td>
                        <td>{{ ucfirst($acct['type'] ?? '') }}</td>
                        <td>{{ ucfirst($acct['purpose'] ?? '') }}</td>
                        <td>{{ $biz }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="disclaimer-bar">
            <div class="disclaimer-text">
                <strong>Disclaimer</strong> &mdash; This report was generated by SpendifiAI using AI-assisted transaction categorization.
                {{ $summary['total_line_items'] }} line items were analyzed across bank transactions and email receipts.
                AI-categorized items should be reviewed by a qualified tax professional before filing.
                This document does not constitute tax advice.
            </div>
            <div class="footer-brand">SpendifiAI &middot; AI-Powered Financial Intelligence</div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════ --}}
    {{-- PAGE 2: COMPLETE SCHEDULE C                                     --}}
    {{-- ════════════════════════════════════════════════════════════════ --}}

    <div class="page-break"></div>
    <div class="page-header">
        @if(!empty($logo_base64))
            <img src="{{ $logo_base64 }}" alt="" style="height: 20px; width: 20px; vertical-align: middle; margin-right: 6px; float: left; margin-top: 2px;">
        @endif
        <div class="brand" style="float: left; line-height: 24px;">SpendifiAI</div>
        <div class="year-tag">{{ $year }}</div>
        <div style="clear: both;"></div>
        <div class="page-title">Schedule C &mdash; Profit or Loss From Business</div>
        <div class="page-subtitle">Form 1040, Lines 8&ndash;30 &middot; {{ $user['name'] }}</div>
    </div>
    <div class="page-header-rule"></div>

    <div class="content">
        <div class="section-desc">
            Complete IRS Schedule C line mapping. All lines shown; unused lines display $0.00.
        </div>

        <table class="report">
            <thead>
                <tr>
                    <th style="width: 80px;">Line</th>
                    <th>Description</th>
                    <th class="right" style="width: 100px;">Amount</th>
                    <th style="width: 200px;">Contributing Categories</th>
                </tr>
            </thead>
            <tbody>
                @php $scheduleCGrand = 0; @endphp
                @foreach($all_schedule_c_lines as $i => $line)
                    @php
                        $hasAmount = $line['total'] > 0;
                        $catNames = collect($line['categories'] ?? [])->pluck('name')->implode(', ');
                        $scheduleCGrand += $line['total'];
                        $isMeals = $line['line'] === '24b';
                        $isHomeOffice = $line['line'] === '30';
                    @endphp
                    <tr @class(['striped' => $i % 2 === 0, 'meals-note' => $isMeals && $hasAmount])>
                        <td class="bold-label mono">Line {{ $line['line'] }}</td>
                        <td>
                            {{ $line['label'] }}
                            @if($isMeals && $hasAmount)
                                <span class="text-amber">&nbsp;(50% limitation applies)</span>
                            @endif
                        </td>
                        <td class="right {{ $hasAmount ? 'amount' : 'amount-zero' }}">
                            ${{ number_format($line['total'], 2) }}
                        </td>
                        <td style="font-size: 8px; color: #64748b;">{{ $catNames ?: '—' }}</td>
                    </tr>
                @endforeach

                {{-- Total row --}}
                <tr class="total-row">
                    <td></td>
                    <td>Total Expenses (Lines 8 through 27a)</td>
                    <td class="right amount">${{ number_format($scheduleCGrand, 2) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        @if(collect($all_schedule_c_lines)->where('line', '27a')->first()['total'] > 0)
            <div class="section-spacer-sm"></div>
            <div class="irs-note">
                * Line 27a &ldquo;Other expenses&rdquo; &mdash; IRS requires itemization. Sub-categories:
                @php
                    $line27a = collect($all_schedule_c_lines)->where('line', '27a')->first();
                    $subCats = collect($line27a['categories'] ?? []);
                @endphp
                @foreach($subCats as $sc)
                    {{ $sc['name'] }}: ${{ number_format($sc['amount'], 2) }}@if(!$loop->last); @endif
                @endforeach
            </div>
        @endif

        <div class="irs-note" style="margin-top: 10px;">
            This schedule maps AI-categorized expenses to IRS form lines.
            Verify each line with your tax preparer before filing.
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════ --}}
    {{-- PAGE 3: SCHEDULE A DEDUCTIONS                                   --}}
    {{-- ════════════════════════════════════════════════════════════════ --}}

    @php $scheduleATotal = collect($all_schedule_a_lines)->sum('total'); @endphp
    @if($scheduleATotal > 0)
        <div class="page-break"></div>
        <div class="page-header">
            @if(!empty($logo_base64))
                <img src="{{ $logo_base64 }}" alt="" style="height: 20px; width: 20px; vertical-align: middle; margin-right: 6px; float: left; margin-top: 2px;">
            @endif
            <div class="brand" style="float: left; line-height: 24px;">SpendifiAI</div>
            <div class="year-tag">{{ $year }}</div>
            <div style="clear: both;"></div>
            <div class="page-title">Schedule A &mdash; Itemized Deductions</div>
            <div class="page-subtitle">Personal deductions &middot; {{ $user['name'] }}</div>
        </div>
        <div class="page-header-rule"></div>

        <div class="content">
            <div class="section-desc">
                Itemized personal deductions identified from your transaction history.
                These are reported on Schedule A (Form 1040) rather than Schedule C.
            </div>

            <table class="report">
                <thead>
                    <tr>
                        <th>Deduction Type</th>
                        <th class="right" style="width: 100px;">Amount</th>
                        <th style="width: 200px;">Contributing Categories</th>
                        <th style="width: 180px;">IRS Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($all_schedule_a_lines as $i => $line)
                        @php
                            $hasAmount = $line['total'] > 0;
                            $catNames = collect($line['categories'] ?? [])->pluck('name')->implode(', ');
                        @endphp
                        <tr @class(['striped' => $i % 2 === 0])>
                            <td class="bold-label">{{ $line['label'] }}</td>
                            <td class="right {{ $hasAmount ? 'amount' : 'amount-zero' }}">
                                ${{ number_format($line['total'], 2) }}
                            </td>
                            <td style="font-size: 8px; color: #64748b;">{{ $catNames ?: '—' }}</td>
                            <td class="note">{{ $line['note'] ?? '' }}</td>
                        </tr>
                    @endforeach

                    <tr class="total-row">
                        <td>Total Schedule A Deductions</td>
                        <td class="right amount">${{ number_format($scheduleATotal, 2) }}</td>
                        <td></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>

            <div class="irs-note" style="margin-top: 12px;">
                <strong>Important limitations:</strong><br>
                &bull; Medical &amp; dental expenses are deductible only to the extent they exceed 7.5% of your adjusted gross income (AGI).<br>
                &bull; State and local tax (SALT) deduction is capped at $10,000 ($5,000 if married filing separately).<br>
                &bull; Charitable contributions are generally limited to 60% of AGI for cash donations.<br>
                &bull; Compare your total itemized deductions to the standard deduction ($14,600 single / $29,200 married filing jointly for 2024).
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════════════════════════════════ --}}
    {{-- PAGE 4: TRANSACTION DETAIL BY IRS LINE                          --}}
    {{-- ════════════════════════════════════════════════════════════════ --}}

    @if(!empty($transactions_by_line))
        <div class="page-break"></div>
        <div class="page-header">
            @if(!empty($logo_base64))
                <img src="{{ $logo_base64 }}" alt="" style="height: 20px; width: 20px; vertical-align: middle; margin-right: 6px; float: left; margin-top: 2px;">
            @endif
            <div class="brand" style="float: left; line-height: 24px;">SpendifiAI</div>
            <div class="year-tag">{{ $year }}</div>
            <div style="clear: both;"></div>
            <div class="page-title">Supporting Detail by IRS Line</div>
            <div class="page-subtitle">Complete transaction detail for each deduction line &middot; {{ $user['name'] }}</div>
        </div>
        <div class="page-header-rule"></div>

        <div class="content">
            <div class="section-desc">
                Every deductible transaction grouped by its assigned IRS Schedule C/A line.
                Use this section to verify categorization accuracy with your tax preparer.
            </div>

            <table class="report">
                <thead>
                    <tr>
                        <th style="width: 75px;">Date</th>
                        <th>Merchant</th>
                        <th>Description</th>
                        <th class="right" style="width: 90px;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions_by_line as $lineLabel => $transactions)
                        @php $lineTotal = collect($transactions)->sum('amount'); @endphp
                        <tr class="line-header">
                            <td colspan="3">{{ $lineLabel }}</td>
                            <td class="right amount">${{ number_format($lineTotal, 2) }}</td>
                        </tr>
                        @foreach($transactions as $i => $tx)
                            <tr @class(['striped' => $i % 2 === 0])>
                                <td class="mono">{{ $tx['date'] }}</td>
                                <td class="bold-label">{{ $tx['merchant'] }}</td>
                                <td style="font-size: 8.5px; color: #475569;">{{ \Illuminate\Support\Str::limit($tx['description'] ?? '', 60) }}</td>
                                <td class="right amount">${{ number_format($tx['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="subtotal-row">
                            <td colspan="3" style="text-align: right; font-size: 8.5px;">
                                Subtotal &mdash; {{ $lineLabel }} ({{ count($transactions) }} transactions)
                            </td>
                            <td class="right amount" style="font-size: 9px;">${{ number_format($lineTotal, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ════════════════════════════════════════════════════════════════ --}}
    {{-- PAGE 5: RECURRING BUSINESS EXPENSES                             --}}
    {{-- ════════════════════════════════════════════════════════════════ --}}

    @if(!empty($business_subscriptions))
        <div class="page-break"></div>
        <div class="page-header">
            @if(!empty($logo_base64))
                <img src="{{ $logo_base64 }}" alt="" style="height: 20px; width: 20px; vertical-align: middle; margin-right: 6px; float: left; margin-top: 2px;">
            @endif
            <div class="brand" style="float: left; line-height: 24px;">SpendifiAI</div>
            <div class="year-tag">{{ $year }}</div>
            <div style="clear: both;"></div>
            <div class="page-title">Recurring Business Subscriptions &amp; Services</div>
            <div class="page-subtitle">Active recurring expenses detected &middot; {{ $user['name'] }}</div>
        </div>
        <div class="page-header-rule"></div>

        <div class="content">
            <div class="section-desc">
                Recurring business expenses identified from transaction patterns.
                Annual totals are included in the deduction figures on preceding pages.
            </div>

            <table class="report">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th class="right" style="width: 90px;">Monthly</th>
                        <th class="right" style="width: 90px;">Annual</th>
                        <th style="width: 100px;">Category</th>
                        <th style="width: 80px;">Frequency</th>
                    </tr>
                </thead>
                <tbody>
                    @php $subMonthly = 0; $subAnnual = 0; @endphp
                    @foreach($business_subscriptions as $i => $sub)
                        @php
                            $subMonthly += $sub['monthly_cost'];
                            $subAnnual += $sub['annual_cost'];
                        @endphp
                        <tr @class(['striped' => $i % 2 === 0])>
                            <td class="bold-label">{{ $sub['service'] }}</td>
                            <td class="right amount">${{ number_format($sub['monthly_cost'], 2) }}</td>
                            <td class="right amount">${{ number_format($sub['annual_cost'], 2) }}</td>
                            <td>{{ $sub['category'] ?? '—' }}</td>
                            <td>{{ ucfirst($sub['frequency'] ?? '—') }}</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td>Total Recurring</td>
                        <td class="right amount">${{ number_format($subMonthly, 2) }}/mo</td>
                        <td class="right amount">${{ number_format($subAnnual, 2) }}/yr</td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            </table>

            <div class="irs-note" style="margin-top: 12px;">
                Note: Annual totals for these recurring services are already included in the Schedule C deduction figures above.
                This page is provided for reference and to assist in projecting next year's deductions.
            </div>
        </div>
    @endif

</body>
</html>
