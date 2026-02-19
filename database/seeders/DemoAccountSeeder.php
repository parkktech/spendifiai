<?php

namespace Database\Seeders;

use App\Models\AIQuestion;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BudgetGoal;
use App\Models\EmailConnection;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ParsedEmail;
use App\Models\SavingsPlanAction;
use App\Models\SavingsRecommendation;
use App\Models\SavingsTarget;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserFinancialProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        // ── Create or update demo user ──
        $user = User::updateOrCreate(
            ['email' => 'demo@spendifiai.com'],
            [
                'name' => 'Alex Johnson',
                'password' => Hash::make('Demo1234!'),
                'email_verified_at' => now(),
            ]
        );

        // ── Clean up existing demo data (safe to re-run) ──
        $this->cleanDemoData($user);

        // ── Financial Profile ──
        UserFinancialProfile::create([
            'user_id' => $user->id,
            'employment_type' => 'self_employed',
            'business_type' => 'sole_proprietor',
            'has_home_office' => true,
            'housing_status' => 'renter',
            'tax_filing_status' => 'single',
            'monthly_income' => '6200',
            'monthly_savings_goal' => 500.00,
        ]);

        // ── Bank Connections ──
        $personalConn = BankConnection::create([
            'user_id' => $user->id,
            'plaid_item_id' => 'demo-item-personal-001',
            'plaid_access_token' => 'demo-access-token-personal-001',
            'institution_name' => 'Chase',
            'institution_id' => 'ins_3',
            'status' => 'active',
            'last_synced_at' => now()->subHours(6),
        ]);

        $businessConn = BankConnection::create([
            'user_id' => $user->id,
            'plaid_item_id' => 'demo-item-business-001',
            'plaid_access_token' => 'demo-access-token-business-001',
            'institution_name' => 'American Express',
            'institution_id' => 'ins_10',
            'status' => 'active',
            'last_synced_at' => now()->subHours(6),
        ]);

        // ── Bank Accounts ──
        $checking = BankAccount::create([
            'user_id' => $user->id,
            'bank_connection_id' => $personalConn->id,
            'plaid_account_id' => 'demo-acct-checking-001',
            'name' => 'Chase Total Checking',
            'official_name' => 'Chase Total Checking',
            'type' => 'depository',
            'subtype' => 'checking',
            'mask' => '4829',
            'purpose' => 'personal',
            'include_in_spending' => true,
            'include_in_tax_tracking' => true,
            'current_balance' => 8450.00,
            'available_balance' => 8320.00,
            'is_active' => true,
        ]);

        $businessCard = BankAccount::create([
            'user_id' => $user->id,
            'bank_connection_id' => $businessConn->id,
            'plaid_account_id' => 'demo-acct-business-001',
            'name' => 'Amex Business Gold',
            'official_name' => 'American Express Business Gold Card',
            'type' => 'credit',
            'subtype' => 'credit card',
            'mask' => '1007',
            'purpose' => 'business',
            'include_in_spending' => true,
            'include_in_tax_tracking' => true,
            'current_balance' => -2340.00,
            'available_balance' => 7660.00,
            'is_active' => true,
        ]);

        // ── Transactions (6 months of realistic data) ──
        $transactions = $this->createTransactions($user, $checking, $businessCard);

        // ── Subscriptions ──
        $this->createSubscriptions($user);

        // ── Savings Recommendations ──
        $this->createSavingsRecommendations($user);

        // ── Savings Target + Plan Actions ──
        $this->createSavingsTarget($user);

        // ── AI Questions (linked to specific low-confidence transactions) ──
        $this->createAIQuestions($user, $transactions);

        // ── Email Connection + Orders (for tax page) ──
        $this->createEmailAndOrders($user, $transactions);

        // ── Budget Goals ──
        $this->createBudgetGoals($user);

        $this->command->info('Demo account created: demo@spendifiai.com / Demo1234!');
    }

    protected function cleanDemoData(User $user): void
    {
        // Delete in dependency order
        OrderItem::where('user_id', $user->id)->delete();
        Order::where('user_id', $user->id)->delete();
        ParsedEmail::where('user_id', $user->id)->delete();
        AIQuestion::where('user_id', $user->id)->delete();
        SavingsPlanAction::where('user_id', $user->id)->delete();
        SavingsTarget::where('user_id', $user->id)->delete();
        SavingsRecommendation::where('user_id', $user->id)->delete();
        Subscription::where('user_id', $user->id)->delete();
        BudgetGoal::where('user_id', $user->id)->delete();
        Transaction::where('user_id', $user->id)->delete();
        BankAccount::where('user_id', $user->id)->delete();
        BankConnection::where('user_id', $user->id)->delete();
        EmailConnection::where('user_id', $user->id)->delete();
        UserFinancialProfile::where('user_id', $user->id)->delete();
    }

    protected function createTransactions(User $user, BankAccount $checking, BankAccount $businessCard): array
    {
        $txns = [];
        $txnId = 1;

        for ($m = 5; $m >= 0; $m--) {
            $month = now()->subMonths($m);

            // ── INCOME (negative amounts) ──

            // Primary salary — 1st of month
            $txns[] = Transaction::create([
                'user_id' => $user->id,
                'bank_account_id' => $checking->id,
                'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                'account_purpose' => 'personal',
                'merchant_name' => 'ACME CORP PAYROLL',
                'merchant_normalized' => 'acme corp',
                'description' => 'Direct Deposit - Payroll',
                'amount' => -5200.00,
                'transaction_date' => $month->copy()->startOfMonth(),
                'authorized_date' => $month->copy()->startOfMonth(),
                'payment_channel' => 'other',
                'plaid_category' => 'INCOME',
                'plaid_detailed_category' => 'INCOME_WAGES',
                'plaid_metadata' => [],
                'ai_category' => 'Income',
                'ai_confidence' => 0.98,
                'expense_type' => 'personal',
                'tax_deductible' => false,
                'review_status' => 'auto_categorized',
                'is_reconciled' => false,
            ]);

            // Freelance income — mid-month (variable)
            $freelanceAmount = -1 * $this->rand(800, 1200);
            $txns[] = Transaction::create([
                'user_id' => $user->id,
                'bank_account_id' => $checking->id,
                'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                'account_purpose' => 'personal',
                'merchant_name' => 'STRIPE TRANSFER',
                'merchant_normalized' => 'stripe',
                'description' => 'Freelance Payment',
                'amount' => $freelanceAmount,
                'transaction_date' => $month->copy()->day(rand(15, 20)),
                'authorized_date' => $month->copy()->day(rand(15, 20)),
                'payment_channel' => 'online',
                'plaid_category' => 'INCOME',
                'plaid_detailed_category' => 'INCOME_OTHER',
                'plaid_metadata' => [],
                'ai_category' => 'Freelance Income',
                'ai_confidence' => 0.92,
                'expense_type' => 'business',
                'tax_deductible' => false,
                'review_status' => 'auto_categorized',
                'is_reconciled' => false,
            ]);

            // ── ESSENTIAL EXPENSES ──

            // Rent — 1st of month (housing detection needs largest on 1st-5th)
            $txns[] = Transaction::create([
                'user_id' => $user->id,
                'bank_account_id' => $checking->id,
                'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                'account_purpose' => 'personal',
                'merchant_name' => 'PARKVIEW APARTMENTS',
                'merchant_normalized' => 'parkview apartments',
                'description' => 'Monthly Rent',
                'amount' => 1850.00,
                'transaction_date' => $month->copy()->day(1),
                'authorized_date' => $month->copy()->day(1),
                'payment_channel' => 'other',
                'plaid_category' => 'RENT_AND_UTILITIES',
                'plaid_detailed_category' => 'RENT_AND_UTILITIES_RENT',
                'plaid_metadata' => [],
                'ai_category' => 'Housing & Rent',
                'ai_confidence' => 0.97,
                'expense_type' => 'personal',
                'tax_deductible' => false,
                'review_status' => 'auto_categorized',
                'is_reconciled' => false,
            ]);

            // Utilities
            $txns[] = Transaction::create([
                'user_id' => $user->id,
                'bank_account_id' => $checking->id,
                'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                'account_purpose' => 'personal',
                'merchant_name' => 'CITY POWER & LIGHT',
                'merchant_normalized' => 'city power & light',
                'description' => 'Electric Bill',
                'amount' => $this->rand(120, 180),
                'transaction_date' => $month->copy()->day(rand(8, 12)),
                'authorized_date' => $month->copy()->day(rand(8, 12)),
                'payment_channel' => 'online',
                'plaid_category' => 'RENT_AND_UTILITIES',
                'plaid_detailed_category' => 'RENT_AND_UTILITIES_GAS_AND_ELECTRICITY',
                'plaid_metadata' => [],
                'ai_category' => 'Utilities',
                'ai_confidence' => 0.95,
                'expense_type' => 'personal',
                'tax_deductible' => false,
                'review_status' => 'auto_categorized',
                'is_reconciled' => false,
            ]);

            // Phone
            $txns[] = Transaction::create([
                'user_id' => $user->id,
                'bank_account_id' => $checking->id,
                'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                'account_purpose' => 'personal',
                'merchant_name' => 'T-MOBILE',
                'merchant_normalized' => 't-mobile',
                'description' => 'Wireless Service',
                'amount' => 85.00,
                'transaction_date' => $month->copy()->day(15),
                'authorized_date' => $month->copy()->day(15),
                'payment_channel' => 'online',
                'plaid_category' => 'RENT_AND_UTILITIES',
                'plaid_detailed_category' => 'RENT_AND_UTILITIES_TELEPHONE',
                'plaid_metadata' => [],
                'ai_category' => 'Phone & Internet',
                'ai_confidence' => 0.96,
                'expense_type' => 'personal',
                'tax_deductible' => false,
                'review_status' => 'auto_categorized',
                'is_reconciled' => false,
            ]);

            // Insurance
            $txns[] = Transaction::create([
                'user_id' => $user->id,
                'bank_account_id' => $checking->id,
                'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                'account_purpose' => 'personal',
                'merchant_name' => 'GEICO AUTO INSURANCE',
                'merchant_normalized' => 'geico',
                'description' => 'Auto Insurance Premium',
                'amount' => 145.00,
                'transaction_date' => $month->copy()->day(5),
                'authorized_date' => $month->copy()->day(5),
                'payment_channel' => 'online',
                'plaid_category' => 'LOAN_PAYMENTS',
                'plaid_detailed_category' => 'LOAN_PAYMENTS_INSURANCE',
                'plaid_metadata' => [],
                'ai_category' => 'Insurance',
                'ai_confidence' => 0.94,
                'expense_type' => 'personal',
                'tax_deductible' => false,
                'review_status' => 'auto_categorized',
                'is_reconciled' => false,
            ]);

            // Groceries (3-4 trips per month)
            $groceryStores = [
                ['WHOLE FOODS MARKET', 'whole foods', 80, 130],
                ['TRADER JOES', 'trader joes', 45, 85],
                ['COSTCO WHSE', 'costco', 120, 220],
            ];

            foreach ($groceryStores as $idx => [$name, $norm, $min, $max]) {
                $txns[] = Transaction::create([
                    'user_id' => $user->id,
                    'bank_account_id' => $checking->id,
                    'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                    'account_purpose' => 'personal',
                    'merchant_name' => $name,
                    'merchant_normalized' => $norm,
                    'description' => 'Groceries',
                    'amount' => $this->rand($min, $max),
                    'transaction_date' => $month->copy()->day(rand(3 + ($idx * 8), 8 + ($idx * 8))),
                    'authorized_date' => $month->copy()->day(rand(3 + ($idx * 8), 8 + ($idx * 8))),
                    'payment_channel' => 'in_store',
                    'plaid_category' => 'FOOD_AND_DRINK',
                    'plaid_detailed_category' => 'FOOD_AND_DRINK_GROCERIES',
                    'plaid_metadata' => [],
                    'ai_category' => 'Food & Groceries',
                    'ai_confidence' => 0.96,
                    'expense_type' => 'personal',
                    'tax_deductible' => false,
                    'review_status' => 'auto_categorized',
                    'is_reconciled' => false,
                ]);
            }

            // Gas (2x per month)
            for ($g = 0; $g < 2; $g++) {
                $txns[] = Transaction::create([
                    'user_id' => $user->id,
                    'bank_account_id' => $checking->id,
                    'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                    'account_purpose' => 'personal',
                    'merchant_name' => $g === 0 ? 'SHELL OIL' : 'CHEVRON',
                    'merchant_normalized' => $g === 0 ? 'shell' : 'chevron',
                    'description' => 'Fuel',
                    'amount' => $this->rand(45, 68),
                    'transaction_date' => $month->copy()->day(rand(5 + ($g * 12), 15 + ($g * 12))),
                    'authorized_date' => $month->copy()->day(rand(5 + ($g * 12), 15 + ($g * 12))),
                    'payment_channel' => 'in_store',
                    'plaid_category' => 'TRANSPORTATION',
                    'plaid_detailed_category' => 'TRANSPORTATION_GAS',
                    'plaid_metadata' => [],
                    'ai_category' => 'Gas & Auto',
                    'ai_confidence' => 0.97,
                    'expense_type' => 'personal',
                    'tax_deductible' => false,
                    'review_status' => 'auto_categorized',
                    'is_reconciled' => false,
                ]);
            }

            // ── SUBSCRIPTION CHARGES (on personal account) ──
            $subCharges = [
                ['NETFLIX.COM', 'netflix', 15.99, 3],
                ['SPOTIFY', 'spotify', 10.99, 7],
                ['PLANET FITNESS', 'planet fitness', 24.99, 1],
                ['HULU', 'hulu', 17.99, 10],
                ['XFINITY INTERNET', 'xfinity', 79.99, 12],
                ['APPLE.COM/BILL', 'apple', 2.99, 14],
            ];

            foreach ($subCharges as [$name, $norm, $amount, $day]) {
                $txns[] = Transaction::create([
                    'user_id' => $user->id,
                    'bank_account_id' => $checking->id,
                    'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                    'account_purpose' => 'personal',
                    'merchant_name' => $name,
                    'merchant_normalized' => $norm,
                    'description' => 'Subscription',
                    'amount' => $amount,
                    'transaction_date' => $month->copy()->day($day),
                    'authorized_date' => $month->copy()->day($day),
                    'payment_channel' => 'online',
                    'plaid_category' => 'RENT_AND_UTILITIES',
                    'plaid_detailed_category' => 'RENT_AND_UTILITIES_OTHER',
                    'plaid_metadata' => [],
                    'ai_category' => $name === 'XFINITY INTERNET' ? 'Phone & Internet' : 'Entertainment & Streaming',
                    'ai_confidence' => 0.95,
                    'expense_type' => 'personal',
                    'tax_deductible' => false,
                    'review_status' => 'auto_categorized',
                    'is_subscription' => true,
                    'is_reconciled' => false,
                ]);
            }

            // Audible — only charge for older months (unused detection)
            if ($m >= 3) {
                $txns[] = Transaction::create([
                    'user_id' => $user->id,
                    'bank_account_id' => $checking->id,
                    'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                    'account_purpose' => 'personal',
                    'merchant_name' => 'AUDIBLE',
                    'merchant_normalized' => 'audible',
                    'description' => 'Audible Premium Plus',
                    'amount' => 14.99,
                    'transaction_date' => $month->copy()->day(18),
                    'authorized_date' => $month->copy()->day(18),
                    'payment_channel' => 'online',
                    'plaid_category' => 'RENT_AND_UTILITIES',
                    'plaid_detailed_category' => 'RENT_AND_UTILITIES_OTHER',
                    'plaid_metadata' => [],
                    'ai_category' => 'Entertainment & Streaming',
                    'ai_confidence' => 0.93,
                    'expense_type' => 'personal',
                    'tax_deductible' => false,
                    'review_status' => 'auto_categorized',
                    'is_subscription' => true,
                    'is_reconciled' => false,
                ]);
            }

            // ── DISCRETIONARY SPENDING ──

            // Dining (2-3 per month)
            $restaurants = [
                ['CHIPOTLE', 'chipotle', 12, 18],
                ['STARBUCKS', 'starbucks', 5, 8],
                ['THE CHEESECAKE FACTORY', 'cheesecake factory', 45, 75],
            ];

            foreach ($restaurants as $idx => [$name, $norm, $min, $max]) {
                $txns[] = Transaction::create([
                    'user_id' => $user->id,
                    'bank_account_id' => $checking->id,
                    'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                    'account_purpose' => 'personal',
                    'merchant_name' => $name,
                    'merchant_normalized' => $norm,
                    'description' => 'Dining',
                    'amount' => $this->rand($min, $max),
                    'transaction_date' => $month->copy()->day(rand(6 + ($idx * 7), 12 + ($idx * 7))),
                    'authorized_date' => $month->copy()->day(rand(6 + ($idx * 7), 12 + ($idx * 7))),
                    'payment_channel' => 'in_store',
                    'plaid_category' => 'FOOD_AND_DRINK',
                    'plaid_detailed_category' => 'FOOD_AND_DRINK_RESTAURANT',
                    'plaid_metadata' => [],
                    'ai_category' => 'Restaurant & Dining',
                    'ai_confidence' => 0.93,
                    'expense_type' => 'personal',
                    'tax_deductible' => false,
                    'review_status' => 'auto_categorized',
                    'is_reconciled' => false,
                ]);
            }

            // Shopping (1-2 per month)
            $shops = [
                ['AMAZON.COM', 'amazon', 25, 120],
                ['TARGET', 'target', 30, 90],
            ];

            foreach ($shops as $idx => [$name, $norm, $min, $max]) {
                $txns[] = Transaction::create([
                    'user_id' => $user->id,
                    'bank_account_id' => $checking->id,
                    'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                    'account_purpose' => 'personal',
                    'merchant_name' => $name,
                    'merchant_normalized' => $norm,
                    'description' => 'Online Purchase',
                    'amount' => $this->rand($min, $max),
                    'transaction_date' => $month->copy()->day(rand(10 + ($idx * 10), 20 + ($idx * 5))),
                    'authorized_date' => $month->copy()->day(rand(10 + ($idx * 10), 20 + ($idx * 5))),
                    'payment_channel' => 'online',
                    'plaid_category' => 'GENERAL_MERCHANDISE',
                    'plaid_detailed_category' => 'GENERAL_MERCHANDISE_ONLINE_MARKETPLACES',
                    'plaid_metadata' => [],
                    'ai_category' => 'Shopping & Retail',
                    'ai_confidence' => 0.88,
                    'expense_type' => 'personal',
                    'tax_deductible' => false,
                    'review_status' => 'auto_categorized',
                    'is_reconciled' => $name === 'AMAZON.COM' && $m <= 1,
                ]);
            }

            // ── BUSINESS EXPENSES (on business card) ──

            // Adobe Creative Cloud
            $txns[] = Transaction::create([
                'user_id' => $user->id,
                'bank_account_id' => $businessCard->id,
                'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                'account_purpose' => 'business',
                'merchant_name' => 'ADOBE *CREATIVE CLD',
                'merchant_normalized' => 'adobe',
                'description' => 'Adobe Creative Cloud',
                'amount' => 54.99,
                'transaction_date' => $month->copy()->day(8),
                'authorized_date' => $month->copy()->day(8),
                'payment_channel' => 'online',
                'plaid_category' => 'RENT_AND_UTILITIES',
                'plaid_detailed_category' => 'RENT_AND_UTILITIES_OTHER',
                'plaid_metadata' => [],
                'ai_category' => 'Software & SaaS',
                'ai_confidence' => 0.94,
                'expense_type' => 'business',
                'tax_deductible' => true,
                'tax_category' => 'Other Expenses',
                'review_status' => 'auto_categorized',
                'is_subscription' => true,
                'is_reconciled' => false,
            ]);

            // Office supplies
            if ($m % 2 === 0) {
                $txns[] = Transaction::create([
                    'user_id' => $user->id,
                    'bank_account_id' => $businessCard->id,
                    'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                    'account_purpose' => 'business',
                    'merchant_name' => 'STAPLES',
                    'merchant_normalized' => 'staples',
                    'description' => 'Office Supplies',
                    'amount' => $this->rand(35, 95),
                    'transaction_date' => $month->copy()->day(rand(10, 18)),
                    'authorized_date' => $month->copy()->day(rand(10, 18)),
                    'payment_channel' => 'in_store',
                    'plaid_category' => 'GENERAL_MERCHANDISE',
                    'plaid_detailed_category' => 'GENERAL_MERCHANDISE_OFFICE_SUPPLIES',
                    'plaid_metadata' => [],
                    'ai_category' => 'Office Supplies',
                    'ai_confidence' => 0.91,
                    'expense_type' => 'business',
                    'tax_deductible' => true,
                    'tax_category' => 'Office Expenses',
                    'review_status' => 'auto_categorized',
                    'is_reconciled' => false,
                ]);
            }

            // Business meals
            $txns[] = Transaction::create([
                'user_id' => $user->id,
                'bank_account_id' => $businessCard->id,
                'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                'account_purpose' => 'business',
                'merchant_name' => 'PANERA BREAD',
                'merchant_normalized' => 'panera bread',
                'description' => 'Client lunch meeting',
                'amount' => $this->rand(25, 55),
                'transaction_date' => $month->copy()->day(rand(12, 22)),
                'authorized_date' => $month->copy()->day(rand(12, 22)),
                'payment_channel' => 'in_store',
                'plaid_category' => 'FOOD_AND_DRINK',
                'plaid_detailed_category' => 'FOOD_AND_DRINK_RESTAURANT',
                'plaid_metadata' => [],
                'ai_category' => 'Business Meals',
                'ai_confidence' => 0.72,
                'expense_type' => 'business',
                'tax_deductible' => true,
                'tax_category' => 'Meals',
                'review_status' => $m <= 1 ? 'needs_review' : 'auto_categorized',
                'is_reconciled' => false,
            ]);

            // Professional development (books/courses — every other month)
            if ($m % 2 === 1) {
                $txns[] = Transaction::create([
                    'user_id' => $user->id,
                    'bank_account_id' => $businessCard->id,
                    'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                    'account_purpose' => 'business',
                    'merchant_name' => 'UDEMY.COM',
                    'merchant_normalized' => 'udemy',
                    'description' => 'Online Course',
                    'amount' => $this->rand(12, 30),
                    'transaction_date' => $month->copy()->day(rand(5, 25)),
                    'authorized_date' => $month->copy()->day(rand(5, 25)),
                    'payment_channel' => 'online',
                    'plaid_category' => 'GENERAL_SERVICES',
                    'plaid_detailed_category' => 'GENERAL_SERVICES_OTHER',
                    'plaid_metadata' => [],
                    'ai_category' => 'Education & Training',
                    'ai_confidence' => 0.85,
                    'expense_type' => 'business',
                    'tax_deductible' => true,
                    'tax_category' => 'Other Expenses',
                    'review_status' => 'auto_categorized',
                    'is_reconciled' => false,
                ]);
            }

            // Zoom subscription (business)
            $txns[] = Transaction::create([
                'user_id' => $user->id,
                'bank_account_id' => $businessCard->id,
                'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                'account_purpose' => 'business',
                'merchant_name' => 'ZOOM.US',
                'merchant_normalized' => 'zoom',
                'description' => 'Zoom Pro Monthly',
                'amount' => 13.33,
                'transaction_date' => $month->copy()->day(22),
                'authorized_date' => $month->copy()->day(22),
                'payment_channel' => 'online',
                'plaid_category' => 'GENERAL_SERVICES',
                'plaid_detailed_category' => 'GENERAL_SERVICES_OTHER',
                'plaid_metadata' => [],
                'ai_category' => 'Software & SaaS',
                'ai_confidence' => 0.93,
                'expense_type' => 'business',
                'tax_deductible' => true,
                'tax_category' => 'Other Expenses',
                'review_status' => 'auto_categorized',
                'is_subscription' => true,
                'is_reconciled' => false,
            ]);

            // Home office supply (quarterly)
            if ($m % 3 === 0) {
                $txns[] = Transaction::create([
                    'user_id' => $user->id,
                    'bank_account_id' => $businessCard->id,
                    'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                    'account_purpose' => 'business',
                    'merchant_name' => 'AMZN MKTP US',
                    'merchant_normalized' => 'amazon',
                    'description' => 'Monitor Stand, USB Hub',
                    'amount' => $this->rand(65, 180),
                    'transaction_date' => $month->copy()->day(rand(8, 20)),
                    'authorized_date' => $month->copy()->day(rand(8, 20)),
                    'payment_channel' => 'online',
                    'plaid_category' => 'GENERAL_MERCHANDISE',
                    'plaid_detailed_category' => 'GENERAL_MERCHANDISE_ONLINE_MARKETPLACES',
                    'plaid_metadata' => [],
                    'ai_category' => 'Office Equipment',
                    'ai_confidence' => 0.68,
                    'expense_type' => 'business',
                    'tax_deductible' => true,
                    'tax_category' => 'Office Expenses',
                    'review_status' => 'needs_review',
                    'is_reconciled' => false,
                ]);
            }

            // Entertainment (1 per month)
            $entertainment = [
                ['AMC THEATRES', 'amc theatres', 15, 30],
                ['TICKETMASTER', 'ticketmaster', 50, 150],
                ['STEAM GAMES', 'steam', 10, 60],
            ];
            $ent = $entertainment[$m % 3];
            $txns[] = Transaction::create([
                'user_id' => $user->id,
                'bank_account_id' => $checking->id,
                'plaid_transaction_id' => 'demo-txn-'.($txnId++),
                'account_purpose' => 'personal',
                'merchant_name' => $ent[0],
                'merchant_normalized' => $ent[1],
                'description' => 'Entertainment',
                'amount' => $this->rand($ent[2], $ent[3]),
                'transaction_date' => $month->copy()->day(rand(16, 26)),
                'authorized_date' => $month->copy()->day(rand(16, 26)),
                'payment_channel' => $ent[0] === 'STEAM GAMES' ? 'online' : 'in_store',
                'plaid_category' => 'ENTERTAINMENT',
                'plaid_detailed_category' => 'ENTERTAINMENT_OTHER',
                'plaid_metadata' => [],
                'ai_category' => 'Entertainment',
                'ai_confidence' => 0.90,
                'expense_type' => 'personal',
                'tax_deductible' => false,
                'review_status' => 'auto_categorized',
                'is_reconciled' => false,
            ]);
        }

        return $txns;
    }

    protected function createSubscriptions(User $user): void
    {
        $subs = [
            ['Netflix', 'netflix', 15.99, 'monthly', false, 'active', 18],
            ['Spotify Premium', 'spotify', 10.99, 'monthly', false, 'active', 24],
            ['Planet Fitness', 'planet fitness', 24.99, 'monthly', false, 'active', 12],
            ['Hulu', 'hulu', 17.99, 'monthly', false, 'active', 8],
            ['Xfinity Internet', 'xfinity', 79.99, 'monthly', true, 'active', 36],
            ['iCloud+', 'apple', 2.99, 'monthly', false, 'active', 30],
            ['Adobe Creative Cloud', 'adobe', 54.99, 'monthly', false, 'active', 14],
            ['Audible Premium Plus', 'audible', 14.99, 'monthly', false, 'unused', 10],
        ];

        foreach ($subs as [$name, $norm, $amount, $freq, $essential, $status, $months]) {
            $lastCharge = $status === 'unused'
                ? now()->subMonths(3)->day(18)
                : now()->subDays(rand(1, 28));

            Subscription::create([
                'user_id' => $user->id,
                'merchant_name' => $name,
                'merchant_normalized' => $norm,
                'amount' => $amount,
                'frequency' => $freq,
                'status' => $status,
                'is_essential' => $essential,
                'months_active' => $months,
                'last_charge_date' => $lastCharge,
                'next_expected_date' => $lastCharge->copy()->addMonth(),
                'annual_cost' => $amount * 12,
            ]);
        }
    }

    protected function createSavingsRecommendations(User $user): void
    {
        SavingsRecommendation::create([
            'user_id' => $user->id,
            'title' => 'Cut dining out spending by 40%',
            'description' => "You're spending an average of $380/month on restaurants and takeout. By cooking at home more and limiting dining out to weekends, you could save $150/month.",
            'monthly_savings' => 150.00,
            'annual_savings' => 1800.00,
            'difficulty' => 'medium',
            'impact' => 'high',
            'category' => 'Restaurant & Dining',
            'status' => 'active',
            'action_steps' => [
                'Meal prep on Sundays for weekday lunches',
                'Limit restaurant visits to 2x per week',
                'Use cashback dining apps like Seated or Resy',
                'Try batch cooking — saves time and money',
            ],
            'related_merchants' => ['Chipotle', 'Starbucks', 'The Cheesecake Factory', 'Panera Bread'],
            'generated_at' => now()->subDays(5),
        ]);

        SavingsRecommendation::create([
            'user_id' => $user->id,
            'title' => 'Cancel unused Audible subscription',
            'description' => "Your Audible Premium Plus subscription ($14.99/mo) hasn't been used in over 3 months. Credits are piling up unused.",
            'monthly_savings' => 14.99,
            'annual_savings' => 179.88,
            'difficulty' => 'easy',
            'impact' => 'low',
            'category' => 'Entertainment & Streaming',
            'status' => 'active',
            'action_steps' => [
                'Use remaining credits before cancelling',
                'Consider switching to Audible Plus ($7.95/mo) if you still want access',
                'Try your local library\'s free Libby app as an alternative',
            ],
            'related_merchants' => ['Audible'],
            'generated_at' => now()->subDays(5),
        ]);

        SavingsRecommendation::create([
            'user_id' => $user->id,
            'title' => 'Negotiate internet bill or switch providers',
            'description' => "You're paying $79.99/month for Xfinity Internet. Many providers offer promotional rates of $40-50/month for similar speeds.",
            'monthly_savings' => 30.00,
            'annual_savings' => 360.00,
            'difficulty' => 'medium',
            'impact' => 'medium',
            'category' => 'Phone & Internet',
            'status' => 'active',
            'action_steps' => [
                'Call Xfinity retention department and ask for a loyalty discount',
                'Check T-Mobile 5G Home Internet ($50/mo) availability at your address',
                'Compare rates on BroadbandNow.com for your zip code',
            ],
            'related_merchants' => ['Xfinity'],
            'generated_at' => now()->subDays(5),
        ]);

        SavingsRecommendation::create([
            'user_id' => $user->id,
            'title' => 'Reduce grocery spending with strategic shopping',
            'description' => "Your grocery spending averages $420/month across Whole Foods, Trader Joe's, and Costco. Shifting more shopping to Trader Joe's and Costco could save ~$80/month.",
            'monthly_savings' => 80.00,
            'annual_savings' => 960.00,
            'difficulty' => 'easy',
            'impact' => 'medium',
            'category' => 'Food & Groceries',
            'status' => 'active',
            'action_steps' => [
                'Do bulk staples at Costco (rice, pasta, frozen proteins)',
                'Shift weekly shopping from Whole Foods to Trader Joe\'s',
                'Use the Flipp app to compare weekly sale prices',
                'Buy store brands — often identical quality at 30% less',
            ],
            'related_merchants' => ['Whole Foods', 'Trader Joes', 'Costco'],
            'generated_at' => now()->subDays(5),
        ]);
    }

    protected function createSavingsTarget(User $user): void
    {
        $target = SavingsTarget::create([
            'user_id' => $user->id,
            'monthly_target' => 500.00,
            'motivation' => 'Building an emergency fund and saving for a down payment on a house',
            'target_start_date' => now()->subMonths(2)->startOfMonth(),
            'target_end_date' => now()->addMonths(10)->endOfMonth(),
            'goal_total' => 6000.00,
            'is_active' => true,
        ]);

        SavingsPlanAction::create([
            'user_id' => $user->id,
            'savings_target_id' => $target->id,
            'title' => 'Reduce dining out to 2x per week',
            'description' => 'Limit restaurant and takeout meals to twice per week maximum',
            'how_to' => 'Meal prep on Sundays, bring lunch to work, save dining for social occasions',
            'monthly_savings' => 150.00,
            'current_spending' => 380.00,
            'recommended_spending' => 230.00,
            'category' => 'Restaurant & Dining',
            'difficulty' => 'medium',
            'impact' => 'high',
            'priority' => 1,
            'is_essential_cut' => false,
            'related_merchants' => ['Chipotle', 'Starbucks', 'The Cheesecake Factory'],
            'status' => 'pending',
        ]);

        SavingsPlanAction::create([
            'user_id' => $user->id,
            'savings_target_id' => $target->id,
            'title' => 'Cancel Audible subscription',
            'description' => 'Cancel unused Audible Premium Plus and switch to free library app',
            'how_to' => 'Use remaining credits, then cancel via Amazon account settings. Install Libby app.',
            'monthly_savings' => 14.99,
            'current_spending' => 14.99,
            'recommended_spending' => 0.00,
            'category' => 'Entertainment & Streaming',
            'difficulty' => 'easy',
            'impact' => 'low',
            'priority' => 2,
            'is_essential_cut' => false,
            'related_merchants' => ['Audible'],
            'status' => 'pending',
        ]);

        SavingsPlanAction::create([
            'user_id' => $user->id,
            'savings_target_id' => $target->id,
            'title' => 'Negotiate Xfinity internet bill',
            'description' => 'Call retention to get promotional rate or switch to T-Mobile Home Internet',
            'how_to' => 'Call 1-800-XFINITY, say "cancel service" to reach retention. Ask for loyalty discount or match competitor pricing.',
            'monthly_savings' => 30.00,
            'current_spending' => 79.99,
            'recommended_spending' => 49.99,
            'category' => 'Phone & Internet',
            'difficulty' => 'medium',
            'impact' => 'medium',
            'priority' => 3,
            'is_essential_cut' => false,
            'related_merchants' => ['Xfinity'],
            'status' => 'pending',
        ]);
    }

    protected function createAIQuestions(User $user, array $transactions): void
    {
        // Find some low-confidence transactions for questions
        $needsReview = collect($transactions)->filter(fn ($t) => $t->review_status->value === 'needs_review')->values();

        // Create pending questions for needs_review transactions
        foreach ($needsReview->take(4) as $txn) {
            AIQuestion::create([
                'user_id' => $user->id,
                'transaction_id' => $txn->id,
                'question' => $txn->expense_type->value === 'business'
                    ? "Is this {$txn->merchant_normalized} purchase a business expense?"
                    : "What category best fits this {$txn->merchant_normalized} transaction?",
                'options' => $txn->expense_type->value === 'business'
                    ? ['Business Expense', 'Personal Expense', 'Mixed Use']
                    : ['Office Supplies', 'Equipment', 'Personal Shopping', 'Business Supplies'],
                'question_type' => $txn->expense_type->value === 'business' ? 'business_personal' : 'category',
                'ai_confidence' => $txn->ai_confidence,
                'ai_best_guess' => $txn->ai_category,
                'status' => 'pending',
            ]);
        }

        // Create 2 already-answered questions
        $answered = collect($transactions)
            ->filter(fn ($t) => $t->review_status->value === 'auto_categorized' && $t->ai_confidence < 0.95)
            ->take(2);

        foreach ($answered as $txn) {
            AIQuestion::create([
                'user_id' => $user->id,
                'transaction_id' => $txn->id,
                'question' => "Is this {$txn->merchant_normalized} transaction categorized correctly as {$txn->ai_category}?",
                'options' => ['Yes, correct', 'No, it should be different'],
                'question_type' => 'confirm',
                'ai_confidence' => $txn->ai_confidence,
                'ai_best_guess' => $txn->ai_category,
                'user_answer' => 'Yes, correct',
                'status' => 'answered',
                'answered_at' => now()->subDays(rand(1, 5)),
            ]);
        }
    }

    protected function createEmailAndOrders(User $user, array $transactions): void
    {
        // Email connection
        $emailConn = EmailConnection::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'connection_type' => 'oauth',
            'email_address' => 'demo@spendifiai.com',
            'access_token' => 'demo-gmail-access-token',
            'refresh_token' => 'demo-gmail-refresh-token',
            'token_expires_at' => now()->addHours(1),
            'status' => 'active',
            'last_synced_at' => now()->subHours(6),
        ]);

        $emailIdx = 1;
        $txnCollection = collect($transactions);
        $reconciledTxnIds = [];

        // ── Purchase orders matched to transactions ──
        $orderData = [
            [
                'merchant' => 'Amazon', 'match_merchant' => 'amazon', 'months_ago' => 0,
                'items' => [
                    ['USB-C Hub Adapter', 'Anker 7-in-1 hub for home office', 34.99, true, 'Office Equipment'],
                    ['Wireless Mouse', 'Logitech MX Master 3S', 29.99, true, 'Office Equipment'],
                    ['Phone Case', 'Spigen Ultra Hybrid for iPhone 15', 15.99, false, 'Shopping & Retail'],
                ],
            ],
            [
                'merchant' => 'Amazon', 'match_merchant' => 'amazon', 'months_ago' => 1,
                'items' => [
                    ['Mechanical Keyboard', 'Keychron K2 wireless 75%', 89.99, true, 'Office Equipment'],
                    ['Desk Mat', 'Leather desk pad 36x17', 24.99, true, 'Office Supplies'],
                    ['Webcam', 'Logitech C920 HD Pro', 69.99, true, 'Office Equipment'],
                ],
            ],
            [
                'merchant' => 'Amazon', 'match_merchant' => 'amazon', 'months_ago' => 2,
                'items' => [
                    ['Ring Light', '10" LED ring light with tripod', 29.99, true, 'Office Equipment'],
                    ['Blue Light Glasses', 'Computer glasses 2-pack', 18.99, false, 'Shopping & Retail'],
                ],
            ],
            [
                'merchant' => 'Amazon', 'match_merchant' => 'amazon', 'months_ago' => 3,
                'items' => [
                    ['Standing Desk Converter', 'FlexiSpot 35" desktop riser', 199.99, true, 'Office Equipment'],
                    ['Cable Management Kit', 'Under desk cable tray + clips', 22.99, true, 'Office Supplies'],
                ],
            ],
            [
                'merchant' => 'Target', 'match_merchant' => 'target', 'months_ago' => 0,
                'items' => [
                    ['Household Cleaning Bundle', 'All-purpose cleaner, sponges, paper towels', 32.47, false, 'Household'],
                    ['Throw Blanket', 'Casaluna weighted knit throw', 45.00, false, 'Shopping & Retail'],
                ],
            ],
            [
                'merchant' => 'Target', 'match_merchant' => 'target', 'months_ago' => 2,
                'items' => [
                    ['Laundry Detergent', 'Tide Pods 42ct', 13.99, false, 'Household'],
                    ['Bath Towel Set', '6-piece towel set gray', 29.99, false, 'Shopping & Retail'],
                    ['Air Freshener', 'Febreze plug-in starter kit', 8.49, false, 'Household'],
                ],
            ],
            [
                'merchant' => 'Home Depot', 'match_merchant' => 'home depot', 'months_ago' => 1,
                'items' => [
                    ['Desk Lamp', 'LED architect desk lamp', 45.99, true, 'Office Equipment'],
                    ['Surge Protector', 'Belkin 12-outlet power strip', 19.99, true, 'Office Supplies'],
                    ['Extension Cord', '25ft indoor/outdoor', 14.99, false, 'Household'],
                ],
            ],
            [
                'merchant' => 'Best Buy', 'match_merchant' => 'best buy', 'months_ago' => 1,
                'items' => [
                    ['External SSD 1TB', 'Samsung T7 portable drive', 89.99, true, 'Office Equipment'],
                    ['HDMI Cable 6ft', 'Insignia high speed 4K cable', 12.99, true, 'Office Supplies'],
                ],
            ],
            [
                'merchant' => 'Best Buy', 'match_merchant' => 'best buy', 'months_ago' => 3,
                'items' => [
                    ['Webcam Cover', 'Privacy slide cover 3-pack', 7.99, true, 'Office Supplies'],
                    ['USB Flash Drive 128GB', 'SanDisk Ultra Flair', 14.99, true, 'Office Supplies'],
                    ['Laptop Stand', 'Rain Design mStand', 49.99, true, 'Office Equipment'],
                ],
            ],
            [
                'merchant' => 'Costco', 'match_merchant' => 'costco', 'months_ago' => 0,
                'items' => [
                    ['Printer Paper (5 reams)', 'HP Premium 8.5x11 2500 sheets', 32.99, true, 'Office Supplies'],
                    ['K-Cup Variety Pack', 'Kirkland Signature 80-count', 38.99, false, 'Food & Groceries'],
                    ['Batteries', 'Kirkland AA 48-pack', 15.99, false, 'Household'],
                ],
            ],
            [
                'merchant' => 'Costco', 'match_merchant' => 'costco', 'months_ago' => 2,
                'items' => [
                    ['Bottled Water', 'Kirkland Spring 40-pack', 4.49, false, 'Food & Groceries'],
                    ['Paper Towels', 'Kirkland Signature 12-roll', 19.99, false, 'Household'],
                    ['Trash Bags', 'Kirkland 50-gallon 50ct', 14.99, false, 'Household'],
                    ['Snack Bars', 'KIND variety 40-count', 22.99, false, 'Food & Groceries'],
                ],
            ],
            [
                'merchant' => 'Staples', 'match_merchant' => 'staples', 'months_ago' => 0,
                'items' => [
                    ['Ink Cartridges', 'HP 63XL Black + Color combo', 54.99, true, 'Office Supplies'],
                    ['Sticky Notes', 'Post-it 12-pack assorted', 12.49, true, 'Office Supplies'],
                    ['File Folders', 'Pendaflex letter 100-count', 18.99, true, 'Office Supplies'],
                ],
            ],
            [
                'merchant' => 'Staples', 'match_merchant' => 'staples', 'months_ago' => 4,
                'items' => [
                    ['Toner Cartridge', 'HP LaserJet 58A Black', 79.99, true, 'Office Supplies'],
                    ['Shipping Labels', 'Avery 2x4 white 250ct', 12.99, true, 'Office Supplies'],
                ],
            ],
            [
                'merchant' => 'Walmart', 'match_merchant' => 'walmart', 'months_ago' => 1,
                'items' => [
                    ['Desk Organizer', 'Madesmart 6-compartment', 14.97, true, 'Office Supplies'],
                    ['Whiteboard', 'Quartet 36x24 magnetic', 34.97, true, 'Office Equipment'],
                ],
            ],
            [
                'merchant' => 'Apple', 'match_merchant' => 'apple', 'months_ago' => 3,
                'items' => [
                    ['AirPods Pro 2', 'With MagSafe charging case', 249.00, false, 'Electronics'],
                ],
            ],
        ];

        foreach ($orderData as $data) {
            $orderDate = now()->subMonths($data['months_ago'])->day(rand(5, 22));
            $total = collect($data['items'])->sum(fn ($i) => $i[2] * ($i[5] ?? 1));
            $tax = round($total * 0.0825, 2);
            $shipping = $total > 35 ? 0 : 5.99;

            $parsedEmail = ParsedEmail::create([
                'user_id' => $user->id,
                'email_connection_id' => $emailConn->id,
                'email_message_id' => 'demo-email-'.($emailIdx++),
                'is_purchase' => true,
                'parse_status' => 'parsed',
                'email_date' => $orderDate,
                'search_source' => $emailIdx <= 10 ? 'keyword' : 'transaction_guided',
            ]);

            // Match to a transaction by merchant and ensure we don't double-match
            $matchTxn = $txnCollection->first(function ($t) use ($data, $reconciledTxnIds) {
                return str_contains(strtolower($t->merchant_normalized ?? ''), $data['match_merchant'])
                    && ! in_array($t->id, $reconciledTxnIds)
                    && (float) $t->amount > 0;
            });

            if ($matchTxn) {
                $reconciledTxnIds[] = $matchTxn->id;
                $matchTxn->update(['is_reconciled' => true, 'matched_order_id' => null]); // will set order id below
            }

            $order = Order::create([
                'user_id' => $user->id,
                'parsed_email_id' => $parsedEmail->id,
                'merchant' => $data['merchant'],
                'merchant_normalized' => strtolower($data['merchant']),
                'order_number' => strtoupper(substr(md5('demo-order-'.$emailIdx), 0, 12)),
                'order_date' => $orderDate,
                'subtotal' => $total,
                'tax' => $tax,
                'shipping' => $shipping,
                'total' => $total + $tax + $shipping,
                'currency' => 'USD',
                'matched_transaction_id' => $matchTxn?->id,
                'is_reconciled' => (bool) $matchTxn,
            ]);

            // Link the transaction back to the order
            if ($matchTxn) {
                $matchTxn->update(['matched_order_id' => $order->id]);
            }

            foreach ($data['items'] as $item) {
                $qty = $item[5] ?? 1;
                OrderItem::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'product_name' => $item[0],
                    'product_description' => $item[1],
                    'quantity' => $qty,
                    'unit_price' => $item[2],
                    'total_price' => $item[2] * $qty,
                    'ai_category' => $item[4],
                    'tax_deductible' => $item[3],
                    'tax_deductible_confidence' => $item[3] ? round($this->rand(80, 95) / 100, 2) : round($this->rand(5, 20) / 100, 2),
                    'expense_type' => $item[3] ? 'business' : 'personal',
                ]);
            }
        }

        // ── Non-purchase parsed emails (skipped by AI — makes sync look realistic) ──
        $skippedSubjects = [
            'Your Amazon.com order has shipped',
            'Delivery notification: Your package is out for delivery',
            'Your Netflix payment was successful',
            'Spotify: Your monthly receipt',
            'Your Xfinity bill is ready',
            'T-Mobile: Your bill is ready to view',
            'GEICO: Auto ID card enclosed',
            'Adobe: Your Creative Cloud subscription renewed',
            'Costco: Your membership renewal reminder',
            'Thank you for contacting Target Support',
            'Your Hulu subscription was renewed',
            'Planet Fitness: Gym visit confirmation',
            'Uber Eats: Your order is confirmed',
            'DoorDash: Your delivery receipt',
            'Your Chipotle Rewards update',
            'Starbucks: Stars earned on your last visit',
        ];

        foreach ($skippedSubjects as $idx => $subject) {
            ParsedEmail::create([
                'user_id' => $user->id,
                'email_connection_id' => $emailConn->id,
                'email_message_id' => 'demo-email-skip-'.($idx + 1),
                'is_purchase' => false,
                'parse_status' => 'skipped',
                'email_date' => now()->subDays(rand(1, 120)),
                'search_source' => 'keyword',
            ]);
        }

        // ── A couple of subscription renewal emails parsed as purchases ──
        $subEmails = [
            ['Netflix', 'netflix', 15.99, 'Entertainment & Streaming'],
            ['Spotify', 'spotify', 10.99, 'Entertainment & Streaming'],
            ['Adobe Creative Cloud', 'adobe', 54.99, 'Software & SaaS'],
            ['Zoom', 'zoom', 13.33, 'Software & SaaS'],
        ];

        foreach ($subEmails as $idx => [$merchant, $norm, $amount, $category]) {
            $emailDate = now()->subDays(rand(3, 30));

            $parsedEmail = ParsedEmail::create([
                'user_id' => $user->id,
                'email_connection_id' => $emailConn->id,
                'email_message_id' => 'demo-email-sub-'.($idx + 1),
                'is_purchase' => true,
                'is_subscription' => true,
                'parse_status' => 'parsed',
                'email_date' => $emailDate,
                'search_source' => 'keyword',
            ]);

            $matchTxn = $txnCollection->first(function ($t) use ($norm, $reconciledTxnIds) {
                return str_contains(strtolower($t->merchant_normalized ?? ''), $norm)
                    && ! in_array($t->id, $reconciledTxnIds)
                    && $t->is_subscription;
            });

            if ($matchTxn) {
                $reconciledTxnIds[] = $matchTxn->id;
                $matchTxn->update(['is_reconciled' => true]);
            }

            $order = Order::create([
                'user_id' => $user->id,
                'parsed_email_id' => $parsedEmail->id,
                'merchant' => $merchant,
                'merchant_normalized' => $norm,
                'order_number' => null,
                'order_date' => $emailDate,
                'subtotal' => $amount,
                'tax' => 0,
                'shipping' => 0,
                'total' => $amount,
                'currency' => 'USD',
                'matched_transaction_id' => $matchTxn?->id,
                'is_reconciled' => (bool) $matchTxn,
            ]);

            if ($matchTxn) {
                $matchTxn->update(['matched_order_id' => $order->id]);
            }

            OrderItem::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'product_name' => $merchant.' Monthly Subscription',
                'product_description' => 'Monthly subscription renewal',
                'quantity' => 1,
                'unit_price' => $amount,
                'total_price' => $amount,
                'ai_category' => $category,
                'tax_deductible' => str_contains($category, 'SaaS'),
                'tax_deductible_confidence' => str_contains($category, 'SaaS') ? 0.88 : 0.10,
                'expense_type' => str_contains($category, 'SaaS') ? 'business' : 'personal',
            ]);
        }
    }

    protected function createBudgetGoals(User $user): void
    {
        $goals = [
            ['restaurant-dining', 300.00],
            ['shopping-retail', 200.00],
            ['entertainment', 100.00],
        ];

        foreach ($goals as [$slug, $limit]) {
            BudgetGoal::create([
                'user_id' => $user->id,
                'category_slug' => $slug,
                'monthly_limit' => $limit,
            ]);
        }
    }

    /**
     * Generate a random dollar amount between min and max with cents.
     */
    protected function rand(int $min, int $max): float
    {
        return rand($min * 100, $max * 100) / 100;
    }
}
