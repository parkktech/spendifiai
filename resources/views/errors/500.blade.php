<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Something went wrong — SpendifiAI</title>
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
            background: #fee2e2;
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
            background: #fee2e2;
            color: #991b1b;
            border-radius: .375rem;
            padding: .25rem .625rem;
            font-size: .8125rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
        }
        .actions { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; margin-top: .5rem; }
        a, button {
            display: inline-block;
            padding: .625rem 1.25rem;
            border-radius: .5rem;
            font-size: .9375rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #0f172a;
            transition: background .15s, border-color .15s;
        }
        a.primary, button.primary {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
        }
        a:hover, button:hover { background: #f1f5f9; }
        a.primary:hover, button.primary:hover { background: #1d4ed8; }
        .hint { font-size: .8125rem; color: #64748b; margin-top: 1.25rem; }
        .hint a { background: none; border: none; color: #2563eb; padding: 0; font-size: .8125rem; display: inline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">⚡</div>
        <span class="badge">500 — Server Error</span>
        <h1>Something went wrong on our end</h1>
        <p>
            We've logged the error automatically and our team will look into it.
            Your financial data is safe — this is a temporary hiccup.
        </p>
        <p>
            The most common fix is refreshing the page. If the problem persists,
            try signing out and back in.
        </p>
        <div class="actions">
            <button onclick="location.reload()" class="primary">Refresh page</button>
            <a href="/">Go home</a>
        </div>
        <p class="hint">Still stuck? <a href="mailto:support@spendifiai.com">support@spendifiai.com</a></p>
    </div>
</body>
</html>
