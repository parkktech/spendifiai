<?php

namespace App\Http\Controllers;

use App\Models\SeoPage;
use Illuminate\Http\Request;

class SeoPageController extends Controller
{
    public function index(Request $request)
    {
        $category = $request->query('category');

        $query = SeoPage::published()
            ->select('id', 'slug', 'title', 'meta_description', 'category', 'excerpt', 'featured_image', 'featured_image_alt', 'published_at')
            ->orderByDesc('published_at');

        if ($category) {
            $query->inCategory($category);
        }

        $pages = $query->paginate(24);

        $categories = SeoPage::published()
            ->selectRaw('category, count(*) as count')
            ->groupBy('category')
            ->orderBy('category')
            ->pluck('count', 'category');

        return view('seo.index', [
            'pages' => $pages,
            'categories' => $categories,
            'currentCategory' => $category,
        ]);
    }

    public function show(string $slug)
    {
        $page = SeoPage::published()->where('slug', $slug)->firstOrFail();

        $related = SeoPage::published()
            ->where('category', $page->category)
            ->where('id', '!=', $page->id)
            ->inRandomOrder()
            ->limit(4)
            ->get(['id', 'slug', 'title', 'excerpt', 'category', 'featured_image', 'featured_image_alt']);

        $crossLinks = SeoPage::published()
            ->where('category', '!=', $page->category)
            ->inRandomOrder()
            ->limit(4)
            ->get(['id', 'slug', 'title', 'category', 'featured_image', 'featured_image_alt']);

        return view('seo.show', [
            'page' => $page,
            'related' => $related,
            'crossLinks' => $crossLinks,
        ]);
    }

    public function category(string $category)
    {
        $pages = SeoPage::published()
            ->inCategory($category)
            ->orderByDesc('published_at')
            ->paginate(24);

        $categories = SeoPage::published()
            ->selectRaw('category, count(*) as count')
            ->groupBy('category')
            ->orderBy('category')
            ->pluck('count', 'category');

        $categoryLabels = [
            'comparison' => 'Comparison Guides',
            'alternative' => 'Software Alternatives',
            'guide' => 'How-To Guides',
            'tax' => 'Tax & Deduction Guides',
            'industry' => 'Industry Guides',
            'feature' => 'Features & Solutions',
        ];

        return view('seo.index', [
            'pages' => $pages,
            'categories' => $categories,
            'currentCategory' => $category,
            'categoryTitle' => $categoryLabels[$category] ?? ucfirst($category),
        ]);
    }
}
