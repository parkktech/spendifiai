<?php

namespace App\Listeners;

use App\Events\TransactionCategorized;
use App\Models\BudgetGoal;
use App\Models\Transaction;
use App\Notifications\BudgetThresholdReached;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class CheckBudgetThresholds implements ShouldQueue
{
    public function handle(TransactionCategorized $event): void
    {
        $user = $event->user;
        $budgetGoals = BudgetGoal::where('user_id', $user->id)->get();

        if ($budgetGoals->isEmpty()) {
            return;
        }

        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        foreach ($budgetGoals as $goal) {
            $categorySlug = $goal->category_slug;

            if (! $categorySlug) {
                continue;
            }

            // Sum spending for current month in this category
            $spent = (float) Transaction::where('user_id', $user->id)
                ->where('transaction_date', '>=', $startOfMonth)
                ->where('transaction_date', '<=', $endOfMonth)
                ->where('amount', '>', 0) // only spending
                ->where(function ($q) use ($categorySlug) {
                    $q->where('user_category', $categorySlug)
                        ->orWhere('ai_category', $categorySlug);
                })
                ->sum('amount');

            $budget = (float) $goal->monthly_limit;

            if ($budget <= 0) {
                continue;
            }

            $percentage = ($spent / $budget) * 100;

            if ($percentage >= 100 && $goal->notify_at_100_pct) {
                $user->notify(new BudgetThresholdReached(
                    category: $categorySlug,
                    spent: $spent,
                    budget: $budget,
                    exceeded: true,
                ));

                Log::info('Budget exceeded', [
                    'user_id' => $user->id,
                    'category' => $categorySlug,
                    'spent' => $spent,
                    'budget' => $budget,
                ]);
            } elseif ($percentage >= 80 && $goal->notify_at_80_pct) {
                $user->notify(new BudgetThresholdReached(
                    category: $categorySlug,
                    spent: $spent,
                    budget: $budget,
                    exceeded: false,
                ));

                Log::info('Budget threshold reached', [
                    'user_id' => $user->id,
                    'category' => $categorySlug,
                    'spent' => $spent,
                    'budget' => $budget,
                    'percentage' => round($percentage),
                ]);
            }
        }
    }
}
