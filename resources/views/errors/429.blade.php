@php
// D24: JSON requests get a JSON response (handled by Laravel before hitting this view).
// This view serves browser requests only.
$retryAfter = $exception->getHeaders()['Retry-After'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Too Many Requests — SpendifiAI</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Inter, system-ui, -apple-system, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            max-width: 480px;
            width: 100%;
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
        }
        .icon {
            width: 3rem;
            height: 3rem;
            background: #fef3c7;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
            font-size: 1.5rem;
        }
        h1 { font-size: 1.25rem; font-weight: 700; margin-bottom: .5rem; }
        p  { font-size: .9375rem; color: #334155; line-height: 1.6; margin-bottom: 1rem; }
        .badge {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            border-radius: .375rem;
            padding: .25rem .625rem;
            font-size: .8125rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
        }
        a, button {
            display: inline-block;
            margin-top: .5rem;
            padding: .625rem 1.25rem;
            border-radius: .5rem;
            font-size: .9375rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            background: #2563eb;
            color: #fff;
            transition: background .15s;
        }
        a:hover, button:hover { background: #1d4ed8; }
        .hint { font-size: .8125rem; color: #64748b; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">⏳</div>
        <span class="badge">429 — Too Many Requests</span>
        <h1>Slow down just a moment</h1>
        <p>
            You've sent too many requests in a short window.
            This is a temporary rate limit to keep the service fast for everyone.
        </p>
        @if ($retryAfter)
            <p>You can try again in <strong>{{ $retryAfter }} seconds</strong>.</p>
        @else
            <p>Please wait a moment before trying again.</p>
        @endif
        <a href="javascript:history.back()">Go back</a>
        <p class="hint">If this keeps happening, please contact <a href="mailto:support@spendifiai.com" style="background:none;color:#2563eb;padding:0;font-size:.8125rem;">support@spendifiai.com</a>.</p>
    </div>
</body>
</html>
