<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @php
        $seoMap = [
            'Welcome' => [
                'title' => 'AI Expense Tracker - Free Automatic Categorization & Tax Deductions',
                'description' => 'Track expenses automatically with AI. LedgerIQ categorizes transactions, detects unused subscriptions, finds savings, and maps tax deductions to IRS Schedule C. 100% free, forever.',
            ],
            'Features' => [
                'title' => 'AI Expense Tracking Features - Bank Sync, Tax Export & More',
                'description' => 'Explore LedgerIQ features: AI transaction categorization, Plaid bank sync, subscription detection, savings recommendations, IRS Schedule C tax export, and email receipt parsing. All free.',
            ],
            'HowItWorks' => [
                'title' => 'How It Works - Set Up AI Expense Tracking in 5 Minutes',
                'description' => 'Get started with LedgerIQ in under 5 minutes. Create an account, connect your bank via Plaid or upload statements, and let AI categorize your transactions automatically.',
            ],
            'About' => [
                'title' => 'About LedgerIQ - AI-Powered Personal Finance for Everyone',
                'description' => 'LedgerIQ is a free AI-powered personal finance platform built for freelancers, small business owners, and individuals. Automatic expense tracking, tax deductions, and savings insights.',
            ],
            'FAQ' => [
                'title' => 'FAQ - AI Expense Tracking Questions Answered',
                'description' => 'Answers to common questions about LedgerIQ: security, AI categorization accuracy, bank connections, tax exports, Plaid integration, subscription detection, and account management.',
            ],
            'Contact' => [
                'title' => 'Contact Us - LedgerIQ Support',
                'description' => 'Get in touch with the LedgerIQ team. Email us at support@ledgeriq.com or use our contact form for questions about AI expense tracking, bank connections, or tax exports.',
            ],
            'Legal/PrivacyPolicy' => [
                'title' => 'Privacy Policy',
                'description' => 'LedgerIQ privacy policy. Learn how we collect, use, and protect your personal and financial data. Plaid integration disclosures, data retention, and your rights explained.',
            ],
            'Legal/TermsOfService' => [
                'title' => 'Terms of Service',
                'description' => 'LedgerIQ terms of service. Usage rules, disclaimers, intellectual property, account responsibilities, and service limitations for our AI expense tracking platform.',
            ],
            'Legal/DataRetention' => [
                'title' => 'Data Retention Policy',
                'description' => 'LedgerIQ data retention policy. How long we keep your financial data, transaction records, AI analysis results, and what happens when you delete your account.',
            ],
            'Legal/Security' => [
                'title' => 'Security Policy - Bank-Level Encryption & Data Protection',
                'description' => 'LedgerIQ security: AES-256 encryption, Plaid SOC 2 Type II, bcrypt password hashing, TLS 1.2+, two-factor authentication, and responsible disclosure program.',
            ],
        ];
        $component = $page['component'] ?? '';
        $seo = $seoMap[$component] ?? null;
        $seoTitle = $seo ? $seo['title'] . ' - LedgerIQ' : config('app.name', 'LedgerIQ');
        $seoDescription = $seo['description'] ?? '';
        $seoCanonical = 'https://ledgeriq.com' . rtrim($page['url'] ?? '/', '?');
        $seoOgImage = 'https://ledgeriq.com/images/ledgeriq-og.png';
    @endphp
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ $seoTitle }}</title>

        @if($seo)
            <meta name="description" content="{{ $seoDescription }}">
            <link rel="canonical" href="{{ $seoCanonical }}">

            {{-- Open Graph --}}
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="LedgerIQ">
            <meta property="og:title" content="{{ $seoTitle }}">
            <meta property="og:description" content="{{ $seoDescription }}">
            <meta property="og:url" content="{{ $seoCanonical }}">
            <meta property="og:image" content="{{ $seoOgImage }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="{{ $seo['title'] }} - LedgerIQ AI Expense Tracking">

            {{-- Twitter Card --}}
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $seoTitle }}">
            <meta name="twitter:description" content="{{ $seoDescription }}">
            <meta name="twitter:image" content="{{ $seoOgImage }}">
            <meta name="twitter:image:alt" content="{{ $seo['title'] }} - LedgerIQ AI Expense Tracking">
        @endif

        <!-- AI Discovery -->
        <link rel="author" href="/llms.txt">

        <!-- Favicon -->
        <link rel="icon" type="image/png" href="/favicon.png">
        <link rel="apple-touch-icon" href="/images/ledgeriq-icon.png">
        <meta name="theme-color" content="#2563eb">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
