<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            // ── Housing ──
            ['name' => 'Housing & Rent', 'slug' => 'housing-rent', 'icon' => 'home', 'color' => '#3b82f6', 'is_essential' => true, 'keywords' => '["rent","mortgage","hoa","apartment","lease"]'],
            ['name' => 'Mortgage', 'slug' => 'mortgage', 'icon' => 'home', 'color' => '#3b82f6', 'parent_slug' => 'housing-rent', 'is_essential' => true, 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule A - Mortgage Interest'],

            // ── Food ──
            ['name' => 'Food & Groceries', 'slug' => 'food-groceries', 'icon' => 'shopping-bag', 'color' => '#10b981', 'is_essential' => true, 'keywords' => '["grocery","whole foods","trader joe","kroger","safeway","walmart grocery","costco","aldi"]'],
            ['name' => 'Restaurant & Dining', 'slug' => 'restaurant-dining', 'icon' => 'utensils', 'color' => '#f59e0b', 'keywords' => '["restaurant","doordash","uber eats","grubhub","dine","cafe","bistro"]'],
            ['name' => 'Coffee & Drinks', 'slug' => 'coffee-drinks', 'icon' => 'coffee', 'color' => '#92400e', 'parent_slug' => 'restaurant-dining', 'keywords' => '["starbucks","coffee","dunkin","boba","tea"]'],
            ['name' => 'Business Meals', 'slug' => 'business-meals', 'icon' => 'briefcase', 'color' => '#f59e0b', 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule C Line 24b - Meals (50%)'],

            // ── Transportation ──
            ['name' => 'Transportation', 'slug' => 'transportation', 'icon' => 'car', 'color' => '#8b5cf6', 'is_essential' => true],
            ['name' => 'Gas & Fuel', 'slug' => 'gas-fuel', 'icon' => 'fuel', 'color' => '#a855f7', 'parent_slug' => 'transportation', 'is_essential' => true, 'keywords' => '["shell","chevron","exxon","bp","circle k","gas","fuel"]'],
            ['name' => 'Car Payment', 'slug' => 'car-payment', 'icon' => 'car', 'color' => '#8b5cf6', 'parent_slug' => 'transportation', 'is_essential' => true],
            ['name' => 'Car Insurance', 'slug' => 'car-insurance', 'icon' => 'shield', 'color' => '#06b6d4', 'parent_slug' => 'transportation', 'is_essential' => true, 'keywords' => '["geico","state farm","progressive","allstate","usaa"]'],
            ['name' => 'Auto Maintenance', 'slug' => 'auto-maintenance', 'icon' => 'wrench', 'color' => '#8b5cf6', 'parent_slug' => 'transportation', 'keywords' => '["jiffy lube","autozone","tire","mechanic","oil change"]'],
            ['name' => 'Public Transit', 'slug' => 'public-transit', 'icon' => 'train', 'color' => '#8b5cf6', 'parent_slug' => 'transportation'],
            ['name' => 'Rideshare', 'slug' => 'rideshare', 'icon' => 'car', 'color' => '#8b5cf6', 'parent_slug' => 'transportation', 'keywords' => '["uber","lyft"]'],
            ['name' => 'Parking', 'slug' => 'parking', 'icon' => 'car', 'color' => '#8b5cf6', 'parent_slug' => 'transportation'],

            // ── Bills & Utilities ──
            ['name' => 'Utilities', 'slug' => 'utilities', 'icon' => 'zap', 'color' => '#10b981', 'is_essential' => true, 'keywords' => '["electric","water","gas","power","aps","srp","pg&e"]'],
            ['name' => 'Phone & Internet', 'slug' => 'phone-internet', 'icon' => 'phone', 'color' => '#6366f1', 'is_essential' => true, 'keywords' => '["at&t","t-mobile","verizon","xfinity","spectrum","comcast","cox"]'],
            ['name' => 'Trash & Recycling', 'slug' => 'trash-recycling', 'icon' => 'trash', 'color' => '#78716c', 'is_essential' => true, 'keywords' => '["republic services","waste management","trash"]'],

            // ── Insurance ──
            ['name' => 'Health Insurance', 'slug' => 'health-insurance', 'icon' => 'heart', 'color' => '#ef4444', 'is_essential' => true, 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule C Line 15 (self-employed) or Schedule A'],
            ['name' => 'Home Insurance', 'slug' => 'home-insurance', 'icon' => 'shield', 'color' => '#06b6d4', 'is_essential' => true],
            ['name' => 'Life Insurance', 'slug' => 'life-insurance', 'icon' => 'shield', 'color' => '#06b6d4', 'is_essential' => true],

            // ── Subscriptions & Software ──
            ['name' => 'Subscriptions & Streaming', 'slug' => 'subscriptions-streaming', 'icon' => 'tv', 'color' => '#ef4444', 'keywords' => '["netflix","hulu","disney","hbo","paramount","spotify","apple tv","peacock"]'],
            ['name' => 'Software & SaaS', 'slug' => 'software-saas', 'icon' => 'code', 'color' => '#3b82f6', 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule C Line 18 - Office Expense', 'keywords' => '["adobe","microsoft","openai","anthropic","github","aws"]'],

            // ── Health & Wellness ──
            ['name' => 'Medical & Dental', 'slug' => 'medical-dental', 'icon' => 'heart', 'color' => '#14b8a6', 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule A - Medical Expenses'],
            ['name' => 'Pharmacy', 'slug' => 'pharmacy', 'icon' => 'pill', 'color' => '#14b8a6', 'keywords' => '["cvs","walgreens","rite aid","pharmacy"]'],
            ['name' => 'Fitness & Gym', 'slug' => 'fitness-gym', 'icon' => 'dumbbell', 'color' => '#14b8a6', 'keywords' => '["planet fitness","la fitness","gym","crossfit","orangetheory"]'],
            ['name' => 'Personal Care', 'slug' => 'personal-care', 'icon' => 'heart', 'color' => '#ec4899'],

            // ── Shopping ──
            ['name' => 'Shopping (General)', 'slug' => 'shopping-general', 'icon' => 'shopping-bag', 'color' => '#ec4899', 'keywords' => '["amazon","target","walmart","costco","best buy"]'],
            ['name' => 'Clothing & Apparel', 'slug' => 'clothing', 'icon' => 'shirt', 'color' => '#ec4899'],
            ['name' => 'Electronics', 'slug' => 'electronics', 'icon' => 'monitor', 'color' => '#3b82f6'],
            ['name' => 'Home Improvement', 'slug' => 'home-improvement', 'icon' => 'hammer', 'color' => '#f97316', 'keywords' => '["home depot","lowes","ace hardware"]'],

            // ── Business Expenses (Tax Deductible) ──
            ['name' => 'Office Supplies', 'slug' => 'office-supplies', 'icon' => 'pen', 'color' => '#6366f1', 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule C Line 18'],
            ['name' => 'Home Office', 'slug' => 'home-office', 'icon' => 'home', 'color' => '#6366f1', 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule C Line 30'],
            ['name' => 'Professional Development', 'slug' => 'professional-development', 'icon' => 'book', 'color' => '#6366f1', 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule C Line 27a'],
            ['name' => 'Marketing & Advertising', 'slug' => 'marketing-advertising', 'icon' => 'megaphone', 'color' => '#6366f1', 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule C Line 8'],
            ['name' => 'Professional Services', 'slug' => 'professional-services', 'icon' => 'briefcase', 'color' => '#6366f1', 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule C Line 17'],
            ['name' => 'Shipping & Postage', 'slug' => 'shipping-postage', 'icon' => 'package', 'color' => '#6366f1', 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule C Line 18'],

            // ── Entertainment & Lifestyle ──
            ['name' => 'Entertainment', 'slug' => 'entertainment', 'icon' => 'film', 'color' => '#f97316'],
            ['name' => 'Gaming', 'slug' => 'gaming', 'icon' => 'gamepad', 'color' => '#f97316'],
            ['name' => 'Travel & Hotels', 'slug' => 'travel-hotels', 'icon' => 'plane', 'color' => '#06b6d4'],
            ['name' => 'Flights', 'slug' => 'flights', 'icon' => 'plane', 'color' => '#06b6d4', 'parent_slug' => 'travel-hotels'],
            ['name' => 'Education', 'slug' => 'education', 'icon' => 'book', 'color' => '#8b5cf6'],

            // ── Family ──
            ['name' => 'Childcare & Kids', 'slug' => 'childcare-kids', 'icon' => 'baby', 'color' => '#f472b6', 'is_essential' => true],
            ['name' => 'Pet Care', 'slug' => 'pet-care', 'icon' => 'paw', 'color' => '#a78bfa'],
            ['name' => 'Gifts', 'slug' => 'gifts', 'icon' => 'gift', 'color' => '#f472b6'],

            // ── Financial ──
            ['name' => 'Charity & Donations', 'slug' => 'charity', 'icon' => 'heart', 'color' => '#10b981', 'is_typically_deductible' => true, 'tax_schedule_line' => 'Schedule A - Charitable Contributions'],
            ['name' => 'Taxes', 'slug' => 'taxes', 'icon' => 'receipt', 'color' => '#ef4444', 'is_essential' => true],
            ['name' => 'Savings & Investment', 'slug' => 'savings-investment', 'icon' => 'piggy-bank', 'color' => '#10b981'],
            ['name' => 'Debt Payment', 'slug' => 'debt-payment', 'icon' => 'credit-card', 'color' => '#ef4444', 'is_essential' => true],
            ['name' => 'Fees & Charges', 'slug' => 'fees-charges', 'icon' => 'alert', 'color' => '#ef4444'],

            // ── Income ──
            ['name' => 'Income (Salary)', 'slug' => 'income-salary', 'icon' => 'dollar', 'color' => '#10b981'],
            ['name' => 'Income (Freelance)', 'slug' => 'income-freelance', 'icon' => 'dollar', 'color' => '#10b981'],
            ['name' => 'Income (Investment)', 'slug' => 'income-investment', 'icon' => 'trending-up', 'color' => '#10b981'],
            ['name' => 'Transfer', 'slug' => 'transfer', 'icon' => 'arrow-right', 'color' => '#94a3b8'],
            ['name' => 'ATM Withdrawal', 'slug' => 'atm', 'icon' => 'dollar', 'color' => '#94a3b8'],
            ['name' => 'Uncategorized', 'slug' => 'uncategorized', 'icon' => 'help-circle', 'color' => '#64748b'],
        ];

        foreach ($categories as $i => $cat) {
            DB::table('expense_categories')->insert(array_merge([
                'user_id'                => null, // System default
                'parent_slug'            => null,
                'tax_schedule_line'      => null,
                'is_essential'           => false,
                'is_typically_deductible' => false,
                'keywords'               => null,
                'sort_order'             => $i,
                'created_at'             => now(),
                'updated_at'             => now(),
            ], $cat));
        }
    }
}
