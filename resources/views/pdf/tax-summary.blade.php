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
            width: 33.33%;
            text-align: center;
            padding: 20px 16px 16px;
            background-color: #f8faf8;
            border: 1px solid #e8ece8;
        }

        .hero-metrics td:first-child {
            border-right: none;
        }

        .hero-metrics td:last-child {
            border-left: none;
        }

        .hero-metrics td.center-cell {
            border-left: none;
            border-right: none;
            background-color: #f0f7f1;
        }

        .hero-value {
            font-size: 28px;
            font-weight: bold;
            color: #0d7a3f;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }

        .hero-value.neutral {
            color: #0c1b33;
        }

        .hero-label {
            font-size: 9px;
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

        table.report tr.meals-note td {
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
    </style>
</head>
<body>

    {{-- ════════════════════════════════════════════ --}}
    {{-- MASTHEAD                                     --}}
    {{-- ════════════════════════════════════════════ --}}
    <div class="masthead">
        <div class="brand">LedgerIQ</div>
        <div class="doc-title">Tax Deduction Report</div>
        <div class="doc-subtitle">
            Prepared for {{ $user['name'] }} &middot; {{ $user['email'] }}
        </div>
        <div class="doc-meta">
            <div class="year-badge">{{ $year }}</div>
            <div class="generated">{{ substr($summary['generated_at'], 0, 10) }}</div>
        </div>
    </div>
    <div class="masthead-rule"></div>

    <div class="content">

        {{-- ════════════════════════════════════════ --}}
        {{-- HERO METRICS                             --}}
        {{-- ════════════════════════════════════════ --}}
        <table class="hero-metrics">
            <tr>
                <td>
                    <div class="hero-value">${{ number_format($summary['grand_total_deductible'], 2) }}</div>
                    <div class="hero-label">Total Deductible</div>
                </td>
                <td class="center-cell">
                    <div class="hero-value">${{ number_format($summary['estimated_tax_savings'], 2) }}</div>
                    <div class="hero-label">Est. Tax Savings ({{ $profile['tax_bracket'] ?? 22 }}%)</div>
                </td>
                <td>
                    <div class="hero-value neutral">{{ $summary['total_line_items'] }}</div>
                    <div class="hero-label">Analyzed Line Items</div>
                </td>
            </tr>
        </table>

        {{-- ════════════════════════════════════════ --}}
        {{-- TAXPAYER PROFILE                         --}}
        {{-- ════════════════════════════════════════ --}}
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

        {{-- ════════════════════════════════════════ --}}
        {{-- SCHEDULE C MAPPING                       --}}
        {{-- ════════════════════════════════════════ --}}
        <div class="section-label">Schedule C Line Mapping</div>
        <div class="section-desc">
            Deductible expenses mapped to IRS Schedule C (Form 1040) lines for your tax professional.
        </div>
        <table class="report">
            <thead>
                <tr>
                    <th style="width: 90px;">Line</th>
                    <th>Description</th>
                    <th class="right" style="width: 100px;">Amount</th>
                    <th class="right" style="width: 60px;">Items</th>
                </tr>
            </thead>
            <tbody>
                @php $schedTotal = 0; @endphp
                @foreach($schedule_c_mapping as $i => $line)
                    @php
                        $note = $line['line'] === '24b' ? ' (50% deductible)' : '';
                        $items = collect($line['categories'] ?? [])->sum('items');
                        $schedTotal += $line['total'];
                    @endphp
                    <tr @class(['striped' => $i % 2 === 0, 'meals-note' => $line['line'] === '24b'])>
                        <td class="bold-label mono">Line {{ $line['line'] }}</td>
                        <td>{{ $line['label'] }}{{ $note }}</td>
                        <td class="right amount">${{ number_format($line['total'], 2) }}</td>
                        <td class="right">{{ $items }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td></td>
                    <td>Total Schedule C Deductions</td>
                    <td class="right amount">${{ number_format($schedTotal, 2) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <div class="section-spacer"></div>

        {{-- ════════════════════════════════════════ --}}
        {{-- DEDUCTIONS BY CATEGORY                   --}}
        {{-- ════════════════════════════════════════ --}}
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

        {{-- ════════════════════════════════════════ --}}
        {{-- LINKED ACCOUNTS                          --}}
        {{-- ════════════════════════════════════════ --}}
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

        {{-- ════════════════════════════════════════ --}}
        {{-- DISCLAIMER                               --}}
        {{-- ════════════════════════════════════════ --}}
        <div class="disclaimer-bar">
            <div class="disclaimer-text">
                <strong>Disclaimer</strong> &mdash; This report was generated by LedgerIQ using AI-assisted transaction categorization.
                {{ $summary['total_line_items'] }} line items were analyzed across bank transactions and email receipts.
                AI-categorized items should be reviewed by a qualified tax professional before filing.
                This document does not constitute tax advice. The accompanying Excel workbook contains
                complete transaction-level detail across five tabs.
            </div>
            <div class="footer-brand">LedgerIQ &middot; AI-Powered Financial Intelligence</div>
        </div>
    </div>

</body>
</html>
