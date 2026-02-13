<?php

namespace App\Console\Commands;

use App\Models\SeoPage;
use Illuminate\Console\Command;

class EnrichSeoContent extends Command
{
    protected $signature = 'seo:enrich {--dry-run : Show what would be changed without saving}';

    protected $description = 'Enrich SEO content with FAQ items, internal cross-links, and image alt text';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $pages = SeoPage::published()->get();

        $this->info("Processing {$pages->count()} articles...");

        $stats = ['faq' => 0, 'alt' => 0, 'links' => 0];

        // Build a lookup of all articles for cross-linking
        $allArticles = $pages->map(fn ($p) => [
            'id' => $p->id,
            'slug' => $p->slug,
            'title' => $p->title,
            'category' => $p->category,
            'keywords' => $p->keywords ?? [],
        ])->all();

        foreach ($pages as $page) {
            $changed = false;

            // 1. Fill missing featured_image_alt
            if (empty($page->featured_image_alt)) {
                $page->featured_image_alt = $this->generateAltText($page);
                $stats['alt']++;
                $changed = true;
            }

            // 2. Add FAQ items if missing
            if (empty($page->faq_items)) {
                $faqs = $this->generateFaqItems($page);
                if (! empty($faqs)) {
                    $page->faq_items = $faqs;
                    $stats['faq']++;
                    $changed = true;
                }
            }

            // 3. Add internal cross-links if content lacks blog links
            $blogLinkCount = substr_count($page->content, '/blog/');
            if ($blogLinkCount < 2) {
                $enrichedContent = $this->injectCrossLinks($page, $allArticles);
                if ($enrichedContent !== $page->content) {
                    $page->content = $enrichedContent;
                    $stats['links']++;
                    $changed = true;
                }
            }

            if ($changed && ! $dryRun) {
                $page->save();
            }
        }

        $this->info("Alt text added: {$stats['alt']}");
        $this->info("FAQ items added: {$stats['faq']}");
        $this->info("Cross-links injected: {$stats['links']}");

        if ($dryRun) {
            $this->warn('Dry run — no changes saved.');
        }

        return self::SUCCESS;
    }

    private function generateAltText(SeoPage $page): string
    {
        $title = $page->title;
        // Remove "| LedgerIQ" suffix if present
        $title = preg_replace('/\s*[|\-]\s*LedgerIQ$/i', '', $title);

        $prefixes = [
            'comparison' => 'Comparison chart showing',
            'alternative' => 'Software comparison for',
            'guide' => 'Illustration for',
            'tax' => 'Tax guide illustration for',
            'industry' => 'Industry guide image for',
            'feature' => 'Feature screenshot showing',
        ];

        $prefix = $prefixes[$page->category] ?? 'Article image for';

        return "{$prefix} {$title}";
    }

    private function generateFaqItems(SeoPage $page): array
    {
        $category = $page->category;
        $title = $page->title;

        return match ($category) {
            'comparison' => $this->comparisonFaqs($page),
            'alternative' => $this->alternativeFaqs($page),
            'guide' => $this->guideFaqs($page),
            'tax' => $this->taxFaqs($page),
            'industry' => $this->industryFaqs($page),
            'feature' => $this->featureFaqs($page),
            default => [],
        };
    }

    private function comparisonFaqs(SeoPage $page): array
    {
        // Extract competitor name from title like "LedgerIQ vs Mint: ..."
        preg_match('/vs\s+(.+?)[\s:]/i', $page->title, $matches);
        $competitor = $matches[1] ?? 'the competitor';

        return [
            [
                'question' => "Is LedgerIQ really free compared to {$competitor}?",
                'answer' => 'Yes, LedgerIQ is 100% free with no premium tiers, no trial periods, and no credit card required. Unlike many competitors that charge monthly or annual fees, LedgerIQ provides AI-powered expense tracking, tax deduction exports, and subscription detection at no cost.',
            ],
            [
                'question' => "Can I switch from {$competitor} to LedgerIQ easily?",
                'answer' => "Yes. You can import your transaction history by connecting your bank through Plaid or uploading your bank statements as PDF or CSV files. LedgerIQ's AI will automatically categorize your imported transactions, so you won't need to start from scratch.",
            ],
            [
                'question' => "How does LedgerIQ's AI categorization compare to {$competitor}?",
                'answer' => "LedgerIQ uses Claude AI by Anthropic to categorize transactions with over 85% accuracy. Unlike rule-based systems that match merchant names, LedgerIQ's AI considers transaction amount, frequency, account type, and context. When confidence is low, it asks you a quick question instead of guessing wrong.",
            ],
            [
                'question' => 'Does LedgerIQ support tax deduction tracking?',
                'answer' => 'Yes. LedgerIQ automatically maps your business expenses to IRS Schedule C categories and lets you export a complete tax package as Excel, PDF, or CSV. You can also email the package directly to your accountant. This feature is especially valuable for freelancers and self-employed workers.',
            ],
            [
                'question' => 'Is my financial data secure with LedgerIQ?',
                'answer' => 'Absolutely. LedgerIQ encrypts all sensitive data at rest using AES-256 encryption. Bank connections use Plaid, which is SOC 2 Type II certified and trusted by major fintech apps. All connections use HTTPS/TLS, and optional two-factor authentication is available.',
            ],
        ];
    }

    private function alternativeFaqs(SeoPage $page): array
    {
        // Extract the product from title like "Best Mint Alternatives..."
        preg_match('/Best\s+(.+?)\s+Alternatives/i', $page->title, $matches);
        $product = $matches[1] ?? 'the software';

        return [
            [
                'question' => "What is the best free alternative to {$product}?",
                'answer' => 'LedgerIQ is the best free alternative offering AI-powered expense categorization, automatic subscription detection, savings recommendations, and IRS Schedule C tax exports. Unlike most alternatives that charge monthly fees, LedgerIQ is completely free with no premium tiers.',
            ],
            [
                'question' => "How do I migrate from {$product} to a different expense tracker?",
                'answer' => 'Most expense trackers let you export your data as CSV files. You can then import this data into your new tool, or connect your bank directly through Plaid to pull in fresh transaction data. LedgerIQ also supports PDF and CSV bank statement uploads as an alternative to direct bank connections.',
            ],
            [
                'question' => 'What features should I look for in an expense tracker?',
                'answer' => 'Key features to consider include automatic transaction categorization (ideally AI-powered), bank sync capabilities, subscription detection, tax deduction tracking, security features like encryption and 2FA, and export options. For freelancers, look for Schedule C tax mapping and business/personal expense separation.',
            ],
            [
                'question' => 'Are paid alternatives worth it over free options?',
                'answer' => 'It depends on your needs. Many paid alternatives offer features that are now available for free in tools like LedgerIQ. Before paying $99-200/year for a budgeting app, compare the features available in free alternatives. AI-powered categorization, tax exports, and subscription detection are all available without a subscription fee.',
            ],
        ];
    }

    private function guideFaqs(SeoPage $page): array
    {
        $title = $page->title;
        $slug = $page->slug;

        // Generic guide FAQs tailored to common topics
        $faqs = [];

        if (str_contains($slug, 'tax') || str_contains($slug, 'deduction') || str_contains($slug, 'schedule-c') || str_contains($slug, '1099')) {
            $faqs = [
                [
                    'question' => 'Do I need an accountant to track my tax deductions?',
                    'answer' => 'Not necessarily. AI-powered tools like LedgerIQ can automatically categorize your expenses and map them to IRS Schedule C lines. However, consulting a tax professional is recommended for complex situations. LedgerIQ can export your categorized deductions in formats your accountant can easily review.',
                ],
                [
                    'question' => 'What happens if I miss a tax deduction?',
                    'answer' => 'Missing deductions means paying more in taxes than necessary. The average freelancer misses $3,000-5,000 in deductions annually. Using automated expense tracking throughout the year helps capture every deductible expense in real-time rather than scrambling during tax season.',
                ],
                [
                    'question' => 'How far back can I claim missed deductions?',
                    'answer' => 'You can generally amend tax returns to claim missed deductions for up to three years from the original filing date. However, it is much easier and more reliable to track deductions as they occur rather than retroactively searching through bank statements.',
                ],
                [
                    'question' => 'Can AI really help with tax deduction tracking?',
                    'answer' => 'Yes. AI-powered expense trackers like LedgerIQ analyze each transaction contextually, considering factors like merchant type, amount, frequency, and your account designation (business vs personal). This catches deductions that manual tracking or simple rule-based systems often miss.',
                ],
            ];
        } elseif (str_contains($slug, 'subscription') || str_contains($slug, 'cancel')) {
            $faqs = [
                [
                    'question' => 'How much can I save by canceling unused subscriptions?',
                    'answer' => 'The average American spends $219 per month on subscriptions and underestimates their spending by $133. Many people have 2-4 subscriptions they have forgotten about entirely. Identifying and canceling these can save $500-2,000+ per year.',
                ],
                [
                    'question' => 'How does automatic subscription detection work?',
                    'answer' => 'Tools like LedgerIQ scan your transaction history for recurring charge patterns across weekly, monthly, quarterly, and annual frequencies. They then analyze billing gaps to identify subscriptions that may have stopped billing or that you may have forgotten about.',
                ],
                [
                    'question' => 'Should I cancel all subscriptions to save money?',
                    'answer' => 'Not necessarily. The goal is to identify subscriptions you are not actively using or getting value from. Keep services that genuinely improve your life or business, and cancel those you have forgotten about or rarely use. Focus on the highest-cost unused subscriptions first.',
                ],
                [
                    'question' => 'Can I track subscriptions automatically without linking my bank?',
                    'answer' => 'Yes. You can upload PDF or CSV bank statements to LedgerIQ, which will detect recurring charges in your transaction history. This is a good option if you prefer not to connect your bank account directly through Plaid.',
                ],
            ];
        } elseif (str_contains($slug, 'budget') || str_contains($slug, 'spending') || str_contains($slug, 'save') || str_contains($slug, 'saving')) {
            $faqs = [
                [
                    'question' => 'What is the easiest way to start budgeting?',
                    'answer' => 'Start by tracking your spending for one month without changing any habits. Use an AI-powered tool like LedgerIQ to automatically categorize your transactions. Once you see where your money goes, you can set realistic budget limits based on actual spending patterns rather than guesses.',
                ],
                [
                    'question' => 'How does AI help with budgeting?',
                    'answer' => 'AI analyzes your spending patterns to provide personalized insights. It automatically categorizes transactions with high accuracy, detects recurring charges you might have forgotten, identifies spending trends, and suggests specific areas where you could save money based on your actual habits.',
                ],
                [
                    'question' => 'Do I need to track every purchase manually?',
                    'answer' => 'No. By connecting your bank account through Plaid or uploading bank statements, tools like LedgerIQ automatically import and categorize all your transactions. This eliminates manual data entry and ensures nothing is missed.',
                ],
                [
                    'question' => 'What budgeting method works best for beginners?',
                    'answer' => 'The 50/30/20 rule is a great starting point: allocate 50% of income to needs, 30% to wants, and 20% to savings and debt repayment. Once you are comfortable tracking your spending, you can explore more detailed methods like zero-based budgeting.',
                ],
            ];
        } elseif (str_contains($slug, 'freelanc') || str_contains($slug, 'self-employ') || str_contains($slug, 'business')) {
            $faqs = [
                [
                    'question' => 'What expenses can freelancers deduct?',
                    'answer' => 'Freelancers can deduct ordinary and necessary business expenses including home office costs, internet and phone bills, software subscriptions, equipment, professional development, marketing, travel, and health insurance premiums. The key is that the expense must be directly related to your business.',
                ],
                [
                    'question' => 'Should I separate business and personal bank accounts?',
                    'answer' => 'Yes, strongly recommended. Separating accounts makes expense tracking easier, simplifies tax preparation, and provides cleaner records if audited. If you use a single account, tools like LedgerIQ let you tag accounts as mixed and use AI to separate business from personal transactions.',
                ],
                [
                    'question' => 'How do I track expenses if I am just starting freelancing?',
                    'answer' => 'Start by connecting your bank account to an expense tracker like LedgerIQ or uploading your bank statements. The AI will automatically categorize your transactions as business or personal. Set up a system from day one — it is much harder to reconstruct expenses at year-end.',
                ],
                [
                    'question' => 'Do freelancers need to make quarterly tax payments?',
                    'answer' => 'Generally yes. If you expect to owe $1,000 or more in taxes, the IRS requires quarterly estimated tax payments (due April 15, June 15, September 15, and January 15). Missing these deadlines can result in penalties. Tracking your income and expenses throughout the year helps you estimate payments accurately.',
                ],
            ];
        } else {
            // Generic guide FAQs
            $faqs = [
                [
                    'question' => 'How long does it take to set up expense tracking?',
                    'answer' => 'With LedgerIQ, setup takes under 5 minutes. Connect your bank through Plaid, and your transactions are imported automatically. Alternatively, upload a PDF or CSV bank statement. The AI begins categorizing your expenses immediately with no manual configuration needed.',
                ],
                [
                    'question' => 'Do I need any accounting knowledge to track expenses?',
                    'answer' => 'No. LedgerIQ is designed for people without accounting backgrounds. The AI handles categorization, and the dashboard presents your finances in simple, visual formats. For tax purposes, expenses are automatically mapped to the correct IRS categories.',
                ],
                [
                    'question' => 'Is my financial data safe with online expense trackers?',
                    'answer' => 'Reputable expense trackers like LedgerIQ use bank-level security. This includes AES-256 encryption for stored data, secure bank connections through Plaid (SOC 2 Type II certified), HTTPS for all connections, and optional two-factor authentication.',
                ],
                [
                    'question' => 'Can I use expense tracking on my phone?',
                    'answer' => 'LedgerIQ is a web application that works on any device with a browser, including smartphones and tablets. The responsive design adapts to your screen size, so you can check your finances, review AI questions, and track spending from anywhere.',
                ],
            ];
        }

        return $faqs;
    }

    private function taxFaqs(SeoPage $page): array
    {
        // Tax articles already have FAQs from the seeder, but just in case
        return $this->guideFaqs($page);
    }

    private function industryFaqs(SeoPage $page): array
    {
        // Industry articles already have FAQs, but fallback
        return $this->guideFaqs($page);
    }

    private function featureFaqs(SeoPage $page): array
    {
        // Feature articles already have FAQs, but fallback
        return $this->guideFaqs($page);
    }

    private function injectCrossLinks(SeoPage $page, array $allArticles): string
    {
        $content = $page->content;

        // Find related articles from same category (exclude self)
        $sameCategoryArticles = array_filter($allArticles, fn ($a) => $a['category'] === $page->category && $a['id'] !== $page->id);

        // Find articles from different categories that share keywords
        $otherCategoryArticles = array_filter($allArticles, fn ($a) => $a['category'] !== $page->category && $a['id'] !== $page->id);

        // Pick 2-3 from same category and 1-2 from other categories
        $sameCatPicks = array_slice(array_values($sameCategoryArticles), 0, 3);
        $otherCatPicks = array_slice(array_values($otherCategoryArticles), 0, 2);

        $relatedLinks = array_merge($sameCatPicks, $otherCatPicks);
        if (empty($relatedLinks)) {
            return $content;
        }

        // Build the "Related Reading" section
        $linksHtml = '<h2>Related Reading</h2><p>Explore more guides and resources from LedgerIQ:</p><ul>';
        foreach ($relatedLinks as $link) {
            $linksHtml .= '<li><a href="/blog/'.$link['slug'].'">'.$link['title'].'</a></li>';
        }
        $linksHtml .= '</ul>';

        // Insert before the last inline-cta div, or append to the end
        if (str_contains($content, '<div class="inline-cta">')) {
            // Find the last occurrence of inline-cta
            $lastCtaPos = strrpos($content, '<div class="inline-cta">');
            $content = substr($content, 0, $lastCtaPos).$linksHtml.substr($content, $lastCtaPos);
        } else {
            $content .= $linksHtml;
        }

        return $content;
    }
}
