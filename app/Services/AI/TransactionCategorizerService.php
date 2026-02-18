<?php

namespace App\Services\AI;

use App\Models\AIQuestion;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\UserFinancialProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TransactionCategorizerService
{
    protected ?string $apiKey;

    protected string $model;

    // Confidence thresholds
    const CONFIDENCE_AUTO = 0.85; // Auto-categorize without asking

    const CONFIDENCE_CONFIRM = 0.60; // Suggest but ask user to confirm

    const CONFIDENCE_ASK = 0.40; // Not sure — ask user with options

    const CONFIDENCE_UNKNOWN = 0.00; // No clue — open-ended question

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key') ?? '';
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Categorize a batch of transactions using Claude.
     * Returns categorized transactions with confidence scores.
     * Generates AI questions for uncertain ones.
     */
    public function categorizeBatch(Collection $transactions, int $userId): array
    {
        $profile = UserFinancialProfile::where('user_id', $userId)->first();
        $systemPrompt = $this->buildCategorizationPrompt($profile);

        // Pre-load matched orders for transactions that have been reconciled
        $txIds = $transactions->pluck('id')->toArray();
        $matchedOrders = Order::whereIn('matched_transaction_id', $txIds)
            ->where('is_reconciled', true)
            ->with('items')
            ->get()
            ->keyBy('matched_transaction_id');

        // Prepare transaction data for Claude
        $txData = $transactions->map(function (Transaction $tx) use ($matchedOrders) {
            $data = [
                'id' => $tx->id,
                'merchant' => $tx->merchant_name,
                'amount' => $tx->amount,
                'date' => $tx->transaction_date->format('Y-m-d'),
                'description' => $tx->description,
                'channel' => $tx->payment_channel,
                'plaid_cat' => $tx->plaid_category,
                'account_purpose' => $tx->account_purpose,
                'account_name' => $tx->bankAccount?->nickname ?? $tx->bankAccount?->name,
            ];

            // Include matched email order details so AI knows exactly what was purchased
            $order = $matchedOrders->get($tx->id);
            if ($order) {
                $data['email_order'] = [
                    'merchant' => $order->merchant,
                    'items' => $order->items->map(fn ($i) => [
                        'name' => $i->product_name,
                        'category' => $i->ai_category,
                        'amount' => (float) $i->total_price,
                        'tax_deductible' => $i->tax_deductible,
                        'expense_type' => $i->expense_type,
                    ])->toArray(),
                ];
            }

            return $data;
        })->toArray();

        $response = $this->callClaude($systemPrompt, json_encode($txData));

        if (isset($response['error'])) {
            Log::error('Transaction categorization failed', $response);

            return ['error' => $response['error'], 'processed' => 0];
        }

        return $this->processCategorizationResults($response, $transactions, $userId);
    }

    /**
     * Build the system prompt for transaction categorization.
     * Includes user's financial profile for smarter business/personal detection.
     */
    protected function buildCategorizationPrompt(?UserFinancialProfile $profile): string
    {
        $profileContext = '';
        if ($profile) {
            $profileContext = "\n\nUSER FINANCIAL PROFILE:\n";
            $profileContext .= $profile->employment_type
                ? "- Employment: {$profile->employment_type}\n" : '';
            $profileContext .= $profile->business_type
                ? "- Business: {$profile->business_type}\n" : '';
            $profileContext .= $profile->has_home_office
                ? "- Has home office (home office deductions may apply)\n" : '';
            $profileContext .= $profile->tax_filing_status
                ? "- Filing status: {$profile->tax_filing_status}\n" : '';

            if ($profile->custom_rules) {
                $profileContext .= '- Custom rules: '.json_encode($profile->custom_rules)."\n";
            }
        }

        return <<<PROMPT
You are a financial transaction categorizer. Analyze each transaction and return JSON.
{$profileContext}

CRITICAL CONTEXT — ACCOUNT PURPOSE:
Each transaction includes an "account_purpose" field indicating whether the bank account is:
- "business" → ALL transactions from this account default to business expenses / tax deductible
  UNLESS clearly personal (e.g., Netflix on a business card). For business accounts, assume
  business purpose and only flag as personal if obviously not work-related.
- "personal" → ALL transactions default to personal. Only flag as business if clearly work-related
  (e.g., "ADOBE CREATIVE" on a personal card for a designer). ASK the user if uncertain.
- "mixed" → Cannot assume either way. Use merchant/category signals. ASK the user more often.

This is the STRONGEST signal for expense_type. A coffee purchase on a business account is a
business meal. The same purchase on a personal account is personal unless the user says otherwise.

EMAIL ORDER DATA:
Some transactions include an "email_order" field with itemized purchase details from parsed email
receipts. When present, use this data to determine the exact category, expense type, and tax
deductibility. This eliminates guesswork — you know exactly what was purchased. Set confidence
to 0.95+ when email order data is available.

For EACH transaction, return:
{
  "id": <transaction_id>,
  "category": "<category from list below>",
  "confidence": <0.0-1.0>,
  "expense_type": "personal|business|mixed",
  "tax_deductible": true|false,
  "tax_category": "<IRS category if deductible, null otherwise>",
  "is_subscription": true|false,
  "merchant_normalized": "<clean merchant name>",
  "reasoning": "<brief explanation>",
  "uncertain_about": "<what you're unsure of, null if confident>",
  "suggested_question": "<question to ask user if confidence < 0.6, null otherwise>",
  "question_type": "category|business_personal|split|confirm|null",
  "question_options": ["option1", "option2", "option3", "Skip"]
}

CATEGORIES (use exactly one):
- Housing & Rent
- Mortgage
- Food & Groceries
- Restaurant & Dining
- Coffee & Drinks
- Transportation
- Gas & Fuel
- Car Payment
- Car Insurance
- Auto Maintenance
- Public Transit
- Rideshare
- Subscriptions & Streaming
- Software & SaaS
- Phone & Internet
- Utilities (Electric/Water/Gas)
- Trash & Recycling
- Health Insurance
- Medical & Dental
- Pharmacy
- Fitness & Gym
- Home Insurance
- Life Insurance
- Clothing & Apparel
- Personal Care
- Pet Care
- Childcare & Kids
- Education
- Entertainment
- Gaming
- Shopping (General)
- Electronics
- Home Improvement
- Office Supplies
- Travel & Hotels
- Flights
- Parking
- Professional Services
- Business Meals
- Marketing & Advertising
- Shipping & Postage
- Income (Salary)
- Income (Freelance)
- Income (Investment)
- Transfer
- ATM Withdrawal
- Fees & Charges
- Charity & Donations
- Gifts
- Taxes
- Savings & Investment
- Debt Payment
- Uncategorized

TAX DEDUCTION RULES:
- Self-employed/freelancer: Office supplies, software, business meals (50%), home office, professional development, business travel, marketing → likely deductible
- Employee with home office: More limited deductions
- Universal: Charitable donations, medical expenses (above threshold), mortgage interest, state/local taxes
- NOT deductible: Personal meals, entertainment, clothing (unless uniforms), commuting

CONFIDENCE SCORING:
- 0.90-1.00: Obvious (rent, utilities, known subscription services, ANY transaction on a business account that matches a business category)
- 0.70-0.89: Very likely correct (grocery stores, gas stations, recognizable merchants, business account + plausible business expense)
- 0.50-0.69: Probably right but could be wrong (Amazon, Costco, generic merchants, personal account + possible business use)
- 0.30-0.49: Uncertain (Venmo, ambiguous merchants, mixed-use stores, mixed account)
- 0.00-0.29: Can't determine (generic descriptions, unknown merchants)

ACCOUNT PURPOSE ADJUSTMENTS:
- Business account → boost confidence by +0.15 for business categorization, reduce questions
- Personal account → boost confidence by +0.15 for personal categorization
- Mixed account → reduce confidence by -0.10, ask more questions
- Business account + clearly personal (Netflix, pet store) → flag as personal, ASK to confirm
- Personal account + clearly business (office supplies for freelancer) → flag as business, ASK to confirm

For confidence < 0.60, you MUST provide a suggested_question and options.
For Venmo/Zelle/CashApp, ALWAYS ask if personal or business.
For Amazon/Costco/Target, ask about mixed categories if amount > $50.

Respond ONLY with a JSON array. No markdown, no backticks.
PROMPT;
    }

    /**
     * Process Claude's categorization results.
     * Auto-apply high-confidence results, generate questions for uncertain ones.
     */
    protected function processCategorizationResults(
        array $results,
        Collection $transactions,
        int $userId
    ): array {
        $stats = ['auto_categorized' => 0, 'needs_review' => 0, 'questions_generated' => 0];

        foreach ($results as $result) {
            $transaction = $transactions->firstWhere('id', $result['id']);
            if (! $transaction) {
                continue;
            }

            $confidence = $result['confidence'] ?? 0;

            // Update transaction with AI results
            $transaction->update([
                'ai_category' => $result['category'] ?? 'Uncategorized',
                'ai_confidence' => $confidence,
                'merchant_normalized' => $result['merchant_normalized'] ?? $transaction->merchant_name,
                'expense_type' => $result['expense_type'] ?? 'personal',
                'tax_deductible' => $result['tax_deductible'] ?? false,
                'tax_category' => $result['tax_category'] ?? null,
                'is_subscription' => $result['is_subscription'] ?? false,
                'review_status' => $confidence >= self::CONFIDENCE_AUTO
                    ? 'auto_categorized'
                    : 'needs_review',
            ]);

            if ($confidence >= self::CONFIDENCE_AUTO) {
                $stats['auto_categorized']++;
            } else {
                $stats['needs_review']++;

                // Generate a question for the user (skip if one already exists)
                if (! empty($result['suggested_question'])) {
                    $existingQuestion = AIQuestion::where('transaction_id', $transaction->id)
                        ->where('status', 'pending')
                        ->exists();

                    if (! $existingQuestion) {
                        AIQuestion::create([
                            'user_id' => $userId,
                            'transaction_id' => $transaction->id,
                            'question' => $result['suggested_question'],
                            'options' => $result['question_options'] ?? ['Personal', 'Business', 'Skip'],
                            'ai_confidence' => $confidence,
                            'ai_best_guess' => $result['category'],
                            'question_type' => $result['question_type'] ?? 'category',
                            'status' => 'pending',
                        ]);
                        $stats['questions_generated']++;
                    }
                }

                // Mark as ai_uncertain so it won't be re-processed
                $transaction->update(['review_status' => 'ai_uncertain']);
            }
        }

        return $stats;
    }

    /**
     * Handle user's answer to an AI question.
     * Updates the transaction and learns from the response.
     */
    public function handleUserAnswer(AIQuestion $question, string $answer): void
    {
        $question->update([
            'user_answer' => $answer,
            'status' => $answer === 'Skip' ? 'skipped' : 'answered',
            'answered_at' => now(),
        ]);

        if ($answer === 'Skip') {
            return;
        }

        $transaction = $question->transaction;

        $questionType = $question->question_type instanceof \App\Enums\QuestionType
            ? $question->question_type->value
            : $question->question_type;

        switch ($questionType) {
            case 'business_personal':
                $expenseType = match (true) {
                    str_contains(strtolower($answer), 'business') => 'business',
                    str_contains(strtolower($answer), 'personal') => 'personal',
                    str_contains(strtolower($answer), 'mixed')
                        || str_contains(strtolower($answer), 'split') => 'mixed',
                    default => 'personal',
                };
                $transaction->update([
                    'expense_type' => $expenseType,
                    'tax_deductible' => $expenseType !== 'personal',
                    'user_category' => $transaction->ai_category,
                    'review_status' => 'user_confirmed',
                ]);
                break;

            case 'category':
                $transaction->update([
                    'user_category' => $answer,
                    'review_status' => 'user_confirmed',
                ]);
                break;

            case 'confirm':
                if (str_contains(strtolower($answer), 'yes')
                    || str_contains(strtolower($answer), 'correct')) {
                    $transaction->update(['review_status' => 'user_confirmed']);
                } else {
                    // User said no — keep as needs_review for manual categorization
                    $transaction->update(['review_status' => 'needs_review']);
                }
                break;

            case 'split':
                // Handle split transactions (e.g., Costco with mixed categories)
                $transaction->update([
                    'user_category' => $answer,
                    'expense_type' => str_contains(strtolower($answer), 'mixed') ? 'mixed' : 'personal',
                    'review_status' => 'user_confirmed',
                ]);
                break;
        }

        // Apply same categorization to all matching merchant transactions not yet user-confirmed
        $transaction->refresh();
        $merchantName = $transaction->merchant_normalized ?? $transaction->merchant_name;

        if ($merchantName) {
            Transaction::where('user_id', $transaction->user_id)
                ->where('id', '!=', $transaction->id)
                ->where(function ($q) use ($merchantName) {
                    $q->where('merchant_normalized', $merchantName)
                        ->orWhere('merchant_name', $merchantName);
                })
                ->where('review_status', '!=', 'user_confirmed')
                ->update([
                    'user_category' => $transaction->user_category,
                    'expense_type' => $transaction->expense_type,
                    'tax_deductible' => $transaction->tax_deductible,
                    'review_status' => 'user_confirmed',
                ]);

            // Also mark duplicate AI questions for the same merchant as answered
            $siblingTransactionIds = Transaction::where('user_id', $transaction->user_id)
                ->where('id', '!=', $transaction->id)
                ->where(function ($q) use ($merchantName) {
                    $q->where('merchant_normalized', $merchantName)
                        ->orWhere('merchant_name', $merchantName);
                })
                ->pluck('id');

            if ($siblingTransactionIds->isNotEmpty()) {
                \App\Models\AIQuestion::whereIn('transaction_id', $siblingTransactionIds)
                    ->where('status', 'pending')
                    ->update([
                        'user_answer' => $answer,
                        'status' => 'answered',
                        'answered_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Interpret a user's free-text response to an AI question.
     * Returns a suggested category and explanation.
     */
    public function interpretUserResponse(AIQuestion $question, string $userMessage): array
    {
        $transaction = $question->transaction;

        $categories = \App\Models\ExpenseCategory::whereNull('user_id')
            ->orderBy('name')
            ->pluck('name')
            ->implode(', ');

        $system = <<<PROMPT
You are a financial categorization assistant. A user has a transaction that needs categorizing.
They've been asked a question and are providing additional context instead of picking from the predefined options.

Your job: interpret their response and suggest the best expense category.

Available categories: {$categories}

Return ONLY valid JSON (no markdown):
{
  "category": "the best matching category name from the list above",
  "expense_type": "personal" or "business" or "mixed",
  "tax_deductible": true or false,
  "explanation": "A brief 1-2 sentence explanation of why this category fits, acknowledging what the user told you"
}

Rules:
- Pick the single best category from the available list
- If no category fits well, use the closest match
- Set tax_deductible based on whether a reasonable person would claim this as a business deduction
- Keep the explanation friendly and concise
PROMPT;

        $userPrompt = <<<MSG
Transaction:
- Merchant: {$transaction->merchant_name}
- Amount: \${$transaction->amount}
- Date: {$transaction->transaction_date->format('M j, Y')}
- Description: {$transaction->description}
- Account type: {$transaction->account_purpose}

Original question: {$question->question}
Predefined options: {$this->formatOptions($question->options)}

User's response: {$userMessage}
MSG;

        $result = $this->callClaude($system, $userPrompt);

        if (isset($result['error'])) {
            Log::error('AI question chat failed', $result);

            return [
                'category' => $question->ai_best_guess ?? 'Uncategorized',
                'expense_type' => 'personal',
                'tax_deductible' => false,
                'explanation' => 'Sorry, I had trouble processing that. You can pick from the options above or try again.',
            ];
        }

        return [
            'category' => $result['category'] ?? 'Uncategorized',
            'expense_type' => $result['expense_type'] ?? 'personal',
            'tax_deductible' => $result['tax_deductible'] ?? false,
            'explanation' => $result['explanation'] ?? 'Category updated based on your input.',
        ];
    }

    private function formatOptions(?array $options): string
    {
        if (! $options || empty($options)) {
            return 'None';
        }

        return implode(', ', $options);
    }

    /**
     * Call Claude API with retry logic.
     */
    protected function callClaude(string $system, string $userMessage): array
    {
        $maxRetries = 2;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 4000,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $userMessage]],
                ]);

                if (! $response->successful()) {
                    if ($attempt < $maxRetries) {
                        sleep(2);

                        continue;
                    }

                    return ['error' => "API error: {$response->status()}"];
                }

                $text = $response->json('content.0.text');
                $text = preg_replace('/^```json\s*/i', '', $text);
                $text = preg_replace('/\s*```$/i', '', $text);

                $decoded = json_decode(trim($text), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return ['error' => 'Invalid JSON: '.json_last_error_msg()];
                }

                return $decoded;

            } catch (\Exception $e) {
                if ($attempt < $maxRetries) {
                    sleep(2);

                    continue;
                }

                return ['error' => $e->getMessage()];
            }
        }

        return ['error' => 'Max retries exceeded'];
    }
}
