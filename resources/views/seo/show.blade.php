@extends('seo.layout')

@section('title', $page->title)
@section('description', $page->meta_description)
@section('canonical', 'https://ledgeriq.com/blog/' . $page->slug)
@if($page->featured_image)
    @section('og_image', $page->featured_image)
@endif

@section('og_article')
    <meta property="article:published_time" content="{{ $page->published_at?->toIso8601String() }}">
    <meta property="article:modified_time" content="{{ $page->updated_at->toIso8601String() }}">
    <meta property="article:author" content="https://ledgeriq.com/about">
    <meta property="article:section" content="{{ ucfirst($page->category) }}">
@endsection

@php
    $defaultImages = [
        'comparison' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=1200&auto=format&fit=crop&q=80',
        'alternative' => 'https://images.unsplash.com/photo-1507679799987-c73779587ccf?w=1200&auto=format&fit=crop&q=80',
        'guide' => 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?w=1200&auto=format&fit=crop&q=80',
        'tax' => 'https://images.unsplash.com/photo-1554224154-26032ffc0d07?w=1200&auto=format&fit=crop&q=80',
        'industry' => 'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?w=1200&auto=format&fit=crop&q=80',
        'feature' => 'https://images.unsplash.com/photo-1677442136019-21780ecad995?w=1200&auto=format&fit=crop&q=80',
    ];
    $heroImage = $page->featured_image ?? ($defaultImages[$page->category] ?? $defaultImages['guide']);
    $categoryLabels = [
        'comparison' => 'Comparison Guide',
        'alternative' => 'Alternatives',
        'guide' => 'How-To Guide',
        'tax' => 'Tax & Deductions',
        'industry' => 'Industry Guide',
        'feature' => 'Product Feature',
    ];
@endphp

@section('jsonld')
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "Article",
    "headline": @json($page->h1),
    "description": @json($page->meta_description),
    "url": "https://ledgeriq.com/blog/{{ $page->slug }}",
    "datePublished": "{{ $page->published_at?->toIso8601String() }}",
    "dateModified": "{{ $page->updated_at->toIso8601String() }}",
    "author": {
        "@@type": "Person",
        "name": "LedgerIQ Team",
        "url": "https://ledgeriq.com/about"
    },
    "publisher": {
        "@@id": "https://ledgeriq.com/#organization"
    },
    "isPartOf": {
        "@@id": "https://ledgeriq.com/#website"
    },
    "mainEntityOfPage": {
        "@@type": "WebPage",
        "@@id": "https://ledgeriq.com/blog/{{ $page->slug }}"
    },
    "image": {
        "@@type": "ImageObject",
        "url": "{{ $heroImage }}",
        "width": 1200,
        "height": 630
    },
    "speakable": {
        "@@type": "SpeakableSpecification",
        "cssSelector": ["article h1", "article .prose-blog > p:first-of-type", "article h2"]
    },
    "keywords": @json($page->keywords ?? []),
    "about": {
        "@@type": "SoftwareApplication",
        "@@id": "https://ledgeriq.com/#software"
    }
}
</script>
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "BreadcrumbList",
    "itemListElement": [
        { "@@type": "ListItem", "position": 1, "name": "Home", "item": "https://ledgeriq.com" },
        { "@@type": "ListItem", "position": 2, "name": "Blog", "item": "https://ledgeriq.com/blog" },
        { "@@type": "ListItem", "position": 3, "name": "{{ $categoryLabels[$page->category] ?? ucfirst($page->category) }}", "item": "https://ledgeriq.com/blog/{{ $page->category }}" },
        { "@@type": "ListItem", "position": 4, "name": @json($page->title), "item": "https://ledgeriq.com/blog/{{ $page->slug }}" }
    ]
}
</script>
@if(!empty($page->faq_items))
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "FAQPage",
    "mainEntity": [
        @foreach($page->faq_items as $i => $faq)
        {
            "@@type": "Question",
            "name": @json($faq['question']),
            "acceptedAnswer": {
                "@@type": "Answer",
                "text": @json($faq['answer'])
            }
        }{{ $i < count($page->faq_items) - 1 ? ',' : '' }}
        @endforeach
    ]
}
</script>
@endif
@endsection

@section('content')
<!-- Breadcrumb -->
<div class="border-b border-slate-200 bg-slate-50 px-6 py-3">
    <nav class="mx-auto max-w-4xl text-sm text-slate-500" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-1">
            <li><a href="/" class="transition-colors hover:text-slate-700">Home</a></li>
            <li><svg class="mx-1 h-3.5 w-3.5 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg></li>
            <li><a href="/blog" class="transition-colors hover:text-slate-700">Blog</a></li>
            <li><svg class="mx-1 h-3.5 w-3.5 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg></li>
            <li><a href="/blog/{{ $page->category }}" class="transition-colors hover:text-slate-700">{{ $categoryLabels[$page->category] ?? ucfirst($page->category) }}</a></li>
            <li><svg class="mx-1 h-3.5 w-3.5 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg></li>
            <li class="text-slate-700">{{ Str::limit($page->title, 40) }}</li>
        </ol>
    </nav>
</div>

<!-- Hero Image + Article Header -->
<div class="relative">
    <div class="aspect-[3/1] w-full overflow-hidden sm:aspect-[4/1]">
        <img
            src="{{ $heroImage }}"
            alt="{{ $page->featured_image_alt ?? $page->h1 }}"
            class="h-full w-full object-cover"
            width="1200"
            height="400"
            loading="eager"
        >
        <div class="gradient-overlay absolute inset-0"></div>
    </div>
    <div class="absolute inset-x-0 bottom-0 px-6 pb-8 sm:pb-12">
        <div class="mx-auto max-w-4xl">
            <div class="category-{{ $page->category }} mb-4">
                <span class="cat-badge inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide shadow-sm backdrop-blur-sm">
                    {{ $categoryLabels[$page->category] ?? ucfirst($page->category) }}
                </span>
            </div>
            <h1 class="font-serif-display text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl" style="text-shadow: 0 2px 8px rgba(0,0,0,0.3);">
                {{ $page->h1 }}
            </h1>
        </div>
    </div>
</div>

<!-- Article Metadata Bar -->
<div class="border-b border-slate-200 bg-white px-6 py-4">
    <div class="mx-auto flex max-w-4xl flex-wrap items-center gap-4 text-sm text-slate-500">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 text-blue-600">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
            </div>
            <span class="font-medium text-slate-700">LedgerIQ Team</span>
        </div>
        <span class="text-slate-300">|</span>
        @if($page->published_at)
            <div class="flex items-center gap-1.5">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                <time datetime="{{ $page->published_at->toIso8601String() }}">{{ $page->published_at->format('F j, Y') }}</time>
            </div>
            <span class="text-slate-300">|</span>
        @endif
        <div class="flex items-center gap-1.5">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ $page->read_time }} min read</span>
        </div>
        @if($page->updated_at && $page->published_at && $page->updated_at->gt($page->published_at->addDay()))
            <span class="text-slate-300">|</span>
            <div class="flex items-center gap-1.5">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182"/></svg>
                <span>Updated <time datetime="{{ $page->updated_at->toIso8601String() }}">{{ $page->updated_at->format('F j, Y') }}</time></span>
            </div>
        @endif
    </div>
</div>

<!-- Article Excerpt -->
<div class="bg-slate-50 px-6 py-8">
    <div class="mx-auto max-w-3xl">
        <p class="font-serif-display text-xl leading-relaxed text-slate-700 sm:text-2xl">{{ $page->excerpt }}</p>
    </div>
</div>

<!-- Article Body -->
<article class="px-6 py-12">
    <div class="prose-blog prose prose-lg prose-slate mx-auto max-w-3xl prose-headings:font-bold prose-headings:tracking-tight prose-a:text-blue-600 prose-a:no-underline hover:prose-a:underline prose-img:rounded-xl prose-h2:text-2xl prose-h3:text-xl prose-p:leading-relaxed prose-li:leading-relaxed">
        {!! $page->content !!}
    </div>
</article>

<!-- FAQ Section -->
@if(!empty($page->faq_items))
<div class="border-t border-slate-200 bg-slate-50 px-6 py-16">
    <div class="mx-auto max-w-3xl">
        <div class="mb-8 text-center">
            <span class="inline-flex items-center gap-2 rounded-full bg-blue-100 px-4 py-1.5 text-sm font-medium text-blue-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/></svg>
                FAQ
            </span>
            <h2 class="font-serif-display mt-4 text-3xl font-bold text-slate-900">Frequently Asked Questions</h2>
        </div>
        <div class="space-y-3">
            @foreach($page->faq_items as $faq)
                <details class="faq-toggle group rounded-xl border border-slate-200 bg-white shadow-sm">
                    <summary class="flex cursor-pointer items-center justify-between px-6 py-5 text-left font-semibold text-slate-900 transition-colors hover:text-blue-600">
                        <span>{{ $faq['question'] }}</span>
                        <svg class="ml-4 h-5 w-5 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </summary>
                    <div class="border-t border-slate-100 px-6 py-5 text-slate-600 leading-relaxed">
                        {{ $faq['answer'] }}
                    </div>
                </details>
            @endforeach
        </div>
    </div>
</div>
@endif

<!-- CTA Banner -->
<div class="px-6 py-16">
    <div class="mx-auto max-w-4xl">
        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-800 px-8 py-12 text-center shadow-xl sm:px-16">
            <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle at 80% 20%, white 0%, transparent 50%);"></div>
            <div class="relative">
                <h2 class="font-serif-display text-2xl font-bold text-white sm:text-3xl">Track Your Expenses Automatically with AI</h2>
                <p class="mx-auto mt-4 max-w-xl text-blue-100">LedgerIQ categorizes transactions, detects unused subscriptions, finds savings, and exports tax deductions. 100% free.</p>
                <div class="mt-8 flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                    <a href="/register" class="inline-flex items-center gap-2 rounded-lg bg-white px-8 py-3.5 text-lg font-semibold text-blue-700 shadow-md transition-all hover:bg-blue-50 hover:shadow-lg">
                        Get Started Free
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                    </a>
                    <a href="/features" class="inline-flex items-center gap-1 text-sm font-medium text-blue-200 transition-colors hover:text-white">
                        See all features
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Related Articles (Same Category) -->
@if($related->isNotEmpty())
<div class="border-t border-slate-200 bg-white px-6 py-16">
    <div class="mx-auto max-w-7xl">
        <div class="mb-8 flex items-center justify-between">
            <h2 class="font-serif-display text-2xl font-bold text-slate-900">Related Articles</h2>
            <a href="/blog/{{ $page->category }}" class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 transition-colors hover:text-blue-700">
                View all
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
        </div>
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($related as $article)
                <a href="/blog/{{ $article->slug }}" class="group">
                    <div class="card-hover overflow-hidden rounded-xl border border-slate-200 bg-white">
                        @php
                            $relImg = $article->featured_image ?? ($defaultImages[$article->category] ?? $defaultImages['guide']);
                        @endphp
                        <div class="relative aspect-[16/10] overflow-hidden">
                            <img src="{{ $relImg }}" alt="{{ $article->featured_image_alt ?? $article->title }}" class="image-zoom h-full w-full object-cover" width="600" height="375" loading="lazy">
                        </div>
                        <div class="p-4">
                            <div class="category-{{ $article->category }} mb-2">
                                <span class="cat-badge inline-block rounded-full px-2 py-0.5 text-xs font-semibold uppercase tracking-wide">{{ $article->category }}</span>
                            </div>
                            <h3 class="font-serif-display text-sm font-bold leading-snug text-slate-900 transition-colors group-hover:text-blue-600">{{ $article->title }}</h3>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</div>
@endif

<!-- Cross-Category Links (SEO) -->
@if(isset($crossLinks) && $crossLinks->isNotEmpty())
<div class="border-t border-slate-200 bg-slate-50 px-6 py-16">
    <div class="mx-auto max-w-7xl">
        <div class="mb-8 text-center">
            <h2 class="font-serif-display text-2xl font-bold text-slate-900">Explore More from LedgerIQ</h2>
            <p class="mt-2 text-slate-500">Discover guides across all our categories</p>
        </div>
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($crossLinks as $article)
                <a href="/blog/{{ $article->slug }}" class="group">
                    <div class="card-hover overflow-hidden rounded-xl border border-slate-200 bg-white">
                        @php
                            $crossImg = $article->featured_image ?? ($defaultImages[$article->category] ?? $defaultImages['guide']);
                        @endphp
                        <div class="relative aspect-[16/10] overflow-hidden">
                            <img src="{{ $crossImg }}" alt="{{ $article->featured_image_alt ?? $article->title }}" class="image-zoom h-full w-full object-cover" width="600" height="375" loading="lazy">
                        </div>
                        <div class="p-4">
                            <div class="category-{{ $article->category }} mb-2">
                                <span class="cat-badge inline-block rounded-full px-2 py-0.5 text-xs font-semibold uppercase tracking-wide">{{ $article->category }}</span>
                            </div>
                            <h3 class="font-serif-display text-sm font-bold leading-snug text-slate-900 transition-colors group-hover:text-blue-600">{{ $article->title }}</h3>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</div>
@endif
@endsection
