<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * AiUsageController — D17 AI Cost Discipline admin spend surface.
 *
 * Exposes the per-purpose daily Claude call counters that every sanctioned call
 * site increments via its budget helper (Cache key `claude_calls_{purpose}_{date}`).
 * Read-only. Nested inside the existing `admin` middleware group — no additional
 * auth logic here (route group applies auth:sanctum + admin).
 */
class AiUsageController extends Controller
{
    /**
     * Per-purpose Claude call purposes tracked by the D17 budget helpers.
     *
     * @var list<string>
     */
    private const PURPOSES = ['narration', 'wording', 'extraction', 'categorization'];

    /**
     * Number of days (including today) reported in the usage window.
     */
    private const WINDOW_DAYS = 7;

    /**
     * GET /api/admin/ai-usage
     *
     * Returns, for each purpose, the daily call counter for the current day and
     * the prior 6 days (a 7-day window), plus the configured daily budget cap.
     */
    public function index(): JsonResponse
    {
        $usage = [];

        foreach (self::PURPOSES as $purpose) {
            $days = [];
            for ($i = self::WINDOW_DAYS - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();
                $days[] = [
                    'date' => $date,
                    'count' => (int) Cache::get("claude_calls_{$purpose}_{$date}", 0),
                ];
            }

            $cap = config("services.anthropic.daily_budget_{$purpose}");

            $usage[$purpose] = [
                'purpose' => $purpose,
                'daily_budget' => $cap === null ? null : (int) $cap,
                'model' => config("services.anthropic.model_{$purpose}", config('services.anthropic.model')),
                'days' => $days,
            ];
        }

        return response()->json([
            'window_days' => self::WINDOW_DAYS,
            'today' => now()->toDateString(),
            'usage' => $usage,
        ]);
    }
}
