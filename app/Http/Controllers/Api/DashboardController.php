<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardRequest;
use App\Http\Resources\SavingsRecommendationResource;
use App\Http\Resources\TransactionResource;
use App\Models\AIQuestion;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\OrderItem;
use App\Models\SavingsRecommendation;
use App\Models\SavingsTarget;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\UserFinancialOverride;
use App\Services\AI\SavingsTargetPlannerService;
use App\Services\IncomeDetectorService;
use App\Services\SavingsTrackingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Dashboard composite data: spending summary, categories, questions,
     * recent transactions, trend, sync status, and accounts summary.
     */
    public function index(DashboardRequest $request): JsonResponse
    {
        $user = auth()->user();
        $viewMode = $request->input('view', 'all');
        $avgMode = $request->input('avg_mode', 'total');

        // Timeline filter: custom date range or default to current month
        $periodStart = $request->filled('period_start')
            ? Carbon::parse($request->input('period_start'))->startOfDay()
            : null;
        $periodEnd = $request->filled('period_end')
            ? Carbon::parse($request->input('period_end'))->endOfDay()
            : null;

        $periodStartKey = $periodStart?->format('Y-m-d') ?? '';
        $periodEndKey = $periodEnd?->format('Y-m-d') ?? '';
        $cacheKey = "dashboard:{$user->id}:{$viewMode}:{$periodStartKey}:{$periodEndKey}";

        return response()->json(Cache::remember($cacheKey, 60, function () use ($user, $viewMode, $periodStart, $periodEnd, $avgMode) {
            $now = Carbon::now();

            // When custom period is set, use it; otherwise default to current month
            $isCustomPeriod = $periodStart !== null && $periodEnd !== null;
            $monthStart = $isCustomPeriod ? $periodStart : $now->copy()->startOfMonth();
            $monthEnd = $isCustomPeriod ? $periodEnd : $now->copy()->endOfDay();

            // Compute prior period for comparison (same duration, shifted back)
            $periodDays = $monthStart->diffInDays($monthEnd);
            $lastMonthEnd = $monthStart->copy()->subDay()->endOfDay();
            $lastMonthStart = $lastMonthEnd->copy()->subDays($periodDays)->startOfDay();

            // Period months for the selected date range
            $periodMonths = max((int) $monthStart->diffInMonths($monthEnd), 1);

            // Base query builder that respects the view filter
            $txQuery = fn () => Transaction::where('user_id', $user->id)
                ->when($viewMode === 'personal', fn ($q) => $q->where('account_purpose', 'personal'))
                ->when($viewMode === 'business', fn ($q) => $q->where('account_purpose', 'business'));

            // This period's spending (outgoing)
            $thisMonth = $txQuery()
                ->where('amount', '>', 0)
                ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                ->sum('amount');

            // This period's income (incoming — negative amounts, excluding transfers)
            $thisMonthIncome = $txQuery()
                ->where('amount', '<', 0)
                ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                ->whereNotIn('plaid_category', ['TRANSFER_IN', 'TRANSFER_OUT'])
                ->sum(DB::raw('ABS(amount)'));

            // Last month's spending
            $lastMonth = $txQuery()
                ->where('amount', '>', 0)
                ->whereBetween('transaction_date', [$lastMonthStart, $lastMonthEnd])
                ->sum('amount');

            // Last month's income
            $lastMonthIncome = $txQuery()
                ->where('amount', '<', 0)
                ->whereBetween('transaction_date', [$lastMonthStart, $lastMonthEnd])
                ->whereNotIn('plaid_category', ['TRANSFER_IN', 'TRANSFER_OUT'])
                ->sum(DB::raw('ABS(amount)'));

            $monthOverMonth = $lastMonth > 0
                ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
                : 0;

            // Category breakdown (this period)
            // Use the query builder directly to avoid the model's `category` accessor
            // which overrides the COALESCE alias.
            $categories = $txQuery()
                ->where('amount', '>', 0)
                ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                ->toBase()
                ->select(
                    DB::raw("COALESCE(user_category, ai_category, 'Uncategorized') as category"),
                    DB::raw('SUM(amount) as total'),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('category')
                ->orderByDesc('total')
                ->get();

            // Pending AI questions (total count + limited preview)
            $questionsQuery = AIQuestion::where('user_id', $user->id)
                ->where('status', 'pending')
                ->when($viewMode !== 'all', function ($q) use ($viewMode) {
                    $q->whereHas('transaction', fn ($tq) => $tq->where('account_purpose', $viewMode));
                });

            $pendingQuestionsCount = (clone $questionsQuery)->count();

            $questions = $questionsQuery
                ->with('transaction:id,merchant_name,amount,transaction_date,account_purpose')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();

            // Savings potential
            $savingsTotal = SavingsRecommendation::where('user_id', $user->id)
                ->where('status', 'active')
                ->sum('monthly_savings');

            // Tax deductible (within selected period, or YTD for default)
            $taxDeductible = $txQuery()
                ->where('tax_deductible', true)
                ->whereBetween('transaction_date', [
                    $isCustomPeriod ? $monthStart : $now->copy()->startOfYear(),
                    $monthEnd,
                ])
                ->sum('amount');

            // Items needing review
            $needsReview = $txQuery()
                ->whereIn('review_status', ['needs_review', 'pending_ai', 'ai_uncertain'])
                ->count();

            // Unused subscriptions
            $unusedSubs = Subscription::where('user_id', $user->id)
                ->where('status', 'unused')
                ->count();

            // Unused subscription details for dashboard display
            $unusedSubDetails = Subscription::where('user_id', $user->id)
                ->where('status', 'unused')
                ->select('id', 'merchant_name', 'merchant_normalized', 'amount', 'last_charge_date', 'last_used_at', 'annual_cost')
                ->orderByDesc('amount')
                ->limit(5)
                ->get();

            // Top savings recommendations
            $savingsRecs = SavingsRecommendation::where('user_id', $user->id)
                ->where('status', 'active')
                ->orderByDesc('annual_savings')
                ->limit(5)
                ->get();

            // Savings target with progress
            $savingsTarget = SavingsTarget::where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            $savingsTargetData = null;
            if ($savingsTarget) {
                $planner = app(SavingsTargetPlannerService::class);
                $currentMonth = $planner->calculateProgress($user, $savingsTarget);
                $savingsTargetData = [
                    'monthly_target' => $savingsTarget->monthly_target,
                    'motivation' => $savingsTarget->motivation,
                    'goal_total' => $savingsTarget->goal_total,
                    'current_month' => $currentMonth,
                ];
            }

            // Applied recommendations in period (for momentum tracker)
            $appliedThisMonth = SavingsRecommendation::where('user_id', $user->id)
                ->where('status', 'applied')
                ->whereBetween('applied_at', [$monthStart, $monthEnd])
                ->orderByDesc('monthly_savings')
                ->select('id', 'title', 'monthly_savings', 'category', 'applied_at')
                ->get();

            $appliedSavingsTotal = $appliedThisMonth->sum('monthly_savings');

            // Upcoming recurring charges (subscriptions due this month)
            $upcomingRecurring = Subscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->sum('amount');

            $freeToSpend = max(round($thisMonthIncome - $thisMonth - $upcomingRecurring, 2), 0);

            // AI stats
            $autoCategorized = $txQuery()
                ->where('review_status', 'auto_categorized')
                ->count();
            $pendingReview = $txQuery()
                ->whereIn('review_status', ['pending_ai', 'needs_review'])
                ->count();

            // Recent transactions (within period if custom)
            $recent = $txQuery()
                ->when($isCustomPeriod, fn ($q) => $q->whereBetween('transaction_date', [$monthStart, $monthEnd]))
                ->with('bankAccount:id,name,mask,purpose,nickname')
                ->orderByDesc('transaction_date')
                ->limit(20)
                ->get();

            // Monthly spending trend — includes income + expenses
            $trendStart = $isCustomPeriod ? $monthStart : $now->copy()->subMonths(6)->startOfMonth();
            $trend = $txQuery()
                ->whereBetween('transaction_date', [$trendStart, $monthEnd])
                ->whereNotIn('plaid_category', ['TRANSFER_IN', 'TRANSFER_OUT'])
                ->select(
                    DB::raw("TO_CHAR(transaction_date, 'Mon') as month"),
                    DB::raw("DATE_TRUNC('month', transaction_date) as month_start"),
                    DB::raw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as expenses'),
                    DB::raw('SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as income')
                )
                ->groupBy('month', 'month_start')
                ->orderBy('month_start')
                ->get();

            // Top savings opportunity categories — highest discretionary spending
            $oppsStart = $isCustomPeriod ? $monthStart : $now->copy()->subMonths(3)->startOfMonth();
            $oppsMonths = max((int) $oppsStart->diffInMonths($monthEnd), 1);
            $savingsOpportunities = $txQuery()
                ->where('amount', '>', 0)
                ->whereBetween('transaction_date', [$oppsStart, $monthEnd])
                ->toBase()
                ->select(
                    DB::raw("COALESCE(user_category, ai_category, 'Uncategorized') as category"),
                    DB::raw('SUM(amount) as total_3mo'),
                    DB::raw("ROUND(SUM(amount) / {$oppsMonths}, 2) as monthly_avg"),
                    DB::raw('COUNT(*) as transaction_count'),
                    DB::raw('ROUND(AVG(amount), 2) as avg_transaction')
                )
                ->groupBy('category')
                ->orderByDesc('total_3mo')
                ->limit(10)
                ->get();

            // --- All Recurring Bills (active + unused) ---
            $allRecurringBills = Subscription::where('user_id', $user->id)
                ->whereIn('status', ['active', 'unused'])
                ->orderByDesc('amount')
                ->select('id', 'merchant_name', 'merchant_normalized', 'amount', 'frequency', 'status', 'is_essential', 'last_charge_date', 'next_expected_date', 'annual_cost')
                ->get();

            $totalMonthlyBills = $allRecurringBills->sum('amount');

            // --- Monthly Budget Waterfall ---
            $essentialBills = $allRecurringBills->where('is_essential', true)->sum('amount');
            $nonEssentialBills = $allRecurringBills->where('is_essential', false)->sum('amount');

            // Discretionary = total spending minus recurring bills
            $discretionarySpending = max(round($thisMonth - $totalMonthlyBills, 2), 0);

            $monthlySurplus = round($thisMonthIncome - $thisMonth, 2);

            $budgetWaterfall = [
                'monthly_income' => round($thisMonthIncome, 2),
                'essential_bills' => round($essentialBills, 2),
                'non_essential_subscriptions' => round($nonEssentialBills, 2),
                'discretionary_spending' => $discretionarySpending,
                'total_spending' => round($thisMonth, 2),
                'monthly_surplus' => $monthlySurplus,
                'can_save' => $monthlySurplus > 0,
                'savings_rate' => $thisMonthIncome > 0 ? round(($monthlySurplus / $thisMonthIncome) * 100, 1) : 0,
            ];

            // --- Income Detection (early for use in home affordability) ---
            $userOverrides = UserFinancialOverride::getOverridesFor($user->id);
            $incomeDetector = app(IncomeDetectorService::class);
            $incomeMonths = $isCustomPeriod ? $periodMonths : 3;
            $incomeSources = $incomeDetector->analyze($user->id, $viewMode, $incomeMonths, $userOverrides);

            // --- Home Affordability Calculator ---
            $reliableIncome = $incomeSources['reliable_monthly'];
            $monthlyIncome = $reliableIncome > 0
                ? $reliableIncome
                : ($thisMonthIncome > 0 ? $thisMonthIncome : ($lastMonthIncome > 0 ? $lastMonthIncome : 0));
            $monthlyDebt = $totalMonthlyBills; // All recurring obligations
            $downPayment = 100000; // Default $100k, could be configurable
            $interestRate = 0.0685; // ~6.85% current avg 30yr fixed
            $loanTermYears = 30;

            $monthlyRate = $interestRate / 12;
            $numPayments = $loanTermYears * 12;

            // Max housing payment: 28% of gross income (front-end DTI)
            $maxHousingFrontEnd = $monthlyIncome * 0.28;

            // Max total debt payments: 43% of gross income (back-end DTI)
            $maxTotalDebtPayment = $monthlyIncome * 0.43;
            $maxHousingBackEnd = $maxTotalDebtPayment - $monthlyDebt;

            // Use the more conservative (lower) of the two
            $maxMonthlyPayment = max(min($maxHousingFrontEnd, $maxHousingBackEnd), 0);

            // Calculate max loan from payment using mortgage formula: P = M * [(1+r)^n - 1] / [r(1+r)^n]
            $maxLoanAmount = 0;
            if ($maxMonthlyPayment > 0 && $monthlyRate > 0) {
                $factor = pow(1 + $monthlyRate, $numPayments);
                $maxLoanAmount = round($maxMonthlyPayment * ($factor - 1) / ($monthlyRate * $factor), 0);
            }

            $maxHomePrice = $maxLoanAmount + $downPayment;

            // Current DTI
            $currentDti = $monthlyIncome > 0 ? round(($monthlyDebt / $monthlyIncome) * 100, 1) : 0;

            $homeAffordability = [
                'monthly_income' => round($monthlyIncome, 2),
                'monthly_debt' => round($monthlyDebt, 2),
                'current_dti' => $currentDti,
                'down_payment' => $downPayment,
                'interest_rate' => round($interestRate * 100, 2),
                'max_monthly_payment' => round($maxMonthlyPayment, 2),
                'max_loan_amount' => $maxLoanAmount,
                'max_home_price' => $maxHomePrice,
                'estimated_monthly_mortgage' => round($maxMonthlyPayment, 2),
                'loan_term_years' => $loanTermYears,
            ];

            // --- Cost of Living Breakdown ---
            // Map Plaid detailed categories + AI categories to essential bill buckets
            $essentialCategoryMap = [
                // Plaid detailed → bucket
                'RENT_AND_UTILITIES_GAS_AND_ELECTRICITY' => 'Utilities',
                'RENT_AND_UTILITIES_SEWAGE_AND_WASTE_MANAGEMENT' => 'Utilities',
                'RENT_AND_UTILITIES_INTERNET_AND_CABLE' => 'Internet & Cable',
                'RENT_AND_UTILITIES_TELEPHONE' => 'Phone',
                'RENT_AND_UTILITIES_RENT' => 'Housing',
                'FOOD_AND_DRINK_GROCERIES' => 'Groceries',
                'GENERAL_SERVICES_INSURANCE' => 'Insurance',
                'TRANSPORTATION_GAS' => 'Gas & Auto',
                'GENERAL_SERVICES_AUTOMOTIVE' => 'Gas & Auto',
                'MEDICAL_PRIMARY_CARE' => 'Medical',
                'MEDICAL_PHARMACIES_AND_SUPPLEMENTS' => 'Medical',
                'MEDICAL_OTHER_MEDICAL' => 'Medical',
                'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT' => 'Credit Card Payments',
                'LOAN_PAYMENTS_OTHER_PAYMENT' => '_loan_detect', // Needs smart detection
            ];
            $essentialAiCategoryMap = [
                'Mortgage' => 'Housing',
                'Utilities (Electric/Water/Gas)' => 'Utilities',
                'Trash & Recycling' => 'Utilities',
                'Phone & Internet' => 'Phone',
                'Food & Groceries' => 'Groceries',
                'Car Insurance' => 'Insurance',
                'Home Insurance' => 'Insurance',
                'Gas & Fuel' => 'Gas & Auto',
                'Auto Maintenance' => 'Gas & Auto',
                'Car Payment' => 'Car Payment',
                'Debt Payment' => '_debt_detect', // Needs smart detection
                'Medical & Dental' => 'Medical',
                'Childcare & Kids' => 'Childcare',
            ];

            // Query average for essential categories from transactions
            $essentialStart = $isCustomPeriod ? $monthStart : $now->copy()->subMonths(3)->startOfMonth();
            $monthsElapsed = max((int) $essentialStart->diffInMonths($isCustomPeriod ? $monthEnd : $now->copy()->startOfMonth()), 1);

            $essentialSpending = $txQuery()
                ->where('amount', '>', 0)
                ->whereBetween('transaction_date', [$essentialStart, $monthEnd])
                ->where(function ($q) use ($essentialCategoryMap, $essentialAiCategoryMap) {
                    $q->whereIn('plaid_detailed_category', array_keys($essentialCategoryMap))
                        ->orWhereIn(DB::raw('COALESCE(user_category, ai_category)'), array_keys($essentialAiCategoryMap));
                })
                ->select('plaid_detailed_category', DB::raw('COALESCE(user_category, ai_category) as resolved_category'), 'amount', 'merchant_name', 'transaction_date')
                ->get();

            // First pass: identify the housing payment by finding the largest
            // recurring loan/debt charge (rent/mortgage is typically the biggest
            // monthly payment, due on the 1st-5th of each month).
            $loanByMerchant = [];
            foreach ($essentialSpending as $tx) {
                $bucket = null;
                if ($tx->plaid_detailed_category && isset($essentialCategoryMap[$tx->plaid_detailed_category])) {
                    $bucket = $essentialCategoryMap[$tx->plaid_detailed_category];
                } elseif ($tx->resolved_category && isset($essentialAiCategoryMap[$tx->resolved_category])) {
                    $bucket = $essentialAiCategoryMap[$tx->resolved_category];
                }
                if (! in_array($bucket, ['_loan_detect', '_debt_detect', 'Housing'])) {
                    continue;
                }
                // If AI already said "Mortgage", it's housing
                if ($bucket === 'Housing') {
                    $loanByMerchant[$tx->merchant_name]['is_mortgage'] = true;
                }
                $loanByMerchant[$tx->merchant_name]['total'] = ($loanByMerchant[$tx->merchant_name]['total'] ?? 0) + $tx->amount;
                $loanByMerchant[$tx->merchant_name]['count'] = ($loanByMerchant[$tx->merchant_name]['count'] ?? 0) + 1;
                $loanByMerchant[$tx->merchant_name]['amounts'][] = $tx->amount;
                $day = (int) Carbon::parse($tx->transaction_date)->format('d');
                $loanByMerchant[$tx->merchant_name]['days'][] = $day;
            }

            // Determine which merchant is the housing payment:
            // 1) AI tagged as Mortgage, or
            // 2) Recurring loan with consistent amount >= $500/charge, or
            // 3) Single large charge (>= $1000) on 1st-5th that matches an
            //    already-identified housing amount (servicer change)
            $housingMerchants = [];
            $housingAmounts = [];
            foreach ($loanByMerchant as $merchant => $info) {
                if (! empty($info['is_mortgage'])) {
                    $housingMerchants[] = $merchant;
                    $housingAmounts = array_merge($housingAmounts, $info['amounts']);

                    continue;
                }
                // At least 2 charges, consistent amount, >= $500 each
                if ($info['count'] >= 2) {
                    $amounts = $info['amounts'];
                    $avg = array_sum($amounts) / count($amounts);
                    if ($avg >= 500) {
                        $maxDev = max(array_map(fn ($a) => abs($a - $avg), $amounts));
                        if ($maxDev / $avg <= 0.05) {
                            $housingMerchants[] = $merchant;
                            $housingAmounts = array_merge($housingAmounts, $amounts);
                        }
                    }
                }
            }

            // Second chance: single large charges that match a known housing
            // amount or are >= $1000 on the 1st-5th (likely a new servicer
            // for the same mortgage/rent)
            $knownHousingAmount = ! empty($housingAmounts) ? round(array_sum($housingAmounts) / count($housingAmounts), 2) : 0;
            foreach ($loanByMerchant as $merchant => $info) {
                if (in_array($merchant, $housingMerchants)) {
                    continue;
                }
                $avg = array_sum($info['amounts']) / count($info['amounts']);
                $earlyMonth = ! empty($info['days']) && min($info['days']) <= 5;

                // Match known housing amount (within 1%)
                if ($knownHousingAmount > 0 && abs($avg - $knownHousingAmount) / $knownHousingAmount <= 0.01) {
                    $housingMerchants[] = $merchant;

                    continue;
                }
                // Large payment on 1st-5th, no recurring charges yet identified
                if ($earlyMonth && $avg >= 1000 && empty($housingMerchants)) {
                    $housingMerchants[] = $merchant;
                }
            }

            // Second pass: bucket all transactions
            $buckets = [];
            foreach ($essentialSpending as $tx) {
                $bucket = null;
                if ($tx->plaid_detailed_category && isset($essentialCategoryMap[$tx->plaid_detailed_category])) {
                    $bucket = $essentialCategoryMap[$tx->plaid_detailed_category];
                } elseif ($tx->resolved_category && isset($essentialAiCategoryMap[$tx->resolved_category])) {
                    $bucket = $essentialAiCategoryMap[$tx->resolved_category];
                }
                if (! $bucket) {
                    continue;
                }

                // Resolve smart detection buckets
                if (in_array($bucket, ['_loan_detect', '_debt_detect', 'Housing'])) {
                    $bucket = in_array($tx->merchant_name, $housingMerchants)
                        ? 'Housing'
                        : 'Credit Card Payments';
                }

                if (! isset($buckets[$bucket])) {
                    $buckets[$bucket] = ['total' => 0, 'count' => 0, 'merchants' => []];
                }
                $buckets[$bucket]['total'] += $tx->amount;
                $buckets[$bucket]['count']++;
                $merchant = $tx->merchant_name ?? 'Unknown';
                if (! isset($buckets[$bucket]['merchants'][$merchant])) {
                    $buckets[$bucket]['merchants'][$merchant] = 0;
                }
                $buckets[$bucket]['merchants'][$merchant] += $tx->amount;
            }

            // Also include housing from subscriptions (mortgage/rent) if not
            // already captured from transactions
            if (! isset($buckets['Housing']) || $buckets['Housing']['total'] == 0) {
                $housingSubscriptions = $allRecurringBills->filter(function ($bill) {
                    $name = strtolower($bill->merchant_normalized ?? $bill->merchant_name);

                    return str_contains($name, 'mortgage') || str_contains($name, 'rent')
                        || str_contains($name, 'housing') || $bill->category === 'Housing';
                });
                if ($housingSubscriptions->isNotEmpty()) {
                    if (! isset($buckets['Housing'])) {
                        $buckets['Housing'] = ['total' => 0, 'count' => 0, 'merchants' => []];
                    }
                    foreach ($housingSubscriptions as $sub) {
                        $subMonthly = (float) $sub->amount * $monthsElapsed;
                        $buckets['Housing']['total'] += $subMonthly;
                        $buckets['Housing']['count'] += $monthsElapsed;
                        $merchant = $sub->merchant_normalized ?? $sub->merchant_name;
                        $buckets['Housing']['merchants'][$merchant] = ($buckets['Housing']['merchants'][$merchant] ?? 0) + $subMonthly;
                    }
                }
            }

            // Merge housing merchants when a servicer changes
            if (isset($buckets['Housing']) && count($buckets['Housing']['merchants']) > 1) {
                $buckets['Housing'] = $this->mergeHousingMerchants($buckets['Housing'], $monthsElapsed);
            }

            // Format into sorted array with monthly averages
            $costOfLivingItems = [];
            $totalEssentialSpending = 0;
            foreach ($buckets as $category => $data) {
                $monthlyAvg = round($data['total'] / $monthsElapsed, 2);
                $totalEssentialSpending += $monthlyAvg;

                // Top merchants for this bucket
                arsort($data['merchants']);
                $topMerchants = array_slice(
                    array_map(fn ($merchant, $amt) => [
                        'name' => $merchant,
                        'monthly_avg' => round($amt / $monthsElapsed, 2),
                    ], array_keys($data['merchants']), $data['merchants']),
                    0,
                    5
                );

                $costOfLivingItems[] = [
                    'category' => $category,
                    'monthly_avg' => $monthlyAvg,
                    'total_3mo' => round($data['total'], 2),
                    'transaction_count' => $data['count'],
                    'top_merchants' => $topMerchants,
                ];
            }
            usort($costOfLivingItems, fn ($a, $b) => $b['monthly_avg'] <=> $a['monthly_avg']);

            $discretionaryMonthly = round(($thisMonth > 0 ? $thisMonth : $lastMonth) - $totalEssentialSpending, 2);

            $costOfLiving = [
                'items' => $costOfLivingItems,
                'total_essential_monthly' => round($totalEssentialSpending, 2),
                'discretionary_monthly' => max($discretionaryMonthly, 0),
                'monthly_income' => round($thisMonthIncome > 0 ? $thisMonthIncome : $lastMonthIncome, 2),
                'reliable_monthly_income' => $incomeSources['reliable_monthly'],
                'months_analyzed' => $monthsElapsed,
            ];

            // --- Primary vs Extra ---
            // Primary expenses = essential CoL buckets (user can override) + essential subs
            // Extra expenses = non-essential subs + discretionary + user-overridden buckets
            $expenseOverrides = $userOverrides['expense_category'] ?? [];
            $primaryExpenses = 0;
            $extraExpenses = 0;

            foreach ($costOfLivingItems as $item) {
                $cat = $item['category'];
                if (isset($expenseOverrides[$cat]) && $expenseOverrides[$cat] === 'extra') {
                    $extraExpenses += $item['monthly_avg'];
                } else {
                    // All CoL buckets default to primary
                    $primaryExpenses += $item['monthly_avg'];
                }
            }

            // Add non-essential subscriptions to extra
            $extraExpenses += $nonEssentialBills;

            $primaryIncome = $incomeSources['primary_monthly'];
            $primarySurplus = round($primaryIncome - $primaryExpenses, 2);

            $primaryVsExtra = [
                'primary_income' => round($primaryIncome, 2),
                'extra_income' => round($incomeSources['extra_monthly'], 2),
                'primary_expenses' => round($primaryExpenses, 2),
                'extra_expenses' => round($extraExpenses, 2),
                'primary_surplus' => $primarySurplus,
                'can_live_on_primary' => $primarySurplus >= 0,
                'coverage_pct' => $primaryIncome > 0
                    ? round(($primaryExpenses / $primaryIncome) * 100, 1)
                    : 0,
            ];

            // --- Top Stores by Spend ---
            $topStores = $txQuery()
                ->where('amount', '>', 0)
                ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                ->toBase()
                ->select(
                    DB::raw("COALESCE(NULLIF(merchant_normalized, ''), merchant_name, 'Unknown') as store_name"),
                    DB::raw('SUM(amount) as total_spent'),
                    DB::raw('COUNT(*) as transaction_count'),
                    DB::raw('ROUND(AVG(amount), 2) as avg_per_visit'),
                    DB::raw('MIN(transaction_date) as first_visit'),
                    DB::raw('MAX(transaction_date) as last_visit'),
                    DB::raw('BOOL_OR(matched_order_id IS NOT NULL) as has_order_items'),
                    DB::raw('SUM(CASE WHEN tax_deductible THEN amount ELSE 0 END) as tax_deductible_total'),
                    DB::raw('COUNT(CASE WHEN tax_deductible THEN 1 END) as tax_deductible_count')
                )
                ->groupBy('store_name')
                ->orderByDesc('total_spent')
                ->limit(15)
                ->get();

            $topStoresTotal = (float) $topStores->sum('total_spent');

            $topStoresFormatted = $topStores->map(fn ($store) => [
                'store_name' => $store->store_name,
                'total_spent' => round((float) $store->total_spent, 2),
                'transaction_count' => (int) $store->transaction_count,
                'avg_per_visit' => round((float) $store->avg_per_visit, 2),
                'pct_of_total' => $topStoresTotal > 0
                    ? round(((float) $store->total_spent / $topStoresTotal) * 100, 1)
                    : 0,
                'first_visit' => $store->first_visit,
                'last_visit' => $store->last_visit,
                'has_order_items' => (bool) $store->has_order_items,
                'tax_deductible_total' => round((float) $store->tax_deductible_total, 2),
                'tax_deductible_count' => (int) $store->tax_deductible_count,
            ])->values();

            // --- Period Metadata ---
            $periodMeta = [
                'start' => $monthStart->format('Y-m-d'),
                'end' => ($isCustomPeriod ? $monthEnd : $now)->format('Y-m-d'),
                'months' => $periodMonths,
                'avg_mode' => $avgMode,
                'is_custom' => $isCustomPeriod,
            ];

            return [
                'view_mode' => $viewMode,
                'summary' => [
                    'this_month_spending' => round($thisMonth, 2),
                    'this_month_income' => round($thisMonthIncome, 2),
                    'last_month_spending' => round($lastMonth, 2),
                    'last_month_income' => round($lastMonthIncome, 2),
                    'net_this_month' => round($thisMonthIncome - $thisMonth, 2),
                    'month_over_month' => $monthOverMonth,
                    'potential_savings' => round($savingsTotal, 2),
                    'tax_deductible_ytd' => round($taxDeductible, 2),
                    'needs_review' => $needsReview,
                    'unused_subscriptions' => $unusedSubs,
                    'pending_questions' => $pendingQuestionsCount,
                ],
                'categories' => $categories,
                'questions' => $questions,
                'recent' => TransactionResource::collection($recent),
                'spending_trend' => $trend,
                'sync_status' => BankConnection::where('user_id', $user->id)
                    ->first()?->only(['status', 'last_synced_at', 'institution_name']),
                'accounts_summary' => BankAccount::where('user_id', $user->id)
                    ->select('purpose', DB::raw('COUNT(*) as count'))
                    ->groupBy('purpose')
                    ->pluck('count', 'purpose'),
                'savings_recommendations' => SavingsRecommendationResource::collection($savingsRecs),
                'savings_target' => $savingsTargetData,
                'unused_subscription_details' => $unusedSubDetails,
                'savings_opportunities' => $savingsOpportunities,
                'free_to_spend' => $freeToSpend,
                'applied_this_month' => $appliedThisMonth,
                'applied_savings_total' => round($appliedSavingsTotal, 2),
                'ai_stats' => [
                    'auto_categorized' => $autoCategorized,
                    'pending_review' => $pendingReview,
                    'questions_generated' => $pendingQuestionsCount,
                ],
                'recurring_bills' => $allRecurringBills,
                'total_monthly_bills' => round($totalMonthlyBills, 2),
                'budget_waterfall' => $budgetWaterfall,
                'home_affordability' => $homeAffordability,
                'cost_of_living' => $costOfLiving,
                'income_sources' => $incomeSources,
                'primary_vs_extra' => $primaryVsExtra,
                'projected_savings' => app(SavingsTrackingService::class)->getProjectedSavings($user->id),
                'savings_history' => app(SavingsTrackingService::class)->getSavingsHistory($user->id, 6),
                'top_stores' => $topStoresFormatted,
                'top_stores_total' => round($topStoresTotal, 2),
                'period' => $periodMeta,
            ];
        }));
    }

    /**
     * Store detail: monthly spending trend + reconciled order items.
     */
    public function storeDetail(Request $request, string $storeName): JsonResponse
    {
        $user = auth()->user();
        $storeName = urldecode($storeName);

        $cacheKey = "store_detail:{$user->id}:{$storeName}";

        return response()->json(Cache::remember($cacheKey, 300, function () use ($user, $storeName) {
            $storeFilter = function ($q) use ($storeName) {
                $q->where('merchant_normalized', $storeName)
                    ->orWhere('merchant_name', $storeName);
            };

            // Monthly spending trend at this store
            $monthlyTrend = Transaction::where('user_id', $user->id)
                ->where('amount', '>', 0)
                ->where($storeFilter)
                ->toBase()
                ->select(
                    DB::raw("TO_CHAR(transaction_date, 'Mon YYYY') as month"),
                    DB::raw("DATE_TRUNC('month', transaction_date) as month_start"),
                    DB::raw('SUM(amount) as total'),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('month', 'month_start')
                ->orderBy('month_start')
                ->limit(12)
                ->get()
                ->map(fn ($row) => [
                    'month' => $row->month,
                    'month_start' => Carbon::parse($row->month_start)->format('Y-m-d'),
                    'total' => round((float) $row->total, 2),
                    'count' => (int) $row->count,
                ]);

            // Individual transactions grouped by month for drill-down
            $transactions = Transaction::where('user_id', $user->id)
                ->where('amount', '>', 0)
                ->where($storeFilter)
                ->with(['matchedOrder.items' => function ($q) {
                    $q->select('id', 'order_id', 'product_name', 'product_description', 'quantity',
                        'total_price', 'ai_category', 'user_category', 'expense_type',
                        'tax_deductible', 'tax_deductible_confidence');
                }])
                ->orderByDesc('transaction_date')
                ->limit(200)
                ->get()
                ->map(function ($tx) {
                    $monthKey = $tx->transaction_date->format('M Y');

                    return [
                        'id' => $tx->id,
                        'month' => $monthKey,
                        'date' => $tx->transaction_date->format('Y-m-d'),
                        'merchant_name' => $tx->merchant_name,
                        'amount' => round((float) $tx->amount, 2),
                        'description' => $tx->description,
                        'category' => $tx->user_category ?? $tx->ai_category ?? 'Uncategorized',
                        'expense_type' => $tx->expense_type,
                        'tax_deductible' => (bool) $tx->tax_deductible,
                        'is_reconciled' => (bool) $tx->is_reconciled,
                        'order_items' => $tx->matchedOrder?->items?->map(fn ($item) => [
                            'id' => $item->id,
                            'product_name' => $item->product_name,
                            'product_description' => $item->product_description,
                            'quantity' => $item->quantity,
                            'total_price' => round((float) $item->total_price, 2),
                            'ai_category' => $item->ai_category,
                            'user_category' => $item->user_category,
                            'expense_type' => $item->expense_type,
                            'tax_deductible' => (bool) $item->tax_deductible,
                            'tax_deductible_confidence' => $item->tax_deductible_confidence,
                        ])?->values() ?? [],
                    ];
                })
                ->groupBy('month');

            // Reconciled order items for this store
            // Use flexible matching: exact, case-insensitive, and ILIKE partial match
            // so "PCI Race Radios" matches bank's "PCI RACE" and vice versa
            $storeNameLower = strtolower($storeName);
            $orderItems = OrderItem::where('order_items.user_id', $user->id)
                ->whereHas('order', function ($q) use ($storeName, $storeNameLower) {
                    $q->where(function ($inner) use ($storeName, $storeNameLower) {
                        $inner->where('merchant_normalized', $storeName)
                            ->orWhere('merchant', $storeName)
                            ->orWhereRaw('LOWER(merchant_normalized) = ?', [$storeNameLower])
                            ->orWhereRaw('LOWER(merchant) = ?', [$storeNameLower])
                            ->orWhere('merchant_normalized', 'ILIKE', '%'.$storeName.'%')
                            ->orWhere('merchant', 'ILIKE', '%'.$storeName.'%');
                    });
                })
                ->with('order:id,order_date,order_number,total')
                ->select(
                    'id', 'order_id', 'product_name', 'product_description', 'quantity',
                    'total_price', 'ai_category', 'user_category', 'expense_type',
                    'tax_deductible', 'tax_deductible_confidence'
                )
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            return [
                'store_name' => $storeName,
                'monthly_trend' => $monthlyTrend,
                'transactions' => $transactions,
                'order_items' => $orderItems,
            ];
        }));
    }

    /**
     * Update a user's income/expense classification override.
     */
    public function classify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'override_type' => 'required|in:income_source,expense_category',
            'override_key' => 'required|string|max:255',
            'classification' => 'required|in:primary,extra',
        ]);

        $userId = auth()->id();

        UserFinancialOverride::updateOrCreate(
            [
                'user_id' => $userId,
                'override_type' => $validated['override_type'],
                'override_key' => $validated['override_key'],
            ],
            ['classification' => $validated['classification']]
        );

        // Clear dashboard cache (flush by tag pattern)
        $this->clearDashboardCache($userId);

        return response()->json(['message' => 'Classification updated']);
    }

    /**
     * Clear all dashboard caches for a user (default + any custom period caches).
     */
    private function clearDashboardCache(int $userId): void
    {
        foreach (['all', 'personal', 'business'] as $view) {
            // Default cache (no period params)
            Cache::forget("dashboard:{$userId}:{$view}::");

            // Legacy cache key (without period suffix) for backward compat
            Cache::forget("dashboard:{$userId}:{$view}");
        }
    }

    /**
     * Merge housing merchants when a servicer changes (one stops, another starts
     * at the same amount). Returns the modified bucket data.
     */
    private function mergeHousingMerchants(array $bucket, int $monthsElapsed): array
    {
        $merchants = $bucket['merchants'];
        if (count($merchants) <= 1) {
            return $bucket;
        }

        // Get per-charge averages for each merchant
        $merchantAmounts = [];
        foreach ($merchants as $name => $total) {
            // Approximate per-charge amount from total / months (rough)
            $perCharge = $total / max($monthsElapsed, 1);
            $merchantAmounts[$name] = $perCharge;
        }

        // Check if all merchants have similar per-charge amounts (within 5%)
        $amounts = array_values($merchantAmounts);
        $maxAmount = max($amounts);
        $minAmount = min($amounts);

        if ($maxAmount > 0 && ($maxAmount - $minAmount) / $maxAmount <= 0.05) {
            // Similar amounts — likely a servicer change. Merge into one entry.
            $mergedTotal = array_sum($merchants);
            $bucket['merchants'] = ['Rent / Mortgage' => $mergedTotal];
        }

        return $bucket;
    }
}
