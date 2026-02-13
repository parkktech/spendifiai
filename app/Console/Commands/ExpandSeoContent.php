<?php

namespace App\Console\Commands;

use App\Models\SeoPage;
use Illuminate\Console\Command;

class ExpandSeoContent extends Command
{
    protected $signature = 'seo:expand {--dry-run : Show what would be changed without saving} {--min-length=5000 : Minimum content length in characters}';

    protected $description = 'Expand short SEO articles with additional depth sections';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $minLength = (int) $this->option('min-length');

        $pages = SeoPage::published()
            ->whereRaw('LENGTH(content) < ?', [$minLength])
            ->orderBy('id')
            ->get();

        $this->info("Found {$pages->count()} articles under {$minLength} characters.");

        $expanded = 0;
        foreach ($pages as $page) {
            $additionalContent = $this->generateExpansion($page);
            if (empty($additionalContent)) {
                continue;
            }

            $originalLength = strlen($page->content);

            // Insert expansion before "Related Reading" or before last inline-cta, or append
            $content = $page->content;
            $insertPoint = $this->findInsertionPoint($content);
            $content = substr($content, 0, $insertPoint).$additionalContent.substr($content, $insertPoint);

            $newLength = strlen($content);
            $this->line("  [{$page->slug}] {$originalLength} → {$newLength} chars (+".($newLength - $originalLength).')');

            if (! $dryRun) {
                $page->content = $content;
                $page->save();
            }
            $expanded++;
        }

        $this->info("Expanded: {$expanded} articles.");
        if ($dryRun) {
            $this->warn('Dry run — no changes saved.');
        }

        return self::SUCCESS;
    }

    private function findInsertionPoint(string $content): int
    {
        // Insert before "Related Reading" section if it exists
        $relatedPos = strpos($content, '<h2>Related Reading</h2>');
        if ($relatedPos !== false) {
            return $relatedPos;
        }

        // Insert before last inline-cta
        $lastCtaPos = strrpos($content, '<div class="inline-cta">');
        if ($lastCtaPos !== false) {
            return $lastCtaPos;
        }

        // Append to end
        return strlen($content);
    }

    private function generateExpansion(SeoPage $page): string
    {
        return match ($page->category) {
            'comparison' => $this->expandComparison($page),
            'alternative' => $this->expandAlternative($page),
            'guide' => $this->expandGuide($page),
            default => '',
        };
    }

    private function expandComparison(SeoPage $page): string
    {
        preg_match('/vs\s+(.+?)[\s:]/i', $page->title, $matches);
        $competitor = $matches[1] ?? 'the competitor';

        $sections = [];

        // Add "Who Should Choose" section if not already present
        if (! str_contains($page->content, 'Who Should Choose') && ! str_contains($page->content, 'Who Is It For')) {
            $sections[] = <<<HTML

<h2>Who Should Choose LedgerIQ Over {$competitor}</h2>
<p>LedgerIQ is the better choice if you:</p>
<ul>
<li><strong>Want zero subscription costs</strong> — LedgerIQ is completely free with no premium tiers, while many competitors charge \$6-15/month or \$50-200/year</li>
<li><strong>Need AI-powered categorization</strong> — Rule-based systems misclassify 15-30% of transactions. LedgerIQ's Claude AI achieves 85%+ accuracy by understanding context, not just merchant names</li>
<li><strong>Track business expenses for taxes</strong> — Automatic IRS Schedule C mapping and export means less work at tax time and fewer missed deductions</li>
<li><strong>Have subscriptions you've forgotten about</strong> — LedgerIQ's frequency-based detection finds recurring charges across weekly, monthly, quarterly, and annual billing cycles</li>
<li><strong>Prefer bank statement uploads</strong> — Not everyone wants to link their bank electronically. LedgerIQ supports PDF and CSV statement imports with AI-powered transaction extraction</li>
</ul>

<h3>When {$competitor} Might Be Better</h3>
<p>No tool is perfect for everyone. {$competitor} could be a better fit if you need specific features like advanced investment tracking, joint account management with multiple users, or deep integration with a particular accounting workflow. However, for core expense tracking, categorization, and tax preparation, LedgerIQ offers more functionality at zero cost.</p>
HTML;
        }

        // Add "Making the Switch" section
        if (! str_contains($page->content, 'Making the Switch') && ! str_contains($page->content, 'How to Switch')) {
            $sections[] = <<<HTML

<h2>Making the Switch: Step-by-Step</h2>
<p>Transitioning from {$competitor} to LedgerIQ is straightforward:</p>
<ol>
<li><strong>Export your data</strong> — Download your transaction history from {$competitor} as a CSV file if available</li>
<li><strong>Create a LedgerIQ account</strong> — Sign up at <a href="/register">ledgeriq.com/register</a> (takes under 60 seconds)</li>
<li><strong>Connect your bank</strong> — Link your accounts through Plaid, or upload your bank statements as PDF or CSV files</li>
<li><strong>Let AI categorize</strong> — LedgerIQ will automatically categorize all imported transactions. Review any AI questions for low-confidence transactions</li>
<li><strong>Set up your profile</strong> — Tag your accounts as business or personal, and the AI will use this context for more accurate categorization going forward</li>
</ol>
<p>Most users complete the full migration in under 10 minutes. Your historical transaction data remains accessible in your previous tool if you need to reference it.</p>
HTML;
        }

        return implode("\n", $sections);
    }

    private function expandAlternative(SeoPage $page): string
    {
        preg_match('/Best\s+(.+?)\s+Alternatives/i', $page->title, $matches);
        $product = $matches[1] ?? 'the software';

        $sections = [];

        if (! str_contains($page->content, 'How to Choose') && ! str_contains($page->content, 'How We Evaluated')) {
            $sections[] = <<<HTML

<h2>How to Choose the Right Expense Tracker</h2>
<p>When evaluating alternatives to {$product}, consider these factors:</p>
<ul>
<li><strong>Total cost of ownership</strong> — Many tools advertise low monthly prices but costs add up. A \$10/month app costs \$120/year. LedgerIQ is genuinely free with no hidden fees or premium tiers</li>
<li><strong>Categorization accuracy</strong> — The biggest time-saver is automatic categorization. AI-powered tools like LedgerIQ achieve 85%+ accuracy vs 60-70% for rule-based systems, meaning far fewer manual corrections</li>
<li><strong>Tax preparation features</strong> — If you are self-employed or freelance, look for IRS Schedule C mapping and export functionality. This can save hours during tax season and catch deductions you would otherwise miss</li>
<li><strong>Bank connectivity options</strong> — Plaid integration covers 12,000+ institutions. But also check for statement upload support as a backup option</li>
<li><strong>Data security</strong> — Verify the tool uses encryption at rest, secure bank connections, and offers two-factor authentication</li>
<li><strong>Subscription detection</strong> — Tools that automatically find recurring charges and flag unused subscriptions can save hundreds per year in forgotten charges</li>
</ul>

<h3>Why Free Does Not Mean Low Quality</h3>
<p>Many users assume free tools must be inferior. LedgerIQ challenges this assumption by offering AI-powered features that most paid competitors lack. The key difference is the business model: while subscription-based tools need recurring revenue, LedgerIQ focuses on making expense tracking accessible to everyone, especially freelancers and self-employed individuals who are already managing tight budgets.</p>
HTML;
        }

        return implode("\n", $sections);
    }

    private function expandGuide(SeoPage $page): string
    {
        $slug = $page->slug;
        $sections = [];

        // Add practical tips section based on topic
        if (str_contains($slug, 'tax') || str_contains($slug, 'deduction') || str_contains($slug, 'schedule-c') || str_contains($slug, '1099')) {
            if (! str_contains($page->content, 'Common Mistakes') && ! str_contains($page->content, 'Mistakes to Avoid')) {
                $sections[] = <<<'HTML'

<h2>Common Tax Tracking Mistakes to Avoid</h2>
<p>Even experienced freelancers make these costly errors:</p>
<ul>
<li><strong>Mixing business and personal expenses</strong> — This creates confusion at tax time and raises audit risk. Tag your accounts as business or personal in LedgerIQ, and the AI will use this context for every categorization</li>
<li><strong>Waiting until tax season to organize</strong> — Retroactively categorizing a year of transactions is painful and error-prone. Automated tracking throughout the year captures everything in real-time</li>
<li><strong>Missing the home office deduction</strong> — If you work from home, you can deduct a portion of rent, utilities, internet, and insurance. The simplified method allows $5 per square foot up to 300 square feet ($1,500 max)</li>
<li><strong>Forgetting about mileage</strong> — Business driving at the 2026 IRS standard rate adds up quickly. Even 100 miles per week at $0.70/mile is $3,640 per year in deductions</li>
<li><strong>Not keeping digital records</strong> — The IRS requires documentation for all deductions. AI-powered tools create an automatic audit trail by linking transactions to categories with timestamps</li>
</ul>

<h3>How AI Improves Tax Deduction Accuracy</h3>
<p>Traditional expense tracking relies on you remembering to categorize every purchase correctly. AI-powered tools like LedgerIQ analyze each transaction in context, considering the merchant, amount, frequency, and your account designation. This catches deductions that manual tracking often misses, like recurring software subscriptions, professional development purchases, and mixed-use expenses that are partially deductible.</p>
HTML;
            }
        } elseif (str_contains($slug, 'budget') || str_contains($slug, 'spending') || str_contains($slug, 'expense') && ! str_contains($slug, 'tax')) {
            if (! str_contains($page->content, 'Getting Started') && ! str_contains($page->content, 'First Steps')) {
                $sections[] = <<<'HTML'

<h2>Getting Started: Your First Week of Tracking</h2>
<p>The first week of expense tracking sets the foundation. Here is a practical day-by-day approach:</p>
<ul>
<li><strong>Day 1: Connect your accounts</strong> — Link your primary checking and credit card accounts through Plaid, or upload your most recent bank statements. This gives you immediate visibility into recent spending</li>
<li><strong>Day 2-3: Review AI categorization</strong> — Check how the AI has categorized your transactions. Answer any questions it has about low-confidence items. This teaches the system your spending patterns</li>
<li><strong>Day 4-5: Tag your accounts</strong> — Mark each account as personal, business, or mixed. This dramatically improves categorization accuracy for future transactions</li>
<li><strong>Day 6: Check your subscriptions</strong> — Review the automatically detected recurring charges. You will likely find at least one or two you had forgotten about</li>
<li><strong>Day 7: Set your first budget goal</strong> — Based on the spending data you have reviewed, set a realistic monthly limit for one category. Start small and expand as tracking becomes habitual</li>
</ul>
<p>After this initial setup, expense tracking becomes almost entirely automatic. New transactions are imported and categorized without any manual effort.</p>
HTML;
            }
        } elseif (str_contains($slug, 'freelanc') || str_contains($slug, 'self-employ') || str_contains($slug, 'business')) {
            if (! str_contains($page->content, 'Tools and Resources') && ! str_contains($page->content, 'Recommended Tools')) {
                $sections[] = <<<'HTML'

<h2>Essential Tools for Freelance Financial Management</h2>
<p>Successful freelancers use a combination of tools to stay on top of their finances:</p>
<ul>
<li><strong>Expense tracker with AI categorization</strong> — LedgerIQ handles automatic categorization, subscription detection, and tax export. The AI distinguishes business from personal expenses based on your account tags</li>
<li><strong>Separate business bank account</strong> — Even a free checking account dedicated to business income and expenses simplifies everything from bookkeeping to tax preparation</li>
<li><strong>Quarterly tax calendar</strong> — Set reminders for estimated tax payment deadlines (April 15, June 15, September 15, January 15) to avoid IRS penalties</li>
<li><strong>Receipt documentation system</strong> — While digital bank records cover most deductions, keep receipts for cash payments and large purchases. LedgerIQ's email receipt matching can automate this for online purchases</li>
<li><strong>Year-end tax package</strong> — Use LedgerIQ's export feature to generate an Excel workbook, PDF summary, or CSV file mapped to IRS Schedule C categories. This gives your accountant everything they need in one organized package</li>
</ul>
HTML;
            }
        } else {
            // Generic expansion for other guides
            if (! str_contains($page->content, 'Key Takeaways') && ! str_contains($page->content, 'Bottom Line') && ! str_contains($page->content, 'Summary')) {
                $sections[] = <<<'HTML'

<h2>Putting It All Together</h2>
<p>The most important step is getting started. Perfectionism is the enemy of financial tracking — a basic system you actually use is infinitely better than a complex one you abandon after a week. Here is the practical reality:</p>
<ul>
<li><strong>Automation beats willpower</strong> — Instead of relying on yourself to log every purchase, let AI-powered tools handle the tracking. Connect your bank account once and transactions flow in automatically</li>
<li><strong>Start with visibility, then optimize</strong> — Before cutting expenses or setting strict budgets, simply observe where your money goes for 30 days. The insights often surprise people and motivate real change</li>
<li><strong>Small changes compound</strong> — Canceling one forgotten $15/month subscription saves $180/year. Finding three such subscriptions saves $540. Add in better tax deduction tracking and the annual impact can reach thousands of dollars</li>
<li><strong>Review weekly, not daily</strong> — Checking your finances once a week is enough to stay informed without becoming obsessive. Set a recurring 10-minute review on Sunday evenings</li>
</ul>
HTML;
            }
        }

        return implode("\n", $sections);
    }
}
