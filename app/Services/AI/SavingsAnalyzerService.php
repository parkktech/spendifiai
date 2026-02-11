<?php

namespace App\Services\AI;

use App\Models\Transaction;
use App\Models\Subscription;
use App\Models\SavingsRecommendation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SavingsAnalyzerService
{
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key');
        $this->model  = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Run full savings analysis for a user.
     * Analyzes spending patterns, detects waste, and generates recommendations.
     */
    public function analyze(User $user): array
    {
        // Gather 3 months of data
        $since = Carbon::now()->subMonths(3);

        $spending = $this->getSpendingSummary($user->id, $since);
        $subscriptions = $this->getSubscriptionAnalysis($user->id);
        $patterns = $this->getSpendingPatterns($user->id, $since);

        // Send to Claude for deep analysis
        $analysis = $this->getAIAnalysis($spending, $subscriptions, $patterns, $user);

        if (isset($analysis['error'])) {
            return ['error' => $analysis['error']];
        }

        // Store recommendations
        $saved = $this->storeRecommendations($user->id, $analysis);

        return [
            'total_monthly_savings'  => collect($analysis)->sum('monthly_savings'),
            'total_annual_savings'   => collect($analysis)->sum('annual_savings'),
            'recommendations_count'  => count($analysis),
            'new_recommendations'    => $saved,
        ];
    }

    /**
     * Get aggregated spending by category for the period.
     */
    protected function getSpendingSummary(int $userId, Carbon $since): array
    {
        return Transaction::where('user_id', $userId)
            ->where('transaction_date', '>=', $since)
            ->where('amount', '>', 0) // Only spending
            ->select(
                DB::raw("COALESCE(user_category, ai_category, 'Uncategorized') as category"),
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as count'),
                DB::raw('AVG(amount) as avg_amount'),
                DB::raw('MAX(amount) as max_amount'),
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->toArray();
    }

    /**
     * Analyze subscriptions for waste and optimization opportunities.
     */
    protected function getSubscriptionAnalysis(int $userId): array
    {
        $subs = Subscription::where('user_id', $userId)->get();

        return [
            'active'       => $subs->where('status', 'active')->values()->toArray(),
            'unused'       => $subs->where('status', 'unused')->values()->toArray(),
            'total_monthly' => $subs->where('status', 'active')->sum('amount'),
            'total_annual'  => $subs->where('status', 'active')->sum('annual_cost'),
            'unused_monthly' => $subs->where('status', 'unused')->sum('amount'),
        ];
    }

    /**
     * Detect spending patterns: frequency, time-of-day, weekday/weekend, etc.
     */
    protected function getSpendingPatterns(int $userId, Carbon $since): array
    {
        $transactions = Transaction::where('user_id', $userId)
            ->where('transaction_date', '>=', $since)
            ->where('amount', '>', 0)
            ->get();

        // Daily averages by day of week
        $byDayOfWeek = $transactions->groupBy(fn($t) => $t->transaction_date->dayOfWeek)
            ->map(fn($group) => round($group->avg('amount'), 2));

        // Frequent small purchases (impulse buys)
        $impulseBuys = $transactions->where('amount', '<', 20)
            ->where('amount', '>', 0)
            ->count();

        // Eating out frequency
        $diningOut = $transactions->filter(fn($t) =>
            in_array($t->ai_category ?? $t->user_category, ['Restaurant & Dining', 'Coffee & Drinks'])
        );

        // Late-night purchases (potential impulse)
        $lateNight = $transactions->filter(fn($t) =>
            $t->authorized_date && Carbon::parse($t->authorized_date)->hour >= 22
        );

        return [
            'avg_daily_spend'        => round($transactions->sum('amount') / max($since->diffInDays(now()), 1), 2),
            'spending_by_day'        => $byDayOfWeek->toArray(),
            'impulse_buy_count'      => $impulseBuys,
            'impulse_buy_total'      => round($transactions->where('amount', '<', 20)->sum('amount'), 2),
            'dining_out_count'       => $diningOut->count(),
            'dining_out_total'       => round($diningOut->sum('amount'), 2),
            'dining_out_avg'         => $diningOut->count() > 0 ? round($diningOut->avg('amount'), 2) : 0,
            'late_night_purchases'   => $lateNight->count(),
            'total_transactions'     => $transactions->count(),
            'months_analyzed'        => 3,
        ];
    }

    /**
     * Send all data to Claude for comprehensive savings analysis.
     */
    protected function getAIAnalysis(
        array $spending,
        array $subscriptions,
        array $patterns,
        User $user
    ): array {
        $profile = $user->financialProfile;

        $system = <<<'PROMPT'
You are a personal finance advisor AI. Analyze the user's spending data and generate specific,
actionable savings recommendations. Be honest and direct about where money is being wasted.

For EACH recommendation, return JSON:
{
  "title": "Short actionable title",
  "description": "Specific explanation with numbers from their actual spending. Reference their actual merchants and amounts.",
  "monthly_savings": <estimated monthly savings as number>,
  "annual_savings": <monthly * 12>,
  "difficulty": "easy|medium|hard",
  "category": "<spending category>",
  "impact": "high|medium|low",
  "action_steps": ["Step 1", "Step 2"],
  "related_merchants": ["merchant names"]
}

GUIDELINES:
- Use ACTUAL numbers from their data, not generic advice
- "Easy" = can do today with no lifestyle change (cancel unused sub, switch plans)
- "Medium" = requires some behavior change (eat out less, shop less)
- "Hard" = significant lifestyle adjustment (downgrade housing, sell car)
- High impact = saves $50+/month
- Medium impact = saves $15-50/month
- Low impact = saves under $15/month
- Look for: unused subscriptions, redundant services (multiple streaming), high dining frequency,
  insurance that hasn't been shopped, phone plan optimization, impulse buying patterns,
  subscription bundles, annual vs monthly billing savings, grocery vs dining ratio
- Be specific: "You spent $340 at Starbucks in 3 months" not "Consider reducing coffee purchases"
- Suggest alternatives where possible: "Switch from Netflix Premium ($22.99) to Standard ($15.49)"

Respond with a JSON array of recommendations, ordered by annual_savings descending. No markdown.
PROMPT;

        $userData = json_encode([
            'spending_by_category' => $spending,
            'subscriptions'        => $subscriptions,
            'spending_patterns'    => $patterns,
            'monthly_income'       => $profile?->monthly_income,
            'savings_goal'         => $profile?->monthly_savings_goal,
        ], JSON_PRETTY_PRINT);

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                'model'      => $this->model,
                'max_tokens' => 4000,
                'system'     => $system,
                'messages'   => [['role' => 'user', 'content' => $userData]],
            ]);

            $text = $response->json('content.0.text');
            $text = preg_replace('/^```json\s*/i', '', $text);
            $text = preg_replace('/\s*```$/i', '', $text);

            $decoded = json_decode(trim($text), true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : ['error' => 'Invalid JSON'];

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Store recommendations in the database, avoiding duplicates.
     */
    protected function storeRecommendations(int $userId, array $recommendations): int
    {
        $saved = 0;

        foreach ($recommendations as $rec) {
            // Check for similar existing active recommendation
            $exists = SavingsRecommendation::where('user_id', $userId)
                ->where('status', 'active')
                ->where('title', $rec['title'])
                ->exists();

            if ($exists) continue;

            SavingsRecommendation::create([
                'user_id'                  => $userId,
                'title'                    => $rec['title'],
                'description'              => $rec['description'],
                'monthly_savings'          => $rec['monthly_savings'],
                'annual_savings'           => $rec['annual_savings'],
                'difficulty'               => $rec['difficulty'],
                'category'                 => $rec['category'],
                'impact'                   => $rec['impact'],
                'generated_at'             => now(),
            ]);
            $saved++;
        }

        return $saved;
    }
}
