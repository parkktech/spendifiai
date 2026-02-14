<?php

// RTF Generator for LedgerIQ Product Overview
// RTF is universally compatible with Word, Google Docs, LibreOffice, etc.

$rtf = '';

// ── RTF Header ──────────────────────────────────────────────────────────
$rtf .= '{\rtf1\ansi\ansicpg1252\deff0';
$rtf .= '{\fonttbl{\f0\fswiss\fcharset0 Calibri;}{\f1\fswiss\fcharset0 Arial;}}';
$rtf .= '{\colortbl;';
$rtf .= '\red26\green86\blue219;';    // 1 - blue accent
$rtf .= '\red51\green51\blue51;';     // 2 - dark gray
$rtf .= '\red85\green85\blue85;';     // 3 - medium gray
$rtf .= '\red136\green136\blue136;';  // 4 - light gray
$rtf .= '\red22\green163\blue74;';    // 5 - green
$rtf .= '\red255\green255\blue255;';  // 6 - white
$rtf .= '\red204\green204\blue204;';  // 7 - border gray
$rtf .= '\red240\green240\blue240;';  // 8 - light bg
$rtf .= '\red170\green170\blue170;';  // 9 - lighter gray
$rtf .= '}';
$rtf .= '\widowctrl\ftnbj\aenddoc\formshade';
$rtf .= '\viewkind1\viewscale100';
$rtf .= '\pgwsxn12240\pghsxn15840\margl1440\margr1440\margt1440\margb1440'; // Letter, 1" margins

// Helper functions
function h1($text) {
    return '\pard\qc\sb0\sa300{\f0\b\fs52\cf1 ' . esc($text) . '}\par' . "\n";
}
function h2($text) {
    return '\pard\keepn\sb480\sa200{\f0\b\fs36\cf1 ' . esc($text) . '}\par' . "\n";
}
function h3($text) {
    return '\pard\keepn\sb360\sa120{\f0\b\fs28\cf2 ' . esc($text) . '}\par' . "\n";
}
function h4($text) {
    return '\pard\keepn\sb240\sa80{\f0\b\fs24\cf3 ' . esc($text) . '}\par' . "\n";
}
function p($text) {
    return '\pard\sb0\sa180\sl360\slmult1{\f0\fs22 ' . esc($text) . '}\par' . "\n";
}
function pcenter($text, $size = 22, $color = 0) {
    $cf = $color > 0 ? '\cf' . $color : '';
    return '\pard\qc\sb0\sa120{\f0\fs' . $size . $cf . ' ' . esc($text) . '}\par' . "\n";
}
function bullet($text) {
    return '\pard\li720\fi-360\sb0\sa100\sl320\slmult1{\f0\fs22 \u8226  ' . esc($text) . '}\par' . "\n";
}
function linebreak() {
    return '\pard\sb0\sa0\par' . "\n";
}
function pagebreak() {
    return '\pard\page' . "\n";
}
function esc($text) {
    // Escape RTF special characters
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('{', '\\{', $text);
    $text = str_replace('}', '\\}', $text);
    // Handle smart quotes and special chars
    $text = str_replace("\xe2\x80\x93", '\\endash ', $text); // en dash
    $text = str_replace("\xe2\x80\x94", '\\emdash ', $text); // em dash
    $text = str_replace("\xe2\x80\x99", "\\'92", $text); // right single quote
    $text = str_replace("\xe2\x80\x9c", "\\'93", $text); // left double quote
    $text = str_replace("\xe2\x80\x9d", "\\'94", $text); // right double quote
    return $text;
}

// Table helpers
function tableStart() {
    return '\pard\sb200\sa200' . "\n";
}

function tableRow($cells, $widths, $isHeader = false) {
    $rtf = '\trowd\trqc\trgaph108\trleft0';
    $pos = 0;
    foreach ($widths as $w) {
        $pos += $w;
        if ($isHeader) {
            $rtf .= '\clcbpat1\clbrdrt\brdrs\brdrw10\clbrdrb\brdrs\brdrw10\clbrdrl\brdrs\brdrw10\clbrdrr\brdrs\brdrw10';
        } else {
            $rtf .= '\clbrdrt\brdrs\brdrw5\brdrcf7\clbrdrb\brdrs\brdrw5\brdrcf7\clbrdrl\brdrs\brdrw5\brdrcf7\clbrdrr\brdrs\brdrw5\brdrcf7';
        }
        $rtf .= '\cellx' . $pos;
    }
    $rtf .= "\n";
    foreach ($cells as $i => $cell) {
        if ($isHeader) {
            $rtf .= '\pard\intbl\qc{\f0\b\fs20\cf6 ' . esc($cell) . '}\cell' . "\n";
        } else {
            $bold = ($i === 0) ? '\b' : '';
            $rtf .= '\pard\intbl{\f0' . $bold . '\fs20 ' . esc($cell) . '}\cell' . "\n";
        }
    }
    $rtf .= '\row' . "\n";
    return $rtf;
}

function tableRowGreen($cells, $widths) {
    $rtf = '\trowd\trqc\trgaph108\trleft0';
    $pos = 0;
    foreach ($widths as $w) {
        $pos += $w;
        $rtf .= '\clbrdrt\brdrs\brdrw5\brdrcf7\clbrdrb\brdrs\brdrw5\brdrcf7\clbrdrl\brdrs\brdrw5\brdrcf7\clbrdrr\brdrs\brdrw5\brdrcf7';
        $rtf .= '\cellx' . $pos;
    }
    $rtf .= "\n";
    foreach ($cells as $i => $cell) {
        if ($i === 0) {
            $rtf .= '\pard\intbl{\f0\b\fs20 ' . esc($cell) . '}\cell' . "\n";
        } elseif ($i === 1) {
            $rtf .= '\pard\intbl{\f0\b\fs20\cf5 ' . esc($cell) . '}\cell' . "\n";
        } else {
            $rtf .= '\pard\intbl{\f0\fs20 ' . esc($cell) . '}\cell' . "\n";
        }
    }
    $rtf .= '\row' . "\n";
    return $rtf;
}


// ╔══════════════════════════════════════════════════════════════════════╗
// ║  COVER PAGE                                                        ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= h1('LedgerIQ');
$rtf .= pcenter('AI-Powered Personal Finance Platform', 32, 3);
$rtf .= linebreak();
$rtf .= pcenter('Comprehensive Product Overview', 28, 1);
$rtf .= pcenter('For Prospective Partners & Stakeholders', 24, 4);
$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= pcenter('Prepared: February 2026', 22, 4);
$rtf .= pcenter('Version 1.0 | Confidential', 20, 9);

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  EXECUTIVE SUMMARY                                                 ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('Executive Summary');

$rtf .= p('LedgerIQ is a free, AI-powered personal finance platform that helps individuals, freelancers, and small business owners take complete control of their financial lives. By combining bank-grade security, intelligent automation, and the power of Claude AI, LedgerIQ eliminates the tedious manual work of expense tracking, subscription management, savings optimization, and tax preparation.');

$rtf .= p('Unlike competitors that charge $8-15/month (Mint, YNAB, Copilot), LedgerIQ is 100% free with no premium tiers, no trial periods, and no credit card required. This is not a loss leader - it is a deliberate strategy to capture market share in the rapidly growing personal finance management sector.');

$rtf .= h3('Core Value Propositions');

$rtf .= bullet('Automatic AI Categorization - Claude AI categorizes every transaction with 85%+ accuracy, learns from corrections, and cascades intelligence across merchants');
$rtf .= bullet('Bank Connectivity - Secure Plaid integration (12,000+ institutions, SOC 2 Type II) plus manual statement upload for banks not on Plaid');
$rtf .= bullet('Subscription Detection - Pattern-based detection of all recurring charges, with "stopped billing" alerts for unused services');
$rtf .= bullet('AI Savings Recommendations - Personalized, actionable plans based on 90 days of real spending data, prioritized by ease of implementation');
$rtf .= bullet('Tax Deduction Optimization - Automatic IRS Schedule C mapping with one-click export (Excel, PDF, CSV) and email-to-accountant');
$rtf .= bullet('Comprehensive Dashboard - 8-widget financial command center: budget waterfall, monthly bills, home affordability, where to cut, savings tracking, and more');

$rtf .= h3('Technology Stack');
$rtf .= p('Built on Laravel 12 (PHP 8.3), React 19, TypeScript, Tailwind CSS v4, PostgreSQL, and Redis. AI powered by Anthropic Claude Sonnet 4. Bank integration via Plaid API. 142 automated tests ensure reliability across all features.');

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  BANK CONNECTIVITY                                                 ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('Bank Connectivity & Data Ingestion');

$rtf .= p('LedgerIQ provides two complementary methods for importing financial data, ensuring every user can participate regardless of their bank or technical comfort level.');

$rtf .= h3('Plaid Bank Integration');

$rtf .= p('Through Plaid - the same infrastructure trusted by Venmo, Robinhood, Coinbase, and thousands of financial applications - users can securely connect their bank accounts in under 60 seconds. Their banking credentials never touch LedgerIQ\'s servers.');

$rtf .= h4('How It Works');
$rtf .= bullet('User clicks "Connect Bank" and authenticates directly with their bank through Plaid\'s secure modal');
$rtf .= bullet('Plaid returns an encrypted access token - LedgerIQ stores it with AES-256 encryption at rest');
$rtf .= bullet('Transactions sync automatically in real-time via webhooks (SYNC_UPDATES_AVAILABLE events)');
$rtf .= bullet('Account balances, types, and metadata are imported alongside transactions');
$rtf .= bullet('Users can disconnect at any time with one click - the encrypted token is immediately destroyed');

$rtf .= h4('Security Credentials');
$rtf .= bullet('SOC 2 Type II certified infrastructure');
$rtf .= bullet('End-to-end TLS encryption for all data in transit');
$rtf .= bullet('Plaid access tokens encrypted with AES-256 at rest in PostgreSQL');
$rtf .= bullet('Webhook signature verification prevents tampering');
$rtf .= bullet('Idempotency logging prevents duplicate transaction processing');

$rtf .= h4('Multi-Account Support');
$rtf .= p('Users can link multiple banks and multiple account types (checking, savings, credit card, investment). Each account can be tagged with a purpose - personal, business, mixed, or investment - which becomes the strongest signal for AI categorization accuracy.');

$rtf .= h3('Bank Statement Upload');

$rtf .= p('For users whose banks are not supported by Plaid, or who prefer not to link their accounts electronically, LedgerIQ offers intelligent statement parsing. Users simply upload a PDF or CSV bank statement, and Claude AI extracts every transaction automatically.');

$rtf .= h4('PDF Statement Processing');
$rtf .= bullet('User uploads a PDF bank statement (up to 10 MB)');
$rtf .= bullet('The system extracts raw text using spatie/pdf-to-text (pdftotext binary)');
$rtf .= bullet('Extracted text is sent to Claude AI with context about the bank name and account type');
$rtf .= bullet('AI identifies and extracts each transaction: date, description, amount, deposit/withdrawal');
$rtf .= bullet('Merchant names are cleaned - card numbers, reference IDs, and city/state suffixes are stripped');
$rtf .= bullet('Example: "AMAZON.COM*RT3K2 AMZN.COM/BIL WA" becomes "Amazon"');

$rtf .= h4('CSV Statement Processing');
$rtf .= bullet('User uploads a CSV file from any bank');
$rtf .= bullet('System reads the first 5 rows and sends them to Claude AI for column detection');
$rtf .= bullet('AI identifies which columns contain the date, description, amount (or separate debit/credit), and balance');
$rtf .= bullet('AI determines the date format and number of header rows to skip');
$rtf .= bullet('The entire CSV is then parsed using the detected column mapping');
$rtf .= bullet('This approach works with any CSV format from any institution - no pre-configured templates needed');

$rtf .= h4('Duplicate Detection');
$rtf .= p('Before importing, LedgerIQ automatically checks each parsed transaction against existing records. Matches are identified using amount (within $0.01 tolerance), date (exact match), and merchant name (Levenshtein distance threshold). Flagged duplicates are shown to the user for review before import, preventing double-counting.');

$rtf .= h4('Post-Import Pipeline');
$rtf .= p('After the user reviews and confirms the parsed transactions, they are imported into the same pipeline as Plaid transactions. The AI categorization engine processes them identically - there is no second-class treatment for uploaded data.');

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  AI CATEGORIZATION                                                 ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('AI-Powered Transaction Categorization');

$rtf .= p('At the heart of LedgerIQ is Claude AI\'s ability to understand and categorize financial transactions with human-like accuracy. This is not simple keyword matching - the AI considers context, account purpose, merchant patterns, and user history to make intelligent decisions.');

$rtf .= h3('What the AI Considers');
$rtf .= bullet('Merchant name and normalized merchant identifier');
$rtf .= bullet('Transaction amount and date');
$rtf .= bullet('Description and payment channel (card, ACH, wire, etc.)');
$rtf .= bullet('Plaid\'s baseline automated category (when available)');
$rtf .= bullet('Account purpose: personal, business, mixed, or investment - the single strongest categorization signal');
$rtf .= bullet('Account nickname (e.g., "Business Checking" provides strong context)');
$rtf .= bullet('User\'s employment type (W-2, self-employed, freelancer)');
$rtf .= bullet('Business type and home office status');
$rtf .= bullet('Tax filing status and custom categorization rules');

$rtf .= h3('Confidence-Based Routing');
$rtf .= p('The AI assigns a confidence score (0.0 to 1.0) to every categorization. This score determines the user experience:');

$w3 = [2000, 4200, 9800];
$rtf .= tableStart();
$rtf .= tableRow(['Confidence', 'Action', 'User Experience'], $w3, true);
$rtf .= tableRow(['85%+', 'Auto-categorize', 'Transaction is silently categorized. User sees it fully processed.'], $w3);
$rtf .= tableRow(['60-84%', 'Flag for review', 'Category applied but marked for optional human verification.'], $w3);
$rtf .= tableRow(['40-59%', 'Multiple-choice', 'AI generates 3-4 options and asks the user to choose.'], $w3);
$rtf .= tableRow(['Below 40%', 'Open-ended Q', 'AI asks a free-text question; user can chat for clarification.'], $w3);

$rtf .= h3('Learning from Corrections');
$rtf .= p('When a user corrects a categorization or answers an AI question, the system doesn\'t just update that one transaction:');

$rtf .= bullet('The correction is applied to the target transaction immediately');
$rtf .= bullet('All other transactions from the same merchant are updated to match (unless already user-confirmed)');
$rtf .= bullet('Pending AI questions for the same merchant are automatically resolved');
$rtf .= bullet('Future transactions from that merchant inherit the user\'s preference');
$rtf .= bullet('This "cascade" behavior means answering one question can resolve dozens of transactions simultaneously');

$rtf .= h3('56 Expense Categories');
$rtf .= p('LedgerIQ uses 56 expense categories (46 top-level, 10 subcategories) organized into logical groups: Housing & Utilities, Transportation, Food & Dining, Business Expenses, Personal & Health, Subscriptions & Entertainment, Household & Personal, Education & Wealth, and Transfer/Income categories. Of these, 12 categories have direct IRS Schedule C line mappings for tax export.');

$rtf .= h3('AI Question Types');

$w2 = [2800, 13200];
$rtf .= tableStart();
$rtf .= tableRow(['Question Type', 'Example'], $w2, true);
$rtf .= tableRow(['Category', '"What category best describes this $47.99 charge at COSTCO?"'], $w2);
$rtf .= tableRow(['Business/Personal', '"Is this $129 charge at Staples for business or personal use?"'], $w2);
$rtf .= tableRow(['Split', '"Was this $200 Costco purchase all groceries, or mixed categories?"'], $w2);
$rtf .= tableRow(['Confirm', '"Is this $15.99 charge correctly categorized as Streaming?"'], $w2);

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  SUBSCRIPTION DETECTION                                            ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('Subscription Detection & Management');

$rtf .= p('The average American spends $219/month on subscriptions, with studies showing that most people underestimate their recurring charges by 2-3x. LedgerIQ\'s Subscription Detective scans 6 months of transaction history to surface every recurring charge - especially the ones users have forgotten about.');

$rtf .= h3('Detection Algorithm');
$rtf .= bullet('All transactions from the past 6 months are grouped by normalized merchant name');
$rtf .= bullet('Merchants with 2+ transactions are analyzed for recurrence patterns');
$rtf .= bullet('The system measures intervals between charges and checks amount consistency (within 20% tolerance)');
$rtf .= bullet('Charges are classified by frequency: weekly, monthly, quarterly, or annual');
$rtf .= bullet('A pre-populated registry of 49 known subscription merchants enhances accuracy');

$rtf .= h3('"Stopped Billing" Detection - Finding Unused Subscriptions');
$rtf .= p('This is where LedgerIQ provides immediate, concrete value. The system compares each subscription\'s last charge date against its expected billing cycle:');

$w3b = [2800, 5200, 8000];
$rtf .= tableStart();
$rtf .= tableRow(['Frequency', 'Flagged As Unused After', 'Logic'], $w3b, true);
$rtf .= tableRow(['Weekly', '21+ days without a charge', '3x the weekly interval'], $w3b);
$rtf .= tableRow(['Monthly', '60+ days without a charge', '2x the monthly interval'], $w3b);
$rtf .= tableRow(['Quarterly', '180+ days without a charge', '2x the quarterly interval'], $w3b);
$rtf .= tableRow(['Annual', '400+ days without a charge', 'About 13 months since last charge'], $w3b);

$rtf .= p('When a subscription is flagged as "stopped billing," it receives a prominent red badge on both the Subscriptions page and the Dashboard\'s monthly bills widget. This immediately draws the user\'s attention to services they may be paying for but not using.');

$rtf .= h3('User Response System');
$rtf .= p('For each subscription, users can take one of three actions:');
$rtf .= bullet('Cancel - Mark the subscription for cancellation. LedgerIQ provides direct links to cancellation pages and tracks the projected savings.');
$rtf .= bullet('Reduce - Downgrade to a cheaper plan. The AI suggests alternative tiers and calculates the savings difference.');
$rtf .= bullet('Keep - Acknowledge the subscription and dismiss the alert. This prevents future nagging about services the user values.');

$rtf .= h3('AI-Powered Alternatives');
$rtf .= p('When a user considers canceling or reducing a subscription, LedgerIQ\'s AI can suggest cheaper alternatives. For example, if a user is paying $15.99/month for a streaming service, the AI might suggest a competitor at $7.99/month or a bundle that combines multiple services for less. Alternative suggestions are cached for 7 days to minimize API calls.');

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  AI SAVINGS                                                        ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('AI Savings Recommendations & Goal Tracking');

$rtf .= p('LedgerIQ\'s savings engine goes beyond simple "spend less" advice. It analyzes 90 days of actual spending data - broken down by category, merchant, timing, and pattern - to generate specific, actionable recommendations with real dollar amounts pulled from the user\'s own transactions.');

$rtf .= h3('90-Day Spending Analysis');
$rtf .= p('The AI receives a comprehensive spending profile including:');
$rtf .= bullet('Total spending by category with transaction counts and averages');
$rtf .= bullet('Active and unused subscriptions with monthly costs');
$rtf .= bullet('Daily spending averages and day-of-week patterns');
$rtf .= bullet('Impulse purchase frequency (transactions under $20)');
$rtf .= bullet('Dining out frequency and totals');
$rtf .= bullet('Late-night purchasing patterns (potential impulse indicator)');
$rtf .= bullet('Total transaction volume for context');

$rtf .= h3('Recommendation Quality');
$rtf .= p('Each AI recommendation includes:');

$w2b = [2800, 13200];
$rtf .= tableStart();
$rtf .= tableRow(['Field', 'Description'], $w2b, true);
$rtf .= tableRow(['Title', 'Short, actionable headline (e.g., "Cancel Paramount+ and Crunchyroll")'], $w2b);
$rtf .= tableRow(['Description', 'Specific explanation using REAL merchant names and amounts from user data'], $w2b);
$rtf .= tableRow(['Monthly Savings', 'Estimated monthly dollar savings'], $w2b);
$rtf .= tableRow(['Annual Savings', 'Projected annual impact (monthly x 12)'], $w2b);
$rtf .= tableRow(['Difficulty', 'Easy (no lifestyle change), Medium (behavior change), Hard (sacrifice)'], $w2b);
$rtf .= tableRow(['Impact', 'High ($50+/mo), Medium ($15-50/mo), Low (under $15/mo)'], $w2b);
$rtf .= tableRow(['Action Steps', 'Numbered instructions (e.g., "Go to paramountplus.com/account, click Cancel")'], $w2b);
$rtf .= tableRow(['Related Merchants', 'The specific merchants/services this recommendation affects'], $w2b);

$rtf .= h3('Savings Goal Planning');
$rtf .= p('Users can set a concrete savings target (e.g., "Save $500/month for an emergency fund by December"). The AI then generates a personalized action plan:');
$rtf .= bullet('Calculates the gap between current spending and the savings target');
$rtf .= bullet('Prioritizes easy wins first (unused subscriptions, plan downgrades)');
$rtf .= bullet('Then targets discretionary spending (dining, entertainment, impulse buys)');
$rtf .= bullet('Only suggests cutting essentials as a last resort, with clear warnings');
$rtf .= bullet('Each action includes specific "how-to" steps using real merchants and amounts');
$rtf .= bullet('Actions are priority-ordered (1 = do first) for maximum impact');
$rtf .= bullet('Progress is tracked with a visual progress bar and on-track indicators');

$rtf .= h3('Response & Tracking System');
$rtf .= p('For each recommendation, users can respond with Cancel (they\'ll stop the service), Reduce (they\'ll downgrade), or Keep (they value it). Responses are tracked over time, and the projected savings banner updates in real-time as users commit to changes. Monthly pulse checks compare actual spending against commitments.');

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  TAX OPTIMIZATION                                                  ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('Tax Deduction Optimization & Export');

$rtf .= p('For freelancers, self-employed individuals, and small business owners, tax deduction tracking is one of LedgerIQ\'s most valuable features. The AI automatically identifies tax-deductible expenses and maps them to IRS Schedule C line items, then exports accountant-ready reports in multiple formats.');

$rtf .= h3('Automatic Tax Deductibility Detection');
$rtf .= p('During categorization, Claude AI evaluates each transaction for tax deductibility based on the user\'s employment type and the expense category:');

$rtf .= h4('Self-Employed / Freelancer Deductions');
$rtf .= bullet('Office supplies and equipment');
$rtf .= bullet('Software and SaaS subscriptions used for business');
$rtf .= bullet('Business meals (50% deductible per IRS rules)');
$rtf .= bullet('Home office expenses');
$rtf .= bullet('Professional development and training');
$rtf .= bullet('Business travel (flights, hotels, transportation)');
$rtf .= bullet('Marketing and advertising');
$rtf .= bullet('Health insurance premiums (self-employed deduction)');
$rtf .= bullet('Professional services (legal, accounting)');
$rtf .= bullet('Shipping and postage');

$rtf .= h4('Universal Deductions');
$rtf .= bullet('Charitable donations (Schedule A)');
$rtf .= bullet('Medical expenses above AGI threshold (Schedule A)');
$rtf .= bullet('Mortgage interest (Schedule A)');
$rtf .= bullet('State and local taxes - SALT (Schedule A)');

$rtf .= h3('IRS Schedule C Line Mapping');
$rtf .= p('LedgerIQ maps 12 expense categories directly to IRS Schedule C lines:');

$w3c = [2800, 4000, 9200];
$rtf .= tableStart();
$rtf .= tableRow(['Schedule C Line', 'Description', 'LedgerIQ Categories'], $w3c, true);
$rtf .= tableRow(['Line 8', 'Advertising', 'Marketing & Advertising'], $w3c);
$rtf .= tableRow(['Line 9', 'Car & Truck', 'Gas & Fuel, Auto Maintenance, Transportation'], $w3c);
$rtf .= tableRow(['Line 15', 'Insurance', 'Health Insurance, Home Insurance'], $w3c);
$rtf .= tableRow(['Line 17', 'Legal & Professional', 'Professional Services'], $w3c);
$rtf .= tableRow(['Line 18', 'Office Expense', 'Office Supplies, Software & SaaS, Shipping'], $w3c);
$rtf .= tableRow(['Line 24a', 'Travel', 'Flights, Travel & Hotels'], $w3c);
$rtf .= tableRow(['Line 24b', 'Meals (50%)', 'Business Meals'], $w3c);
$rtf .= tableRow(['Line 25', 'Utilities', 'Phone & Internet, Utilities'], $w3c);
$rtf .= tableRow(['Line 27a', 'Other Expenses', 'Professional Development'], $w3c);
$rtf .= tableRow(['Line 30', 'Home Office', 'Home Office'], $w3c);
$rtf .= tableRow(['Schedule A', 'Charitable', 'Charity & Donations'], $w3c);
$rtf .= tableRow(['Schedule A', 'Medical', 'Medical & Dental'], $w3c);

$rtf .= h3('Export Formats');

$rtf .= h4('Excel Workbook (5 Tabs)');
$rtf .= bullet('Tax Summary - Taxpayer profile, deduction totals, estimated tax savings by bracket, linked accounts');
$rtf .= bullet('Schedule C Mapping - Line-by-line IRS form mapping with amounts and item counts');
$rtf .= bullet('Deductions by Category - Aggregated totals per tax category with transaction counts and date ranges');
$rtf .= bullet('All Deductible Transactions - Full detail: date, merchant, amount, category, tax line, AI confidence, verification status');
$rtf .= bullet('Business Subscriptions - Recurring business expenses with annual projections');

$rtf .= h4('PDF Cover Sheet');
$rtf .= p('Executive summary designed for accountants - key statistics, taxpayer profile, and deduction totals in a clean, professional format.');

$rtf .= h4('CSV Export');
$rtf .= p('Universal flat-file format importable into TurboTax, QuickBooks, and any CPA software. Same columns as the Excel detail tab.');

$rtf .= h4('Email to Accountant');
$rtf .= p('One-click delivery of all three export files directly to the user\'s accountant via email, with a summary in the email body and CC to the user for records.');

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  DASHBOARD                                                         ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('Comprehensive Financial Dashboard');

$rtf .= p('The LedgerIQ dashboard is an 8-widget financial command center that gives users a complete picture of their financial health at a glance. Data is cached for 60 seconds per user and can be filtered by account type (personal/business).');

$rtf .= h3('1. Smart Greeting & Hero Metrics');
$rtf .= p('A dynamic greeting adapts to financial health ("You can save $X/mo" vs. "$X/mo over budget"). Live income/expense indicators with directional arrows, savings rate badge (green if positive, red if deficit), and bank sync status.');

$rtf .= h3('2. Budget Reality Check (Waterfall Analysis)');
$rtf .= p('A visual stacked bar chart breaks down monthly cash flow into Essential Bills (red), Non-Essential Subscriptions (amber), Discretionary Spending (orange), and Monthly Surplus (green). Detailed breakdown rows show exact amounts and percentages. The color-coded verdict tells users whether they can save or are over budget, with the savings rate as a percentage of income.');

$rtf .= h3('3. Your Monthly Bills');
$rtf .= p('All recurring charges consolidated with total monthly cost, annual projection, and essential vs. non-essential split. Each bill shows the merchant, frequency, next expected charge, and a "Stopped Billing" badge for unused subscriptions.');

$rtf .= h3('4. Home Affordability Calculator');
$rtf .= p('Calculates maximum home price based on actual income and debt. Shows estimated monthly mortgage payment, interest rate, current debt-to-income ratio (DTI), and loan term. Color-coded DTI analysis: green (under 28%, excellent), amber (28-36%, moderate), red (over 36%, exceeds lender maximums).');

$rtf .= h3('5. Where to Cut - Action Feed');
$rtf .= p('A three-tab system (Quick Wins, This Week, This Month) surfaces actionable savings. Each card shows title, monthly/annual savings potential, color-coded type icon, expandable action steps, and respond/dismiss buttons. Card types include unused subscriptions, AI savings recommendations, overspending categories, and pending AI questions.');

$rtf .= h3('6. Savings Progress & Goals');
$rtf .= p('6-month savings tracking chart and applied recommendations list alongside savings goal progress bar with motivation text and cumulative vs. target comparison.');

$rtf .= h3('7. Spending Trend');
$rtf .= p('6-month income vs. expense trend chart with category breakdown by month, helping users identify seasonal spending patterns.');

$rtf .= h3('8. Recent Activity');
$rtf .= p('Last 20 transactions with inline category badges and quick link to the full Transactions page.');

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  SECURITY                                                          ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('Security Architecture');

$rtf .= p('LedgerIQ treats security as a foundational requirement, not an afterthought. The platform implements defense-in-depth across every layer of the stack.');

$rtf .= h3('Encryption');
$rtf .= bullet('AES-256 encryption at rest for all sensitive data (Plaid tokens, bank account EINs, email credentials, 2FA secrets, transaction metadata)');
$rtf .= bullet('Laravel model-level encryption casts - sensitive fields are never stored in plaintext');
$rtf .= bullet('TLS/HTTPS for all data in transit');
$rtf .= bullet('HSTS header with 1-year max-age, includeSubDomains, and preload directive');

$rtf .= h3('Authentication & Access Control');
$rtf .= bullet('bcrypt password hashing (Laravel default)');
$rtf .= bullet('Sanctum bearer token authentication for API endpoints');
$rtf .= bullet('Rate limiting on all authentication endpoints');
$rtf .= bullet('Optional TOTP two-factor authentication with encrypted backup recovery codes');
$rtf .= bullet('Google OAuth social login as an alternative');
$rtf .= bullet('Password reset via signed email links');
$rtf .= bullet('Email verification for new accounts');
$rtf .= bullet('Account lockout protection after failed attempts');

$rtf .= h3('Security Headers');
$rtf .= bullet('Content-Security-Policy - Restricts script, style, font, image, and frame sources');
$rtf .= bullet('X-Content-Type-Options: nosniff - Prevents MIME-type sniffing');
$rtf .= bullet('X-Frame-Options: DENY - Prevents clickjacking');
$rtf .= bullet('Referrer-Policy: strict-origin-when-cross-origin');
$rtf .= bullet('Permissions-Policy - Disables camera, microphone, and geolocation access');
$rtf .= bullet('Strict-Transport-Security - Forces HTTPS with preload');

$rtf .= h3('Data Protection');
$rtf .= bullet('Every model with sensitive data uses $hidden arrays to prevent API leakage');
$rtf .= bullet('Plaid access tokens are encrypted and never exposed to the frontend');
$rtf .= bullet('Webhook signature verification prevents request tampering');
$rtf .= bullet('Idempotency logging prevents duplicate webhook processing');
$rtf .= bullet('Users can disconnect banks and delete accounts at any time');

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  ADDITIONAL FEATURES                                               ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('Additional Features');

$rtf .= h3('Email Receipt Parsing (In Development)');
$rtf .= p('Users can connect their Gmail (via OAuth) or any IMAP email account. LedgerIQ scans for order confirmations and receipts, extracts product-level detail using Claude AI, and matches them to bank transactions. This provides itemized purchase tracking - for example, knowing that a $147.32 Amazon charge was specifically for office supplies and a printer cartridge, enabling more accurate tax categorization at the product level.');

$rtf .= h3('AI Question Chat System');
$rtf .= p('When the AI is uncertain about a transaction\'s category, it asks the user. Users interact through a dedicated Questions page where they can answer multiple-choice questions, provide free-text explanations, or engage in a back-and-forth chat with the AI for nuanced situations. Bulk answer mode lets users resolve multiple questions at once.');

$rtf .= h3('Transaction Management');
$rtf .= p('A full-featured transaction table with filtering by date range, category, amount range, merchant, account, and review status. Users can inline-edit categories and mark transactions as deductible. Eight query scopes provide efficient data retrieval across the application.');

$rtf .= h3('Financial Profile & Settings');
$rtf .= p('Users can configure their employment type, business type, home office status, tax filing status, and income details. This profile data enhances AI categorization accuracy and tax deduction detection. Settings also include 2FA management and account deletion.');

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  COMPETITIVE LANDSCAPE                                             ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('Competitive Landscape');

$rtf .= p('LedgerIQ competes in the personal finance management space with a unique combination of AI intelligence and zero-cost pricing:');

$w6 = [2600, 2200, 2200, 2200, 2200, 2200];
$rtf .= tableStart();
$rtf .= tableRow(['Feature', 'LedgerIQ', 'Mint', 'YNAB', 'Copilot', 'Quicken'], $w6, true);
$rtf .= tableRowGreen(['Price', 'FREE', 'Free*', '$14.99/mo', '$10.99/mo', '$5.99/mo'], $w6);
$rtf .= tableRowGreen(['AI Categorize', 'Yes', 'Basic', 'Manual', 'Yes', 'Rules'], $w6);
$rtf .= tableRowGreen(['Bank Sync', 'Plaid', 'Plaid', 'Plaid', 'Plaid', 'Plaid'], $w6);
$rtf .= tableRowGreen(['Statement Upload', 'PDF + CSV', 'No', 'Manual', 'No', 'CSV only'], $w6);
$rtf .= tableRowGreen(['Sub Detection', 'Auto + AI', 'Basic', 'No', 'Auto', 'No'], $w6);
$rtf .= tableRowGreen(['Savings Tips', 'AI-Powered', 'Basic', 'No', 'No', 'No'], $w6);
$rtf .= tableRowGreen(['Tax Export', 'Sched C', 'No', 'No', 'No', 'Basic'], $w6);
$rtf .= tableRowGreen(['2FA Support', 'TOTP', 'No', 'No', 'No', 'No'], $w6);

$rtf .= linebreak();
$rtf .= '\pard\sb0\sa120{\f0\i\fs18\cf4 * Mint (now Credit Karma) shifted to a credit-focused product in 2024, reducing its expense tracking capabilities.}\par' . "\n";

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  TECHNICAL SUMMARY                                                 ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('Technical Architecture Summary');

$w3d = [4200, 5400, 6400];
$rtf .= tableStart();
$rtf .= tableRow(['Layer', 'Technology', 'Version'], $w3d, true);
$rtf .= tableRow(['Backend Framework', 'Laravel', '12.51.0'], $w3d);
$rtf .= tableRow(['Language', 'PHP', '8.3.29'], $w3d);
$rtf .= tableRow(['Database', 'PostgreSQL', '15+'], $w3d);
$rtf .= tableRow(['Cache / Queue', 'Redis', '7+'], $w3d);
$rtf .= tableRow(['Frontend Framework', 'React', '19.2.4'], $w3d);
$rtf .= tableRow(['Frontend Bridge', 'Inertia.js', '2.0.19'], $w3d);
$rtf .= tableRow(['Type System', 'TypeScript', 'Latest'], $w3d);
$rtf .= tableRow(['CSS Framework', 'Tailwind CSS', '4.1.18'], $w3d);
$rtf .= tableRow(['AI Engine', 'Anthropic Claude API', 'Sonnet 4'], $w3d);
$rtf .= tableRow(['Bank Integration', 'Plaid API', 'Sandbox'], $w3d);
$rtf .= tableRow(['Authentication', 'Sanctum + Fortify', '4.3.1'], $w3d);
$rtf .= tableRow(['Social OAuth', 'Laravel Socialite', '5.24.2'], $w3d);
$rtf .= tableRow(['Testing', 'Pest PHP', '3.8.5'], $w3d);
$rtf .= tableRow(['Code Style', 'Laravel Pint', '1.27.1'], $w3d);
$rtf .= tableRow(['PDF Parsing', 'spatie/pdf-to-text', 'Latest'], $w3d);
$rtf .= tableRow(['Excel Export', 'PhpSpreadsheet', 'Latest'], $w3d);
$rtf .= tableRow(['PDF Export', 'DomPDF', 'Latest'], $w3d);

$rtf .= h3('Test Coverage');
$rtf .= p('LedgerIQ has 142 automated tests with 524 assertions covering: authentication (registration, login, 2FA, OAuth, password reset), Plaid integration (link, sync, webhooks), transactions (categorization, filtering), subscriptions (detection, responses), savings (recommendations, tracking, goals), statement uploads (parse, import, history), dashboard (all financial widgets), and AI questions (answer, bulk, chat).');

$rtf .= h3('Application Scale');

$w2c = [5600, 10400];
$rtf .= tableStart();
$rtf .= tableRow(['Metric', 'Count'], $w2c, true);
$rtf .= tableRow(['Eloquent Models', '18'], $w2c);
$rtf .= tableRow(['API Controllers', '12'], $w2c);
$rtf .= tableRow(['Auth Controllers', '5'], $w2c);
$rtf .= tableRow(['Service Classes', '10'], $w2c);
$rtf .= tableRow(['Frontend Pages', '16 (8 app + 8 marketing/legal)'], $w2c);
$rtf .= tableRow(['Frontend Components', '40+'], $w2c);
$rtf .= tableRow(['Database Migrations', '11'], $w2c);
$rtf .= tableRow(['Expense Categories', '56 (12 with IRS mapping)'], $w2c);
$rtf .= tableRow(['Automated Tests', '142 tests, 524 assertions'], $w2c);
$rtf .= tableRow(['Known Subscriptions', '49 pre-populated merchants'], $w2c);
$rtf .= tableRow(['SEO Blog Articles', '109 content pages'], $w2c);

$rtf .= pagebreak();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  CLOSING                                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝

$rtf .= h2('Summary');

$rtf .= p('LedgerIQ represents a significant opportunity in the personal finance management space. By combining AI-powered intelligence with bank-grade security and a completely free pricing model, the platform is positioned to capture users who are currently underserved by expensive, feature-limited alternatives.');

$rtf .= p('The platform\'s key differentiators are:');

$rtf .= bullet('True AI Intelligence - Not rules-based, not keyword matching. Claude AI understands context, learns from corrections, and improves with every interaction.');
$rtf .= bullet('Dual Data Ingestion - Both Plaid connectivity and statement upload ensure universal access regardless of bank support.');
$rtf .= bullet('Immediate Financial Value - Subscription detection and savings recommendations deliver measurable savings from day one.');
$rtf .= bullet('Tax Season Ready - Automatic IRS Schedule C mapping eliminates hours of manual tax preparation for freelancers and small business owners.');
$rtf .= bullet('Production Quality - 142 automated tests, comprehensive security, and modern architecture ensure reliability and maintainability.');
$rtf .= bullet('Zero Cost Barrier - Free forever with no premium tiers removes all friction from user acquisition.');

$rtf .= linebreak();
$rtf .= linebreak();
$rtf .= pcenter('For questions or a live demonstration, please contact the LedgerIQ team.', 22, 4);

// Close RTF
$rtf .= '}';

// ── Save ────────────────────────────────────────────────────────────────
$outputPath = __DIR__ . '/../public/LedgerIQ-Product-Overview.rtf';
file_put_contents($outputPath, $rtf);

echo "RTF saved to: $outputPath\n";
echo "File size: " . number_format(filesize($outputPath)) . " bytes\n";
