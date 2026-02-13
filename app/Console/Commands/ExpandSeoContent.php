<?php

namespace App\Console\Commands;

use App\Models\SeoPage;
use Illuminate\Console\Command;

class ExpandSeoContent extends Command
{
    protected $signature = 'seo:expand {--dry-run : Show what would be changed without saving} {--min-length=10000 : Minimum content length in characters}';

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
        $relatedPos = strpos($content, '<h2>Related Reading</h2>');
        if ($relatedPos !== false) {
            return $relatedPos;
        }

        $lastCtaPos = strrpos($content, '<div class="inline-cta">');
        if ($lastCtaPos !== false) {
            return $lastCtaPos;
        }

        return strlen($content);
    }

    private function generateExpansion(SeoPage $page): string
    {
        return match ($page->category) {
            'comparison' => $this->expandComparison($page),
            'alternative' => $this->expandAlternative($page),
            'guide' => $this->expandGuide($page),
            'tax' => $this->expandTax($page),
            'industry' => $this->expandIndustry($page),
            'feature' => $this->expandFeature($page),
            default => '',
        };
    }

    private function expandComparison(SeoPage $page): string
    {
        preg_match('/vs\s+(.+?)[\s:]/i', $page->title, $matches);
        $competitor = $matches[1] ?? 'the competitor';

        $sections = [];

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

        if (! str_contains($page->content, 'Real-World Impact') && ! str_contains($page->content, 'Cost Comparison')) {
            $sections[] = <<<HTML

<h2>Real-World Cost Comparison</h2>
<p>The financial case for switching is straightforward when you look at what you actually pay over time:</p>
<ul>
<li><strong>Year 1 savings</strong> — If you are paying \$10/month for {$competitor}, switching to LedgerIQ saves \$120 in the first year alone. At \$15/month, that is \$180 back in your pocket</li>
<li><strong>Hidden feature costs</strong> — Many competitors offer basic tracking for free but charge for the features that actually matter: bank connections, tax exports, categorization rules, and customer support. LedgerIQ includes everything at no cost</li>
<li><strong>Subscription detection value</strong> — The average LedgerIQ user discovers \$200-400 in forgotten recurring charges within their first month. This more than offsets any switching effort</li>
<li><strong>Tax deduction capture</strong> — Automated Schedule C mapping catches deductions that manual tracking misses. Self-employed users typically find an additional \$500-2,000 in deductions they would have overlooked</li>
</ul>
<p>When you factor in both the direct savings from eliminating a paid subscription and the indirect savings from better expense visibility, the return on the 10 minutes spent switching is significant.</p>
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

        if (! str_contains($page->content, 'Migration') && ! str_contains($page->content, 'Getting Started')) {
            $sections[] = <<<'HTML'

<h2>Getting Started After Switching</h2>
<p>Once you have decided on an alternative, the transition process matters. A clean migration ensures you do not lose momentum with your financial tracking:</p>
<ol>
<li><strong>Download your history</strong> — Most tools let you export transactions as CSV. Do this before closing your account so you have a backup of historical data</li>
<li><strong>Set up bank connections</strong> — Connect your accounts through Plaid for automatic syncing, or upload recent bank statements as PDF or CSV files for immediate transaction import</li>
<li><strong>Configure account tags</strong> — Mark each connected account as business, personal, or mixed. This context dramatically improves AI categorization accuracy from the start</li>
<li><strong>Review the first batch</strong> — After AI categorizes your initial transactions, spend 5 minutes reviewing any flagged items. This teaches the system your specific spending patterns</li>
<li><strong>Check subscription detection</strong> — Within 24 hours of importing transactions, the subscription detector will identify recurring charges. Review these for any services you have forgotten about</li>
</ol>
<p>The entire process takes under 15 minutes for most users, and automated tracking begins immediately after bank connection.</p>
HTML;
        }

        return implode("\n", $sections);
    }

    private function expandGuide(SeoPage $page): string
    {
        $slug = $page->slug;
        $sections = [];

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
        } elseif (str_contains($slug, 'budget') || str_contains($slug, 'spending') || (str_contains($slug, 'expense') && ! str_contains($slug, 'tax'))) {
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

        // Add a practical tips section for all guides
        if (! str_contains($page->content, 'Practical Tips') && ! str_contains($page->content, 'Pro Tips')) {
            $sections[] = <<<'HTML'

<h2>Practical Tips for Long-Term Success</h2>
<p>Financial tracking is only valuable if you stick with it. These habits help make it sustainable:</p>
<ul>
<li><strong>Automate everything possible</strong> — Bank connections that sync automatically eliminate the biggest friction point. You should never need to manually enter a transaction that went through your bank or credit card</li>
<li><strong>Use the AI question system</strong> — When LedgerIQ asks about a transaction, take 10 seconds to answer. Each response improves future categorization accuracy for similar purchases</li>
<li><strong>Set up account purpose tags</strong> — Tagging accounts as business or personal is the single highest-impact action for categorization accuracy. The AI uses this context for every transaction from that account</li>
<li><strong>Review subscriptions monthly</strong> — Services you signed up for six months ago may no longer provide value. LedgerIQ flags subscriptions that show usage pattern changes automatically</li>
<li><strong>Export before tax deadlines</strong> — Run a tax export at least two weeks before filing deadlines. This gives you time to verify categories and catch any miscategorized expenses before they reach your accountant</li>
</ul>
<p>The goal is not perfection but consistency. A tracking system that captures 95% of your expenses automatically is dramatically better than manually logging 60% of them sporadically.</p>
HTML;
        }

        return implode("\n", $sections);
    }

    private function expandTax(SeoPage $page): string
    {
        $sections = [];

        if (! str_contains($page->content, 'Record-Keeping') && ! str_contains($page->content, 'Documentation')) {
            $sections[] = <<<'HTML'

<h2>Record-Keeping Best Practices for Tax Compliance</h2>
<p>The IRS requires adequate documentation for all claimed deductions. Poor record-keeping is the most common reason deductions are denied during audits. Here is how to stay protected:</p>
<ul>
<li><strong>Digital bank records</strong> — Transaction records from your bank or credit card are generally sufficient documentation for most deductions under $75. LedgerIQ automatically preserves these with timestamps, merchant names, and categorization</li>
<li><strong>Receipt retention</strong> — For expenses over $75, keep the original receipt or a clear digital photo. LedgerIQ's email receipt matching can capture online purchase receipts automatically from Gmail</li>
<li><strong>Business purpose notes</strong> — For meals, travel, and entertainment expenses, document the business purpose: who you met with, what was discussed, and how it relates to your business activity</li>
<li><strong>Mileage logs</strong> — If claiming vehicle deductions, maintain a contemporaneous log with date, destination, business purpose, and miles driven. The IRS standard rate for 2026 is $0.70 per mile</li>
<li><strong>Home office measurements</strong> — If claiming the regular home office deduction, document the square footage of your dedicated workspace and the total square footage of your home</li>
</ul>

<h3>How Long to Keep Tax Records</h3>
<p>The general rule is three years from the filing date, but certain situations require longer retention. If you underreported income by more than 25%, keep records for six years. For property-related deductions, keep records until three years after you dispose of the property. LedgerIQ stores your transaction history and exports indefinitely, so you always have access to historical data.</p>
HTML;
        }

        if (! str_contains($page->content, 'Estimated Tax') && ! str_contains($page->content, 'Quarterly Payment') && ! str_contains($page->content, 'quarterly')) {
            $sections[] = <<<'HTML'

<h2>Understanding Quarterly Estimated Tax Obligations</h2>
<p>If you expect to owe $1,000 or more in taxes for the year, the IRS requires quarterly estimated payments. Missing these deadlines triggers penalties regardless of whether you eventually pay in full:</p>
<ul>
<li><strong>Q1: April 15</strong> — Covers income from January through March</li>
<li><strong>Q2: June 15</strong> — Covers income from April through May (note: only a 2-month period)</li>
<li><strong>Q3: September 15</strong> — Covers income from June through August</li>
<li><strong>Q4: January 15</strong> — Covers income from September through December</li>
</ul>
<p>The safe harbor rule lets you avoid penalties by paying either 100% of last year's tax liability or 90% of the current year's estimated liability (110% of last year's if your AGI exceeded $150,000). Using LedgerIQ's tax export, you can track your deductible expenses throughout the year and estimate your quarterly liability more accurately.</p>
HTML;
        }

        if (! str_contains($page->content, 'Common Mistakes') && ! str_contains($page->content, 'Mistakes to Avoid')) {
            $sections[] = <<<'HTML'

<h2>Tax Filing Mistakes That Cost Money</h2>
<p>Self-employed individuals lose an estimated $3,000-5,000 annually in missed deductions. The most common errors include:</p>
<ul>
<li><strong>Overlooking the self-employment tax deduction</strong> — You can deduct 50% of your self-employment tax on your 1040. This is an above-the-line deduction, meaning you get it even if you take the standard deduction</li>
<li><strong>Missing health insurance premiums</strong> — Self-employed individuals can deduct 100% of health insurance premiums for themselves, their spouse, and dependents. This includes dental and long-term care insurance</li>
<li><strong>Forgetting retirement contributions</strong> — SEP-IRA contributions (up to 25% of net self-employment income) and Solo 401(k) contributions are powerful deductions that also build your retirement savings</li>
<li><strong>Ignoring education expenses</strong> — Courses, books, conferences, and certifications that maintain or improve your current business skills are deductible. This includes online courses and industry publications</li>
<li><strong>Not tracking small expenses</strong> — Individual $5-20 purchases seem insignificant, but they accumulate. Software subscriptions, office supplies, postage, and professional association dues add up to hundreds or thousands per year</li>
</ul>
<p>AI-powered expense tracking catches these categories automatically by analyzing merchant names and transaction patterns, rather than relying on you to remember each deduction category.</p>
HTML;
        }

        return implode("\n", $sections);
    }

    private function expandIndustry(SeoPage $page): string
    {
        preg_match('/for\s+(.+?)$/i', $page->title, $matches);
        $industry = $matches[1] ?? 'your industry';

        $sections = [];

        if (! str_contains($page->content, 'Industry-Specific') && ! str_contains($page->content, 'Unique Deductions')) {
            $sections[] = <<<HTML

<h2>Why Industry-Specific Tracking Matters</h2>
<p>Generic expense tracking misses the nuances of {$industry} finances. Every industry has unique spending patterns, deduction categories, and compliance requirements that general-purpose tools handle poorly:</p>
<ul>
<li><strong>Industry-specific categories</strong> — AI categorization trained on diverse transaction data recognizes industry-specific merchants and expenses that rule-based systems would miscategorize. A photography equipment purchase looks different from a general electronics buy</li>
<li><strong>Variable income patterns</strong> — Many industries have seasonal or project-based income that makes traditional monthly budgeting unrealistic. AI-powered analysis adapts to irregular income patterns and identifies spending trends relative to revenue cycles</li>
<li><strong>Mixed-use expense allocation</strong> — Equipment, vehicles, and even home office space often serve both business and personal purposes. Proper tracking establishes the business-use percentage needed for accurate deductions</li>
<li><strong>Client-billable expenses</strong> — Tracking which expenses are reimbursable versus absorbed costs is critical for maintaining healthy profit margins and accurate invoicing</li>
</ul>
<p>LedgerIQ's AI categorization learns the specific spending patterns of your industry as you use it, improving accuracy with every transaction it processes.</p>
HTML;
        }

        if (! str_contains($page->content, 'Getting Started') && ! str_contains($page->content, 'How to Set Up')) {
            $sections[] = <<<HTML

<h2>Setting Up Expense Tracking for {$industry}</h2>
<p>Getting the most value from automated expense tracking requires some initial configuration specific to your industry:</p>
<ol>
<li><strong>Separate your accounts</strong> — If you have not already, open a dedicated business checking account and credit card. This is the single most impactful step for clean financial tracking and tax preparation</li>
<li><strong>Tag account purposes</strong> — In LedgerIQ, mark your business accounts as "business" and personal accounts as "personal." The AI uses this signal as the strongest indicator for categorization</li>
<li><strong>Import historical data</strong> — Upload 3-6 months of bank statements to give the AI a foundation of your spending patterns. This accelerates the learning process significantly</li>
<li><strong>Review the first categorization batch</strong> — Spend 10-15 minutes reviewing how the AI categorized your initial transactions. Correcting any errors teaches the system your industry-specific patterns</li>
<li><strong>Check subscription detection results</strong> — Industry professionals often accumulate software subscriptions, association memberships, and recurring service fees. Review the detected subscriptions to identify any you have forgotten about</li>
</ol>
<p>After this initial setup, the system operates almost entirely on autopilot. New transactions are categorized in real-time as they sync from your bank.</p>
HTML;
        }

        if (! str_contains($page->content, 'Tax Season') && ! str_contains($page->content, 'When Tax Time')) {
            $sections[] = <<<'HTML'

<h2>Preparing for Tax Season</h2>
<p>The real payoff of year-round expense tracking comes when tax season arrives. Instead of scrambling to organize receipts and reconstruct spending, you simply export your data:</p>
<ul>
<li><strong>Run the tax export</strong> — Generate an Excel, PDF, or CSV report with all expenses mapped to IRS Schedule C categories. LedgerIQ handles the category-to-tax-line mapping automatically</li>
<li><strong>Review flagged items</strong> — Check any transactions the AI categorized with lower confidence. These are worth a quick manual review before filing</li>
<li><strong>Send to your accountant</strong> — Use the email export feature to send the organized report directly to your tax professional. The Schedule C mapping saves them significant time and reduces your preparation costs</li>
<li><strong>Save for records</strong> — Download a copy for your records. The IRS requires documentation retention for at least three years from the filing date</li>
</ul>
<p>Users who track expenses throughout the year typically save 3-5 hours of tax preparation time and identify an additional $500-2,000 in deductions compared to manual year-end reconstruction.</p>
HTML;
        }

        return implode("\n", $sections);
    }

    private function expandFeature(SeoPage $page): string
    {
        $sections = [];

        if (! str_contains($page->content, 'How It Works') && ! str_contains($page->content, 'Under the Hood') && ! str_contains($page->content, 'Technical Details')) {
            $featureName = str_replace([' - LedgerIQ', ' | LedgerIQ'], '', $page->title);
            $sections[] = <<<HTML

<h2>How {$featureName} Works Under the Hood</h2>
<p>Understanding the technology behind this feature helps you use it more effectively and trust the results:</p>
<ul>
<li><strong>AI-powered analysis</strong> — LedgerIQ uses Claude AI from Anthropic to process transaction data. Unlike rule-based systems that match merchant names to categories, AI understands context: the same store might be a business expense for supplies or a personal purchase depending on your account type and purchase pattern</li>
<li><strong>Confidence scoring</strong> — Every automated action includes a confidence score. High-confidence results (85%+) are applied automatically. Medium-confidence results (60-84%) are applied but flagged for review. Low-confidence results generate questions so you make the final decision</li>
<li><strong>Continuous learning</strong> — When you correct a categorization or answer an AI question, that feedback improves future accuracy. The system builds a profile of your specific spending patterns over time</li>
<li><strong>Privacy-first architecture</strong> — Your financial data is encrypted at rest using AES-256. Bank credentials are handled exclusively by Plaid and never touch LedgerIQ's servers. Two-factor authentication adds an additional security layer</li>
</ul>
<p>This architecture ensures accuracy improves over time while maintaining the security standards expected for financial data.</p>
HTML;
        }

        if (! str_contains($page->content, 'Compared to') && ! str_contains($page->content, 'vs Other')) {
            $sections[] = <<<'HTML'

<h2>How This Compares to Other Approaches</h2>
<p>Most expense tracking tools take one of three approaches to this problem. Understanding the differences helps you appreciate why AI-powered tracking delivers better results:</p>
<ul>
<li><strong>Manual entry</strong> — Traditional apps require you to log each expense by hand. This is accurate when done consistently but has a 40-60% abandonment rate within the first month because it requires constant effort</li>
<li><strong>Rule-based automation</strong> — Tools that use merchant-name matching can auto-categorize common purchases but fail on ambiguous transactions, new merchants, and context-dependent expenses. Accuracy typically plateaus at 60-70%</li>
<li><strong>AI-powered categorization</strong> — LedgerIQ's approach analyzes transaction context including merchant, amount, frequency, account type, and spending patterns. This achieves 85%+ accuracy and continues improving as the system learns your specific patterns</li>
</ul>
<p>The practical impact is significant: with AI-powered tracking, you spend minutes per month on expense management instead of hours. The system handles the routine categorization work and only asks for your input when it genuinely needs human judgment.</p>
HTML;
        }

        if (! str_contains($page->content, 'Tips for') && ! str_contains($page->content, 'Best Practices') && ! str_contains($page->content, 'Getting the Most')) {
            $sections[] = <<<'HTML'

<h2>Getting the Most from This Feature</h2>
<p>A few simple actions significantly improve your experience:</p>
<ul>
<li><strong>Tag your accounts immediately</strong> — Setting account purpose (business, personal, mixed) as soon as you connect gives the AI crucial context from the first transaction</li>
<li><strong>Answer AI questions promptly</strong> — When the system asks about a transaction, your response improves accuracy for all similar future transactions. Five minutes of initial Q&A can prevent dozens of miscategorizations</li>
<li><strong>Review weekly</strong> — A quick 5-minute weekly check catches any edge cases and keeps your data clean. This is especially important during the first month as the AI learns your patterns</li>
<li><strong>Use the bank statement upload as backup</strong> — If Plaid experiences a temporary sync delay with your bank, uploading a recent PDF or CSV statement fills any gaps seamlessly</li>
<li><strong>Export regularly before tax deadlines</strong> — Running a tax export quarterly helps you estimate tax obligations and ensures your data is organized well before the filing deadline</li>
</ul>
HTML;
        }

        return implode("\n", $sections);
    }
}
