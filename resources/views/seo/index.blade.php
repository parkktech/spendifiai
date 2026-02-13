@extends('seo.layout')

@section('title', ($categoryTitle ?? 'Blog') . ' - AI Expense Tracking Guides & Tips')
@section('description', $categoryDescription ?? 'Free guides on expense tracking, tax deductions, subscription management, and personal finance. Expert tips for freelancers and small business owners.')
@section('canonical', 'https://ledgeriq.com/blog' . ($currentCategory ? '/' . $currentCategory : ''))
@section('og_type', 'website')

@section('jsonld')
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "CollectionPage",
    "name": "{{ $categoryTitle ?? 'LedgerIQ Blog' }}",
    "url": "https://ledgeriq.com/blog{{ $currentCategory ? '/' . $currentCategory : '' }}",
    "description": @json($categoryDescription ?? 'Free guides on expense tracking, tax deductions, and personal finance for freelancers and small business owners.'),
    "isPartOf": { "@@id": "https://ledgeriq.com/#website" },
    "mainEntity": {
        "@@type": "ItemList",
        "numberOfItems": {{ $pages->total() }},
        "itemListElement": [
            @foreach($pages->take(10) as $i => $item)
            {
                "@@type": "ListItem",
                "position": {{ $i + 1 }},
                "url": "https://ledgeriq.com/blog/{{ $item->slug }}",
                "name": @json($item->title)
            }{{ $i < min($pages->count(), 10) - 1 ? ',' : '' }}
            @endforeach
        ]
    }
}
</script>
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "BreadcrumbList",
    "itemListElement": [
        { "@@type": "ListItem", "position": 1, "name": "Home", "item": "https://ledgeriq.com" },
        { "@@type": "ListItem", "position": 2, "name": "Blog", "item": "https://ledgeriq.com/blog" }
        @if($currentCategory)
        ,{ "@@type": "ListItem", "position": 3, "name": "{{ $categoryTitle }}", "item": "https://ledgeriq.com/blog/{{ $currentCategory }}" }
        @endif
    ]
}
</script>
@endsection

@section('content')
@php
    $labels = [
        'comparison' => 'Comparisons',
        'alternative' => 'Alternatives',
        'guide' => 'How-To Guides',
        'tax' => 'Tax & Deductions',
        'industry' => 'By Industry',
        'feature' => 'Features',
    ];
    $catIcons = [
        'comparison' => '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>',
        'alternative' => '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>',
        'guide' => '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>',
        'tax' => '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>',
        'industry' => '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>',
        'feature' => '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"/></svg>',
    ];
    $defaultImages = [
        'comparison' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800&auto=format&fit=crop&q=80',
        'alternative' => 'https://images.unsplash.com/photo-1507679799987-c73779587ccf?w=800&auto=format&fit=crop&q=80',
        'guide' => 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?w=800&auto=format&fit=crop&q=80',
        'tax' => 'https://images.unsplash.com/photo-1554224154-26032ffc0d07?w=800&auto=format&fit=crop&q=80',
        'industry' => 'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?w=800&auto=format&fit=crop&q=80',
        'feature' => 'https://images.unsplash.com/photo-1677442136019-21780ecad995?w=800&auto=format&fit=crop&q=80',
    ];
@endphp

<!-- Hero Section -->
<div class="relative overflow-hidden bg-slate-900 px-6 py-20 sm:py-28">
    <div class="absolute inset-0 opacity-20">
        <div class="absolute inset-0" style="background-image: radial-gradient(circle at 25% 25%, rgba(37,99,235,0.3) 0%, transparent 50%), radial-gradient(circle at 75% 75%, rgba(124,58,237,0.2) 0%, transparent 50%);"></div>
    </div>
    <div class="relative mx-auto max-w-4xl text-center">
        <div class="mb-4 inline-flex items-center gap-2 rounded-full border border-blue-400/30 bg-blue-500/10 px-4 py-1.5 text-sm font-medium text-blue-300">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
            The LedgerIQ Blog
        </div>
        <h1 class="font-serif-display text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl">
            {{ $categoryTitle ?? 'Guides, Tips & Comparisons' }}
        </h1>
        <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-slate-300">
            Expert guides on expense tracking, tax deductions, subscription management, and AI-powered finance tools for freelancers and small business owners.
        </p>
    </div>
</div>

<!-- Category Filters -->
<div class="sticky top-[65px] z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="mx-auto max-w-7xl overflow-x-auto px-6">
        <div class="flex items-center gap-2 py-4">
            <a href="/blog"
               class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-4 py-2 text-sm font-medium transition-all {{ !$currentCategory ? 'bg-slate-900 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">
                All Articles
            </a>
            @foreach($categories as $cat => $count)
                <a href="/blog/{{ $cat }}"
                   class="category-{{ $cat }} inline-flex shrink-0 items-center gap-1.5 rounded-full px-4 py-2 text-sm font-medium transition-all {{ $currentCategory === $cat ? 'cat-badge shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">
                    {!! $catIcons[$cat] ?? '' !!}
                    {{ $labels[$cat] ?? ucfirst($cat) }}
                    <span class="rounded-full {{ $currentCategory === $cat ? 'bg-white/20' : 'bg-slate-200' }} px-1.5 py-0.5 text-xs">{{ $count }}</span>
                </a>
            @endforeach
        </div>
    </div>
</div>

<div class="mx-auto max-w-7xl px-6 py-12">
    @if($pages->isNotEmpty())
        {{-- Featured Hero Card (first article) --}}
        @php $featured = $pages->first(); @endphp
        <a href="/blog/{{ $featured->slug }}" class="group mb-12 block">
            <div class="card-hover overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div class="grid lg:grid-cols-2">
                    <div class="relative aspect-[16/10] overflow-hidden lg:aspect-auto">
                        <img
                            src="{{ $featured->featured_image ?? ($defaultImages[$featured->category] ?? $defaultImages['guide']) }}"
                            alt="{{ $featured->featured_image_alt ?? $featured->title }}"
                            class="image-zoom h-full w-full object-cover"
                            loading="eager"
                        >
                        <div class="absolute inset-0 bg-gradient-to-t from-black/20 to-transparent lg:bg-gradient-to-r"></div>
                    </div>
                    <div class="flex flex-col justify-center p-8 lg:p-12">
                        <div class="category-{{ $featured->category }} mb-4">
                            <span class="cat-badge inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide">
                                {!! $catIcons[$featured->category] ?? '' !!}
                                {{ $featured->category }}
                            </span>
                        </div>
                        <h2 class="font-serif-display text-2xl font-bold text-slate-900 transition-colors group-hover:text-blue-600 sm:text-3xl">
                            {{ $featured->title }}
                        </h2>
                        <p class="mt-4 text-base leading-relaxed text-slate-500">{{ Str::limit($featured->excerpt, 200) }}</p>
                        <div class="mt-6 flex items-center gap-4">
                            <span class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 transition-colors group-hover:text-blue-700">
                                Read Article
                                <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                            </span>
                            @if($featured->published_at)
                                <span class="text-sm text-slate-400">{{ $featured->published_at->format('M j, Y') }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </a>

        {{-- Articles Grid --}}
        <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($pages->skip(1) as $page)
                <a href="/blog/{{ $page->slug }}" class="group">
                    <article class="card-hover flex h-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <div class="relative aspect-[16/10] overflow-hidden">
                            <img
                                src="{{ $page->featured_image ?? ($defaultImages[$page->category] ?? $defaultImages['guide']) }}"
                                alt="{{ $page->featured_image_alt ?? $page->title }}"
                                class="image-zoom h-full w-full object-cover"
                                loading="lazy"
                            >
                            <div class="absolute inset-0 bg-gradient-to-t from-black/10 to-transparent"></div>
                            <div class="category-{{ $page->category }} absolute left-3 top-3">
                                <span class="cat-badge inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold uppercase tracking-wide shadow-sm backdrop-blur-sm">
                                    {{ $page->category }}
                                </span>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col p-6">
                            <h2 class="font-serif-display text-lg font-bold leading-snug text-slate-900 transition-colors group-hover:text-blue-600">
                                {{ $page->title }}
                            </h2>
                            <p class="mt-3 flex-1 text-sm leading-relaxed text-slate-500">{{ Str::limit($page->excerpt, 120) }}</p>
                            <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
                                <span class="text-sm font-medium text-blue-600 transition-colors group-hover:text-blue-700">Read more</span>
                                @if($page->published_at)
                                    <span class="text-xs text-slate-400">{{ $page->published_at->format('M j, Y') }}</span>
                                @endif
                            </div>
                        </div>
                    </article>
                </a>
            @endforeach
        </div>
    @else
        <div class="py-20 text-center">
            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100">
                <svg class="h-8 w-8 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
            </div>
            <p class="text-lg font-medium text-slate-600">No articles found in this category.</p>
            <p class="mt-2 text-sm text-slate-400">Check back soon for new content.</p>
        </div>
    @endif

    <!-- Pagination -->
    <div class="mt-12">
        {{ $pages->appends(request()->query())->links() }}
    </div>
</div>

<!-- CTA Section -->
<div class="relative overflow-hidden bg-slate-900 px-6 py-20">
    <div class="absolute inset-0 opacity-30" style="background-image: radial-gradient(circle at 50% 50%, rgba(37,99,235,0.4) 0%, transparent 60%);"></div>
    <div class="relative mx-auto max-w-2xl text-center">
        <h2 class="font-serif-display text-3xl font-bold text-white sm:text-4xl">Ready to automate your expense tracking?</h2>
        <p class="mx-auto mt-4 max-w-xl text-lg text-slate-300">Join thousands using LedgerIQ to track expenses, find savings, and prepare taxes with AI. 100% free.</p>
        <a href="/register" class="mt-8 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-8 py-4 text-lg font-semibold text-white shadow-lg transition-all hover:bg-blue-500 hover:shadow-xl">
            Get Started Free
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
        </a>
        <p class="mt-4 text-sm text-slate-400">No credit card required. No premium tiers.</p>
    </div>
</div>
@endsection
