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
                'description' => 'SpendifiAI features: AI categorization, Plaid bank sync, subscription detection, savings tips, Schedule C tax export, and receipt parsing. All free.',
            ],
            'HowItWorks' => [
                'title' => 'How It Works - Set Up AI Expense Tracking in 5 Minutes',
                'description' => 'Get started in under 5 minutes. Connect your bank via Plaid or upload statements and let AI categorize your transactions automatically.',
            ],
            'About' => [
                'title' => 'About SpendifiAI - AI-Powered Personal Finance for Everyone',
                'description' => 'Free AI-powered personal finance for freelancers and small businesses. Automatic expense tracking, tax deductions, and savings insights.',
            ],
            'FAQ' => [
                'title' => 'FAQ - AI Expense Tracking Questions Answered',
                'description' => 'Common questions about SpendifiAI: security, AI accuracy, bank connections, tax exports, Plaid integration, and subscription detection.',
            ],
            'Contact' => [
                'title' => 'Contact Us - SpendifiAI Support',
                'description' => 'Contact SpendifiAI at support@spendifiai.com. Questions about AI expense tracking, bank connections, or tax exports? We are here to help.',
            ],
            'Legal/PrivacyPolicy' => [
                'title' => 'Privacy Policy',
                'description' => 'How we collect, use, and protect your personal and financial data. Plaid disclosures, data retention, and your privacy rights explained.',
            ],
            'Legal/TermsOfService' => [
                'title' => 'Terms of Service',
                'description' => 'Usage rules, disclaimers, intellectual property, account responsibilities, and service limitations for SpendifiAI AI expense tracking.',
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
        $seoTitle = $seo ? $seo['title'] . (str_contains($seo['title'], 'SpendifiAI') ? '' : ' - SpendifiAI') : config('app.name', 'SpendifiAI');
        $seoDescription = $seo['description'] ?? '';
        $seoCanonical = 'https://spendifiai.com' . rtrim($page['url'] ?? '/', '?');
        $seoOgImage = 'https://spendifiai.com/images/spendifiai-og.png';
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
            <meta property="og:locale" content="en_US">
            <meta property="og:site_name" content="SpendifiAI">
            <meta property="og:title" content="{{ $seoTitle }}">
            <meta property="og:description" content="{{ $seoDescription }}">
            <meta property="og:url" content="{{ $seoCanonical }}">
            <meta property="og:image" content="{{ $seoOgImage }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="{{ $seo['title'] }} - SpendifiAI AI Expense Tracking">

            {{-- Twitter Card --}}
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:site" content="@spendifiai">
            <meta name="twitter:title" content="{{ $seoTitle }}">
            <meta name="twitter:description" content="{{ $seoDescription }}">
            <meta name="twitter:image" content="{{ $seoOgImage }}">
            <meta name="twitter:image:alt" content="{{ $seo['title'] }} - SpendifiAI AI Expense Tracking">
        @endif

        @if($seo)
            {{-- JSON-LD: Organization --}}
            <script type="application/ld+json">
            {
                "@@context": "https://schema.org",
                "@@type": "Organization",
                "@@id": "https://spendifiai.com/#organization",
                "name": "SpendifiAI",
                "url": "https://spendifiai.com",
                "logo": {
                    "@@type": "ImageObject",
                    "url": "https://spendifiai.com/images/spendifiai-icon.png",
                    "width": 512,
                    "height": 512
                },
                "email": "support@spendifiai.com",
                "description": "AI-powered expense tracking that automatically categorizes transactions, detects unused subscriptions, finds savings, and prepares tax deductions. 100% free.",
                "contactPoint": {
                    "@@type": "ContactPoint",
                    "contactType": "customer support",
                    "email": "support@spendifiai.com",
                    "url": "https://spendifiai.com/contact"
                },
                "sameAs": [
                    "https://spendifiai.com/about",
                    "https://spendifiai.com/blog"
                ]
            }
            </script>
            {{-- JSON-LD: WebSite --}}
            <script type="application/ld+json">
            {
                "@@context": "https://schema.org",
                "@@type": "WebSite",
                "@@id": "https://spendifiai.com/#website",
                "name": "SpendifiAI",
                "url": "https://spendifiai.com",
                "publisher": { "@@id": "https://spendifiai.com/#organization" },
                "potentialAction": {
                    "@@type": "SearchAction",
                    "target": "https://spendifiai.com/blog?q={search_term_string}",
                    "query-input": "required name=search_term_string"
                }
            }
            </script>
            @if($component === 'Welcome')
                {{-- JSON-LD: SoftwareApplication (homepage only) --}}
                <script type="application/ld+json">
                {
                    "@@context": "https://schema.org",
                    "@@type": "SoftwareApplication",
                    "@@id": "https://spendifiai.com/#software",
                    "name": "SpendifiAI",
                    "url": "https://spendifiai.com",
                    "applicationCategory": "FinanceApplication",
                    "operatingSystem": "Web browser",
                    "description": "Free AI-powered expense tracker with automatic transaction categorization, subscription detection, savings recommendations, and IRS Schedule C tax export.",
                    "offers": {
                        "@@type": "Offer",
                        "price": "0",
                        "priceCurrency": "USD",
                        "availability": "https://schema.org/InStock",
                        "priceValidUntil": "2027-12-31",
                        "url": "https://spendifiai.com/register",
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
                            "reviewBody": "Switched from YNAB to SpendifiAI and saved $99/year. The AI subscription detection found $340 in charges I forgot about."
                        },
                        {
                            "@@type": "Review",
                            "author": { "@@type": "Person", "name": "Maria L." },
                            "datePublished": "2026-01-10",
                            "reviewRating": { "@@type": "Rating", "ratingValue": "5", "bestRating": "5" },
                            "reviewBody": "As a gig worker, tracking deductions was a nightmare. SpendifiAI maps everything to Schedule C automatically. My accountant loves it."
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
                    "provider": { "@@id": "https://spendifiai.com/#organization" }
                }
                </script>
            @endif
            @if($component === 'Features')
                {{-- JSON-LD: ItemList (server-side for crawlers) --}}
                <script type="application/ld+json">
                {
                    "@@context": "https://schema.org",
                    "@@type": "ItemList",
                    "name": "SpendifiAI Features",
                    "description": "Complete list of AI expense tracking features available for free",
                    "numberOfItems": 9,
                    "itemListElement": [
                        { "@@type": "ListItem", "position": 1, "name": "AI Transaction Categorization", "description": "Claude AI automatically categorizes transactions with 85%+ accuracy" },
                        { "@@type": "ListItem", "position": 2, "name": "Plaid Bank Sync", "description": "Connect 12,000+ banks securely via Plaid SOC 2 Type II" },
                        { "@@type": "ListItem", "position": 3, "name": "Bank Statement Upload", "description": "Upload PDF or CSV statements when Plaid is unavailable" },
                        { "@@type": "ListItem", "position": 4, "name": "Subscription Detection", "description": "Find recurring charges and unused subscriptions automatically" },
                        { "@@type": "ListItem", "position": 5, "name": "AI Savings Recommendations", "description": "Personalized tips to save money based on spending analysis" },
                        { "@@type": "ListItem", "position": 6, "name": "Tax Deduction Export", "description": "IRS Schedule C mapped reports in Excel, PDF, and CSV" },
                        { "@@type": "ListItem", "position": 7, "name": "Email Receipt Matching", "description": "Gmail integration for automatic receipt matching" },
                        { "@@type": "ListItem", "position": 8, "name": "Business/Personal Split", "description": "Tag accounts as business, personal, mixed, or investment" },
                        { "@@type": "ListItem", "position": 9, "name": "Budget Dashboard", "description": "Waterfall charts, monthly bills, home affordability analysis" }
                    ]
                }
                </script>
            @endif
            @if($component === 'FAQ')
                {{-- JSON-LD: FAQPage (server-side for crawlers) --}}
                <script type="application/ld+json">
                {
                    "@@context": "https://schema.org",
                    "@@type": "FAQPage",
                    "mainEntity": [
                        {
                            "@@type": "Question",
                            "name": "What is SpendifiAI?",
                            "acceptedAnswer": { "@@type": "Answer", "text": "SpendifiAI is an AI-powered expense tracking platform that automatically categorizes transactions, detects unused subscriptions, provides savings recommendations, and generates tax-ready reports." }
                        },
                        {
                            "@@type": "Question",
                            "name": "Is SpendifiAI really free?",
                            "acceptedAnswer": { "@@type": "Answer", "text": "Yes, 100% free. No premium tiers, no trial periods, no hidden fees, and no credit card required." }
                        },
                        {
                            "@@type": "Question",
                            "name": "How is my data protected?",
                            "acceptedAnswer": { "@@type": "Answer", "text": "All sensitive data is encrypted with AES-256-CBC. Passwords use bcrypt hashing. All connections use HTTPS with TLS 1.2+. We offer optional two-factor authentication." }
                        },
                        {
                            "@@type": "Question",
                            "name": "Does SpendifiAI store my bank credentials?",
                            "acceptedAnswer": { "@@type": "Answer", "text": "No. Bank connections are handled by Plaid, a SOC 2 Type II certified platform. Your bank credentials never touch our servers." }
                        },
                        {
                            "@@type": "Question",
                            "name": "How does AI categorization work?",
                            "acceptedAnswer": { "@@type": "Answer", "text": "Claude AI analyzes each transaction and assigns a category with a confidence score. Transactions above 85% confidence are auto-categorized. Lower confidence ones generate questions for you." }
                        },
                        {
                            "@@type": "Question",
                            "name": "Can I separate business and personal expenses?",
                            "acceptedAnswer": { "@@type": "Answer", "text": "Yes. Tag each bank account as personal, business, mixed, or investment. This purpose cascades to all transactions for accurate categorization." }
                        },
                        {
                            "@@type": "Question",
                            "name": "What tax export formats are available?",
                            "acceptedAnswer": { "@@type": "Answer", "text": "SpendifiAI generates Excel workbooks, PDF cover sheets, and CSV files. All expenses are mapped to IRS Schedule C categories." }
                        },
                        {
                            "@@type": "Question",
                            "name": "How does subscription detection work?",
                            "acceptedAnswer": { "@@type": "Answer", "text": "Our algorithms scan transaction patterns for recurring charges — same merchant, similar amounts, regular intervals. We flag unused subscriptions and calculate annual costs." }
                        },
                        {
                            "@@type": "Question",
                            "name": "Can I delete my data?",
                            "acceptedAnswer": { "@@type": "Answer", "text": "Yes. Delete your account from Settings. All data is permanently removed within 30 days. Plaid access tokens are revoked immediately." }
                        },
                        {
                            "@@type": "Question",
                            "name": "Can I connect multiple bank accounts?",
                            "acceptedAnswer": { "@@type": "Answer", "text": "Yes. Connect multiple accounts from multiple banks. Each can be independently tagged for accurate business and personal expense tracking." }
                        }
                    ]
                }
                </script>
            @endif
            {{-- JSON-LD: BreadcrumbList --}}
                @php
                    $breadcrumbMap = [
                        'Welcome' => [],
                        'Features' => [['name' => 'Features', 'url' => '/features']],
                        'HowItWorks' => [['name' => 'How It Works', 'url' => '/how-it-works']],
                        'About' => [['name' => 'About', 'url' => '/about']],
                        'FAQ' => [['name' => 'FAQ', 'url' => '/faq']],
                        'Contact' => [['name' => 'Contact', 'url' => '/contact']],
                        'Legal/PrivacyPolicy' => [['name' => 'Privacy Policy', 'url' => '/privacy']],
                        'Legal/TermsOfService' => [['name' => 'Terms of Service', 'url' => '/terms']],
                        'Legal/DataRetention' => [['name' => 'Data Retention', 'url' => '/data-retention']],
                        'Legal/Security' => [['name' => 'Security', 'url' => '/security-policy']],
                    ];
                    $crumbs = $breadcrumbMap[$component] ?? [];
                @endphp
                @if(!empty($crumbs))
                <script type="application/ld+json">
                {
                    "@@context": "https://schema.org",
                    "@@type": "BreadcrumbList",
                    "itemListElement": [
                        { "@@type": "ListItem", "position": 1, "name": "Home", "item": "https://spendifiai.com" }@foreach($crumbs as $i => $crumb),
                        { "@@type": "ListItem", "position": {{ $i + 2 }}, "name": "{{ $crumb['name'] }}", "item": "https://spendifiai.com{{ $crumb['url'] }}" }@endforeach

                    ]
                }
                </script>
                @endif
            @if($component === 'HowItWorks')
                {{-- JSON-LD: HowTo (server-side for crawlers) --}}
                <script type="application/ld+json">
                {
                    "@@context": "https://schema.org",
                    "@@type": "HowTo",
                    "name": "How to Set Up AI Expense Tracking with SpendifiAI",
                    "description": "Get started with SpendifiAI in under 5 minutes. Connect your bank or upload statements and let AI categorize your transactions automatically.",
                    "totalTime": "PT5M",
                    "step": [
                        {
                            "@@type": "HowToStep",
                            "position": 1,
                            "name": "Create Your Free Account",
                            "text": "Sign up with your email or Google account. No credit card required."
                        },
                        {
                            "@@type": "HowToStep",
                            "position": 2,
                            "name": "Connect Your Bank",
                            "text": "Link your bank accounts securely through Plaid, or upload PDF/CSV bank statements."
                        },
                        {
                            "@@type": "HowToStep",
                            "position": 3,
                            "name": "AI Categorizes Your Transactions",
                            "text": "Claude AI automatically categorizes every transaction with 85%+ accuracy and maps business expenses to IRS Schedule C categories."
                        },
                        {
                            "@@type": "HowToStep",
                            "position": 4,
                            "name": "Get Insights and Save Money",
                            "text": "View your dashboard for spending analysis, subscription detection, savings recommendations, and tax-ready reports."
                        }
                    ]
                }
                </script>
            @endif
        @endif

        <!-- AI Discovery -->
        <link rel="author" href="/llms.txt">

        <!-- Favicon -->
        <link rel="icon" type="image/png" href="/favicon.png">
        <link rel="apple-touch-icon" href="/images/spendifiai-icon.png">
        <meta name="theme-color" content="#2563eb">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
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
