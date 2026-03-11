<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2563eb, #7c3aed); color: white; padding: 24px 30px; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 4px 0 0; opacity: 0.8; font-size: 13px; }
        .content { background: #ffffff; border: 1px solid #e0e0e0; border-top: none; padding: 30px; border-radius: 0 0 8px 8px; }
        .accountant-card { background: #f5f7fa; border-radius: 8px; padding: 20px; margin: 20px 0; display: flex; align-items: center; gap: 16px; }
        .accountant-avatar { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #7c3aed, #2563eb); display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; font-weight: 700; flex-shrink: 0; }
        .accountant-info .name { font-size: 16px; font-weight: 600; color: #0f172a; }
        .accountant-info .company { font-size: 13px; color: #64748b; }
        .accountant-info .email { font-size: 12px; color: #94a3b8; }
        .permissions { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin: 20px 0; }
        .permissions h3 { margin: 0 0 10px; font-size: 13px; color: #166534; }
        .permissions ul { margin: 0; padding-left: 20px; font-size: 13px; color: #15803d; }
        .permissions li { padding: 3px 0; }
        .cta { display: block; text-align: center; background: #2563eb; color: white; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; margin: 24px 0 16px; }
        .note { font-size: 12px; color: #94a3b8; text-align: center; margin-bottom: 20px; }
        .footer { font-size: 11px; color: #999; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Accountant Access Request</h1>
        <p>{{ config('app.name') }} — AI-Powered Financial Intelligence</p>
    </div>

    <div class="content">
        <p>Hi {{ $client->name }},</p>

        <p>
            Your accountant has requested access to view your financial data on {{ config('app.name') }}.
            Once you approve, they'll be able to help manage your expenses and prepare your tax filings.
        </p>

        <div class="accountant-card">
            <div class="accountant-avatar">{{ strtoupper(substr($accountant->name, 0, 1)) }}</div>
            <div class="accountant-info">
                <div class="name">{{ $accountant->name }}</div>
                @if($accountant->company_name)
                    <div class="company">{{ $accountant->company_name }}</div>
                @endif
                <div class="email">{{ $accountant->email }}</div>
            </div>
        </div>

        <div class="permissions">
            <h3>If you approve, your accountant will be able to:</h3>
            <ul>
                <li>View your transaction history and categories</li>
                <li>Download your tax deduction reports</li>
                <li>Recategorize expenses on your behalf</li>
                <li>Trigger bank data syncs</li>
            </ul>
        </div>

        <a href="{{ config('app.url') }}/settings" class="cta">Review &amp; Respond</a>
        <p class="note">You can accept or decline this request from your Settings page.</p>

        <p style="font-size: 13px; color: #666;">
            If you don't recognize this accountant, you can safely ignore this email or decline the request.
            No data will be shared until you explicitly approve.
        </p>

        <div class="footer">
            This email was sent by <strong>{{ config('app.name') }}</strong> because
            {{ $accountant->name }} ({{ $accountant->email }}) invited you as a client.
            <br>You can manage accountant access anytime from your Settings page.
        </div>
    </div>
</body>
</html>
