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
            // Sum spending for current month in this category
            $spent = (float) Transaction::where('user_id', $user->id)
                ->where('transaction_date', '>=', $startOfMonth)
                ->where('transaction_date', '<=', $endOfMonth)
                ->where('amount', '>', 0) // only spending
                ->where(function ($q) use ($goal) {
                    $q->where('user_category', $goal->category)
                      ->orWhere('ai_category', $goal->category);
                })
                ->sum('amount');

            $budget = (float) $goal->monthly_limit;

            if ($budget <= 0) {
                continue;
            }

            $percentage = ($spent / $budget) * 100;
            $threshold = (float) ($goal->alert_threshold ?? 80);

            if ($percentage >= 100) {
                $user->notify(new BudgetThresholdReached(
                    category: $goal->category,
                    spent: $spent,
                    budget: $budget,
                    exceeded: true,
                ));

                Log::info('Budget exceeded', [
                    'user_id'  => $user->id,
                    'category' => $goal->category,
                    'spent'    => $spent,
                    'budget'   => $budget,
                ]);
            } elseif ($percentage >= $threshold) {
                $user->notify(new BudgetThresholdReached(
                    category: $goal->category,
                    spent: $spent,
                    budget: $budget,
                    exceeded: false,
                ));

                Log::info('Budget threshold reached', [
                    'user_id'    => $user->id,
                    'category'   => $goal->category,
                    'spent'      => $spent,
                    'budget'     => $budget,
                    'percentage' => round($percentage),
                ]);
            }
        }
    }
}
