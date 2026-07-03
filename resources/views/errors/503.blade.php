<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Back soon — SpendifiAI</title>
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
            background: #eff6ff;
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
            background: #eff6ff;
            color: #1e40af;
            border-radius: .375rem;
            padding: .25rem .625rem;
            font-size: .8125rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
        }
        .progress {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
            margin: 1.25rem 0;
        }
        .progress-bar {
            height: 100%;
            background: #2563eb;
            border-radius: 2px;
            animation: progress 3s ease-in-out infinite alternate;
        }
        @keyframes progress { from { width: 20%; } to { width: 80%; } }
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
        .hint a { background: none; border: none; color: #2563eb; padding: 0; font-size: .8125rem; display: inline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🔧</div>
        <span class="badge">503 — Service Unavailable</span>
        <h1>We'll be right back</h1>
        <p>
            SpendifiAI is temporarily unavailable for scheduled maintenance
            or a quick update. Your data is safe.
        </p>
        <div class="progress"><div class="progress-bar"></div></div>
        <p>This usually takes just a few minutes. Try refreshing in a moment.</p>
        <button onclick="location.reload()">Refresh now</button>
        <p class="hint">Questions? <a href="mailto:support@spendifiai.com">support@spendifiai.com</a></p>
    </div>
    <script>
        // Auto-refresh every 30 seconds during maintenance.
        setTimeout(function() { location.reload(); }, 30000);
    </script>
</body>
</html>
