<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SeoPageSeederFeature extends Seeder
{
    public function run(): void
    {
        $pages = array_merge(
            $this->getPages1Through5(),
            $this->getPages6Through10(),
        );

        foreach ($pages as $page) {
            $page['created_at'] = now();
            $page['updated_at'] = now();
            DB::table('seo_pages')->updateOrInsert(
                ['slug' => $page['slug']],
                $page
            );
        }
    }

    private function getPages1Through5(): array
    {
        return [
            $this->aiTransactionCategorization(),
            $this->automaticSubscriptionDetection(),
            $this->bankStatementUploadFeature(),
            $this->aiSavingsRecommendations(),
            $this->taxDeductionExportFeature(),
        ];
    }

    private function getPages6Through10(): array
    {
        return [
            $this->plaidBankSyncFeature(),
            $this->budgetWaterfallDashboard(),
            $this->aiExpenseQuestions(),
            $this->twoFactorAuthenticationSecurity(),
            $this->emailReceiptMatching(),
        ];
    }

    private function aiTransactionCategorization(): array
    {
        $content = <<<'HTML'
<h2>How AI Transaction Categorization Works in LedgerIQ</h2>
<p>Every month, the average American processes over 40 financial transactions across credit cards, debit cards, and bank accounts. Manually sorting each one into the right category is tedious, error-prone, and the number one reason people abandon expense tracking apps within the first week.</p>
<p>LedgerIQ solves this with AI-powered transaction categorization built on Anthropic's Claude, one of the most advanced large language models available. Instead of relying on rigid keyword matching, LedgerIQ understands context, merchant names, and spending patterns to classify your expenses with remarkable accuracy.</p>

<h2>The Intelligence Behind Every Classification</h2>
<p>Traditional expense trackers use simple keyword lookups. If a transaction contains "STARBUCKS," it goes into "Food & Drink." But what about "SQ *JOES COFFEE" or "PAYPAL *INST 4829"? Keyword matching fails on abbreviated merchant names, payment processor prefixes, and obscure transaction descriptions.</p>
<p>LedgerIQ takes a fundamentally different approach. Each transaction is analyzed by Claude AI, which considers the full merchant description, the transaction amount, your account type (business vs. personal), and historical patterns from your spending history.</p>

<h3>Confidence-Based Routing</h3>
<p>Not every transaction is equally easy to classify. LedgerIQ uses a four-tier confidence system to handle uncertainty intelligently:</p>
<ul>
<li><strong>High confidence (85%+):</strong> The transaction is auto-categorized silently. You never need to think about it.</li>
<li><strong>Medium confidence (60-84%):</strong> The category is assigned but flagged for your review. A small indicator lets you confirm or correct it.</li>
<li><strong>Low confidence (40-59%):</strong> LedgerIQ generates a multiple-choice question, presenting the most likely categories for you to pick from.</li>
<li><strong>Very low confidence (below 40%):</strong> An open-ended question is generated so you can describe the expense in your own words.</li>
</ul>
<p>This means you only spend time on the transactions that genuinely need human judgment. Everything else is handled automatically.</p>

<blockquote><strong>Pro tip:</strong> Tagging your bank accounts as "business" or "personal" dramatically improves categorization accuracy. LedgerIQ uses account purpose as the strongest signal when classifying ambiguous transactions.</blockquote>

<h2>Learning From Your Corrections</h2>
<p>Every time you correct a category or answer an AI question, LedgerIQ gets smarter about your specific spending patterns. If you consistently recategorize "AMZN MKTP" from "Shopping" to "Office Supplies," the system learns that pattern for your account.</p>
<p>This feedback loop means accuracy improves over time. Most users see categorization accuracy above 90% within the first two weeks of active use.</p>

<h3>Batch Processing for Efficiency</h3>
<p>When you first connect your bank account or upload a statement, LedgerIQ processes transactions in intelligent batches rather than one at a time. This means hundreds of transactions can be categorized in minutes, not hours. The batch system groups similar transactions together, allowing the AI to make more consistent decisions across related purchases.</p>

<h2>Over 50 Expense Categories</h2>
<p>LedgerIQ maps transactions to over 50 expense categories, each aligned with IRS Schedule C tax lines. This means your categorized expenses are already organized for tax time. Categories range from common ones like "Meals & Entertainment" and "Office Supplies" to specialized ones like "Vehicle Expenses" and "Professional Development."</p>
<p>For freelancers and small business owners, this IRS alignment eliminates the painful year-end scramble of reorganizing expenses for your accountant.</p>

<h2>Why AI Categorization Matters</h2>
<p>Accurate categorization is the foundation of every other feature in LedgerIQ. Your <a href="/features">budget tracking</a>, subscription detection, savings recommendations, and tax exports all depend on transactions being in the right categories. By getting this right with AI, everything downstream works better.</p>
<p>Ready to stop manually sorting receipts? <a href="/register">Create your free LedgerIQ account</a> and let AI handle your transaction categorization from day one.</p>

<h2>Frequently Asked Questions</h2>
<h3>How accurate is the AI categorization?</h3>
<p>Most users see 85-95% accuracy out of the box. Accuracy improves as you correct miscategorized transactions and answer AI questions about ambiguous expenses.</p>

<h3>Can I create custom categories?</h3>
<p>LedgerIQ uses over 50 predefined categories mapped to IRS Schedule C tax lines. This standardized system ensures your expenses are tax-ready while covering virtually every type of personal and business expense.</p>

<h3>What happens if the AI gets a transaction wrong?</h3>
<p>You can correct any category with a single click on the Transactions page. The AI learns from your corrections and applies that knowledge to future similar transactions.</p>
HTML;

        return [
            'slug' => 'ai-transaction-categorization',
            'title' => 'AI Transaction Categorization - Smart Expense Classification | LedgerIQ',
            'meta_description' => 'Learn how LedgerIQ uses Claude AI to automatically categorize your transactions with confidence-based routing, batch processing, and learning from your corrections.',
            'h1' => 'AI-Powered Transaction Categorization That Actually Works',
            'category' => 'feature',
            'keywords' => json_encode(['ai transaction categorization', 'automatic expense classification', 'smart expense tracking', 'ai expense categories', 'transaction sorting ai', 'expense categorization software']),
            'excerpt' => 'LedgerIQ uses Anthropic Claude AI to automatically categorize your transactions with a four-tier confidence system, learning from your corrections to improve over time.',
            'content' => $content,
            'faq_items' => json_encode([
                ['question' => 'How accurate is the AI categorization?', 'answer' => 'Most users see 85-95% accuracy out of the box. Accuracy improves as you correct miscategorized transactions and answer AI questions about ambiguous expenses.'],
                ['question' => 'Can I create custom categories?', 'answer' => 'LedgerIQ uses over 50 predefined categories mapped to IRS Schedule C tax lines. This standardized system ensures your expenses are tax-ready while covering virtually every type of personal and business expense.'],
                ['question' => 'What happens if the AI gets a transaction wrong?', 'answer' => 'You can correct any category with a single click on the Transactions page. The AI learns from your corrections and applies that knowledge to future similar transactions.'],
                ['question' => 'Does the AI work with all types of transactions?', 'answer' => 'Yes. The AI handles credit card charges, debit transactions, bank transfers, and even cryptic payment processor descriptions like PayPal and Square transactions.'],
            ]),
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    private function automaticSubscriptionDetection(): array
    {
        $content = <<<'HTML'
<h2>How LedgerIQ Detects Your Forgotten Subscriptions</h2>
<p>The average American spends $219 per month on subscriptions they have forgotten about or no longer use. That streaming service you signed up for during the free trial, the productivity app you tried once, the gym membership you keep meaning to cancel. These forgotten charges add up to over $2,600 per year in wasted money.</p>
<p>LedgerIQ's automatic subscription detection scans your transaction history to find every recurring charge, including the ones you have completely forgotten about.</p>

<h2>Pattern Recognition, Not Guesswork</h2>
<p>LedgerIQ identifies subscriptions by analyzing the timing and amounts of your transactions. The system looks for charges that repeat at consistent intervals with consistent amounts, the two hallmarks of a subscription.</p>

<h3>Supported Billing Frequencies</h3>
<p>The detection engine recognizes four billing patterns:</p>
<table>
<tr><th>Frequency</th><th>Detection Window</th><th>Example</th></tr>
<tr><td>Weekly</td><td>Every 5-9 days</td><td>Weekly meal kit deliveries</td></tr>
<tr><td>Monthly</td><td>Every 25-35 days</td><td>Netflix, Spotify, gym memberships</td></tr>
<tr><td>Quarterly</td><td>Every 80-100 days</td><td>Quarterly software licenses</td></tr>
<tr><td>Annual</td><td>Every 350-380 days</td><td>Domain renewals, annual memberships</td></tr>
</table>
<p>By allowing a tolerance window around each frequency, LedgerIQ catches subscriptions even when billing dates shift by a few days due to weekends or payment processing delays.</p>

<h2>Stopped Billing Detection</h2>
<p>One of LedgerIQ's most valuable features is detecting when a subscription has stopped billing. If you cancelled a service or if your card expired and a subscription lapsed, LedgerIQ flags it automatically.</p>
<p>The system uses a frequency-based gap detection algorithm. If no charge has appeared for twice the normal billing cycle, the subscription is marked as "stopped." This means:</p>
<ul>
<li><strong>Weekly subscriptions:</strong> Flagged after 21+ days with no charge</li>
<li><strong>Monthly subscriptions:</strong> Flagged after 60+ days with no charge</li>
<li><strong>Quarterly subscriptions:</strong> Flagged after 180+ days with no charge</li>
<li><strong>Annual subscriptions:</strong> Flagged after 400+ days with no charge</li>
</ul>

<blockquote><strong>Tip:</strong> Check your Subscriptions page after connecting a bank account with at least 90 days of transaction history. The more data LedgerIQ has, the more accurately it can identify recurring patterns.</blockquote>

<h2>Take Action on Every Subscription</h2>
<p>Finding subscriptions is only half the battle. LedgerIQ lets you take action on each one directly from the <a href="/features">Subscriptions dashboard</a>. For every detected subscription, you can:</p>
<ul>
<li><strong>Cancel:</strong> Mark it for cancellation and track your projected savings</li>
<li><strong>Reduce:</strong> Explore cheaper plan alternatives suggested by AI</li>
<li><strong>Keep:</strong> Confirm you want to continue the subscription</li>
</ul>

<h3>AI-Powered Alternatives</h3>
<p>When you choose to reduce a subscription, LedgerIQ's AI suggests cheaper alternatives. Paying $15.99/month for a streaming service? The AI might suggest a lower-tier plan or a competitor offering similar content for less. These suggestions are cached for seven days and tailored to your actual usage patterns.</p>

<h2>Projected Savings Tracking</h2>
<p>Every subscription you cancel or reduce is tracked in your projected savings. LedgerIQ calculates exactly how much you will save over the next 12 months based on your decisions. This running total gives you a concrete, motivating number to see the real impact of cleaning up forgotten subscriptions.</p>
<p>The savings projection accounts for billing frequency, so cancelling a $9.99 monthly subscription shows $119.88 in annual savings, while cancelling a $99 annual subscription shows exactly $99.</p>

<h2>Stay On Top of Recurring Charges</h2>
<p>New subscriptions are detected automatically as they appear in your transaction feed. You do not need to manually run detection. Every time your bank data syncs, LedgerIQ checks for new recurring patterns and alerts you to any changes.</p>
<p>Stop losing money to forgotten subscriptions. <a href="/register">Sign up for LedgerIQ</a> and discover exactly where your recurring charges are going.</p>

<h2>Frequently Asked Questions</h2>
<h3>How much transaction history does LedgerIQ need to detect subscriptions?</h3>
<p>At least 60-90 days of history provides the best results. Monthly subscriptions need at least two billing cycles to be detected as recurring.</p>

<h3>Will LedgerIQ cancel subscriptions for me?</h3>
<p>No. LedgerIQ identifies and tracks subscriptions, but cancellation must be done through the service provider. We provide the information and tracking so you know exactly what to cancel.</p>

<h3>Can LedgerIQ detect annual subscriptions?</h3>
<p>Yes, if you have at least 13 months of transaction history. Annual charges that repeat within a 350-380 day window are flagged as annual subscriptions.</p>
HTML;

        return [
            'slug' => 'automatic-subscription-detection',
            'title' => 'Automatic Subscription Detection - Find Hidden Charges | LedgerIQ',
            'meta_description' => 'LedgerIQ automatically detects forgotten subscriptions using pattern recognition across weekly, monthly, quarterly, and annual billing cycles. Stop wasting money on unused services.',
            'h1' => 'Automatic Subscription Detection That Finds Your Hidden Charges',
            'category' => 'feature',
            'keywords' => json_encode(['subscription detection', 'find forgotten subscriptions', 'recurring charge tracker', 'cancel unused subscriptions', 'subscription management', 'hidden charges finder']),
            'excerpt' => 'LedgerIQ scans your transaction history to detect every recurring charge using pattern recognition across four billing frequencies, then helps you cancel or reduce what you do not need.',
            'content' => $content,
            'faq_items' => json_encode([
                ['question' => 'How much transaction history does LedgerIQ need to detect subscriptions?', 'answer' => 'At least 60-90 days of history provides the best results. Monthly subscriptions need at least two billing cycles to be detected as recurring.'],
                ['question' => 'Will LedgerIQ cancel subscriptions for me?', 'answer' => 'No. LedgerIQ identifies and tracks subscriptions, but cancellation must be done through the service provider. We provide the information and tracking so you know exactly what to cancel.'],
                ['question' => 'Can LedgerIQ detect annual subscriptions?', 'answer' => 'Yes, if you have at least 13 months of transaction history. Annual charges that repeat within a 350-380 day window are flagged as annual subscriptions.'],
                ['question' => 'What if a subscription changes its price?', 'answer' => 'LedgerIQ allows for small amount variations in its pattern matching. If the price change is significant, it may be detected as a new subscription while the old one is marked as stopped.'],
            ]),
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    private function bankStatementUploadFeature(): array
    {
        $content = <<<'HTML'
<h2>Upload Your Bank Statements for Instant Analysis</h2>
<p>Not every bank supports direct API connections, and not every user wants to link their bank credentials to a third-party service. LedgerIQ's bank statement upload feature gives you full access to AI-powered expense tracking without ever sharing your banking login.</p>
<p>Simply download your statement from your bank's website and upload the PDF or CSV file to LedgerIQ. Within minutes, every transaction is extracted, categorized, and ready for analysis.</p>

<h2>How Statement Parsing Works</h2>
<p>LedgerIQ supports two statement formats, each processed through a different pipeline optimized for accuracy.</p>

<h3>PDF Statement Parsing</h3>
<p>PDF bank statements are the most common format, but they are also the hardest to parse. Every bank formats their PDFs differently with varying layouts, column arrangements, and date formats.</p>
<p>LedgerIQ uses a two-stage process for PDFs. First, the raw text is extracted from the PDF document. Then, Claude AI analyzes the extracted text to identify individual transactions, their dates, descriptions, and amounts. The AI understands bank statement layouts intuitively, handling multi-line descriptions, running balances, and fee breakdowns that trip up traditional parsers.</p>

<h3>CSV Statement Parsing</h3>
<p>Many banks offer CSV downloads alongside PDFs. CSV files are more structured, but they still vary widely between institutions. Some use "Debit" and "Credit" columns, others use negative numbers, and column headers are rarely standardized.</p>
<p>LedgerIQ's AI examines the CSV structure, identifies the relevant columns, and normalizes the data into a consistent format. Whether your bank exports dates as "01/15/2026" or "2026-01-15" or "Jan 15, 2026," the AI handles it correctly.</p>

<blockquote><strong>Pro tip:</strong> When downloading statements from your bank, choose the longest date range available. More transaction history means better subscription detection and more accurate spending analysis.</blockquote>

<h2>The Upload Wizard</h2>
<p>LedgerIQ's statement upload wizard guides you through the process in three steps:</p>
<ul>
<li><strong>Step 1 - Upload:</strong> Drag and drop your PDF or CSV file, or click to browse. Files are validated for format and size before processing begins.</li>
<li><strong>Step 2 - Review:</strong> LedgerIQ shows you the extracted transactions for verification. You can correct any parsing errors before importing.</li>
<li><strong>Step 3 - Import:</strong> Confirmed transactions are imported into your account, automatically categorized by AI, and immediately available in your dashboard.</li>
</ul>

<h2>Privacy-First Design</h2>
<p>Statement upload is the most privacy-conscious way to use LedgerIQ. Your bank credentials are never involved. The uploaded file is processed, the transactions are extracted, and you maintain complete control over what data enters the system.</p>
<p>This makes LedgerIQ accessible to users who bank with institutions not supported by Plaid, users in regions with limited open banking infrastructure, or anyone who simply prefers not to connect their bank electronically.</p>

<h3>Upload History and Tracking</h3>
<p>Every upload is tracked with its processing status, transaction count, and date range. You can view your complete upload history on the <a href="/features">Connect page</a>, making it easy to see which months have been imported and identify any gaps in your data.</p>

<h2>Combining Uploads with Bank Sync</h2>
<p>Statement uploads and <a href="/blog/plaid-bank-sync-feature">Plaid bank sync</a> work together seamlessly. You might connect your primary checking account via Plaid for automatic syncing while uploading statements from a secondary credit card that is not Plaid-supported. LedgerIQ merges all your transaction data into a single unified view regardless of how it was imported.</p>
<p>Duplicate detection ensures that transactions are not counted twice if you upload a statement covering a period that overlaps with your Plaid sync.</p>

<h2>Get Started Without Linking Your Bank</h2>
<p>You do not need to connect a bank account to start using LedgerIQ. <a href="/register">Create your account</a>, upload a bank statement, and see AI-powered expense tracking in action within minutes.</p>

<h2>Frequently Asked Questions</h2>
<h3>What file formats are supported?</h3>
<p>LedgerIQ supports PDF and CSV bank statement formats. Most banks offer at least one of these formats for statement downloads.</p>

<h3>Is there a file size limit?</h3>
<p>Yes, uploaded files are validated for size during the upload process. Standard bank statements well within the limit. If you have an unusually large file, try splitting it into monthly statements.</p>

<h3>How long does parsing take?</h3>
<p>CSV files are typically parsed in under 30 seconds. PDF files take 1-3 minutes depending on the number of pages and transactions, as the AI needs to interpret the document layout.</p>
HTML;

        return [
            'slug' => 'bank-statement-upload-feature',
            'title' => 'Bank Statement Upload - PDF & CSV Import | LedgerIQ',
            'meta_description' => 'Upload PDF or CSV bank statements to LedgerIQ for AI-powered transaction extraction and categorization. No bank login required. Full privacy control.',
            'h1' => 'Upload Bank Statements for Instant AI-Powered Analysis',
            'category' => 'feature',
            'keywords' => json_encode(['bank statement upload', 'pdf statement parser', 'csv bank import', 'upload bank transactions', 'statement analysis tool', 'bank statement reader']),
            'excerpt' => 'Upload PDF or CSV bank statements to LedgerIQ and get instant AI-powered transaction extraction, categorization, and spending analysis without connecting your bank.',
            'content' => $content,
            'faq_items' => json_encode([
                ['question' => 'What file formats are supported?', 'answer' => 'LedgerIQ supports PDF and CSV bank statement formats. Most banks offer at least one of these formats for statement downloads.'],
                ['question' => 'Is there a file size limit?', 'answer' => 'Yes, uploaded files are validated for size during the upload process. Standard bank statements are well within the limit.'],
                ['question' => 'How long does parsing take?', 'answer' => 'CSV files are typically parsed in under 30 seconds. PDF files take 1-3 minutes depending on the number of pages and transactions.'],
                ['question' => 'Will duplicate transactions be created if I upload overlapping statements?', 'answer' => 'LedgerIQ includes duplicate detection that identifies overlapping transactions when you upload statements covering periods already synced via Plaid or previous uploads.'],
            ]),
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    private function aiSavingsRecommendations(): array
    {
        $content = <<<'HTML'
<h2>How AI Finds Savings Hidden in Your Spending</h2>
<p>You already know you should be saving more money. The problem is not motivation. It is visibility. Without a clear picture of where your money goes and which expenses can realistically be reduced, saving feels like guesswork.</p>
<p>LedgerIQ's AI savings engine analyzes 90 days of your spending history to find specific, actionable ways to reduce your expenses. Not generic advice like "eat out less." Real recommendations tied to your actual spending patterns with projected dollar amounts.</p>

<h2>The 90-Day Spending Analysis</h2>
<p>When you request savings recommendations, LedgerIQ sends your last 90 days of categorized transactions to Claude AI for deep analysis. The AI examines spending patterns across every category, looking for:</p>
<ul>
<li><strong>Unusually high spending</strong> in categories compared to typical benchmarks</li>
<li><strong>Recurring charges</strong> that could be reduced or eliminated</li>
<li><strong>Spending spikes</strong> that indicate one-time splurges versus ongoing habits</li>
<li><strong>Category overlap</strong> where you are paying for multiple services that serve the same purpose</li>
<li><strong>Timing patterns</strong> like increased weekend spending or end-of-month splurges</li>
</ul>

<h3>Personalized, Not Generic</h3>
<p>Every recommendation is tied to your actual data. If you spend $340/month on dining out, the AI might recommend reducing to $250/month and explain exactly which spending patterns contribute to the excess. If you have three overlapping streaming subscriptions, it identifies all three and suggests which to consolidate.</p>

<blockquote><strong>Tip:</strong> Run the savings analysis after you have at least 60-90 days of transaction data imported. The more history the AI can analyze, the more specific and accurate your recommendations will be.</blockquote>

<h2>Action Steps, Not Just Advice</h2>
<p>Each savings recommendation comes with concrete action steps. Instead of saying "reduce subscription spending," LedgerIQ might say:</p>
<ul>
<li>Cancel your Hulu subscription ($17.99/month) since you also have Netflix and Disney+</li>
<li>Switch your Spotify plan from Family ($16.99) to Individual ($10.99) since only one profile is active</li>
<li>Downgrade your cloud storage from 2TB ($9.99/month) to 200GB ($2.99/month) since you are only using 45GB</li>
</ul>
<p>Each action step has a projected monthly savings amount, so you can prioritize the changes that make the biggest impact.</p>

<h2>Respond and Track Your Progress</h2>
<p>For every recommendation, you have three response options:</p>
<ul>
<li><strong>Cancel:</strong> You will eliminate this expense entirely. LedgerIQ tracks the full amount as projected savings.</li>
<li><strong>Reduce:</strong> You will cut back but not eliminate. The AI suggests a realistic target amount.</li>
<li><strong>Keep:</strong> This expense stays as-is. LedgerIQ respects your priorities and focuses on other areas.</li>
</ul>
<p>Your responses feed into the <a href="/features">Savings dashboard</a>, which tracks your projected annual savings and shows your progress month over month.</p>

<h3>Savings Target Planning</h3>
<p>Beyond individual recommendations, you can set a savings target with a deadline. LedgerIQ's AI creates a personalized plan to hit your goal, breaking it down into monthly milestones and specific actions. Want to save $5,000 for a vacation in 8 months? The AI calculates you need $625/month and identifies exactly where that money can come from in your current spending.</p>

<h2>Monthly Savings Ledger</h2>
<p>LedgerIQ tracks your actual savings month by month with a savings ledger. You can see claimed savings (what you committed to) versus verified savings (confirmed by comparing your actual spending to previous months). This accountability system turns good intentions into measurable results.</p>
<p>The savings history chart on your dashboard visualizes your progress over time, making it easy to see trends and stay motivated.</p>

<h2>AI-Powered Alternatives</h2>
<p>When you choose to reduce an expense rather than eliminate it, LedgerIQ suggests cheaper alternatives. These AI-generated suggestions are specific to the service or product you are currently paying for, giving you a direct replacement path rather than vague advice to "shop around."</p>

<h2>Start Saving Smarter</h2>
<p>Stop guessing where to cut back. <a href="/register">Create your LedgerIQ account</a> and let AI analyze your spending to find real savings opportunities backed by your actual data.</p>

<h2>Frequently Asked Questions</h2>
<h3>How often should I run the savings analysis?</h3>
<p>We recommend running a new analysis every 30-60 days. Your spending patterns change over time, and fresh analysis ensures recommendations stay relevant.</p>

<h3>Does LedgerIQ automatically cancel services for me?</h3>
<p>No. LedgerIQ identifies savings opportunities and tracks your progress, but you take action on your own terms. We provide the insight; you make the decisions.</p>

<h3>How accurate are the projected savings amounts?</h3>
<p>Projected savings are calculated from your actual transaction amounts, so they are highly accurate for recurring charges. For variable expenses like dining, projections are based on your average spending and the reduction target you commit to.</p>
HTML;

        return [
            'slug' => 'ai-savings-recommendations',
            'title' => 'AI Savings Recommendations - Find Money in Your Budget | LedgerIQ',
            'meta_description' => 'LedgerIQ analyzes 90 days of spending to find personalized savings opportunities with projected dollar amounts, action steps, and monthly progress tracking.',
            'h1' => 'AI-Powered Savings Recommendations That Find Real Money',
            'category' => 'feature',
            'keywords' => json_encode(['ai savings recommendations', 'spending analysis ai', 'find savings opportunities', 'reduce expenses app', 'personalized savings plan', 'ai budget advisor']),
            'excerpt' => 'LedgerIQ analyzes 90 days of your spending to deliver personalized savings recommendations with specific action steps, projected savings amounts, and monthly progress tracking.',
            'content' => $content,
            'faq_items' => json_encode([
                ['question' => 'How often should I run the savings analysis?', 'answer' => 'We recommend running a new analysis every 30-60 days. Your spending patterns change over time, and fresh analysis ensures recommendations stay relevant.'],
                ['question' => 'Does LedgerIQ automatically cancel services for me?', 'answer' => 'No. LedgerIQ identifies savings opportunities and tracks your progress, but you take action on your own terms. We provide the insight; you make the decisions.'],
                ['question' => 'How accurate are the projected savings amounts?', 'answer' => 'Projected savings are calculated from your actual transaction amounts, so they are highly accurate for recurring charges. For variable expenses like dining, projections are based on your average spending and the reduction target you commit to.'],
                ['question' => 'Can I set a specific savings goal?', 'answer' => 'Yes. LedgerIQ lets you set a savings target with a deadline. The AI creates a personalized plan with monthly milestones and specific actions to help you reach your goal.'],
            ]),
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    private function taxDeductionExportFeature(): array
    {
        $content = <<<'HTML'
<h2>Export Tax Deductions to Excel, PDF, or CSV</h2>
<p>Tax season does not have to mean a frantic scramble through bank statements and shoebox receipts. LedgerIQ organizes your deductible expenses throughout the year and exports them in the exact format your accountant needs.</p>
<p>With transactions already categorized by AI and mapped to IRS Schedule C tax lines, exporting your deductions takes a single click instead of days of manual work.</p>

<h2>IRS Schedule C Category Mapping</h2>
<p>LedgerIQ's 50+ expense categories are not arbitrary labels. Each one maps directly to an IRS Schedule C line item, the tax form used by sole proprietors and freelancers to report business income and expenses.</p>
<p>When your transactions are categorized, they are simultaneously organized by tax line. This means your export groups expenses exactly how the IRS expects to see them:</p>
<table>
<tr><th>Category</th><th>Schedule C Line</th><th>Example Expenses</th></tr>
<tr><td>Advertising</td><td>Line 8</td><td>Google Ads, Facebook Ads, print advertising</td></tr>
<tr><td>Office Expenses</td><td>Line 18</td><td>Paper, ink, desk supplies, software</td></tr>
<tr><td>Travel</td><td>Line 24a</td><td>Flights, hotels, conference travel</td></tr>
<tr><td>Meals</td><td>Line 24b</td><td>Business lunches, client dinners</td></tr>
<tr><td>Utilities</td><td>Line 25</td><td>Phone, internet, electricity (home office)</td></tr>
</table>
<p>This mapping eliminates the tedious work of reorganizing your expenses into tax categories. They arrive at your accountant's desk ready to use.</p>

<h3>Business vs. Personal Separation</h3>
<p>If you have tagged your bank accounts with account purposes (business, personal, or mixed), LedgerIQ uses this to filter your tax export automatically. Only business-purpose transactions appear in your deduction export, preventing personal expenses from inflating your reported deductions.</p>

<blockquote><strong>Pro tip:</strong> Tag your bank accounts as "business" or "personal" early in the year. This ensures clean tax exports and prevents the year-end headache of separating mixed transactions.</blockquote>

<h2>Three Export Formats</h2>
<p>LedgerIQ generates your tax deduction report in three formats to suit different workflows:</p>

<h3>Excel (.xlsx)</h3>
<p>The most popular format for accountants. The Excel export includes formatted sheets with category subtotals, individual transaction details, and summary tables. Columns are pre-formatted for easy sorting and filtering. Your accountant can drop this directly into their tax preparation workflow.</p>

<h3>PDF Report</h3>
<p>A professionally formatted PDF summary suitable for filing or printing. The PDF includes a deduction summary by category, total deductions, and a detailed transaction list. This format is ideal for record-keeping and for providing a clean overview to your accountant alongside the detailed Excel file.</p>

<h3>CSV Export</h3>
<p>A raw data export for users who want to import deductions into other accounting software like QuickBooks, FreshBooks, or custom spreadsheets. The CSV includes all transaction fields with standardized column headers.</p>

<h2>Email to Your Accountant</h2>
<p>LedgerIQ can email your tax export directly to your accountant. Enter their email address, and the formatted report is delivered with a professional cover message. No need to download, attach, and forward. This is especially useful for quarterly estimated tax payments when you need to share updated numbers with your CPA regularly.</p>

<h2>The Tax Center Dashboard</h2>
<p>The <a href="/features">Tax Center</a> in LedgerIQ gives you a real-time view of your deductible expenses organized by category. Expandable category sections let you drill into individual transactions, verify categorizations, and make corrections before exporting.</p>
<p>The summary view shows your total deductions year-to-date, broken down by Schedule C line. This gives you a running estimate of your tax liability throughout the year, not just at tax time.</p>

<h2>Year-Round Tax Readiness</h2>
<p>The best time to organize your tax deductions is not April. It is every day. With LedgerIQ's AI categorization running continuously, your deductions are organized in real time as transactions flow in. When tax season arrives, you export and you are done.</p>
<p><a href="/register">Sign up for LedgerIQ</a> and make next tax season the easiest one yet.</p>

<h2>Frequently Asked Questions</h2>
<h3>Does LedgerIQ work for W-2 employees or only freelancers?</h3>
<p>While the Schedule C mapping is designed for self-employed individuals and freelancers, the expense categorization and export features are useful for anyone who itemizes deductions or needs to track business expenses for reimbursement.</p>

<h3>Can I export deductions for a specific date range?</h3>
<p>Yes. The tax export allows you to select a custom date range, so you can generate reports for any period, whether it is a calendar year, a quarter, or a custom range your accountant requests.</p>

<h3>What if a transaction is miscategorized in my export?</h3>
<p>You can correct any category on the Transactions page or directly in the Tax Center before exporting. Changes are reflected immediately in your next export.</p>
HTML;

        return [
            'slug' => 'tax-deduction-export-feature',
            'title' => 'Tax Deduction Export - Excel, PDF & CSV Reports | LedgerIQ',
            'meta_description' => 'Export your tax deductions to Excel, PDF, or CSV with IRS Schedule C mapping. LedgerIQ organizes expenses by tax line and emails reports directly to your accountant.',
            'h1' => 'Export Tax Deductions to Excel, PDF, and CSV in One Click',
            'category' => 'feature',
            'keywords' => json_encode(['tax deduction export', 'schedule c expense report', 'export tax deductions excel', 'freelancer tax tracker', 'tax expense categories', 'business deduction organizer']),
            'excerpt' => 'LedgerIQ maps your categorized expenses to IRS Schedule C tax lines and exports them to Excel, PDF, or CSV. Email reports directly to your accountant with one click.',
            'content' => $content,
            'faq_items' => json_encode([
                ['question' => 'Does LedgerIQ work for W-2 employees or only freelancers?', 'answer' => 'While the Schedule C mapping is designed for self-employed individuals and freelancers, the expense categorization and export features are useful for anyone who itemizes deductions or needs to track business expenses.'],
                ['question' => 'Can I export deductions for a specific date range?', 'answer' => 'Yes. The tax export allows you to select a custom date range, so you can generate reports for any period your accountant requests.'],
                ['question' => 'What if a transaction is miscategorized in my export?', 'answer' => 'You can correct any category on the Transactions page or directly in the Tax Center before exporting. Changes are reflected immediately in your next export.'],
                ['question' => 'Can I email the export directly to my accountant?', 'answer' => 'Yes. LedgerIQ can email your tax export directly to your accountant with a professional cover message, saving you the step of downloading and forwarding.'],
            ]),
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    private function plaidBankSyncFeature(): array
    {
        $content = <<<'HTML'
<h2>Secure Bank Sync via Plaid</h2>
<p>Manually importing transactions is a chore nobody looks forward to. LedgerIQ integrates with Plaid, the same secure banking infrastructure used by Venmo, Robinhood, and thousands of other financial apps, to sync your bank transactions automatically.</p>
<p>Connect your bank once, and every new transaction flows into LedgerIQ automatically. No downloads, no uploads, no manual data entry.</p>

<h2>What Is Plaid and Why We Use It</h2>
<p>Plaid is a financial technology company that provides the secure connection layer between your bank and apps like LedgerIQ. When you connect your bank through LedgerIQ, you are actually authenticating directly with Plaid's infrastructure. LedgerIQ never sees your bank login credentials.</p>
<p>Plaid connects to over 12,000 financial institutions across the United States and Canada, covering major banks, credit unions, and online-only banks. Whether you bank with Chase, a local credit union, or an online bank like Ally, chances are Plaid supports your institution.</p>

<h3>How the Connection Works</h3>
<p>The connection process takes about 60 seconds:</p>
<ul>
<li><strong>Step 1:</strong> Click "Connect Bank" on the LedgerIQ Connect page. A secure Plaid Link window opens.</li>
<li><strong>Step 2:</strong> Search for your bank and log in with your banking credentials inside the Plaid window. LedgerIQ cannot see these credentials.</li>
<li><strong>Step 3:</strong> Select which accounts to connect (checking, savings, credit cards). You choose what to share.</li>
<li><strong>Step 4:</strong> Plaid establishes the connection and LedgerIQ syncs your recent transaction history.</li>
</ul>
<p>After the initial connection, new transactions are synced automatically on a regular schedule. You can also trigger a manual sync at any time from the Connect page.</p>

<blockquote><strong>Tip:</strong> Connect all your financial accounts, including credit cards, for the most complete picture of your spending. LedgerIQ's AI categorization and subscription detection work best with comprehensive transaction data.</blockquote>

<h2>Bank-Level Security</h2>
<p>Security is not an afterthought with Plaid. Your connection is protected by multiple layers:</p>
<ul>
<li><strong>End-to-end encryption:</strong> All data transmitted between your bank, Plaid, and LedgerIQ is encrypted in transit and at rest.</li>
<li><strong>Token-based access:</strong> LedgerIQ stores an encrypted access token, not your bank credentials. This token can be revoked at any time.</li>
<li><strong>Read-only access:</strong> The Plaid connection only reads transaction data. LedgerIQ cannot move money, make payments, or modify your bank accounts in any way.</li>
<li><strong>Institutional compliance:</strong> Plaid is SOC 2 Type II certified and undergoes regular security audits.</li>
</ul>

<h3>Disconnect Anytime</h3>
<p>You can disconnect any bank connection from LedgerIQ at any time. Disconnecting immediately revokes the access token and stops all future data syncing. Your previously imported transactions remain in LedgerIQ, but no new data flows in.</p>

<h2>Webhook-Driven Updates</h2>
<p>LedgerIQ does not poll your bank on a timer. Instead, Plaid sends webhook notifications whenever new transactions are available. This means your data updates faster and more efficiently. The webhook system handles three key events:</p>
<ul>
<li><strong>New transactions available:</strong> Fresh transactions are synced automatically</li>
<li><strong>Login required:</strong> If your bank requires re-authentication, LedgerIQ alerts you</li>
<li><strong>Token expiration:</strong> If the connection expires, you are prompted to reconnect</li>
</ul>

<h2>Multiple Account Support</h2>
<p>Connect as many bank accounts as you need. Each account is tracked separately with its own balance, transaction history, and account purpose tag. The <a href="/features">Dashboard</a> aggregates data across all connected accounts for a unified financial view.</p>
<p>You can also mix connection methods. Use Plaid for your primary bank and <a href="/blog/bank-statement-upload-feature">upload statements</a> for accounts at institutions not supported by Plaid.</p>

<h2>Get Connected in Under a Minute</h2>
<p><a href="/register">Create your LedgerIQ account</a> and connect your first bank in less than 60 seconds. Automatic transaction syncing starts immediately.</p>

<h2>Frequently Asked Questions</h2>
<h3>Is my bank supported?</h3>
<p>Plaid connects to over 12,000 financial institutions. Most major banks, credit unions, and online banks are supported. You can search for your institution during the connection process.</p>

<h3>Can LedgerIQ access my bank login credentials?</h3>
<p>No. You authenticate directly with Plaid's secure window. LedgerIQ only receives an encrypted access token that allows read-only transaction access. Your username and password are never shared with LedgerIQ.</p>

<h3>What happens if my bank requires re-authentication?</h3>
<p>Some banks periodically require you to re-enter your credentials for security. When this happens, LedgerIQ alerts you and provides a simple re-authentication flow through Plaid Link.</p>
HTML;

        return [
            'slug' => 'plaid-bank-sync-feature',
            'title' => 'Secure Bank Sync via Plaid - Automatic Transaction Import | LedgerIQ',
            'meta_description' => 'LedgerIQ uses Plaid to securely sync bank transactions automatically. Read-only access, end-to-end encryption, and support for 12,000+ institutions.',
            'h1' => 'Secure Automatic Bank Sync Powered by Plaid',
            'category' => 'feature',
            'keywords' => json_encode(['plaid bank sync', 'secure bank connection', 'automatic transaction import', 'plaid integration', 'bank account sync app', 'secure financial data']),
            'excerpt' => 'LedgerIQ uses Plaid to securely sync your bank transactions automatically with read-only access, encrypted tokens, and support for over 12,000 financial institutions.',
            'content' => $content,
            'faq_items' => json_encode([
                ['question' => 'Is my bank supported?', 'answer' => 'Plaid connects to over 12,000 financial institutions. Most major banks, credit unions, and online banks are supported. You can search for your institution during the connection process.'],
                ['question' => 'Can LedgerIQ access my bank login credentials?', 'answer' => 'No. You authenticate directly with Plaid\'s secure window. LedgerIQ only receives an encrypted access token for read-only transaction access.'],
                ['question' => 'What happens if my bank requires re-authentication?', 'answer' => 'Some banks periodically require credential re-entry. LedgerIQ alerts you and provides a simple re-authentication flow through Plaid Link.'],
                ['question' => 'Can I disconnect my bank at any time?', 'answer' => 'Yes. Disconnecting immediately revokes the access token and stops all future data syncing. Your previously imported transactions remain in LedgerIQ.'],
            ]),
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    private function budgetWaterfallDashboard(): array
    {
        $content = <<<'HTML'
<h2>Visual Budget Tracking with the Waterfall Dashboard</h2>
<p>Most budgeting apps show you a list of numbers. Income minus expenses equals what is left. While technically correct, a list of numbers does not tell a story. It does not show you where your money went, in what order, and how each category contributed to the final balance.</p>
<p>LedgerIQ's budget waterfall dashboard visualizes your money flow as a cascading chart that makes your financial picture immediately understandable at a glance.</p>

<h2>How the Budget Waterfall Works</h2>
<p>A waterfall chart starts with your total income at the top and cascades downward through each spending category, showing how each expense reduces your available balance. The visual effect looks like a waterfall, with your income flowing down through expenses until you reach your remaining balance at the bottom.</p>
<p>Each bar in the chart represents a spending category. The width and color of the bar shows the relative size and type of expense. Green bars represent income, red bars represent expenses, and the final bar shows your net balance in blue or red depending on whether you ended the month positive or negative.</p>

<h3>Categories in the Waterfall</h3>
<p>The waterfall groups your spending into the major categories from your AI-categorized transactions:</p>
<ul>
<li>Housing (rent, mortgage, property tax)</li>
<li>Transportation (car payments, gas, insurance, maintenance)</li>
<li>Food and Dining (groceries, restaurants, delivery)</li>
<li>Subscriptions and Entertainment (streaming, memberships, hobbies)</li>
<li>Utilities (electric, water, internet, phone)</li>
<li>Insurance (health, life, disability)</li>
<li>Savings and Investments (401k, IRA, brokerage transfers)</li>
<li>Everything else grouped by category</li>
</ul>
<p>This hierarchical view instantly shows which categories consume the largest share of your income.</p>

<blockquote><strong>Pro tip:</strong> Look at the waterfall chart at the end of each month. The categories with the longest bars are where you have the most potential to reduce spending. Cross-reference these with your <a href="/blog/ai-savings-recommendations">AI savings recommendations</a> for specific action steps.</blockquote>

<h2>Beyond the Waterfall: Dashboard Blocks</h2>
<p>The waterfall chart is the centerpiece of the LedgerIQ dashboard, but it is surrounded by additional financial insight blocks that provide a complete picture of your finances.</p>

<h3>Monthly Bills Tracker</h3>
<p>A dedicated section shows your recurring monthly bills with their amounts and due dates. This block pulls from your detected subscriptions and recurring transactions, giving you a predictable view of your fixed monthly expenses. You can see at a glance how much of your income is committed before discretionary spending even begins.</p>

<h3>Home Affordability Calculator</h3>
<p>Based on your income and current expenses, LedgerIQ calculates how much home you can afford. This uses standard lending ratios (28% front-end, 36% back-end) applied to your actual financial data rather than hypothetical inputs. Whether you are planning to buy a home or evaluating whether your current housing costs are sustainable, this block provides clarity.</p>

<h3>Where to Cut</h3>
<p>The "Where to Cut" block highlights the spending categories with the highest potential for reduction. It combines your category spending data with AI analysis to surface the areas where small changes would yield the biggest savings. Each suggestion links to the relevant savings recommendation for detailed action steps.</p>

<h2>Real-Time Data</h2>
<p>The dashboard updates every time your bank data syncs. New transactions are categorized by AI and reflected in the waterfall chart and all dashboard blocks automatically. You always see your current financial state, not a snapshot from the last time you remembered to update a spreadsheet.</p>

<h2>Designed for Clarity</h2>
<p>LedgerIQ's dashboard was designed with a single principle: your financial picture should be understandable in under 10 seconds. The waterfall chart provides the big picture. The surrounding blocks provide the details. Together, they replace the spreadsheets, bank app dashboards, and mental arithmetic that most people rely on to understand their finances.</p>
<p><a href="/register">Create your LedgerIQ account</a> and see your budget as a clear, visual waterfall instead of a confusing list of numbers.</p>

<h2>Frequently Asked Questions</h2>
<h3>What time period does the waterfall chart cover?</h3>
<p>The dashboard shows the current month by default. The waterfall reflects all transactions from the first through the current date of the month, giving you a real-time view of your monthly spending progress.</p>

<h3>Can I customize which categories appear in the waterfall?</h3>
<p>The waterfall automatically includes all categories where you have transactions. Categories are ordered by amount to highlight the largest expenses first.</p>

<h3>How is the home affordability calculated?</h3>
<p>LedgerIQ uses standard lending ratios applied to your actual income and expenses. The front-end ratio (28% of gross income for housing) and back-end ratio (36% for total debt) provide a realistic estimate based on your data.</p>
HTML;

        return [
            'slug' => 'budget-waterfall-dashboard',
            'title' => 'Budget Waterfall Dashboard - Visual Spending Tracker | LedgerIQ',
            'meta_description' => 'LedgerIQ\'s budget waterfall dashboard visualizes your income flowing through expense categories. See your complete financial picture at a glance with real-time data.',
            'h1' => 'Budget Waterfall Dashboard for Visual Spending Clarity',
            'category' => 'feature',
            'keywords' => json_encode(['budget waterfall chart', 'visual budget tracker', 'spending dashboard', 'budget visualization', 'expense tracking dashboard', 'financial overview app']),
            'excerpt' => 'LedgerIQ\'s budget waterfall dashboard visualizes your money flowing from income through each spending category, giving you instant clarity on where every dollar goes.',
            'content' => $content,
            'faq_items' => json_encode([
                ['question' => 'What time period does the waterfall chart cover?', 'answer' => 'The dashboard shows the current month by default, reflecting all transactions from the first through the current date of the month.'],
                ['question' => 'Can I customize which categories appear in the waterfall?', 'answer' => 'The waterfall automatically includes all categories where you have transactions, ordered by amount to highlight the largest expenses first.'],
                ['question' => 'How is the home affordability calculated?', 'answer' => 'LedgerIQ uses standard lending ratios (28% front-end, 36% back-end) applied to your actual income and expenses for a realistic estimate.'],
                ['question' => 'Does the dashboard update in real time?', 'answer' => 'Yes. The dashboard updates every time your bank data syncs. New transactions are categorized by AI and reflected automatically in all dashboard blocks.'],
            ]),
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    private function aiExpenseQuestions(): array
    {
        $content = <<<'HTML'
<h2>How LedgerIQ Asks Smart Categorization Questions</h2>
<p>Not every transaction is obvious. When you see "PAYPAL *INST 4829" or "SQ *MARKET STREET" on your bank statement, do you instantly remember what you bought? Probably not. And if you cannot figure it out, neither can a simple keyword-matching algorithm.</p>
<p>LedgerIQ's AI question system handles ambiguous transactions by asking you intelligently crafted questions instead of silently miscategorizing your expenses or leaving them uncategorized entirely.</p>

<h2>Why Questions Beat Guessing</h2>
<p>Most expense trackers take one of two approaches with ambiguous transactions: guess and often get it wrong, or leave them uncategorized and hope you sort it out later. Both approaches create problems. Wrong categories corrupt your budget data. Uncategorized transactions create gaps in your spending analysis.</p>
<p>LedgerIQ takes a third approach. When the AI's confidence in a categorization falls below a threshold, it generates a targeted question. The question is designed to resolve the ambiguity with minimal effort from you.</p>

<h3>The Confidence Threshold System</h3>
<p>Every transaction receives a confidence score from the AI during categorization. This score determines how the transaction is handled:</p>
<ul>
<li><strong>85% and above:</strong> Auto-categorized. You see it in your transactions already sorted.</li>
<li><strong>60-84%:</strong> Categorized but flagged. A subtle indicator lets you confirm or change it.</li>
<li><strong>40-59%:</strong> A multiple-choice question is generated. You pick from the most likely categories.</li>
<li><strong>Below 40%:</strong> An open-ended question is generated. You describe the transaction in your own words.</li>
</ul>
<p>This graduated system means you only spend time on the transactions that genuinely need your input.</p>

<blockquote><strong>Tip:</strong> Check the Questions page weekly to stay on top of ambiguous transactions. Answering a batch of questions takes just a few minutes and dramatically improves your categorization accuracy.</blockquote>

<h2>Two Types of Questions</h2>

<h3>Multiple Choice Questions</h3>
<p>For transactions in the 40-59% confidence range, LedgerIQ presents the top candidate categories as multiple-choice options. The AI has a reasonable idea of what the transaction might be but needs you to confirm.</p>
<p>For example, a transaction at "TARGET" might prompt: "Is this purchase for Groceries, Household Supplies, Clothing, or Electronics?" The AI identifies the likely categories and you pick the right one with a single tap.</p>

<h3>Open-Ended Questions</h3>
<p>For transactions below 40% confidence, the description is too cryptic for even probable categories. LedgerIQ asks an open-ended question: "What was this transaction for?" You type a brief description, and the AI uses your response to categorize it correctly.</p>
<p>This is especially useful for payment processor transactions (PayPal, Venmo, Square) where the bank description reveals nothing about the actual purchase.</p>

<h2>AI Chat for Complex Transactions</h2>
<p>Sometimes a single answer is not enough. LedgerIQ includes a chat feature on the Questions page where you can have a brief conversation with the AI about a confusing transaction. If your initial answer does not clearly resolve the categorization, the AI might ask a follow-up question to narrow it down.</p>
<p>For example, if you say a PayPal transaction was "for work," the AI might follow up with "Was this for office supplies, software, professional services, or something else?" This conversational approach resolves even the most ambiguous transactions accurately.</p>

<h2>Bulk Answering</h2>
<p>When you have multiple pending questions, LedgerIQ supports bulk answering. If several transactions are from the same merchant or the same category, you can select them all and apply a single answer. This is particularly useful after importing a new bank statement with dozens of transactions that need review.</p>
<p>The bulk answer feature applies your categorization to all selected transactions simultaneously and updates their confidence scores so similar future transactions are handled automatically.</p>

<h2>Building Your Categorization Intelligence</h2>
<p>Every answered question teaches LedgerIQ about your specific spending patterns. The system remembers that "SQ *JOES COFFEE" is a dining expense for you, that "AMZN MKTP" is usually office supplies for your business, and that "PAYPAL *INST" transactions from a specific merchant are software subscriptions.</p>
<p>Over time, fewer questions are generated as the AI learns your patterns. Most users see a significant drop in questions after the first month of active use.</p>

<h2>Get Better Data Through Better Questions</h2>
<p>Accurate expense tracking starts with accurate categorization. <a href="/register">Sign up for LedgerIQ</a> and experience AI questions that turn ambiguous transactions into clean, organized financial data.</p>

<h2>Frequently Asked Questions</h2>
<h3>How many questions will I need to answer?</h3>
<p>It depends on your bank's transaction descriptions. Banks with clear merchant names generate fewer questions. Expect 10-20 questions in your first week, declining rapidly as the AI learns your patterns.</p>

<h3>Can I skip a question and come back later?</h3>
<p>Yes. Pending questions stay on the Questions page until you answer them. Skipping a question does not affect other transactions or your dashboard data.</p>

<h3>What happens if I answer a question incorrectly?</h3>
<p>You can change the category of any transaction at any time from the Transactions page. If you realize you miscategorized something through a question, simply update it and the AI will adjust accordingly.</p>
HTML;

        return [
            'slug' => 'ai-expense-questions',
            'title' => 'AI Expense Questions - Smart Transaction Clarification | LedgerIQ',
            'meta_description' => 'LedgerIQ asks intelligently crafted questions about ambiguous transactions instead of guessing. Multiple choice, open-ended, and chat-based clarification for perfect categorization.',
            'h1' => 'Smart AI Questions That Resolve Ambiguous Transactions',
            'category' => 'feature',
            'keywords' => json_encode(['ai expense questions', 'transaction categorization questions', 'smart expense clarification', 'ambiguous transaction resolution', 'ai expense assistant', 'transaction classification help']),
            'excerpt' => 'When transactions are ambiguous, LedgerIQ asks smart questions instead of guessing. Multiple-choice, open-ended, and chat-based clarification ensures every expense is correctly categorized.',
            'content' => $content,
            'faq_items' => json_encode([
                ['question' => 'How many questions will I need to answer?', 'answer' => 'It depends on your bank\'s transaction descriptions. Expect 10-20 questions in your first week, declining rapidly as the AI learns your patterns.'],
                ['question' => 'Can I skip a question and come back later?', 'answer' => 'Yes. Pending questions stay on the Questions page until you answer them. Skipping does not affect other transactions or your dashboard data.'],
                ['question' => 'What happens if I answer a question incorrectly?', 'answer' => 'You can change the category of any transaction at any time from the Transactions page. The AI will adjust accordingly.'],
                ['question' => 'Does answering questions improve future categorization?', 'answer' => 'Yes. Every answered question teaches LedgerIQ about your specific spending patterns. Over time, the AI generates fewer questions as it learns your habits.'],
            ]),
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    private function twoFactorAuthenticationSecurity(): array
    {
        $content = <<<'HTML'
<h2>Security Features and Two-Factor Authentication</h2>
<p>Your financial data is some of the most sensitive information you own. Bank account numbers, transaction histories, income patterns, and tax deductions paint a complete picture of your financial life. LedgerIQ treats this data with the security it deserves.</p>
<p>From encrypted storage to two-factor authentication, every layer of LedgerIQ is designed to protect your financial privacy.</p>

<h2>Two-Factor Authentication (2FA)</h2>
<p>Two-factor authentication adds a second verification step beyond your password. Even if someone obtains your password through a data breach or phishing attack, they cannot access your LedgerIQ account without the second factor.</p>

<h3>How 2FA Works in LedgerIQ</h3>
<p>LedgerIQ uses Time-based One-Time Passwords (TOTP), the same standard used by Google, Microsoft, and major banks. Here is how to set it up:</p>
<ul>
<li><strong>Step 1:</strong> Navigate to Settings and click "Enable Two-Factor Authentication"</li>
<li><strong>Step 2:</strong> Scan the QR code with any authenticator app (Google Authenticator, Authy, 1Password, etc.)</li>
<li><strong>Step 3:</strong> Enter the 6-digit code from your authenticator app to confirm</li>
<li><strong>Step 4:</strong> Save your recovery codes in a secure location</li>
</ul>
<p>After enabling 2FA, every login requires both your password and a fresh 6-digit code from your authenticator app. Codes rotate every 30 seconds and cannot be reused.</p>

<h3>Recovery Codes</h3>
<p>When you enable 2FA, LedgerIQ generates a set of recovery codes. These one-time-use codes let you access your account if you lose your authenticator device. Store them somewhere safe, like a password manager or a printed copy in a secure location.</p>
<p>You can regenerate recovery codes at any time from the Settings page if you suspect they have been compromised.</p>

<blockquote><strong>Pro tip:</strong> Use a password manager like 1Password or Bitwarden that supports TOTP codes. This way your 2FA codes are backed up automatically and accessible across all your devices.</blockquote>

<h2>Encryption at Every Layer</h2>
<p>LedgerIQ encrypts sensitive data both in transit and at rest. Here is what that means in practice:</p>

<h3>Data in Transit</h3>
<p>All communication between your browser and LedgerIQ servers uses TLS encryption (HTTPS). This prevents anyone on your network from intercepting your financial data, even on public Wi-Fi.</p>

<h3>Data at Rest</h3>
<p>Sensitive fields in the database are individually encrypted using Laravel's built-in encryption, which uses AES-256-CBC. This means even if the database were compromised, encrypted fields would be unreadable without the application encryption key. Encrypted fields include:</p>
<ul>
<li>Bank connection access tokens</li>
<li>Bank account EIN numbers</li>
<li>Email connection OAuth tokens</li>
<li>Transaction metadata from Plaid</li>
<li>Two-factor authentication secrets</li>
<li>Recovery codes</li>
</ul>

<h2>Authentication Security</h2>
<p>Beyond 2FA, LedgerIQ implements several authentication security measures:</p>

<h3>Rate Limiting</h3>
<p>Login attempts are rate-limited to prevent brute force attacks. After too many failed attempts, the account is temporarily locked. This applies to all authentication endpoints including login, registration, and password reset.</p>

<h3>Token-Based Sessions</h3>
<p>LedgerIQ uses Laravel Sanctum for API authentication with bearer tokens. Tokens are scoped, revocable, and expire after inactivity. This is more secure than traditional session cookies for API-driven applications.</p>

<h3>Google OAuth</h3>
<p>For users who prefer not to manage another password, LedgerIQ supports Google OAuth login. This delegates authentication to Google's infrastructure, which includes its own 2FA, suspicious login detection, and account recovery mechanisms.</p>

<h2>Privacy by Design</h2>
<p>Every model in LedgerIQ that contains sensitive data has hidden fields configured to prevent accidental API exposure. Even if a developer error were to return a full model object, sensitive fields like tokens, secrets, and encrypted data would be automatically stripped from the response.</p>
<p>LedgerIQ also maintains comprehensive <a href="/privacy">privacy</a> and <a href="/data-retention">data retention</a> policies that explain exactly what data is collected, how it is used, and when it is deleted.</p>

<h2>Protect Your Financial Data</h2>
<p><a href="/register">Create your LedgerIQ account</a> and enable two-factor authentication in under a minute. Your financial data deserves enterprise-grade security.</p>

<h2>Frequently Asked Questions</h2>
<h3>Is two-factor authentication required?</h3>
<p>2FA is optional but strongly recommended. You can enable or disable it at any time from the Settings page.</p>

<h3>What authenticator apps are supported?</h3>
<p>Any TOTP-compatible authenticator app works, including Google Authenticator, Authy, Microsoft Authenticator, 1Password, and Bitwarden.</p>

<h3>What happens if I lose my authenticator device?</h3>
<p>Use one of your recovery codes to log in. Each recovery code can only be used once. After logging in, you can disable 2FA and re-enable it with your new device.</p>
HTML;

        return [
            'slug' => 'two-factor-authentication-security',
            'title' => 'Two-Factor Authentication & Security Features | LedgerIQ',
            'meta_description' => 'LedgerIQ protects your financial data with TOTP two-factor authentication, AES-256 encryption, rate-limited logins, and privacy-by-design architecture.',
            'h1' => 'Two-Factor Authentication and Enterprise-Grade Security',
            'category' => 'feature',
            'keywords' => json_encode(['two factor authentication', 'financial data security', '2fa expense tracker', 'secure finance app', 'encrypted financial data', 'totp authentication']),
            'excerpt' => 'LedgerIQ protects your financial data with TOTP two-factor authentication, AES-256 encryption at rest, rate-limited authentication, and privacy-by-design architecture.',
            'content' => $content,
            'faq_items' => json_encode([
                ['question' => 'Is two-factor authentication required?', 'answer' => '2FA is optional but strongly recommended. You can enable or disable it at any time from the Settings page.'],
                ['question' => 'What authenticator apps are supported?', 'answer' => 'Any TOTP-compatible authenticator app works, including Google Authenticator, Authy, Microsoft Authenticator, 1Password, and Bitwarden.'],
                ['question' => 'What happens if I lose my authenticator device?', 'answer' => 'Use one of your recovery codes to log in. Each code can only be used once. After logging in, you can disable 2FA and re-enable it with your new device.'],
                ['question' => 'Is my bank data encrypted?', 'answer' => 'Yes. All sensitive fields including bank access tokens, account numbers, and OAuth tokens are individually encrypted using AES-256-CBC encryption at rest.'],
            ]),
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    private function emailReceiptMatching(): array
    {
        $content = <<<'HTML'
<h2>Automatic Email Receipt Matching</h2>
<p>Every online purchase generates two records: a bank transaction and an email receipt. The bank transaction tells you the amount and merchant. The email receipt tells you exactly what you bought, item by item. LedgerIQ connects these two records automatically, giving you complete visibility into your spending.</p>
<p>Instead of scrolling through your inbox to find receipts at tax time, LedgerIQ pulls them in, parses them with AI, and matches them to your bank transactions.</p>

<h2>How Email Receipt Matching Works</h2>
<p>The matching pipeline has four stages, each building on the previous one to transform raw emails into organized, transaction-linked purchase records.</p>

<h3>Stage 1: Email Connection</h3>
<p>LedgerIQ connects to your email through two methods:</p>
<ul>
<li><strong>Google OAuth:</strong> For Gmail users, a secure OAuth connection grants read-only access to your inbox. LedgerIQ can only read emails; it cannot send, delete, or modify anything.</li>
<li><strong>IMAP:</strong> For non-Gmail providers (Outlook, Yahoo, ProtonMail, custom domains), standard IMAP credentials provide the same read-only access.</li>
</ul>
<p>Both methods are configured on the <a href="/features">Connect page</a> alongside your bank connections.</p>

<h3>Stage 2: Receipt Detection</h3>
<p>LedgerIQ scans your inbox for emails that look like purchase receipts. Not every email from a retailer is a receipt. The system distinguishes between order confirmations, shipping notifications, marketing emails, and actual purchase receipts using AI-powered classification.</p>
<p>Receipts are identified from major retailers and services including Amazon, Apple, Google, Uber, DoorDash, and hundreds of other online merchants.</p>

<h3>Stage 3: AI Parsing</h3>
<p>Each detected receipt is parsed by Claude AI to extract structured data from unstructured email HTML. The AI identifies:</p>
<ul>
<li><strong>Order total:</strong> The final amount charged including tax and shipping</li>
<li><strong>Individual items:</strong> Each product or service with its price</li>
<li><strong>Merchant name:</strong> The business that issued the receipt</li>
<li><strong>Order date:</strong> When the purchase was made</li>
<li><strong>Item categories:</strong> AI-suggested categories for each line item</li>
<li><strong>Tax deductibility:</strong> Whether each item may be tax-deductible based on its category</li>
</ul>

<blockquote><strong>Tip:</strong> Connect your email early, even before you have a full transaction history. LedgerIQ stores parsed receipts and matches them retroactively when the corresponding bank transactions are imported later.</blockquote>

<h3>Stage 4: Transaction Matching</h3>
<p>Parsed receipts are matched to bank transactions using amount, date, and merchant name. When a receipt for $47.82 from Amazon on January 15th matches a bank charge of $47.82 from "AMZN MKTP US" on January 15th, LedgerIQ links them together.</p>
<p>This matching adds item-level detail to your bank transactions. Instead of seeing a $47.82 Amazon charge, you see that it was $29.99 for a keyboard, $12.99 for a phone case, and $4.84 in tax.</p>

<h2>Why Item-Level Detail Matters</h2>
<p>Bank transactions are blunt instruments. A $200 Target charge could be groceries, clothing, electronics, or all three. Without the receipt, there is no way to know. With email receipt matching, LedgerIQ splits that single transaction into its component purchases and categorizes each one separately.</p>
<p>This is especially valuable for:</p>
<ul>
<li><strong>Tax deductions:</strong> Mixed purchases at retailers like Amazon can contain both deductible and non-deductible items. Receipt matching separates them.</li>
<li><strong>Budget accuracy:</strong> A single Walmart charge might include groceries, household supplies, and a clothing item. Each goes into the right budget category.</li>
<li><strong>Spending analysis:</strong> Understanding what you actually buy, not just where you shop, provides deeper spending insights.</li>
</ul>

<h2>Privacy and Control</h2>
<p>Email access is strictly read-only. LedgerIQ cannot send emails, delete messages, or modify your inbox in any way. OAuth tokens and IMAP credentials are encrypted at rest using AES-256 encryption. You can disconnect your email at any time, and previously parsed receipts remain in your account for reference.</p>

<h2>Connect Your Email and See the Full Picture</h2>
<p>Bank transactions show you where money went. Email receipts show you what you bought. <a href="/register">Create your LedgerIQ account</a> and connect your email to unlock item-level spending visibility.</p>

<h2>Frequently Asked Questions</h2>
<h3>Which email providers are supported?</h3>
<p>Gmail is supported via Google OAuth for the smoothest experience. Any email provider that supports IMAP (Outlook, Yahoo, ProtonMail, custom domains) also works.</p>

<h3>Does LedgerIQ read all my emails?</h3>
<p>No. LedgerIQ only scans for emails that match receipt patterns from known merchants. Personal emails, newsletters, and other non-receipt messages are ignored.</p>

<h3>What if a receipt does not match a transaction?</h3>
<p>Unmatched receipts are stored and displayed separately. They may match later when the corresponding bank transaction syncs, or they can be manually linked if needed.</p>
HTML;

        return [
            'slug' => 'email-receipt-matching',
            'title' => 'Email Receipt Matching - Item-Level Transaction Detail | LedgerIQ',
            'meta_description' => 'LedgerIQ automatically matches email receipts to bank transactions, adding item-level detail to your spending data. AI-parsed receipts from Gmail and IMAP providers.',
            'h1' => 'Automatic Email Receipt Matching for Complete Spending Visibility',
            'category' => 'feature',
            'keywords' => json_encode(['email receipt matching', 'automatic receipt parsing', 'receipt to transaction matching', 'email receipt scanner', 'itemized expense tracking', 'receipt organization app']),
            'excerpt' => 'LedgerIQ connects your email to automatically parse purchase receipts with AI, matching them to bank transactions for item-level spending detail and better tax deduction tracking.',
            'content' => $content,
            'faq_items' => json_encode([
                ['question' => 'Which email providers are supported?', 'answer' => 'Gmail is supported via Google OAuth. Any email provider that supports IMAP (Outlook, Yahoo, ProtonMail, custom domains) also works.'],
                ['question' => 'Does LedgerIQ read all my emails?', 'answer' => 'No. LedgerIQ only scans for emails that match receipt patterns from known merchants. Personal emails and newsletters are ignored.'],
                ['question' => 'What if a receipt does not match a transaction?', 'answer' => 'Unmatched receipts are stored separately. They may match later when the corresponding bank transaction syncs, or can be manually linked.'],
                ['question' => 'Can I disconnect my email at any time?', 'answer' => 'Yes. Disconnecting stops all future email scanning. Previously parsed receipts remain in your account for reference.'],
            ]),
            'is_published' => true,
            'published_at' => now(),
        ];
    }
}
