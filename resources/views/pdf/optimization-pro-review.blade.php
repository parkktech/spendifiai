<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pro-Review Packet — {{ $finding_type_label }}</title>
    <style>
        /* Inline CSS only — dompdf cannot fetch external resources */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0f172a; background: #fff; }
        .disclaimer-banner { background: #fef3c7; border-left: 4px solid #d97706; padding: 10px 14px; margin-bottom: 16px; font-size: 9px; line-height: 1.5; }
        .disclaimer-banner strong { color: #92400e; }
        .page-header { border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 16px; }
        .brand { font-size: 14px; font-weight: bold; color: #2563eb; }
        .year-badge { display: inline-block; background: #eff6ff; color: #1d4ed8; padding: 2px 8px; border-radius: 4px; font-size: 9px; font-weight: bold; margin-left: 8px; }
        .packet-title { font-size: 16px; font-weight: bold; color: #0f172a; margin-top: 6px; }
        .packet-subtitle { font-size: 10px; color: #64748b; margin-top: 2px; }
        .meta-row { font-size: 9px; color: #64748b; margin-top: 8px; }
        .section { margin-bottom: 18px; }
        .section-title { font-size: 12px; font-weight: bold; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-bottom: 8px; }
        .section-body { font-size: 10px; line-height: 1.6; color: #334155; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 8px; font-weight: bold; text-transform: uppercase; }
        .badge-high { background: #fee2e2; color: #991b1b; }
        .badge-medium { background: #fef3c7; color: #92400e; }
        .badge-low { background: #ecfdf5; color: #065f46; }
        .badge-solid { background: #ecfdf5; color: #065f46; }
        .badge-fact-dependent { background: #eff6ff; color: #1d4ed8; }
        .badge-frequently-abused { background: #fef3c7; color: #92400e; }
        .assertions-table { width: 100%; border-collapse: collapse; font-size: 9px; margin-top: 6px; }
        .assertions-table th { background: #f1f5f9; color: #334155; text-align: left; padding: 4px 8px; font-weight: bold; }
        .assertions-table td { padding: 4px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .docs-list { font-size: 9px; color: #334155; padding-left: 16px; margin-top: 4px; }
        .docs-list li { margin-bottom: 3px; }
        .legal-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px 12px; border-radius: 4px; font-size: 9px; color: #334155; line-height: 1.5; }
        .legal-citation { font-style: italic; color: #64748b; }
        .question-box { background: #eff6ff; border-left: 4px solid #2563eb; padding: 10px 14px; border-radius: 0 4px 4px 0; font-size: 10px; color: #1e3a5f; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 6px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 8px; color: #64748b; }
        .footer-disclaimer { font-size: 7.5px; color: #94a3b8; margin-top: 3px; }
        .page-content { margin-bottom: 60px; }
    </style>
</head>
<body>
    <div class="page-content">

        {{-- Persistent disclaimer banner — must appear on every page (RPT-05) --}}
        <div class="disclaimer-banner">
            <strong>EDUCATIONAL MATERIAL ONLY — NOT TAX ADVICE.</strong> {{ $pro_review_disclaimer }}
        </div>

        {{-- Header --}}
        <div class="page-header">
            <div class="brand">SpendifiAI <span class="year-badge">{{ $tax_year }}</span></div>
            <div class="packet-title">Professional Review Packet</div>
            <div class="packet-subtitle">{{ $finding_type_label }}</div>
            <div class="meta-row">
                Prepared for: {{ $user_name }} ({{ $user_email }}) &nbsp;|&nbsp;
                Generated: {{ $generated_at }} &nbsp;|&nbsp;
                Tax Year: {{ $tax_year }}
                &nbsp;|&nbsp; Severity: <span class="badge badge-{{ $severity }}">{{ ucfirst($severity) }}</span>
                &nbsp;|&nbsp; Defensibility: <span class="badge badge-{{ str_replace('-', '-', $defensibility_rating) }}">{{ $defensibility_rating }}</span>
            </div>
        </div>

        {{-- 1. Fact-Pattern Narrative --}}
        <div class="section">
            <div class="section-title">1. Fact-Pattern Narrative</div>
            <div class="section-body">{{ $description }}</div>
        </div>

        {{-- 2. Timestamped User Assertions --}}
        @if(!empty($user_assertions))
        <div class="section">
            <div class="section-title">2. User-Asserted Facts (Timestamped)</div>
            <div class="section-body" style="margin-bottom:6px; font-size:9px; color:#64748b;">
                The following facts were self-asserted by the user and have not been independently verified.
            </div>
            <table class="assertions-table">
                <thead>
                    <tr>
                        <th>Date Asserted</th>
                        <th>Fact Key</th>
                        <th>Value / Statement</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($user_assertions as $assertion)
                    <tr>
                        <td>{{ $assertion['asserted_at'] ?? '—' }}</td>
                        <td>{{ $assertion['fact_key'] ?? '—' }}</td>
                        <td>{{ $assertion['value'] ?? '—' }}</td>
                        <td>{{ $assertion['source_type'] ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="section">
            <div class="section-title">2. User-Asserted Facts</div>
            <div class="section-body" style="color:#64748b;">No specific facts on record for this finding.</div>
        </div>
        @endif

        {{-- 3. Documents Captured --}}
        @if(!empty($docs_captured))
        <div class="section">
            <div class="section-title">3. Supporting Documents (Captured)</div>
            <ul class="docs-list">
                @foreach($docs_captured as $doc)
                <li>{{ $doc }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        {{-- 4. Static Legal Basis + Citations (never Claude output) --}}
        <div class="section">
            <div class="section-title">4. Legal Basis &amp; Educational Citations</div>
            <div class="legal-box">
                @if($legal_basis)
                <div>{{ $legal_basis }}</div>
                @endif
                @if(!empty($assumptions))
                <div style="margin-top:6px;">
                    <strong>Known Assumptions &amp; Limitations:</strong>
                    <ul class="docs-list" style="margin-top:3px;">
                        @foreach($assumptions as $assumption)
                        <li>{{ $assumption }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif
                <div class="legal-citation" style="margin-top:6px;">
                    All citations above are static educational references sourced from the tax-rules configuration.
                    They are not legal opinions and do not reflect a complete analysis of your situation.
                </div>
            </div>
        </div>

        {{-- 5. Defensibility Rating --}}
        <div class="section">
            <div class="section-title">5. Defensibility Context</div>
            <div class="section-body">
                <strong>Rating:</strong>
                <span class="badge badge-{{ str_replace(' ', '-', strtolower($defensibility_rating)) }}">{{ $defensibility_rating }}</span>
                <div style="margin-top:6px;">{{ $defensibility_description }}</div>
            </div>
        </div>

        {{-- 6. The Question for Your Professional --}}
        <div class="section">
            <div class="section-title">6. Question for Your Tax Professional</div>
            <div class="question-box">{{ $professional_question }}</div>
        </div>

    </div>

    {{-- Footer — persistent disclaimer (RPT-05) --}}
    <div class="footer">
        <div>SpendifiAI Pro-Review Packet &mdash; {{ $finding_type_label }} &mdash; Tax Year {{ $tax_year }}</div>
        <div class="footer-disclaimer">{{ $pro_review_disclaimer }}</div>
    </div>
</body>
</html>
