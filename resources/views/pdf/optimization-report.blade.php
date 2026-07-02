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
            padding: 28px 48px 24px;
            position: relative;
        }

        .masthead-rule {
            height: 4px;
            background-color: #2563eb;
        }

        .masthead .brand {
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #60a5fa;
            margin-bottom: 2px;
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
            padding: 24px 48px 28px;
        }

        /* ─── Document-level disclaimer bar ─── */
        .doc-disclaimer {
            background-color: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 10px 14px;
            margin-bottom: 20px;
            font-size: 8.5px;
            color: #1e40af;
            line-height: 1.5;
        }

        .doc-disclaimer .disclaimer-label {
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
            font-size: 8px;
        }

        /* ─── Executive Summary ─── */
        .executive-summary {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 14px 18px;
            margin-bottom: 22px;
        }

        .executive-summary .es-label {
            font-size: 10px;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .executive-summary .es-text {
            font-size: 9.5px;
            color: #334155;
            line-height: 1.6;
        }

        /* ─── Section ─── */
        .section {
            margin-bottom: 24px;
        }

        .section-header {
            background-color: #1e3a5f;
            color: #ffffff;
            padding: 10px 16px;
            margin-bottom: 0;
        }

        .section-header .section-title {
            font-size: 12px;
            font-weight: bold;
            color: #ffffff;
        }

        .section-header .section-description {
            font-size: 8.5px;
            color: #93c5fd;
            margin-top: 2px;
        }

        .section-body {
            border: 1px solid #e2e8f0;
            border-top: none;
        }

        /* RPT-03: Per-section educational disclaimer bar */
        .section-disclaimer {
            background-color: #fefce8;
            border-left: 3px solid #ca8a04;
            padding: 8px 14px;
            font-size: 8px;
            color: #92400e;
            line-height: 1.5;
        }

        .section-disclaimer .disclaimer-tag {
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 4px;
        }

        /* ─── Narrator prose ─── */
        .narrator-prose {
            padding: 12px 16px;
            background-color: #f8fafc;
            font-size: 9px;
            color: #334155;
            line-height: 1.6;
            border-bottom: 1px solid #e2e8f0;
            font-style: italic;
        }

        /* ─── Findings table ─── */
        .findings-table {
            width: 100%;
            border-collapse: collapse;
        }

        .findings-table th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 7px 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .findings-table td {
            padding: 8px 12px;
            font-size: 9px;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }

        .findings-table tr:last-child td {
            border-bottom: none;
        }

        .severity-badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .severity-high {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .severity-medium {
            background-color: #fef3c7;
            color: #92400e;
        }

        .severity-low {
            background-color: #f0f9ff;
            color: #0369a1;
        }

        .no-findings {
            padding: 12px 16px;
            font-size: 9px;
            color: #94a3b8;
            font-style: italic;
        }

        /* ─── Docs missing ─── */
        .docs-missing-list {
            padding: 12px 16px;
        }

        .docs-missing-list .doc-item {
            padding: 6px 0;
            font-size: 9px;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }

        .docs-missing-list .doc-item:last-child {
            border-bottom: none;
        }

        .doc-label {
            font-weight: bold;
            color: #1e3a5f;
        }

        /* ─── Refusal list ─── */
        .refusal-section {
            padding: 12px 16px;
        }

        .refusal-item {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f5f9;
        }

        .refusal-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .refusal-category {
            font-size: 9px;
            font-weight: bold;
            color: #1e3a5f;
            margin-bottom: 3px;
        }

        .refusal-what {
            font-size: 8.5px;
            color: #334155;
            margin-bottom: 3px;
        }

        .refusal-why {
            font-size: 8.5px;
            color: #64748b;
            font-style: italic;
        }

        .refusal-label {
            font-weight: bold;
            color: #475569;
        }

        /* ─── Year-end section ─── */
        .year-end-body {
            padding: 12px 16px;
        }

        .year-end-framing {
            font-size: 9px;
            font-weight: bold;
            color: #1e3a5f;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
            margin-top: 10px;
        }

        .year-end-framing:first-child {
            margin-top: 0;
        }

        .year-end-items {
            list-style: none;
            padding: 0;
            margin: 0 0 8px 0;
        }

        .year-end-items li {
            font-size: 9px;
            color: #334155;
            padding: 3px 0 3px 16px;
            border-bottom: 1px solid #f8fafc;
            position: relative;
        }

        .year-end-items li:before {
            content: '•';
            position: absolute;
            left: 4px;
            color: #2563eb;
        }

        .year-end-finding {
            padding: 6px 0;
            font-size: 9px;
            color: #334155;
            border-bottom: 1px solid #f8fafc;
        }

        /* ─── Glossary ─── */
        .glossary-body {
            padding: 12px 16px;
        }

        .glossary-entry {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f5f9;
        }

        .glossary-entry:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .glossary-term {
            font-size: 9.5px;
            font-weight: bold;
            color: #1e3a5f;
            margin-bottom: 3px;
        }

        .glossary-explanation {
            font-size: 8.5px;
            color: #334155;
            line-height: 1.6;
            margin-bottom: 3px;
        }

        .glossary-source {
            font-size: 7.5px;
            color: #94a3b8;
            font-style: italic;
        }

        /* ─── Footer ─── */
        .footer {
            background-color: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 14px 48px;
            margin-top: 24px;
        }

        .footer .footer-disclaimer {
            font-size: 7.5px;
            color: #94a3b8;
            line-height: 1.5;
            margin-bottom: 4px;
        }

        .footer .footer-brand {
            font-size: 8px;
            color: #cbd5e1;
        }

        /* ─── Utility ─── */
        .page-break {
            page-break-after: always;
        }

        .empty-state {
            padding: 14px 16px;
            font-size: 9px;
            color: #94a3b8;
            font-style: italic;
        }
    </style>
</head>
<body>

<!-- ── Masthead ──────────────────────────────────────────────────────── -->
<div class="masthead">
    <div class="brand">SpendifiAI</div>
    <div class="doc-title">Income Optimization Report</div>
    <div class="doc-subtitle">Educational Tax Awareness &amp; Findings Summary</div>
    <div class="doc-meta">
        <div class="year-badge">{{ $tax_year }}</div>
        <div class="generated">Generated {{ $generated_at }}</div>
    </div>
</div>
<div class="masthead-rule"></div>

<div class="content">

    <!-- ── Document-Level Disclaimer (RPT-03) ─────────────────────────── -->
    <div class="doc-disclaimer">
        <div class="disclaimer-label">Educational Disclaimer</div>
        {{ $report_disclaimer }}
    </div>

    <!-- ── Executive Summary ───────────────────────────────────────────── -->
    @if(!empty($executive_summary))
    <div class="executive-summary">
        <div class="es-label">Executive Summary</div>
        <div class="es-text">{{ $executive_summary }}</div>
    </div>
    @endif

    <!-- ── Topical Sections (RPT-01: deductions / taxes / filings / 401k) -->
    @foreach($sections['topical'] as $section)
    <div class="section">
        <div class="section-header">
            <div class="section-title">{{ $section['title'] }}</div>
            <div class="section-description">{{ $section['description'] ?? '' }}</div>
        </div>
        <div class="section-body">

            <!-- RPT-03: Persistent per-section educational disclaimer -->
            <div class="section-disclaimer">
                <span class="disclaimer-tag">Educational Note</span>{{ $section['disclaimer'] ?? $section_disclaimer }}
            </div>

            @if(!empty($section['narrator_prose']))
            <div class="narrator-prose">{{ $section['narrator_prose'] }}</div>
            @endif

            @if(!empty($section['findings']))
            <table class="findings-table">
                <thead>
                    <tr>
                        <th style="width:30%">Area</th>
                        <th style="width:15%">Priority</th>
                        <th style="width:40%">Overview</th>
                        <th style="width:15%">Docs Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($section['findings'] as $finding)
                    <tr>
                        <td>{{ ucwords(str_replace('_', ' ', $finding['finding_type'] ?? '')) }}</td>
                        <td>
                            @if(($finding['severity'] ?? '') === 'high')
                                <span class="severity-badge severity-high">High</span>
                            @elseif(($finding['severity'] ?? '') === 'medium')
                                <span class="severity-badge severity-medium">Medium</span>
                            @else
                                <span class="severity-badge severity-low">Low</span>
                            @endif
                        </td>
                        <td>{{ $finding['description'] ?? 'Pending review.' }}</td>
                        <td>
                            @if(!empty($finding['docs_missing']))
                                <span style="color:#b91c1c;font-weight:bold;">Docs needed</span>
                            @elseif($finding['pro_export_ready'] ?? false)
                                <span style="color:#059669;">Ready</span>
                            @else
                                <span style="color:#64748b;">Pending</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div class="no-findings">No findings detected in this area for {{ $tax_year }}.</div>
            @endif
        </div>
    </div>
    @endforeach

    <!-- ── Documents Missing (RPT-06 Wrapper #1) ───────────────────────── -->
    @if(!empty($sections['docs_missing']))
    @php $sec = $sections['docs_missing']; @endphp
    <div class="section">
        <div class="section-header">
            <div class="section-title">{{ $sec['title'] }}</div>
            <div class="section-description">{{ $sec['description'] ?? '' }}</div>
        </div>
        <div class="section-body">
            <div class="section-disclaimer">
                <span class="disclaimer-tag">Educational Note</span>{{ $sec['disclaimer'] ?? $section_disclaimer }}
            </div>
            @if(!empty($sec['docs_missing']))
            <div class="docs-missing-list">
                @foreach($sec['docs_missing'] as $docItem)
                <div class="doc-item">
                    <span class="doc-label">Document:</span> {{ is_array($docItem) ? ($docItem['document'] ?? '') : $docItem }}
                    @if(is_array($docItem) && !empty($docItem['finding_type']))
                        — <span style="color:#64748b;">relates to {{ ucwords(str_replace('_', ' ', $docItem['finding_type'])) }}</span>
                    @endif
                </div>
                @endforeach
            </div>
            @else
            <div class="empty-state">No documents flagged as missing in this report period.</div>
            @endif
        </div>
    </div>
    @endif

    <!-- ── Needs Professional Review (RPT-06 Wrapper #2) ──────────────── -->
    @if(!empty($sections['needs_professional_review']))
    @php $sec = $sections['needs_professional_review']; @endphp
    <div class="section">
        <div class="section-header">
            <div class="section-title">{{ $sec['title'] }}</div>
            <div class="section-description">{{ $sec['description'] ?? '' }}</div>
        </div>
        <div class="section-body">
            <div class="section-disclaimer">
                <span class="disclaimer-tag">Educational Note</span>{{ $sec['disclaimer'] ?? $section_disclaimer }}
            </div>
            @if(!empty($sec['findings']))
            <table class="findings-table">
                <thead>
                    <tr>
                        <th style="width:35%">Area</th>
                        <th style="width:15%">Band</th>
                        <th style="width:50%">Overview</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sec['findings'] as $finding)
                    <tr>
                        <td>{{ ucwords(str_replace('_', ' ', $finding['finding_type'] ?? '')) }}</td>
                        <td>{{ ucfirst($finding['band'] ?? '') }}</td>
                        <td>{{ $finding['description'] ?? 'Specialist evaluation recommended.' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div class="empty-state">No specialist-band findings in this report period.</div>
            @endif
        </div>
    </div>
    @endif

    <!-- ── What We Refused and Why (RPT-06 Wrapper #3) ────────────────── -->
    @if(!empty($sections['what_we_refused']))
    @php $sec = $sections['what_we_refused']; @endphp
    <div class="section">
        <div class="section-header">
            <div class="section-title">{{ $sec['title'] }}</div>
            <div class="section-description">{{ $sec['description'] ?? '' }}</div>
        </div>
        <div class="section-body">
            <div class="section-disclaimer">
                <span class="disclaimer-tag">Educational Note</span>{{ $sec['disclaimer'] ?? $section_disclaimer }}
            </div>
            @if(!empty($sec['refused_recommendations']))
            <div class="refusal-section">
                @foreach($sec['refused_recommendations'] as $entry)
                <div class="refusal-item">
                    <div class="refusal-category">{{ $entry['category'] ?? '' }}</div>
                    <div class="refusal-what"><span class="refusal-label">What:</span> {{ $entry['what'] ?? '' }}</div>
                    <div class="refusal-why"><span class="refusal-label">Why:</span> {{ $entry['why'] ?? '' }}</div>
                </div>
                @endforeach
            </div>
            @else
            <div class="empty-state">No items in refusal registry.</div>
            @endif
        </div>
    </div>
    @endif

    <!-- ── Year-End Awareness (RPT-08) ─────────────────────────────────── -->
    @if(!empty($sections['year_end']))
    @php $sec = $sections['year_end']; @endphp
    <div class="section">
        <div class="section-header">
            <div class="section-title">{{ $sec['title'] }}</div>
            <div class="section-description">{{ $sec['description'] ?? '' }}</div>
        </div>
        <div class="section-body">
            <div class="section-disclaimer">
                <span class="disclaimer-tag">Educational Note</span>{{ $sec['disclaimer'] ?? $section_disclaimer }}
            </div>
            <div class="year-end-body">

                @if(!empty($sec['dec_31_items']))
                <div class="year-end-framing">{{ ucfirst($sec['dec_31_framing'] ?? 'Commonly reviewed before year end') }}</div>
                @foreach($sec['dec_31_items'] as $item)
                <div class="year-end-finding">
                    {{ ucwords(str_replace('_', ' ', $item['finding_type'] ?? '')) }}
                    @if(!empty($item['description'])) — {{ $item['description'] }} @endif
                </div>
                @endforeach
                @endif

                @if(!empty($sec['jan_15_items']))
                <div class="year-end-framing">{{ ucfirst($sec['jan_15_framing'] ?? 'Commonly relevant in January') }}</div>
                @foreach($sec['jan_15_items'] as $item)
                <div class="year-end-finding">
                    {{ ucwords(str_replace('_', ' ', $item['finding_type'] ?? '')) }}
                    @if(!empty($item['description'])) — {{ $item['description'] }} @endif
                </div>
                @endforeach
                @endif

                @if(!empty($sec['april_items']))
                <div class="year-end-framing">{{ ucfirst($sec['april_framing'] ?? 'Commonly relevant around the April filing window') }}</div>
                @foreach($sec['april_items'] as $item)
                <div class="year-end-finding">
                    {{ ucwords(str_replace('_', ' ', $item['finding_type'] ?? '')) }}
                    @if(!empty($item['description'])) — {{ $item['description'] }} @endif
                </div>
                @endforeach
                @endif

                @if(empty($sec['dec_31_items']) && empty($sec['jan_15_items']) && empty($sec['april_items']))
                <div class="empty-state">No time-sensitive findings detected for {{ $tax_year }}.</div>
                @endif

            </div>
        </div>
    </div>
    @endif

    <!-- ── Educational Glossary (RPT-07) ───────────────────────────────── -->
    @if(!empty($sections['glossary']))
    @php $sec = $sections['glossary']; @endphp
    <div class="section">
        <div class="section-header">
            <div class="section-title">{{ $sec['title'] }}</div>
            <div class="section-description">{{ $sec['description'] ?? '' }}</div>
        </div>
        <div class="section-body">
            <div class="section-disclaimer">
                <span class="disclaimer-tag">Educational Note</span>{{ $sec['disclaimer'] ?? $section_disclaimer }}
            </div>
            @if(!empty($sec['glossary_entries']))
            <div class="glossary-body">
                @foreach($sec['glossary_entries'] as $entry)
                <div class="glossary-entry">
                    <div class="glossary-term">{{ $entry['term'] ?? '' }}</div>
                    <div class="glossary-explanation">{{ $entry['explanation'] ?? '' }}</div>
                    @if(!empty($entry['source']))
                    <div class="glossary-source">Source: {{ $entry['source'] }}</div>
                    @endif
                </div>
                @endforeach
            </div>
            @else
            <div class="empty-state">No glossary entries.</div>
            @endif
        </div>
    </div>
    @endif

</div><!-- /.content -->

<!-- ── Footer with document-level disclaimer ──────────────────────────── -->
<div class="footer">
    <div class="footer-disclaimer">
        {{ $report_disclaimer }}
    </div>
    <div class="footer-brand">SpendifiAI &mdash; Optimization Report {{ $tax_year }} &mdash; Generated {{ $generated_at }}</div>
</div>

</body>
</html>
