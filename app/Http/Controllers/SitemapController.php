<?php

namespace App\Http\Controllers;

use App\Models\SeoPage;

class SitemapController extends Controller
{
    public function index()
    {
        $now = now()->toW3cString();

        $staticPages = [
            ['url' => '/', 'priority' => '1.0', 'changefreq' => 'weekly', 'lastmod' => $now],
            ['url' => '/features', 'priority' => '0.9', 'changefreq' => 'monthly', 'lastmod' => $now],
            ['url' => '/how-it-works', 'priority' => '0.8', 'changefreq' => 'monthly', 'lastmod' => $now],
            ['url' => '/about', 'priority' => '0.7', 'changefreq' => 'monthly', 'lastmod' => $now],
            ['url' => '/faq', 'priority' => '0.8', 'changefreq' => 'monthly', 'lastmod' => $now],
            ['url' => '/contact', 'priority' => '0.6', 'changefreq' => 'yearly', 'lastmod' => $now],
            ['url' => '/blog', 'priority' => '0.8', 'changefreq' => 'daily', 'lastmod' => $now],
            ['url' => '/privacy', 'priority' => '0.3', 'changefreq' => 'yearly', 'lastmod' => $now],
            ['url' => '/terms', 'priority' => '0.3', 'changefreq' => 'yearly', 'lastmod' => $now],
            ['url' => '/data-retention', 'priority' => '0.3', 'changefreq' => 'yearly', 'lastmod' => $now],
            ['url' => '/security-policy', 'priority' => '0.3', 'changefreq' => 'yearly', 'lastmod' => $now],
        ];

        $blogCategories = SeoPage::published()
            ->select('category')
            ->distinct()
            ->pluck('category')
            ->map(fn ($cat) => [
                'url' => '/blog/'.$cat,
                'priority' => '0.6',
                'changefreq' => 'weekly',
                'lastmod' => $now,
            ])
            ->all();

        $blogPages = SeoPage::published()
            ->select('slug', 'updated_at')
            ->orderByDesc('updated_at')
            ->get();

        return response()
            ->view('seo.sitemap', [
                'staticPages' => array_merge($staticPages, $blogCategories),
                'blogPages' => $blogPages,
            ])
            ->header('Content-Type', 'application/xml');
    }
}
