<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: {{ $firm->primary_color ?? '#0D9488' }}; color: white; padding: 24px 30px; border-radius: 8px 8px 0 0; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 4px 0 0; opacity: 0.8; font-size: 13px; }
        .content { background: #ffffff; border: 1px solid #e0e0e0; border-top: none; padding: 30px; border-radius: 0 0 8px 8px; }
        .firm-card { background: #f5f7fa; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center; }
        .firm-card .name { font-size: 18px; font-weight: 600; color: #0f172a; }
        .firm-logo { max-width: 120px; max-height: 60px; margin-bottom: 12px; }
        .cta { display: block; text-align: center; background: {{ $firm->primary_color ?? '#0D9488' }}; color: white; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; margin: 24px 0 16px; }
        .note { font-size: 12px; color: #94a3b8; text-align: center; margin-bottom: 20px; }
        .footer { font-size: 11px; color: #999; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="header">
        <h1>You're Invited</h1>
        <p>{{ config('app.name') }} &mdash; AI-Powered Financial Intelligence</p>
    </div>

    <div class="content">
        <p>Hello,</p>

        <p>
            <strong>{{ $firm->name }}</strong> has invited you to join {{ config('app.name') }} as their client.
            Get started with AI-powered expense tracking, tax preparation, and document management.
        </p>

        <div class="firm-card">
            @if($firm->logo_url)
                <img src="{{ $firm->logo_url }}" alt="{{ $firm->name }}" class="firm-logo"><br>
            @endif
            <div class="name">{{ $firm->name }}</div>
        </div>

        <a href="{{ $inviteUrl }}" class="cta">Accept Invitation</a>
        <p class="note">Click above to create your account and connect with your accountant.</p>

        <p style="font-size: 13px; color: #666;">
            If you weren't expecting this invitation, you can safely ignore this email.
        </p>

        <div class="footer">
            This email was sent by <strong>{{ config('app.name') }}</strong> on behalf of {{ $firm->name }}.
        </div>
    </div>
</body>
</html>
