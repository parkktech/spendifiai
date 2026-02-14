{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @foreach($staticPages as $page)
    <url>
        <loc>https://spendifiai.com{{ $page['url'] }}</loc>
        @if(!empty($page['lastmod']))
        <lastmod>{{ $page['lastmod'] }}</lastmod>
        @endif
        <changefreq>{{ $page['changefreq'] }}</changefreq>
        <priority>{{ $page['priority'] }}</priority>
    </url>
    @endforeach
    @foreach($blogPages as $page)
    <url>
        <loc>https://spendifiai.com/blog/{{ $page->slug }}</loc>
        <lastmod>{{ $page->updated_at->toW3cString() }}</lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
    @endforeach
</urlset>
