<?php

namespace App\Services;

use App\Models\CancellationProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CancellationLinkFinderService
{
    /**
     * Use Claude AI to find the cancellation URL and details for a provider.
     */
    public function findCancellationLink(CancellationProvider $provider): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey) {
            return ['error' => 'No API key configured'];
        }

        $prompt = "I need to find the exact cancellation page URL for {$provider->company_name}.\n\n";
        $prompt .= "Please provide:\n";
        $prompt .= "1. The most direct URL where a user can cancel or manage their subscription/account\n";
        $prompt .= "2. A customer service phone number (if available)\n";
        $prompt .= "3. Brief step-by-step cancellation instructions (2-3 sentences max)\n";
        $prompt .= "4. How difficult it is to cancel: 'easy' (self-service online), 'medium' (requires a few steps), or 'hard' (must call, retention offers, etc.)\n\n";
        $prompt .= "Respond with JSON only: {\"url\": \"...\", \"phone\": \"...\" or null, \"instructions\": \"...\", \"difficulty\": \"easy|medium|hard\"}\n";
        $prompt .= "If you're not sure about the URL, provide your best guess based on common patterns for that company.";

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model', 'claude-sonnet-4-20250514'),
                'max_tokens' => 500,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

            if (! $response->successful()) {
                return ['error' => 'AI request failed'];
            }

            $text = $response->json('content.0.text');
            $text = preg_replace('/^```json\s*/i', '', $text);
            $text = preg_replace('/\s*```$/i', '', $text);
            $decoded = json_decode(trim($text), true);

            if (! is_array($decoded)) {
                return ['error' => 'Invalid AI response'];
            }

            return [
                'cancellation_url' => $decoded['url'] ?? null,
                'cancellation_phone' => $decoded['phone'] ?? null,
                'cancellation_instructions' => $decoded['instructions'] ?? null,
                'difficulty' => $decoded['difficulty'] ?? 'medium',
            ];

        } catch (\Exception $e) {
            Log::warning('Cancellation link finder error', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }
}
