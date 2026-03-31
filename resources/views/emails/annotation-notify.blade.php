<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2563eb, #7c3aed); color: white; padding: 24px 30px; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 4px 0 0; opacity: 0.8; font-size: 13px; }
        .content { background: #ffffff; border: 1px solid #e0e0e0; border-top: none; padding: 30px; border-radius: 0 0 8px 8px; }
        .comment-card { background: #f5f7fa; border-left: 4px solid #2563eb; border-radius: 0 8px 8px 0; padding: 16px 20px; margin: 20px 0; }
        .comment-author { font-size: 14px; font-weight: 600; color: #0f172a; margin-bottom: 6px; }
        .comment-body { font-size: 14px; color: #334155; line-height: 1.5; }
        .document-name { font-size: 13px; color: #64748b; margin-top: 8px; }
        .cta { display: block; text-align: center; background: #2563eb; color: white; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; margin: 24px 0 16px; }
        .note { font-size: 12px; color: #94a3b8; text-align: center; margin-bottom: 20px; }
        .footer { font-size: 11px; color: #999; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="header">
        <h1>New Comment</h1>
        <p>{{ config('app.name') }} &mdash; AI-Powered Financial Intelligence</p>
    </div>

    <div class="content">
        <p>Hi {{ $recipient->name }},</p>

        <p>Someone left a comment on a document in your Tax Vault.</p>

        <div class="comment-card">
            <div class="comment-author">{{ $annotation->author->name }}</div>
            <div class="comment-body">{{ Str::limit($annotation->body, 200) }}</div>
            <div class="document-name">On: {{ $annotation->document->original_filename ?? 'Document' }}</div>
        </div>

        @if($annotation->document)
            <a href="{{ config('app.url') }}/vault/documents/{{ $annotation->tax_document_id }}" class="cta">View Document</a>
        @endif
        <p class="note">Reply directly from the document detail page.</p>

        <div class="footer">
            This email was sent by <strong>{{ config('app.name') }}</strong> because
            {{ $annotation->author->name }} commented on a shared document.
        </div>
    </div>
</body>
</html>
