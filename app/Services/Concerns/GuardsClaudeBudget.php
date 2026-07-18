<?php

namespace App\Services\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * GuardsClaudeBudget trait: D17 per-purpose daily budget guard + call counter.
 *
 * Reads the day-key `claude_calls_{purpose}_{date}` from Redis-backed cache,
 * compares it to the configured daily budget cap (null/absent => uncapped = PHP_INT_MAX).
 * At the cap it logs a warning and returns false (caller skips the call, no HTTP).
 * Otherwise it increments the day-counter (for the Admin ai-usage surface) and returns true.
 *
 * Cache key format `claude_calls_{$purpose}_{$date}` is a read contract with
 * Admin/AiUsageController.php:47 — do not change the key format.
 */
trait GuardsClaudeBudget
{
    /**
     * Check and increment the daily budget counter for a given Claude call purpose.
     *
     * @param  string  $purpose  the purpose key (e.g., 'categorization', 'narration')
     * @return bool true if the call should proceed; false if the daily budget is exhausted
     */
    protected function checkAndIncrementBudget(string $purpose): bool
    {
        $date = now()->toDateString();
        $key = "claude_calls_{$purpose}_{$date}";
        $cap = config("services.anthropic.daily_budget_{$purpose}");
        $cap = ($cap === null) ? PHP_INT_MAX : (int) $cap;

        if ((int) Cache::get($key, 0) >= $cap) {
            Log::info("Claude daily budget cap hit: {$purpose}", ['date' => $date, 'cap' => $cap]);

            return false;
        }

        Cache::increment($key);

        return true;
    }
}
