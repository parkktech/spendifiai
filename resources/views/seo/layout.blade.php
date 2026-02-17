<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title'){{ str_contains(View::yieldContent('title'), 'SpendifiAI') ? '' : ' - SpendifiAI' }}</title>
    <meta name="description" content="@yield('description')">
    <link rel="canonical" href="@yield('canonical')">

    <!-- Open Graph -->
    <meta property="og:type" content="@yield('og_type', 'article')">
    <meta property="og:locale" content="en_US">
    <meta property="og:site_name" content="SpendifiAI">
    <meta property="og:title" content="@yield('title'){{ str_contains(View::yieldContent('title'), 'SpendifiAI') ? '' : ' - SpendifiAI' }}">
    <meta property="og:description" content="@yield('description')">
    <meta property="og:url" content="@yield('canonical')">
    @hasSection('og_image')
        <meta property="og:image" content="@yield('og_image')">
    @else
        <meta property="og:image" content="https://spendifiai.com/images/spendifiai-og.png">
    @endif
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="@yield('title') - SpendifiAI AI Expense Tracking">
    @yield('og_article')

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@spendifiai">
    <meta name="twitter:title" content="@yield('title'){{ str_contains(View::yieldContent('title'), 'SpendifiAI') ? '' : ' - SpendifiAI' }}">
    <meta name="twitter:description" content="@yield('description')">
    @hasSection('og_image')
        <meta name="twitter:image" content="@yield('og_image')">
    @else
        <meta name="twitter:image" content="https://spendifiai.com/images/spendifiai-og.png">
    @endif
    <meta name="twitter:image:alt" content="@yield('title') - SpendifiAI AI Expense Tracking">

    <!-- AI Discovery -->
    <link rel="author" href="/llms.txt">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/favicon.png">
    <link rel="apple-touch-icon" href="/images/spendifiai-icon.png">
    <meta name="theme-color" content="#2563eb">

    <!-- Fonts: Source Serif 4 (Inter is self-hosted via CSS) -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css?family=source-serif-4:400,600,700&display=swap" rel="stylesheet">

    @vite(['resources/js/app.tsx'])

    <style>
        .font-serif-display { font-family: 'Source Serif 4', Georgia, 'Times New Roman', serif; }

        /* ── Prose typography ── */
        .prose-blog h2 {
            font-family: 'Source Serif 4', Georgia, serif;
            position: relative;
            padding-top: 2.5rem;
            margin-top: 3rem;
        }
        .prose-blog h2::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, #e2e8f0 0%, #cbd5e1 50%, #e2e8f0 100%);
        }
        .prose-blog > h2:first-child { padding-top: 0; margin-top: 0; }
        .prose-blog > h2:first-child::before { display: none; }
        .prose-blog h3 {
            font-family: 'Source Serif 4', Georgia, serif;
            margin-top: 2rem;
            padding-bottom: 0.25rem;
        }
        .prose-blog p {
            margin-top: 1.25em;
            margin-bottom: 1.25em;
        }

        /* ── Blockquotes & callouts ── */
        .prose-blog blockquote {
            border-left: 4px solid #2563eb;
            background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%);
            padding: 1.5rem 2rem;
            border-radius: 0 0.75rem 0.75rem 0;
            font-style: normal;
            margin: 2rem 0;
            position: relative;
        }
        .prose-blog blockquote::before {
            content: '';
            position: absolute;
            left: -4px;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, #2563eb 0%, #7c3aed 100%);
            border-radius: 4px 0 0 4px;
        }
        .prose-blog blockquote p { margin: 0; }
        .prose-blog blockquote p + p { margin-top: 0.75em; }
        .prose-blog blockquote strong:first-child {
            color: #1e40af;
            display: inline-block;
            margin-bottom: 0.25em;
        }

        /* ── Key takeaway boxes ── */
        .prose-blog .key-takeaway {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            border: 1px solid #bbf7d0;
            border-radius: 0.75rem;
            padding: 1.5rem 2rem;
            margin: 2rem 0;
        }
        .prose-blog .key-takeaway p { margin: 0; }
        .prose-blog .key-takeaway strong:first-child { color: #15803d; }

        /* ── Stat highlight cards ── */
        .prose-blog .stat-highlight {
            background: linear-gradient(135deg, #faf5ff 0%, #f5f3ff 100%);
            border: 1px solid #e9d5ff;
            border-radius: 0.75rem;
            padding: 1.25rem 1.75rem;
            margin: 1.5rem 0;
            font-size: 1.05em;
            text-align: center;
        }
        .prose-blog .stat-highlight strong {
            color: #7c3aed;
            font-size: 1.15em;
        }

        /* ── Inline CTA boxes ── */
        .prose-blog .inline-cta {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1px solid #93c5fd;
            border-radius: 0.75rem;
            padding: 1.5rem 2rem;
            margin: 2rem 0;
            text-align: center;
        }
        .prose-blog .inline-cta a {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #2563eb;
            color: white !important;
            padding: 0.625rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.9em;
            text-decoration: none !important;
            margin-top: 0.75rem;
            transition: background 0.2s;
        }
        .prose-blog .inline-cta a:hover { background: #1d4ed8; }

        /* ── Tables ── */
        .prose-blog table { border-collapse: collapse; width: 100%; margin: 1.5rem 0; border-radius: 0.75rem; overflow: hidden; }
        .prose-blog th { background: #f1f5f9; font-weight: 600; text-align: left; padding: 0.75rem 1rem; border: 1px solid #e2e8f0; font-size: 0.9em; }
        .prose-blog td { padding: 0.75rem 1rem; border: 1px solid #e2e8f0; }
        .prose-blog tr:hover td { background: #f8fafc; }
        .prose-blog thead tr:first-child th:first-child { border-top-left-radius: 0.75rem; }
        .prose-blog thead tr:first-child th:last-child { border-top-right-radius: 0.75rem; }

        /* ── Lists ── */
        .prose-blog ul { margin-top: 1em; margin-bottom: 1em; }
        .prose-blog li { margin-top: 0.5em; margin-bottom: 0.5em; }
        .prose-blog li p { margin: 0; }

        /* ── Horizontal rules ── */
        .prose-blog hr {
            border: none;
            height: 1px;
            background: linear-gradient(90deg, transparent, #cbd5e1, transparent);
            margin: 2.5rem 0;
        }

        /* ── Card & image animations ── */
        .card-hover { transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .card-hover:hover { transform: translateY(-4px); box-shadow: 0 20px 40px -12px rgba(0,0,0,0.12); }
        .image-zoom { transition: transform 0.6s ease; }
        .group:hover .image-zoom { transform: scale(1.05); }
        .gradient-overlay { background: linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.02) 40%, rgba(0,0,0,0.7) 100%); }

        /* ── Category colors ── */
        .category-comparison { --cat-color: #7c3aed; --cat-bg: #f5f3ff; --cat-border: #ddd6fe; }
        .category-alternative { --cat-color: #059669; --cat-bg: #ecfdf5; --cat-border: #a7f3d0; }
        .category-guide { --cat-color: #d97706; --cat-bg: #fffbeb; --cat-border: #fde68a; }
        .category-tax { --cat-color: #dc2626; --cat-bg: #fef2f2; --cat-border: #fecaca; }
        .category-industry { --cat-color: #0891b2; --cat-bg: #ecfeff; --cat-border: #a5f3fc; }
        .category-feature { --cat-color: #2563eb; --cat-bg: #eff6ff; --cat-border: #bfdbfe; }
        .cat-badge { background: var(--cat-bg); color: var(--cat-color); border: 1px solid var(--cat-border); }

        /* ── FAQ toggles ── */
        .faq-toggle[open] summary svg { transform: rotate(180deg); }
        .faq-toggle summary svg { transition: transform 0.3s ease; }
    </style>

    <!-- JSON-LD: Organization -->
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
    <!-- JSON-LD: WebSite -->
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
    <!-- JSON-LD: SoftwareApplication -->
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
        "screenshot": {
            "@@type": "ImageObject",
            "url": "https://spendifiai.com/images/spendifiai-og.png",
            "caption": "SpendifiAI AI expense tracking dashboard",
            "width": 1200,
            "height": 630
        },
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
    @yield('jsonld')
</head>
<body class="font-sans antialiased bg-white text-slate-800">
    <!-- Nav -->
    <header class="sticky top-0 z-50 border-b border-slate-200/80 bg-white/95 backdrop-blur-md">
        <nav class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
            <a href="/" class="flex items-center gap-2.5">
                <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" class="h-9 w-9 shrink-0">
                    <rect width="40" height="40" rx="10" fill="url(#nav-logo-grad)"/>
                    <path d="M8 28.5 Q20 31 32 28.5" stroke="white" stroke-width="2.2" stroke-linecap="round" fill="none"/>
                    <rect x="12.5" y="22" width="3.5" height="6.5" rx="1.5" fill="white" fill-opacity="0.55"/>
                    <rect x="18" y="17" width="3.5" height="11.5" rx="1.5" fill="white" fill-opacity="0.75"/>
                    <rect x="23.5" y="12" width="3.5" height="16.5" rx="1.5" fill="white"/>
                    <circle cx="25.25" cy="9.5" r="1.6" fill="white" fill-opacity="0.9"/>
                    <line x1="25.25" y1="6" x2="25.25" y2="7.5" stroke="white" stroke-width="1" stroke-linecap="round" opacity="0.7"/>
                    <line x1="22.5" y1="9.5" x2="23.2" y2="9.5" stroke="white" stroke-width="1" stroke-linecap="round" opacity="0.7"/>
                    <line x1="27.3" y1="9.5" x2="28" y2="9.5" stroke="white" stroke-width="1" stroke-linecap="round" opacity="0.7"/>
                    <defs><linearGradient id="nav-logo-grad" x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse"><stop stop-color="#2563eb"/><stop offset="1" stop-color="#7c3aed"/></linearGradient></defs>
                </svg>
                <span class="text-xl font-bold tracking-tight text-slate-900">Spendifi<span class="text-blue-600">AI</span></span>
            </a>
            <div class="hidden items-center gap-8 md:flex">
                <a href="/features" class="text-sm font-medium text-slate-500 transition-colors hover:text-slate-900">Features</a>
                <a href="/how-it-works" class="text-sm font-medium text-slate-500 transition-colors hover:text-slate-900">How It Works</a>
                <a href="/blog" class="text-sm font-medium text-slate-900">Blog</a>
                <a href="/faq" class="text-sm font-medium text-slate-500 transition-colors hover:text-slate-900">FAQ</a>
            </div>
            <div class="hidden items-center gap-3 md:flex">
                <a href="/login" class="text-sm font-medium text-slate-500 transition-colors hover:text-slate-900">Log in</a>
                <a href="/register" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-blue-700 hover:shadow-md">Get Started Free</a>
            </div>
            <a href="/register" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 md:hidden">Get Started</a>
        </nav>
    </header>

    <main>
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="border-t border-slate-200 bg-slate-900 text-slate-400">
        <div class="mx-auto max-w-7xl px-6 py-16">
            <div class="grid gap-12 sm:grid-cols-2 lg:grid-cols-5">
                <div class="lg:col-span-2">
                    <div class="flex items-center gap-2.5">
                        <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" class="h-9 w-9 shrink-0">
                            <rect width="40" height="40" rx="10" fill="url(#footer-logo-grad)"/>
                            <path d="M8 28.5 Q20 31 32 28.5" stroke="white" stroke-width="2.2" stroke-linecap="round" fill="none"/>
                            <rect x="12.5" y="22" width="3.5" height="6.5" rx="1.5" fill="white" fill-opacity="0.55"/>
                            <rect x="18" y="17" width="3.5" height="11.5" rx="1.5" fill="white" fill-opacity="0.75"/>
                            <rect x="23.5" y="12" width="3.5" height="16.5" rx="1.5" fill="white"/>
                            <circle cx="25.25" cy="9.5" r="1.6" fill="white" fill-opacity="0.9"/>
                            <line x1="25.25" y1="6" x2="25.25" y2="7.5" stroke="white" stroke-width="1" stroke-linecap="round" opacity="0.7"/>
                            <line x1="22.5" y1="9.5" x2="23.2" y2="9.5" stroke="white" stroke-width="1" stroke-linecap="round" opacity="0.7"/>
                            <line x1="27.3" y1="9.5" x2="28" y2="9.5" stroke="white" stroke-width="1" stroke-linecap="round" opacity="0.7"/>
                            <defs><linearGradient id="footer-logo-grad" x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse"><stop stop-color="#2563eb"/><stop offset="1" stop-color="#7c3aed"/></linearGradient></defs>
                        </svg>
                        <span class="text-xl font-bold tracking-tight text-white">Spendifi<span class="text-blue-400">AI</span></span>
                    </div>
                    <p class="mt-4 max-w-xs text-sm leading-relaxed text-slate-400">
                        AI-powered expense tracking that helps you save money, track taxes, and take control of your finances. 100% free, forever.
                    </p>
                    <div class="mt-6 flex items-center gap-2 rounded-lg bg-slate-800 px-3 py-2 text-xs text-slate-300">
                        <svg class="h-4 w-4 text-green-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                        <span>Bank-level security via Plaid</span>
                    </div>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-300">Product</h3>
                    <ul class="mt-4 space-y-3">
                        <li><a href="/features" class="text-sm text-slate-400 transition-colors hover:text-white">Features</a></li>
                        <li><a href="/how-it-works" class="text-sm text-slate-400 transition-colors hover:text-white">How It Works</a></li>
                        <li><a href="/faq" class="text-sm text-slate-400 transition-colors hover:text-white">FAQ</a></li>
                        <li><a href="/security-policy" class="text-sm text-slate-400 transition-colors hover:text-white">Security</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-300">Resources</h3>
                    <ul class="mt-4 space-y-3">
                        <li><a href="/blog" class="text-sm text-slate-400 transition-colors hover:text-white">Blog</a></li>
                        <li><a href="/blog/tax" class="text-sm text-slate-400 transition-colors hover:text-white">Tax Guides</a></li>
                        <li><a href="/blog/comparison" class="text-sm text-slate-400 transition-colors hover:text-white">Comparisons</a></li>
                        <li><a href="/blog/guide" class="text-sm text-slate-400 transition-colors hover:text-white">How-To Guides</a></li>
                        <li><a href="/sitemap.xml" class="text-sm text-slate-400 transition-colors hover:text-white">Sitemap</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-300">Company</h3>
                    <ul class="mt-4 space-y-3">
                        <li><a href="/about" class="text-sm text-slate-400 transition-colors hover:text-white">About</a></li>
                        <li><a href="/contact" class="text-sm text-slate-400 transition-colors hover:text-white">Contact</a></li>
                        <li><a href="/privacy" class="text-sm text-slate-400 transition-colors hover:text-white">Privacy Policy</a></li>
                        <li><a href="/terms" class="text-sm text-slate-400 transition-colors hover:text-white">Terms of Service</a></li>
                        <li><a href="/data-retention" class="text-sm text-slate-400 transition-colors hover:text-white">Data Retention</a></li>
                    </ul>
                </div>
            </div>
            <div class="mt-12 flex flex-col items-center justify-between gap-4 border-t border-slate-800 pt-8 sm:flex-row">
                <p class="text-sm text-slate-500">&copy; {{ date('Y') }} SpendifiAI. All rights reserved.</p>
                <div class="flex items-center gap-1 text-sm text-slate-500">
                    <span>Rated</span>
                    <div class="flex text-amber-400">
                        @for($i = 0; $i < 5; $i++)
                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        @endfor
                    </div>
                    <span>4.8/5 from 247 users</span>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>
