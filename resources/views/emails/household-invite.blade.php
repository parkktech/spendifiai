<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2563eb, #7c3aed); color: white; padding: 24px 30px; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 4px 0 0; opacity: 0.8; font-size: 13px; }
        .content { background: #ffffff; border: 1px solid #e0e0e0; border-top: none; padding: 30px; border-radius: 0 0 8px 8px; }
        .inviter-card { background: #f5f7fa; border-radius: 8px; padding: 20px; margin: 20px 0; display: flex; align-items: center; gap: 16px; }
        .inviter-avatar { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #7c3aed, #2563eb); display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; font-weight: 700; flex-shrink: 0; }
        .inviter-info .name { font-size: 16px; font-weight: 600; color: #0f172a; }
        .inviter-info .household { font-size: 13px; color: #64748b; }
        .inviter-info .email { font-size: 12px; color: #94a3b8; }
        .benefits { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin: 20px 0; }
        .benefits h3 { margin: 0 0 10px; font-size: 13px; color: #166534; }
        .benefits ul { margin: 0; padding-left: 20px; font-size: 13px; color: #15803d; }
        .benefits li { padding: 3px 0; }
        .cta { display: block; text-align: center; background: #2563eb; color: white; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; margin: 24px 0 16px; }
        .note { font-size: 12px; color: #94a3b8; text-align: center; margin-bottom: 20px; }
        .footer { font-size: 11px; color: #999; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Household Sharing Invitation</h1>
        <p>{{ config('app.name') }} — AI-Powered Financial Intelligence</p>
    </div>

    <div class="content">
        <p>Hi there,</p>

        <p>
            <strong>{{ $inviter->name }}</strong> has invited you to join their household on {{ config('app.name') }}.
            By joining, you'll share financial data — bank connections, transactions, subscriptions, and more — so you can
            manage your household finances together.
        </p>

        <div class="inviter-card">
            <div class="inviter-avatar">{{ strtoupper(substr($inviter->name, 0, 1)) }}</div>
            <div class="inviter-info">
                <div class="name">{{ $inviter->name }}</div>
                <div class="household">{{ $invitation->household->name }}</div>
                <div class="email">{{ $inviter->email }}</div>
            </div>
        </div>

        <div class="benefits">
            <h3>By joining this household, you'll be able to:</h3>
            <ul>
                <li>See all household bank accounts and transactions</li>
                <li>Track subscriptions and savings recommendations together</li>
                <li>Share tax deduction data for joint filing</li>
                <li>Manage shared dependents for tax credits</li>
            </ul>
        </div>

        <a href="{{ $inviteUrl }}" class="cta">Join Household</a>
        <p class="note">This invitation expires {{ $invitation->expires_at->diffForHumans() }}.</p>

        <p style="font-size: 13px; color: #666;">
            If you don't recognize this person, you can safely ignore this email.
            No data will be shared unless you accept the invitation.
        </p>

        <div class="footer">
            This email was sent by <strong>{{ config('app.name') }}</strong> because
            {{ $inviter->name }} ({{ $inviter->email }}) invited you to join their household.
        </div>
    </div>
</body>
</html>
