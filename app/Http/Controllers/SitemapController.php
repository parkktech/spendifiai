<?php

namespace App\Http\Controllers;

use App\Models\SeoPage;

class SitemapController extends Controller
{
    public function index()
    {
        $staticPages = [
            ['url' => '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['url' => '/features', 'priority' => '0.9', 'changefreq' => 'monthly'],
            ['url' => '/how-it-works', 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['url' => '/about', 'priority' => '0.7', 'changefreq' => 'monthly'],
            ['url' => '/faq', 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['url' => '/contact', 'priority' => '0.6', 'changefreq' => 'yearly'],
            ['url' => '/blog', 'priority' => '0.8', 'changefreq' => 'daily'],
            ['url' => '/privacy', 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => '/terms', 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => '/data-retention', 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => '/security-policy', 'priority' => '0.3', 'changefreq' => 'yearly'],
        ];

        $blogPages = SeoPage::published()
            ->select('slug', 'updated_at')
            ->orderByDesc('updated_at')
            ->get();

        return response()
            ->view('seo.sitemap', [
                'staticPages' => $staticPages,
                'blogPages' => $blogPages,
            ])
            ->header('Content-Type', 'application/xml');
    }
}
