<?php

namespace App\Services\AI;

use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSummaryService
{
    protected ?string $apiKey;

    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key') ?? '';
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Generate a sync digest summary for a user.
     */
    public function generateSummary(User $user, array $syncResults): array
    {
        $rawData = $this->gatherData($user, $syncResults);
        $aiInsights = $this->getAIInsights($user, $rawData);

        return array_merge($rawData, ['ai' => $aiInsights]);
    }

    /**
     * Gather all data needed for the sync digest.
     */
    protected function gatherData(User $user, array $syncResults): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();

        // Current month spending
        $currentMonthSpending = Transaction::where('user_id', $user->id)
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $monthStart)
            ->sum('amount');

        // Last month spending
        $lastMonthSpending = Transaction::where('user_id', $user->id)
            ->where('amount', '>', 0)
            ->whereBetween('transaction_date', [$lastMonthStart, $lastMonthEnd])
            ->sum('amount');

        // 3-month rolling average
        $threeMonthAvg = Transaction::where('user_id', $user->id)
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $now->copy()->subMonths(3)->startOfMonth())
            ->where('transaction_date', '<', $monthStart)
            ->toBase()
            ->select(DB::raw('SUM(amount) / 3 as avg_monthly'))
            ->value('avg_monthly') ?? 0;

        // Top spending categories this month
        $topCategories = Transaction::where('user_id', $user->id)
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $monthStart)
            ->toBase()
            ->select(
                DB::raw("COALESCE(user_category, ai_category, 'Uncategorized') as category"),
                DB::raw('SUM(amount) as total'),
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->toArray();

        // Subscription changes
        $subscriptionChanges = $this->detectSubscriptionChanges($user->id);

        // Pending actions
        $pendingQuestions = $user->aiQuestions()->where('status', 'pending')->count();
        $unusedSubs = Subscription::where('user_id', $user->id)
            ->where('status', 'unused')
            ->get();
        $unusedCount = $unusedSubs->count();
        $unusedWasted = $unusedSubs->sum('amount');

        // Savings target progress
        $savingsTarget = $user->savingsTarget;

        // Spending trend
        $spendingTrend = 'flat';
        if ($threeMonthAvg > 0) {
            $ratio = (float) $currentMonthSpending / (float) $threeMonthAvg;
            if ($ratio < 0.9) {
                $spendingTrend = 'down';
            } elseif ($ratio > 1.1) {
                $spendingTrend = 'up';
            }
        }

        // Financial milestone reminders
        $milestones = [];
        $month = (int) $now->format('n');
        if ($month >= 1 && $month <= 4) {
            $milestones[] = 'tax_season';
        }
        if ($month >= 10 && $month <= 12) {
            $milestones[] = 'year_end_planning';
        }
        if ($month >= 6 && $month <= 7) {
            $milestones[] = 'mid_year_check';
        }

        return [
            'sync' => [
                'added' => $syncResults['added'] ?? 0,
                'modified' => $syncResults['modified'] ?? 0,
                'removed' => $syncResults['removed'] ?? 0,
            ],
            'spending' => [
                'current_month' => round((float) $currentMonthSpending, 2),
                'last_month' => round((float) $lastMonthSpending, 2),
                'three_month_avg' => round((float) $threeMonthAvg, 2),
                'trend' => $spendingTrend,
                'top_categories' => $topCategories,
            ],
            'subscriptions' => $subscriptionChanges,
            'pending_actions' => [
                'questions' => $pendingQuestions,
                'unused_subscriptions' => $unusedCount,
                'unused_wasted_monthly' => round((float) $unusedWasted, 2),
            ],
            'savings_target' => $savingsTarget ? [
                'monthly_target' => (float) $savingsTarget->monthly_target,
                'motivation' => $savingsTarget->motivation,
            ] : null,
            'milestones' => $milestones,
            'user_name' => $user->name,
            'month_name' => $now->format('F'),
        ];
    }

    /**
     * Detect subscription changes since last sync.
     */
    protected function detectSubscriptionChanges(int $userId): array
    {
        $recent = Subscription::where('user_id', $userId)
            ->where('updated_at', '>=', now()->subDays(1))
            ->get();

        $newSubs = $recent->filter(fn ($s) => $s->created_at->gte(now()->subDays(1)));
        $cancelled = $recent->filter(fn ($s) => $s->status === 'cancelled');

        return [
            'new' => $newSubs->map(fn ($s) => [
                'name' => $s->merchant_normalized ?? $s->merchant_name,
                'amount' => (float) $s->amount,
            ])->values()->toArray(),
            'cancelled' => $cancelled->map(fn ($s) => [
                'name' => $s->merchant_normalized ?? $s->merchant_name,
                'amount' => (float) $s->amount,
            ])->values()->toArray(),
            'has_changes' => $newSubs->isNotEmpty() || $cancelled->isNotEmpty(),
        ];
    }

    /**
     * Get AI-generated personalized insights.
     */
    protected function getAIInsights(User $user, array $data): array
    {
        if (empty($this->apiKey)) {
            return $this->fallbackInsights($data);
        }

        $system = <<<'PROMPT'
You are a friendly personal finance assistant generating a brief email digest. Be encouraging but honest.

Given the user's sync data, generate a JSON response with:
{
  "headline": "A personalized greeting/headline (max 10 words, encouraging tone)",
  "insights": [
    "Insight 1 with specific numbers (max 2 sentences)",
    "Insight 2 with specific numbers (max 2 sentences)"
  ],
  "recommendation": "One specific actionable tip based on their data (max 2 sentences)",
  "closing": "A brief motivational closing tied to their savings goal if set (max 1 sentence)"
}

GUIDELINES:
- Use ACTUAL numbers from their data — never make up figures
- Keep each insight to 1-2 sentences max
- Be specific: "$X on Y" not "spending seems high"
- If spending is down, celebrate it. If up, gently note it.
- The closing should reference their savings motivation if provided
- Tone: supportive friend, not lecturing parent

Respond with valid JSON only. No markdown.
PROMPT;

        $userData = json_encode([
            'name' => $data['user_name'],
            'new_transactions' => $data['sync']['added'],
            'current_month_spending' => $data['spending']['current_month'],
            'last_month_spending' => $data['spending']['last_month'],
            'three_month_avg' => $data['spending']['three_month_avg'],
            'spending_trend' => $data['spending']['trend'],
            'top_categories' => $data['spending']['top_categories'],
            'subscription_changes' => $data['subscriptions'],
            'unused_subscriptions' => $data['pending_actions']['unused_subscriptions'],
            'unused_wasted' => $data['pending_actions']['unused_wasted_monthly'],
            'savings_goal' => $data['savings_target'],
            'month' => $data['month_name'],
        ], JSON_PRETTY_PRINT);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 1000,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $userData]],
            ]);

            $text = $response->json('content.0.text');
            $text = preg_replace('/^```json\s*/i', '', $text);
            $text = preg_replace('/\s*```$/i', '', $text);

            $decoded = json_decode(trim($text), true);

            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['headline'])) {
                return $decoded;
            }

            return $this->fallbackInsights($data);
        } catch (\Exception $e) {
            Log::warning('Sync digest AI generation failed', ['error' => $e->getMessage()]);

            return $this->fallbackInsights($data);
        }
    }

    /**
     * Generate basic insights without AI.
     */
    protected function fallbackInsights(array $data): array
    {
        $insights = [];

        if ($data['sync']['added'] > 0) {
            $insights[] = sprintf(
                'We found %d new transaction%s since your last sync.',
                $data['sync']['added'],
                $data['sync']['added'] === 1 ? '' : 's'
            );
        }

        $current = $data['spending']['current_month'];
        $avg = $data['spending']['three_month_avg'];
        if ($avg > 0 && $current > 0) {
            $pct = round((($current - $avg) / $avg) * 100);
            if ($pct < -5) {
                $insights[] = sprintf(
                    'Your spending this month is %d%% below your 3-month average — nice work!',
                    abs($pct)
                );
            } elseif ($pct > 10) {
                $insights[] = sprintf(
                    'Heads up: spending this month is %d%% above your 3-month average.',
                    $pct
                );
            }
        }

        return [
            'headline' => sprintf('Your %s financial update is here', $data['month_name']),
            'insights' => $insights ?: ['Your accounts have been synced with the latest data.'],
            'recommendation' => $data['pending_actions']['unused_subscriptions'] > 0
                ? sprintf(
                    'You have %d unused subscription%s costing $%.2f/mo — consider cancelling.',
                    $data['pending_actions']['unused_subscriptions'],
                    $data['pending_actions']['unused_subscriptions'] === 1 ? '' : 's',
                    $data['pending_actions']['unused_wasted_monthly']
                )
                : 'Keep tracking your spending to stay on top of your finances.',
            'closing' => $data['savings_target']
                ? sprintf('Keep going — you\'re working toward "%s"!', $data['savings_target']['motivation'] ?? 'your savings goal')
                : 'Every dollar tracked is a step toward financial clarity.',
        ];
    }
}
