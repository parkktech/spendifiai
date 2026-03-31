<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #059669, #0d9488); color: white; padding: 24px 30px; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 4px 0 0; opacity: 0.8; font-size: 13px; }
        .content { background: #ffffff; border: 1px solid #e0e0e0; border-top: none; padding: 30px; border-radius: 0 0 8px 8px; }
        .success-badge { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin: 20px 0; text-align: center; }
        .success-badge .icon { font-size: 28px; margin-bottom: 8px; }
        .success-badge .text { font-size: 14px; font-weight: 600; color: #166534; }
        .details-card { background: #f5f7fa; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .details-card .label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .details-card .value { font-size: 15px; font-weight: 500; color: #0f172a; margin-bottom: 12px; }
        .cta { display: block; text-align: center; background: #059669; color: white; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; margin: 24px 0 16px; }
        .note { font-size: 12px; color: #94a3b8; text-align: center; margin-bottom: 20px; }
        .footer { font-size: 11px; color: #999; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Request Fulfilled</h1>
        <p>{{ config('app.name') }} &mdash; AI-Powered Financial Intelligence</p>
    </div>

    <div class="content">
        <p>Hello,</p>

        <div class="success-badge">
            <div class="text">A document request has been fulfilled</div>
        </div>

        <div class="details-card">
            <div class="label">Original Request</div>
            <div class="value">{{ $request->description }}</div>

            <div class="label">Uploaded Document</div>
            <div class="value">{{ $document->original_filename }}</div>

            @if($request->client)
                <div class="label">Client</div>
                <div class="value">{{ $request->client->name }}</div>
            @endif
        </div>

        <a href="{{ config('app.url') }}/accountant/clients" class="cta">Review Document</a>
        <p class="note">View the fulfilled request in your accountant dashboard.</p>

        <div class="footer">
            This email was sent by <strong>{{ config('app.name') }}</strong> because
            a document request you created has been fulfilled.
        </div>
    </div>
</body>
</html>
