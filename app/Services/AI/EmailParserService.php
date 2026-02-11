<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class EmailParserService
{
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key');
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Parse an email and extract structured order/product data.
     * This is the core engine — Claude handles ALL the complexity of
     * different email formats across hundreds of retailers.
     */
    public function parseOrderEmail(array $emailData): array
    {
        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt($emailData);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 2000,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

            if (!$response->successful()) {
                Log::error('Claude API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['error' => 'API request failed', 'status' => $response->status()];
            }

            $content = $response->json('content.0.text');
            return $this->parseResponse($content);

        } catch (\Exception $e) {
            Log::error('Email parsing failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * System prompt that instructs Claude to be a precision email parser.
     * Focused on extracting INDIVIDUAL PRODUCTS with categorization.
     */
    protected function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a precision email parser specialized in extracting purchase data from order confirmation emails.

Your job is to extract EVERY individual product/item from the email with accurate pricing.

Respond ONLY with valid JSON. No markdown, no backticks, no explanation.

JSON Schema:
{
  "is_purchase": boolean,
  "is_refund": boolean,
  "is_subscription": boolean,
  "merchant": "string - the store/company name",
  "merchant_normalized": "string - standardized name (e.g. 'AMZN MKTP US' -> 'Amazon', 'WMT GROCERY' -> 'Walmart')",
  "order_number": "string or null",
  "order_date": "YYYY-MM-DD",
  "currency": "USD",
  "items": [
    {
      "product_name": "string - the specific product name",
      "product_description": "string or null - any additional details (size, color, variant)",
      "quantity": number,
      "unit_price": number,
      "total_price": number,
      "suggested_category": "string - one of the standard categories below",
      "tax_deductible_likelihood": number between 0.0 and 1.0,
      "business_use_indicator": "string - why this might be business-related, or 'likely personal'",
      "product_type": "string - physical, digital, subscription, service"
    }
  ],
  "subtotal": number or null,
  "tax": number or null,
  "shipping": number or null,
  "discount": number or null,
  "total": number,
  "payment_method": "string or null - last 4 digits of card if visible",
  "notes": "string or null - any relevant details like delivery date, membership savings"
}

Standard categories (use the most specific one):
- Office Supplies (pens, paper, ink, toner, desk accessories)
- Computer & Electronics (hardware, peripherals, cables, components)
- Software & Digital Services (apps, SaaS, digital downloads, licenses)
- Books & Education (books, courses, training materials)
- Food & Groceries (groceries, meal kits, pantry items)
- Restaurant & Dining (takeout, delivery, restaurant orders)
- Household & Home (furniture, appliances, cleaning, home improvement)
- Health & Wellness (supplements, vitamins, personal care, fitness)
- Clothing & Apparel (clothes, shoes, accessories)
- Kids & Family (toys, children's items, baby products)
- Automotive (parts, maintenance, accessories)
- Entertainment (games, media, streaming, hobbies)
- Travel & Transportation (flights, hotels, rideshare, parking)
- Professional Services (consulting, legal, accounting)
- Marketing & Advertising (ad spend, promotional materials)
- Shipping & Postage (shipping supplies, postage, courier)
- Subscriptions (recurring services, memberships, boxes)
- Pet Supplies (pet food, accessories, vet)
- Tools & Equipment (power tools, hand tools, machinery)
- Other (anything that doesn't fit above)

Tax deductibility hints:
- Office supplies, software, computer equipment → high likelihood if user is self-employed
- Books related to profession → moderate likelihood
- Food/groceries → low unless business meals
- Clothing → very low unless uniforms/safety gear
- Home office furniture → moderate likelihood

If the email is NOT a purchase (newsletter, marketing, etc.), return:
{"is_purchase": false}

CRITICAL RULES:
1. Extract EVERY item individually — never combine items
2. If prices per item aren't shown, estimate based on total and quantities
3. Preserve exact product names as shown in the email
4. If you can't determine something, use null — never guess
5. Amounts should be numbers, not strings (47.82 not "$47.82")
PROMPT;
    }

    /**
     * Build the user prompt with the email content.
     */
    protected function buildUserPrompt(array $emailData): string
    {
        $prompt = "Parse this order email:\n\n";
        $prompt .= "FROM: {$emailData['from']}\n";
        $prompt .= "SUBJECT: {$emailData['subject']}\n";
        $prompt .= "DATE: {$emailData['date']}\n";
        $prompt .= "---\n";
        $prompt .= $emailData['body'];

        return $prompt;
    }

    /**
     * Parse Claude's JSON response with error handling.
     */
    protected function parseResponse(string $content): array
    {
        // Strip any markdown fencing Claude might add despite instructions
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        $content = trim($content);

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Failed to parse Claude response as JSON', [
                'error' => json_last_error_msg(),
                'content' => substr($content, 0, 500),
            ]);
            return ['error' => 'Invalid JSON response', 'raw' => $content];
        }

        return $data;
    }

    /**
     * Re-categorize items in bulk with additional user context.
     * Useful for improving accuracy after initial parse — e.g., user says
     * "I'm a freelance web developer" and we can re-score deductibility.
     */
    public function recategorizeItems(array $items, string $userContext): array
    {
        $systemPrompt = <<<PROMPT
You are a tax categorization assistant. Given the user's business context and a list of purchased items,
re-evaluate each item's category, tax deductibility, and expense type (personal vs business).

User context: {$userContext}

Respond with ONLY a JSON array of objects with these fields for each item:
- product_name (unchanged)
- suggested_category
- tax_deductible (boolean)
- tax_deductible_confidence (0.0-1.0)
- expense_type ("personal", "business", "mixed")
- reasoning (brief explanation)
PROMPT;

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model,
            'max_tokens' => 2000,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => json_encode($items)],
            ],
        ]);

        $content = $response->json('content.0.text');
        return $this->parseResponse($content);
    }
}
