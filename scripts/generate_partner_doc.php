<?php

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;

// Force Zip extension
Settings::setZipClass(Settings::PCLZIP);

$phpWord = new PhpWord;

$phpWord->setDefaultFontName('Calibri');
$phpWord->setDefaultFontSize(11);

// ── Styles ──────────────────────────────────────────────────────────────

$phpWord->addTitleStyle(1, ['size' => 26, 'bold' => true, 'color' => '1a56db'], ['alignment' => Jc::CENTER, 'spaceAfter' => 240]);
$phpWord->addTitleStyle(2, ['size' => 18, 'bold' => true, 'color' => '1a56db'], ['spaceBefore' => 360, 'spaceAfter' => 120]);
$phpWord->addTitleStyle(3, ['size' => 14, 'bold' => true, 'color' => '333333'], ['spaceBefore' => 240, 'spaceAfter' => 80]);
$phpWord->addTitleStyle(4, ['size' => 12, 'bold' => true, 'color' => '555555'], ['spaceBefore' => 160, 'spaceAfter' => 60]);

$body = ['spaceAfter' => 120, 'lineHeight' => 1.4];
$centered = ['alignment' => Jc::CENTER, 'spaceAfter' => 80];

$tableStyle = [
    'borderSize' => 6,
    'borderColor' => 'cccccc',
    'cellMargin' => 80,
];
$phpWord->addTableStyle('dataTable', $tableStyle, ['bgColor' => '1a56db']);

$hFont = ['bold' => true, 'color' => 'ffffff', 'size' => 10];
$cFont = ['size' => 10];
$cBold = ['size' => 10, 'bold' => true];
$greenBold = ['size' => 10, 'bold' => true, 'color' => '16a34a'];

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  COVER PAGE                                                        ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();

$section->addTextBreak(5);
$section->addTitle('SpendifiAI', 1);
$section->addText('AI-Powered Personal Finance Platform', ['size' => 16, 'color' => '555555'], $centered);
$section->addTextBreak(1);
$section->addText('Comprehensive Product Overview', ['size' => 14, 'bold' => true, 'color' => '1a56db'], $centered);
$section->addText('For Prospective Partners & Stakeholders', ['size' => 12, 'color' => '888888'], $centered);
$section->addTextBreak(8);
$section->addText('Prepared: February 2026', ['size' => 11, 'color' => '888888'], $centered);
$section->addText('Version 1.0 | Confidential', ['size' => 10, 'italic' => true, 'color' => 'aaaaaa'], $centered);

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  EXECUTIVE SUMMARY                                                 ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('Executive Summary', 2);

$section->addText(
    'SpendifiAI is a free, AI-powered personal finance platform that helps individuals, freelancers, and small business owners take complete control of their financial lives. By combining bank-grade security, intelligent automation, and the power of Claude AI, SpendifiAI eliminates the tedious manual work of expense tracking, subscription management, savings optimization, and tax preparation.',
    null, $body
);

$section->addText(
    'Unlike competitors that charge $8-15/month (Mint, YNAB, Copilot), SpendifiAI is 100% free with no premium tiers, no trial periods, and no credit card required. This is not a loss leader - it is a deliberate strategy to capture market share in the rapidly growing personal finance management sector.',
    null, $body
);

$section->addTitle('Core Value Propositions', 3);

$bullets = [
    'Automatic AI Categorization - Claude AI categorizes every transaction with 85%+ accuracy, learns from corrections, and cascades intelligence across merchants',
    'Bank Connectivity - Secure Plaid integration (12,000+ institutions, SOC 2 Type II) plus manual statement upload for banks not on Plaid',
    'Subscription Detection - Pattern-based detection of all recurring charges, with "stopped billing" alerts for unused services',
    'AI Savings Recommendations - Personalized, actionable plans based on 90 days of real spending data, prioritized by ease of implementation',
    'Tax Deduction Optimization - Automatic IRS Schedule C mapping with one-click export (Excel, PDF, CSV) and email-to-accountant',
    'Comprehensive Dashboard - 8-widget financial command center: budget waterfall, monthly bills, home affordability, where to cut, savings tracking, and more',
];
foreach ($bullets as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('Technology Stack', 3);
$section->addText(
    'Built on Laravel 12 (PHP 8.3), React 19, TypeScript, Tailwind CSS v4, PostgreSQL, and Redis. AI powered by Anthropic Claude Sonnet 4. Bank integration via Plaid API. 142 automated tests ensure reliability across all features.',
    null, $body
);

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  BANK CONNECTIVITY                                                 ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('Bank Connectivity & Data Ingestion', 2);

$section->addText(
    'SpendifiAI provides two complementary methods for importing financial data, ensuring every user can participate regardless of their bank or technical comfort level.',
    null, $body
);

$section->addTitle('Plaid Bank Integration', 3);

$section->addText(
    'Through Plaid - the same infrastructure trusted by Venmo, Robinhood, Coinbase, and thousands of financial applications - users can securely connect their bank accounts in under 60 seconds. Their banking credentials never touch SpendifiAI\'s servers.',
    null, $body
);

$section->addTitle('How It Works', 4);
$items = [
    'User clicks "Connect Bank" and authenticates directly with their bank through Plaid\'s secure modal',
    'Plaid returns an encrypted access token - SpendifiAI stores it with AES-256 encryption at rest',
    'Transactions sync automatically in real-time via webhooks (SYNC_UPDATES_AVAILABLE events)',
    'Account balances, types, and metadata are imported alongside transactions',
    'Users can disconnect at any time with one click - the encrypted token is immediately destroyed',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('Security Credentials', 4);
$items = [
    'SOC 2 Type II certified infrastructure',
    'End-to-end TLS encryption for all data in transit',
    'Plaid access tokens encrypted with AES-256 at rest in PostgreSQL',
    'Webhook signature verification prevents tampering',
    'Idempotency logging prevents duplicate transaction processing',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('Multi-Account Support', 4);
$section->addText(
    'Users can link multiple banks and multiple account types (checking, savings, credit card, investment). Each account can be tagged with a purpose - personal, business, mixed, or investment - which becomes the strongest signal for AI categorization accuracy.',
    null, $body
);

$section->addTitle('Bank Statement Upload', 3);

$section->addText(
    'For users whose banks are not supported by Plaid, or who prefer not to link their accounts electronically, SpendifiAI offers intelligent statement parsing. Users simply upload a PDF or CSV bank statement, and Claude AI extracts every transaction automatically.',
    null, $body
);

$section->addTitle('PDF Statement Processing', 4);
$items = [
    'User uploads a PDF bank statement (up to 10 MB)',
    'The system extracts raw text using spatie/pdf-to-text (pdftotext binary)',
    'Extracted text is sent to Claude AI with context about the bank name and account type',
    'AI identifies and extracts each transaction: date, description, amount, deposit/withdrawal',
    'Merchant names are cleaned - card numbers, reference IDs, and city/state suffixes are stripped',
    'Example: "AMAZON.COM*RT3K2 AMZN.COM/BIL WA" becomes "Amazon"',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('CSV Statement Processing', 4);
$items = [
    'User uploads a CSV file from any bank',
    'System reads the first 5 rows and sends them to Claude AI for column detection',
    'AI identifies which columns contain the date, description, amount (or separate debit/credit), and balance',
    'AI determines the date format and number of header rows to skip',
    'The entire CSV is then parsed using the detected column mapping',
    'This approach works with any CSV format from any institution - no pre-configured templates needed',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('Duplicate Detection', 4);
$section->addText(
    'Before importing, SpendifiAI automatically checks each parsed transaction against existing records. Matches are identified using amount (within $0.01 tolerance), date (exact match), and merchant name (Levenshtein distance threshold). Flagged duplicates are shown to the user for review before import, preventing double-counting.',
    null, $body
);

$section->addTitle('Post-Import Pipeline', 4);
$section->addText(
    'After the user reviews and confirms the parsed transactions, they are imported into the same pipeline as Plaid transactions. The AI categorization engine processes them identically - there is no second-class treatment for uploaded data.',
    null, $body
);

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  AI CATEGORIZATION                                                 ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('AI-Powered Transaction Categorization', 2);

$section->addText(
    'At the heart of SpendifiAI is Claude AI\'s ability to understand and categorize financial transactions with human-like accuracy. This is not simple keyword matching - the AI considers context, account purpose, merchant patterns, and user history to make intelligent decisions.',
    null, $body
);

$section->addTitle('What the AI Considers', 3);
$items = [
    'Merchant name and normalized merchant identifier',
    'Transaction amount and date',
    'Description and payment channel (card, ACH, wire, etc.)',
    'Plaid\'s baseline automated category (when available)',
    'Account purpose: personal, business, mixed, or investment - the single strongest categorization signal',
    'Account nickname (e.g., "Business Checking" provides strong context)',
    'User\'s employment type (W-2, self-employed, freelancer)',
    'Business type and home office status',
    'Tax filing status and custom categorization rules',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('Confidence-Based Routing', 3);

$section->addText(
    'The AI assigns a confidence score (0.0 to 1.0) to every categorization. This score determines the user experience:',
    null, $body
);

$table = $section->addTable('dataTable');
$table->addRow();
$table->addCell(2000)->addText('Confidence', $hFont);
$table->addCell(2200)->addText('Action', $hFont);
$table->addCell(5800)->addText('User Experience', $hFont);

$rows = [
    ['85%+', 'Auto-categorize', 'Transaction is silently categorized. User sees it fully processed.'],
    ['60-84%', 'Flag for review', 'Category applied but marked for optional human verification.'],
    ['40-59%', 'Multiple-choice', 'AI generates 3-4 options and asks the user to choose.'],
    ['Below 40%', 'Open-ended Q', 'AI asks a free-text question; user can chat for clarification.'],
];
foreach ($rows as $row) {
    $table->addRow();
    $table->addCell(2000)->addText($row[0], $cBold);
    $table->addCell(2200)->addText($row[1], $cFont);
    $table->addCell(5800)->addText($row[2], $cFont);
}

$section->addTitle('Learning from Corrections', 3);

$section->addText(
    'When a user corrects a categorization or answers an AI question, the system doesn\'t just update that one transaction:',
    null, $body
);

$items = [
    'The correction is applied to the target transaction immediately',
    'All other transactions from the same merchant are updated to match (unless already user-confirmed)',
    'Pending AI questions for the same merchant are automatically resolved',
    'Future transactions from that merchant inherit the user\'s preference',
    'This "cascade" behavior means answering one question can resolve dozens of transactions simultaneously',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('56 Expense Categories', 3);
$section->addText(
    'SpendifiAI uses 56 expense categories (46 top-level, 10 subcategories) organized into logical groups: Housing & Utilities, Transportation, Food & Dining, Business Expenses, Personal & Health, Subscriptions & Entertainment, Household & Personal, Education & Wealth, and Transfer/Income categories. Of these, 12 categories have direct IRS Schedule C line mappings for tax export.',
    null, $body
);

$section->addTitle('AI Question Types', 3);

$table = $section->addTable('dataTable');
$table->addRow();
$table->addCell(2500)->addText('Question Type', $hFont);
$table->addCell(7500)->addText('Example', $hFont);

$rows = [
    ['Category', '"What category best describes this $47.99 charge at COSTCO?"'],
    ['Business/Personal', '"Is this $129 charge at Staples for business or personal use?"'],
    ['Split', '"Was this $200 Costco purchase all groceries, or mixed categories?"'],
    ['Confirm', '"Is this $15.99 charge correctly categorized as Streaming?"'],
];
foreach ($rows as $row) {
    $table->addRow();
    $table->addCell(2500)->addText($row[0], $cBold);
    $table->addCell(7500)->addText($row[1], $cFont);
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  SUBSCRIPTION DETECTION                                            ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('Subscription Detection & Management', 2);

$section->addText(
    'The average American spends $219/month on subscriptions, with studies showing that most people underestimate their recurring charges by 2-3x. SpendifiAI\'s Subscription Detective scans 6 months of transaction history to surface every recurring charge - especially the ones users have forgotten about.',
    null, $body
);

$section->addTitle('Detection Algorithm', 3);

$items = [
    'All transactions from the past 6 months are grouped by normalized merchant name',
    'Merchants with 2+ transactions are analyzed for recurrence patterns',
    'The system measures intervals between charges and checks amount consistency (within 20% tolerance)',
    'Charges are classified by frequency: weekly, monthly, quarterly, or annual',
    'A pre-populated registry of 49 known subscription merchants enhances accuracy',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('"Stopped Billing" Detection - Finding Unused Subscriptions', 3);

$section->addText(
    'This is where SpendifiAI provides immediate, concrete value. The system compares each subscription\'s last charge date against its expected billing cycle:',
    null, $body
);

$table = $section->addTable('dataTable');
$table->addRow();
$table->addCell(2500)->addText('Frequency', $hFont);
$table->addCell(3500)->addText('Flagged As Unused After', $hFont);
$table->addCell(4000)->addText('Logic', $hFont);

$rows = [
    ['Weekly', '21+ days without a charge', '3x the weekly interval'],
    ['Monthly', '60+ days without a charge', '2x the monthly interval'],
    ['Quarterly', '180+ days without a charge', '2x the quarterly interval'],
    ['Annual', '400+ days without a charge', 'About 13 months since last charge'],
];
foreach ($rows as $row) {
    $table->addRow();
    $table->addCell(2500)->addText($row[0], $cFont);
    $table->addCell(3500)->addText($row[1], $cFont);
    $table->addCell(4000)->addText($row[2], $cFont);
}

$section->addTextBreak(1);
$section->addText(
    'When a subscription is flagged as "stopped billing," it receives a prominent red badge on both the Subscriptions page and the Dashboard\'s monthly bills widget. This immediately draws the user\'s attention to services they may be paying for but not using.',
    null, $body
);

$section->addTitle('User Response System', 3);
$section->addText('For each subscription, users can take one of three actions:', null, $body);

$items = [
    'Cancel - Mark the subscription for cancellation. SpendifiAI provides direct links to cancellation pages and tracks the projected savings.',
    'Reduce - Downgrade to a cheaper plan. The AI suggests alternative tiers and calculates the savings difference.',
    'Keep - Acknowledge the subscription and dismiss the alert. This prevents future nagging about services the user values.',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('AI-Powered Alternatives', 3);
$section->addText(
    'When a user considers canceling or reducing a subscription, SpendifiAI\'s AI can suggest cheaper alternatives. For example, if a user is paying $15.99/month for a streaming service, the AI might suggest a competitor at $7.99/month or a bundle that combines multiple services for less. Alternative suggestions are cached for 7 days to minimize API calls.',
    null, $body
);

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  AI SAVINGS                                                        ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('AI Savings Recommendations & Goal Tracking', 2);

$section->addText(
    'SpendifiAI\'s savings engine goes beyond simple "spend less" advice. It analyzes 90 days of actual spending data - broken down by category, merchant, timing, and pattern - to generate specific, actionable recommendations with real dollar amounts pulled from the user\'s own transactions.',
    null, $body
);

$section->addTitle('90-Day Spending Analysis', 3);
$section->addText('The AI receives a comprehensive spending profile including:', null, $body);

$items = [
    'Total spending by category with transaction counts and averages',
    'Active and unused subscriptions with monthly costs',
    'Daily spending averages and day-of-week patterns',
    'Impulse purchase frequency (transactions under $20)',
    'Dining out frequency and totals',
    'Late-night purchasing patterns (potential impulse indicator)',
    'Total transaction volume for context',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('Recommendation Quality', 3);
$section->addText('Each AI recommendation includes:', null, $body);

$table = $section->addTable('dataTable');
$table->addRow();
$table->addCell(2500)->addText('Field', $hFont);
$table->addCell(7500)->addText('Description', $hFont);

$rows = [
    ['Title', 'Short, actionable headline (e.g., "Cancel Paramount+ and Crunchyroll")'],
    ['Description', 'Specific explanation using REAL merchant names and amounts from user data'],
    ['Monthly Savings', 'Estimated monthly dollar savings'],
    ['Annual Savings', 'Projected annual impact (monthly x 12)'],
    ['Difficulty', 'Easy (no lifestyle change), Medium (behavior change), Hard (sacrifice)'],
    ['Impact', 'High ($50+/mo), Medium ($15-50/mo), Low (under $15/mo)'],
    ['Action Steps', 'Numbered instructions (e.g., "Go to paramountplus.com/account, click Cancel")'],
    ['Related Merchants', 'The specific merchants/services this recommendation affects'],
];
foreach ($rows as $row) {
    $table->addRow();
    $table->addCell(2500)->addText($row[0], $cBold);
    $table->addCell(7500)->addText($row[1], $cFont);
}

$section->addTitle('Savings Goal Planning', 3);
$section->addText(
    'Users can set a concrete savings target (e.g., "Save $500/month for an emergency fund by December"). The AI then generates a personalized action plan:',
    null, $body
);

$items = [
    'Calculates the gap between current spending and the savings target',
    'Prioritizes easy wins first (unused subscriptions, plan downgrades)',
    'Then targets discretionary spending (dining, entertainment, impulse buys)',
    'Only suggests cutting essentials as a last resort, with clear warnings',
    'Each action includes specific "how-to" steps using real merchants and amounts',
    'Actions are priority-ordered (1 = do first) for maximum impact',
    'Progress is tracked with a visual progress bar and on-track indicators',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('Response & Tracking System', 3);
$section->addText(
    'For each recommendation, users can respond with Cancel (they\'ll stop the service), Reduce (they\'ll downgrade), or Keep (they value it). Responses are tracked over time, and the projected savings banner updates in real-time as users commit to changes. Monthly pulse checks compare actual spending against commitments.',
    null, $body
);

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  TAX OPTIMIZATION                                                  ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('Tax Deduction Optimization & Export', 2);

$section->addText(
    'For freelancers, self-employed individuals, and small business owners, tax deduction tracking is one of SpendifiAI\'s most valuable features. The AI automatically identifies tax-deductible expenses and maps them to IRS Schedule C line items, then exports accountant-ready reports in multiple formats.',
    null, $body
);

$section->addTitle('Automatic Tax Deductibility Detection', 3);
$section->addText(
    'During categorization, Claude AI evaluates each transaction for tax deductibility based on the user\'s employment type and the expense category:',
    null, $body
);

$section->addTitle('Self-Employed / Freelancer Deductions', 4);
$items = [
    'Office supplies and equipment',
    'Software and SaaS subscriptions used for business',
    'Business meals (50% deductible per IRS rules)',
    'Home office expenses',
    'Professional development and training',
    'Business travel (flights, hotels, transportation)',
    'Marketing and advertising',
    'Health insurance premiums (self-employed deduction)',
    'Professional services (legal, accounting)',
    'Shipping and postage',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('Universal Deductions', 4);
$items = [
    'Charitable donations (Schedule A)',
    'Medical expenses above AGI threshold (Schedule A)',
    'Mortgage interest (Schedule A)',
    'State and local taxes - SALT (Schedule A)',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('IRS Schedule C Line Mapping', 3);
$section->addText('SpendifiAI maps 12 expense categories directly to IRS Schedule C lines:', null, $body);

$table = $section->addTable('dataTable');
$table->addRow();
$table->addCell(2200)->addText('Schedule C Line', $hFont);
$table->addCell(2800)->addText('Description', $hFont);
$table->addCell(5000)->addText('SpendifiAI Categories', $hFont);

$rows = [
    ['Line 8', 'Advertising', 'Marketing & Advertising'],
    ['Line 9', 'Car & Truck', 'Gas & Fuel, Auto Maintenance, Transportation'],
    ['Line 15', 'Insurance', 'Health Insurance, Home Insurance'],
    ['Line 17', 'Legal & Professional', 'Professional Services'],
    ['Line 18', 'Office Expense', 'Office Supplies, Software & SaaS, Shipping'],
    ['Line 24a', 'Travel', 'Flights, Travel & Hotels'],
    ['Line 24b', 'Meals (50%)', 'Business Meals'],
    ['Line 25', 'Utilities', 'Phone & Internet, Utilities'],
    ['Line 27a', 'Other Expenses', 'Professional Development'],
    ['Line 30', 'Home Office', 'Home Office'],
    ['Schedule A', 'Charitable', 'Charity & Donations'],
    ['Schedule A', 'Medical', 'Medical & Dental'],
];
foreach ($rows as $row) {
    $table->addRow();
    $table->addCell(2200)->addText($row[0], $cBold);
    $table->addCell(2800)->addText($row[1], $cFont);
    $table->addCell(5000)->addText($row[2], $cFont);
}

$section->addTitle('Export Formats', 3);

$section->addTitle('Excel Workbook (5 Tabs)', 4);
$items = [
    'Tax Summary - Taxpayer profile, deduction totals, estimated tax savings by bracket, linked accounts',
    'Schedule C Mapping - Line-by-line IRS form mapping with amounts and item counts',
    'Deductions by Category - Aggregated totals per tax category with transaction counts and date ranges',
    'All Deductible Transactions - Full detail: date, merchant, amount, category, tax line, AI confidence, verification status',
    'Business Subscriptions - Recurring business expenses with annual projections',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('PDF Cover Sheet', 4);
$section->addText(
    'Executive summary designed for accountants - key statistics, taxpayer profile, and deduction totals in a clean, professional format.',
    null, $body
);

$section->addTitle('CSV Export', 4);
$section->addText(
    'Universal flat-file format importable into TurboTax, QuickBooks, and any CPA software. Same columns as the Excel detail tab.',
    null, $body
);

$section->addTitle('Email to Accountant', 4);
$section->addText(
    'One-click delivery of all three export files directly to the user\'s accountant via email, with a summary in the email body and CC to the user for records.',
    null, $body
);

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  DASHBOARD                                                         ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('Comprehensive Financial Dashboard', 2);

$section->addText(
    'The SpendifiAI dashboard is an 8-widget financial command center that gives users a complete picture of their financial health at a glance. Data is cached for 60 seconds per user and can be filtered by account type (personal/business).',
    null, $body
);

$section->addTitle('1. Smart Greeting & Hero Metrics', 3);
$section->addText(
    'A dynamic greeting adapts to financial health ("You can save $X/mo" vs. "$X/mo over budget"). Live income/expense indicators with directional arrows, savings rate badge (green if positive, red if deficit), and bank sync status.',
    null, $body
);

$section->addTitle('2. Budget Reality Check (Waterfall Analysis)', 3);
$section->addText(
    'A visual stacked bar chart breaks down monthly cash flow into Essential Bills (red), Non-Essential Subscriptions (amber), Discretionary Spending (orange), and Monthly Surplus (green). Detailed breakdown rows show exact amounts and percentages. The color-coded verdict tells users whether they can save or are over budget, with the savings rate as a percentage of income.',
    null, $body
);

$section->addTitle('3. Your Monthly Bills', 3);
$section->addText(
    'All recurring charges consolidated with total monthly cost, annual projection, and essential vs. non-essential split. Each bill shows the merchant, frequency, next expected charge, and a "Stopped Billing" badge for unused subscriptions.',
    null, $body
);

$section->addTitle('4. Home Affordability Calculator', 3);
$section->addText(
    'Calculates maximum home price based on actual income and debt. Shows estimated monthly mortgage payment, interest rate, current debt-to-income ratio (DTI), and loan term. Color-coded DTI analysis: green (under 28%, excellent), amber (28-36%, moderate), red (over 36%, exceeds lender maximums).',
    null, $body
);

$section->addTitle('5. Where to Cut - Action Feed', 3);
$section->addText(
    'A three-tab system (Quick Wins, This Week, This Month) surfaces actionable savings. Each card shows title, monthly/annual savings potential, color-coded type icon, expandable action steps, and respond/dismiss buttons. Card types include unused subscriptions, AI savings recommendations, overspending categories, and pending AI questions.',
    null, $body
);

$section->addTitle('6. Savings Progress & Goals', 3);
$section->addText(
    '6-month savings tracking chart and applied recommendations list alongside savings goal progress bar with motivation text and cumulative vs. target comparison.',
    null, $body
);

$section->addTitle('7. Spending Trend', 3);
$section->addText(
    '6-month income vs. expense trend chart with category breakdown by month, helping users identify seasonal spending patterns.',
    null, $body
);

$section->addTitle('8. Recent Activity', 3);
$section->addText(
    'Last 20 transactions with inline category badges and quick link to the full Transactions page.',
    null, $body
);

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  SECURITY                                                          ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('Security Architecture', 2);

$section->addText(
    'SpendifiAI treats security as a foundational requirement, not an afterthought. The platform implements defense-in-depth across every layer of the stack.',
    null, $body
);

$section->addTitle('Encryption', 3);
$items = [
    'AES-256 encryption at rest for all sensitive data (Plaid tokens, bank account EINs, email credentials, 2FA secrets, transaction metadata)',
    'Laravel model-level encryption casts - sensitive fields are never stored in plaintext',
    'TLS/HTTPS for all data in transit',
    'HSTS header with 1-year max-age, includeSubDomains, and preload directive',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('Authentication & Access Control', 3);
$items = [
    'bcrypt password hashing (Laravel default)',
    'Sanctum bearer token authentication for API endpoints',
    'Rate limiting on all authentication endpoints',
    'Optional TOTP two-factor authentication with encrypted backup recovery codes',
    'Google OAuth social login as an alternative',
    'Password reset via signed email links',
    'Email verification for new accounts',
    'Account lockout protection after failed attempts',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('Security Headers', 3);
$items = [
    'Content-Security-Policy - Restricts script, style, font, image, and frame sources',
    'X-Content-Type-Options: nosniff - Prevents MIME-type sniffing',
    'X-Frame-Options: DENY - Prevents clickjacking',
    'Referrer-Policy: strict-origin-when-cross-origin',
    'Permissions-Policy - Disables camera, microphone, and geolocation access',
    'Strict-Transport-Security - Forces HTTPS with preload',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTitle('Data Protection', 3);
$items = [
    'Every model with sensitive data uses $hidden arrays to prevent API leakage',
    'Plaid access tokens are encrypted and never exposed to the frontend',
    'Webhook signature verification prevents request tampering',
    'Idempotency logging prevents duplicate webhook processing',
    'Users can disconnect banks and delete accounts at any time',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  ADDITIONAL FEATURES                                               ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('Additional Features', 2);

$section->addTitle('Email Receipt Parsing (In Development)', 3);
$section->addText(
    'Users can connect their Gmail (via OAuth) or any IMAP email account. SpendifiAI scans for order confirmations and receipts, extracts product-level detail using Claude AI, and matches them to bank transactions. This provides itemized purchase tracking - for example, knowing that a $147.32 Amazon charge was specifically for office supplies and a printer cartridge, enabling more accurate tax categorization at the product level.',
    null, $body
);

$section->addTitle('AI Question Chat System', 3);
$section->addText(
    'When the AI is uncertain about a transaction\'s category, it asks the user. Users interact through a dedicated Questions page where they can answer multiple-choice questions, provide free-text explanations, or engage in a back-and-forth chat with the AI for nuanced situations. Bulk answer mode lets users resolve multiple questions at once.',
    null, $body
);

$section->addTitle('Transaction Management', 3);
$section->addText(
    'A full-featured transaction table with filtering by date range, category, amount range, merchant, account, and review status. Users can inline-edit categories and mark transactions as deductible. Eight query scopes provide efficient data retrieval across the application.',
    null, $body
);

$section->addTitle('Financial Profile & Settings', 3);
$section->addText(
    'Users can configure their employment type, business type, home office status, tax filing status, and income details. This profile data enhances AI categorization accuracy and tax deduction detection. Settings also include 2FA management and account deletion.',
    null, $body
);

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  COMPETITIVE LANDSCAPE                                             ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('Competitive Landscape', 2);

$section->addText(
    'SpendifiAI competes in the personal finance management space with a unique combination of AI intelligence and zero-cost pricing:',
    null, $body
);

$table = $section->addTable('dataTable');
$table->addRow();
$table->addCell(1800)->addText('Feature', $hFont);
$table->addCell(1500)->addText('SpendifiAI', $hFont);
$table->addCell(1500)->addText('Mint', $hFont);
$table->addCell(1500)->addText('YNAB', $hFont);
$table->addCell(1500)->addText('Copilot', $hFont);
$table->addCell(1500)->addText('Quicken', $hFont);

$compRows = [
    ['Price', 'Free', 'Free*', '$14.99/mo', '$10.99/mo', '$5.99/mo'],
    ['AI Categorize', 'Yes', 'Basic', 'Manual', 'Yes', 'Rules'],
    ['Bank Sync', 'Plaid', 'Plaid', 'Plaid', 'Plaid', 'Plaid'],
    ['Statement Upload', 'PDF + CSV', 'No', 'Manual', 'No', 'CSV only'],
    ['Sub Detection', 'Auto + AI', 'Basic', 'No', 'Auto', 'No'],
    ['Savings Tips', 'AI-Powered', 'Basic', 'No', 'No', 'No'],
    ['Tax Export', 'Sched C', 'No', 'No', 'No', 'Basic'],
    ['2FA Support', 'TOTP', 'No', 'No', 'No', 'No'],
];

foreach ($compRows as $i => $row) {
    $table->addRow();
    $table->addCell(1800)->addText($row[0], $cBold);
    $table->addCell(1500)->addText($row[1], $greenBold);
    $table->addCell(1500)->addText($row[2], $cFont);
    $table->addCell(1500)->addText($row[3], $cFont);
    $table->addCell(1500)->addText($row[4], $cFont);
    $table->addCell(1500)->addText($row[5], $cFont);
}

$section->addTextBreak(1);
$section->addText(
    '* Mint (now Credit Karma) shifted to a credit-focused product in 2024, reducing its expense tracking capabilities.',
    ['size' => 9, 'italic' => true, 'color' => '888888'],
    $body
);

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  TECHNICAL SUMMARY                                                 ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('Technical Architecture Summary', 2);

$table = $section->addTable('dataTable');
$table->addRow();
$table->addCell(3000)->addText('Layer', $hFont);
$table->addCell(3500)->addText('Technology', $hFont);
$table->addCell(3500)->addText('Version', $hFont);

$techRows = [
    ['Backend Framework', 'Laravel', '12.51.0'],
    ['Language', 'PHP', '8.3.29'],
    ['Database', 'PostgreSQL', '15+'],
    ['Cache / Queue', 'Redis', '7+'],
    ['Frontend Framework', 'React', '19.2.4'],
    ['Frontend Bridge', 'Inertia.js', '2.0.19'],
    ['Type System', 'TypeScript', 'Latest'],
    ['CSS Framework', 'Tailwind CSS', '4.1.18'],
    ['AI Engine', 'Anthropic Claude API', 'Sonnet 4'],
    ['Bank Integration', 'Plaid API', 'Sandbox'],
    ['Authentication', 'Laravel Sanctum + Fortify', '4.3.1'],
    ['Social OAuth', 'Laravel Socialite', '5.24.2'],
    ['Testing', 'Pest PHP', '3.8.5'],
    ['Code Style', 'Laravel Pint', '1.27.1'],
    ['PDF Parsing', 'spatie/pdf-to-text', 'Latest'],
    ['Excel Export', 'PhpSpreadsheet', 'Latest'],
    ['PDF Export', 'DomPDF', 'Latest'],
];

foreach ($techRows as $row) {
    $table->addRow();
    $table->addCell(3000)->addText($row[0], $cFont);
    $table->addCell(3500)->addText($row[1], $cBold);
    $table->addCell(3500)->addText($row[2], $cFont);
}

$section->addTitle('Test Coverage', 3);
$section->addText(
    'SpendifiAI has 142 automated tests with 524 assertions covering: authentication (registration, login, 2FA, OAuth, password reset), Plaid integration (link, sync, webhooks), transactions (categorization, filtering), subscriptions (detection, responses), savings (recommendations, tracking, goals), statement uploads (parse, import, history), dashboard (all financial widgets), and AI questions (answer, bulk, chat).',
    null, $body
);

$section->addTitle('Application Scale', 3);

$table = $section->addTable('dataTable');
$table->addRow();
$table->addCell(4000)->addText('Metric', $hFont);
$table->addCell(6000)->addText('Count', $hFont);

$scaleRows = [
    ['Eloquent Models', '18'],
    ['API Controllers', '12'],
    ['Auth Controllers', '5'],
    ['Service Classes', '10'],
    ['Frontend Pages', '16 (8 app + 8 marketing/legal)'],
    ['Frontend Components', '40+'],
    ['Database Migrations', '11'],
    ['Expense Categories', '56 (12 with IRS mapping)'],
    ['Automated Tests', '142 tests, 524 assertions'],
    ['Known Subscriptions', '49 pre-populated merchants'],
    ['SEO Blog Articles', '109 content pages'],
];

foreach ($scaleRows as $row) {
    $table->addRow();
    $table->addCell(4000)->addText($row[0], $cFont);
    $table->addCell(6000)->addText($row[1], $cBold);
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  CLOSING                                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝

$section = $phpWord->addSection();
$section->addTitle('Summary', 2);

$section->addText(
    'SpendifiAI represents a significant opportunity in the personal finance management space. By combining AI-powered intelligence with bank-grade security and a completely free pricing model, the platform is positioned to capture users who are currently underserved by expensive, feature-limited alternatives.',
    null, $body
);

$section->addText('The platform\'s key differentiators are:', null, $body);

$items = [
    'True AI Intelligence - Not rules-based, not keyword matching. Claude AI understands context, learns from corrections, and improves with every interaction.',
    'Dual Data Ingestion - Both Plaid connectivity and statement upload ensure universal access regardless of bank support.',
    'Immediate Financial Value - Subscription detection and savings recommendations deliver measurable savings from day one.',
    'Tax Season Ready - Automatic IRS Schedule C mapping eliminates hours of manual tax preparation for freelancers and small business owners.',
    'Production Quality - 142 automated tests, comprehensive security, and modern architecture ensure reliability and maintainability.',
    'Zero Cost Barrier - Free forever with no premium tiers removes all friction from user acquisition.',
];
foreach ($items as $b) {
    $section->addListItem($b, 0, null, null, $body);
}

$section->addTextBreak(2);

$section->addText(
    'For questions or a live demonstration, please contact the SpendifiAI team.',
    ['italic' => true, 'color' => '888888'],
    $centered
);

// ── Save using IOFactory ────────────────────────────────────────────────
$outputPath = __DIR__.'/../public/SpendifiAI-Product-Overview.docx';

$writer = IOFactory::createWriter($phpWord, 'Word2007');
$writer->save($outputPath);

echo "Document saved to: $outputPath\n";
echo 'File size: '.number_format(filesize($outputPath))." bytes\n";
