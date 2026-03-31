<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2563eb, #7c3aed); color: white; padding: 24px 30px; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 4px 0 0; opacity: 0.8; font-size: 13px; }
        .content { background: #ffffff; border: 1px solid #e0e0e0; border-top: none; padding: 30px; border-radius: 0 0 8px 8px; }
        .document-card { background: #f5f7fa; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .document-card .label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .document-card .value { font-size: 15px; font-weight: 500; color: #0f172a; margin-bottom: 12px; }
        .cta { display: block; text-align: center; background: #2563eb; color: white; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; margin: 24px 0 16px; }
        .note { font-size: 12px; color: #94a3b8; text-align: center; margin-bottom: 20px; }
        .footer { font-size: 11px; color: #999; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Document Uploaded</h1>
        <p>{{ config('app.name') }} &mdash; AI-Powered Financial Intelligence</p>
    </div>

    <div class="content">
        <p>Hello,</p>

        <p>
            Your client <strong>{{ $client->name }}</strong> has uploaded a new document to their Tax Vault.
        </p>

        <div class="document-card">
            <div class="label">Document</div>
            <div class="value">{{ $document->original_filename }}</div>

            @if($document->category)
                <div class="label">Category</div>
                <div class="value">{{ $document->category->value }}</div>
            @endif

            @if($document->tax_year)
                <div class="label">Tax Year</div>
                <div class="value">{{ $document->tax_year }}</div>
            @endif
        </div>

        <a href="{{ config('app.url') }}/accountant/clients" class="cta">View Client Documents</a>
        <p class="note">Review the uploaded document in your accountant dashboard.</p>

        <div class="footer">
            This email was sent by <strong>{{ config('app.name') }}</strong> because
            {{ $client->name }} uploaded a document to their Tax Vault.
        </div>
    </div>
</body>
</html>
