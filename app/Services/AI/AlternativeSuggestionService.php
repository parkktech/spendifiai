<?php

namespace App\Services\AI;

use App\Models\SavingsRecommendation;
use App\Models\Subscription;
use Illuminate\Support\Facades\Http;

class AlternativeSuggestionService
{
    protected ?string $apiKey;

    protected string $model;

    protected int $cacheDays;

    protected int $maxPerItem;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key') ?? '';
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-20250514');
        $this->cacheDays = config('spendwise.ai.alternatives.cache_days', 7);
        $this->maxPerItem = config('spendwise.ai.alternatives.max_per_item', 4);
    }

    /**
     * Get cheaper alternatives for a subscription.
     * Caches the result on the model for the configured number of days.
     *
     * @return array<int, array{name: string, price: string, savings: string, url: string|null, notes: string}>
     */
    public function getSubscriptionAlternatives(Subscription $subscription): array
    {
        if (
            ! empty($subscription->ai_alternatives)
            && $subscription->alternatives_generated_at
            && $subscription->alternatives_generated_at->diffInDays(now()) < $this->cacheDays
        ) {
            return $subscription->ai_alternatives;
        }

        $prompt = sprintf(
            'Service: %s, Amount: $%s/month, Category: %s, Frequency: %s',
            $subscription->merchant_name,
            $subscription->amount,
            $subscription->category ?? 'Unknown',
            $subscription->frequency ?? 'monthly',
        );

        $alternatives = $this->callAI($prompt, 'subscription');

        $subscription->update([
            'ai_alternatives' => $alternatives,
            'alternatives_generated_at' => now(),
        ]);

        return $alternatives;
    }

    /**
     * Get alternative approaches for a savings recommendation.
     * Caches the result on the model for the configured number of days.
     *
     * @return array<int, array{title: string, description: string, estimated_savings: string, difficulty: string}>
     */
    public function getRecommendationAlternatives(SavingsRecommendation $rec): array
    {
        if (
            ! empty($rec->ai_alternatives)
            && $rec->alternatives_generated_at
            && $rec->alternatives_generated_at->diffInDays(now()) < $this->cacheDays
        ) {
            return $rec->ai_alternatives;
        }

        $prompt = sprintf(
            'Recommendation: %s, Description: %s, Monthly savings: $%s, Category: %s, Difficulty: %s',
            $rec->title,
            $rec->description,
            $rec->monthly_savings,
            $rec->category ?? 'Unknown',
            $rec->difficulty ?? 'medium',
        );

        $alternatives = $this->callAI($prompt, 'recommendation');

        $rec->update([
            'ai_alternatives' => $alternatives,
            'alternatives_generated_at' => now(),
        ]);

        return $alternatives;
    }

    /**
     * Call Claude API to generate alternative suggestions.
     */
    private function callAI(string $context, string $type): array
    {
        $system = match ($type) {
            'subscription' => <<<'PROMPT'
You are a personal finance advisor. The user has a subscription they're considering changing.
Suggest up to 4 cheaper or free alternatives. For each alternative, provide:
{
  "name": "Service name",
  "price": "$X/month or Free",
  "savings": "$X/month saved",
  "url": "website URL or null",
  "notes": "Brief explanation of tradeoffs"
}
Respond with a JSON array only. No markdown. Be specific and realistic.
PROMPT,
            'recommendation' => <<<'PROMPT'
You are a personal finance advisor. The user received a savings recommendation but wants alternatives.
Suggest up to 4 alternative approaches to save money in the same area. For each alternative, provide:
{
  "title": "Short action title",
  "description": "How to implement this",
  "estimated_savings": "$X/month",
  "difficulty": "easy|medium|hard"
}
Respond with a JSON array only. No markdown. Be specific and actionable.
PROMPT,
            default => '',
        };

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 2000,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $context]],
            ]);

            $text = $response->json('content.0.text');
            $text = preg_replace('/^```json\s*/i', '', $text);
            $text = preg_replace('/\s*```$/i', '', $text);

            $decoded = json_decode(trim($text), true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                return [];
            }

            return array_slice($decoded, 0, $this->maxPerItem);
        } catch (\Exception $e) {
            return [];
        }
    }
}
