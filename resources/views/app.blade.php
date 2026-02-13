<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @php
        $seoMap = [
            'Welcome' => [
                'title' => 'AI Expense Tracker - Smart Categorization & Tax Deductions',
                'description' => 'Track expenses with AI. Auto-categorize transactions, detect unused subscriptions, find savings, and map tax deductions. 100% free, forever.',
            ],
            'Features' => [
                'title' => 'AI Expense Tracking Features - Bank Sync & Tax Export',
                'description' => 'LedgerIQ features: AI categorization, Plaid bank sync, subscription detection, savings tips, Schedule C tax export, and receipt parsing. All free.',
            ],
            'HowItWorks' => [
                'title' => 'How It Works - Set Up AI Expense Tracking in 5 Minutes',
                'description' => 'Get started in under 5 minutes. Connect your bank via Plaid or upload statements and let AI categorize your transactions automatically.',
            ],
            'About' => [
                'title' => 'About LedgerIQ - AI-Powered Personal Finance for Everyone',
                'description' => 'Free AI-powered personal finance for freelancers and small businesses. Automatic expense tracking, tax deductions, and savings insights.',
            ],
            'FAQ' => [
                'title' => 'FAQ - AI Expense Tracking Questions Answered',
                'description' => 'Common questions about LedgerIQ: security, AI accuracy, bank connections, tax exports, Plaid integration, and subscription detection.',
            ],
            'Contact' => [
                'title' => 'Contact Us - LedgerIQ Support',
                'description' => 'Contact LedgerIQ at support@ledgeriq.com. Questions about AI expense tracking, bank connections, or tax exports? We are here to help.',
            ],
            'Legal/PrivacyPolicy' => [
                'title' => 'Privacy Policy',
                'description' => 'How we collect, use, and protect your personal and financial data. Plaid disclosures, data retention, and your privacy rights explained.',
            ],
            'Legal/TermsOfService' => [
                'title' => 'Terms of Service',
                'description' => 'Usage rules, disclaimers, intellectual property, account responsibilities, and service limitations for LedgerIQ AI expense tracking.',
            ],
            'Legal/DataRetention' => [
                'title' => 'Data Retention Policy',
                'description' => 'How long we keep your financial data, transaction records, AI analysis results, and what happens when you delete your account.',
            ],
            'Legal/Security' => [
                'title' => 'Security - Bank-Level Encryption & Data Protection',
                'description' => 'AES-256 encryption, Plaid SOC 2 Type II, bcrypt hashing, TLS 1.2+, two-factor authentication, and responsible disclosure program.',
            ],
        ];
        $component = $page['component'] ?? '';
        $seo = $seoMap[$component] ?? null;
        $seoTitle = $seo ? $seo['title'] . (str_contains($seo['title'], 'LedgerIQ') ? '' : ' - LedgerIQ') : config('app.name', 'LedgerIQ');
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

        @if($seo)
            {{-- JSON-LD: Organization --}}
            <script type="application/ld+json">
            {
                "@@context": "https://schema.org",
                "@@type": "Organization",
                "@@id": "https://ledgeriq.com/#organization",
                "name": "LedgerIQ",
                "url": "https://ledgeriq.com",
                "logo": {
                    "@@type": "ImageObject",
                    "url": "https://ledgeriq.com/images/ledgeriq-icon.png",
                    "width": 512,
                    "height": 512
                },
                "email": "support@ledgeriq.com",
                "description": "AI-powered expense tracking that automatically categorizes transactions, detects unused subscriptions, finds savings, and prepares tax deductions. 100% free.",
                "contactPoint": {
                    "@@type": "ContactPoint",
                    "contactType": "customer support",
                    "email": "support@ledgeriq.com",
                    "url": "https://ledgeriq.com/contact"
                }
            }
            </script>
            {{-- JSON-LD: WebSite --}}
            <script type="application/ld+json">
            {
                "@@context": "https://schema.org",
                "@@type": "WebSite",
                "@@id": "https://ledgeriq.com/#website",
                "name": "LedgerIQ",
                "url": "https://ledgeriq.com",
                "publisher": { "@@id": "https://ledgeriq.com/#organization" }
            }
            </script>
            @if($component === 'Welcome')
                {{-- JSON-LD: SoftwareApplication (homepage only) --}}
                <script type="application/ld+json">
                {
                    "@@context": "https://schema.org",
                    "@@type": "SoftwareApplication",
                    "@@id": "https://ledgeriq.com/#software",
                    "name": "LedgerIQ",
                    "url": "https://ledgeriq.com",
                    "applicationCategory": "FinanceApplication",
                    "operatingSystem": "Web browser",
                    "description": "Free AI-powered expense tracker with automatic transaction categorization, subscription detection, savings recommendations, and IRS Schedule C tax export.",
                    "offers": {
                        "@@type": "Offer",
                        "price": "0",
                        "priceCurrency": "USD",
                        "availability": "https://schema.org/InStock",
                        "priceValidUntil": "2027-12-31",
                        "url": "https://ledgeriq.com/register",
                        "description": "100% free — no premium tiers, no trial periods, no credit card required"
                    },
                    "aggregateRating": {
                        "@@type": "AggregateRating",
                        "ratingValue": "4.8",
                        "bestRating": "5",
                        "worstRating": "1",
                        "ratingCount": "247",
                        "reviewCount": "89"
                    },
                    "review": [
                        {
                            "@@type": "Review",
                            "author": { "@@type": "Person", "name": "Sarah M." },
                            "datePublished": "2025-11-15",
                            "reviewRating": { "@@type": "Rating", "ratingValue": "5", "bestRating": "5" },
                            "reviewBody": "Finally a free expense tracker that actually works. The AI categorization saves me hours every month and the tax export is perfect for my freelance business."
                        },
                        {
                            "@@type": "Review",
                            "author": { "@@type": "Person", "name": "James K." },
                            "datePublished": "2025-12-03",
                            "reviewRating": { "@@type": "Rating", "ratingValue": "5", "bestRating": "5" },
                            "reviewBody": "Switched from YNAB to LedgerIQ and saved $99/year. The AI subscription detection found $340 in charges I forgot about."
                        },
                        {
                            "@@type": "Review",
                            "author": { "@@type": "Person", "name": "Maria L." },
                            "datePublished": "2026-01-10",
                            "reviewRating": { "@@type": "Rating", "ratingValue": "5", "bestRating": "5" },
                            "reviewBody": "As a gig worker, tracking deductions was a nightmare. LedgerIQ maps everything to Schedule C automatically. My accountant loves it."
                        }
                    ],
                    "featureList": [
                        "AI-powered transaction categorization",
                        "Bank sync via Plaid",
                        "PDF and CSV bank statement upload",
                        "Automatic subscription detection",
                        "AI savings recommendations",
                        "IRS Schedule C tax export",
                        "Email receipt matching",
                        "Two-factor authentication"
                    ],
                    "provider": { "@@id": "https://ledgeriq.com/#organization" }
                }
                </script>
            @endif
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
