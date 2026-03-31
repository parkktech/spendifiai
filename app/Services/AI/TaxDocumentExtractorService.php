<?php

namespace App\Services\AI;

use App\Enums\TaxDocumentCategory;
use App\Models\TaxDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TaxDocumentExtractorService
{
    protected ?string $apiKey;

    protected string $model;

    const CONFIDENCE_AUTO = 0.85;

    const CONFIDENCE_REVIEW = 0.60;

    const CLASSIFICATION_GATE = 0.70;

    const W2_FIELDS = [
        'employer_name', 'employer_ein', 'employee_name', 'ssn_last4',
        'wages', 'federal_tax_withheld', 'social_security_wages',
        'social_security_tax', 'medicare_wages', 'medicare_tax',
        'state', 'state_wages', 'state_tax_withheld',
    ];

    const NEC_1099_FIELDS = [
        'payer_name', 'payer_tin', 'recipient_name', 'ssn_last4',
        'nonemployee_compensation',
    ];

    const INT_1099_FIELDS = [
        'payer_name', 'payer_tin', 'recipient_name', 'ssn_last4',
        'interest_income', 'early_withdrawal_penalty', 'federal_tax_withheld',
    ];

    const MORTGAGE_1098_FIELDS = [
        'lender_name', 'lender_tin', 'borrower_name', 'ssn_last4',
        'mortgage_interest', 'outstanding_principal', 'points_paid', 'property_tax',
    ];

    const TIER2_FIELDS = [
        'form_title', 'issuer_name', 'issuer_tin', 'recipient_name',
        'ssn_last4', 'total_amount', 'tax_year',
    ];

    public function __construct()
    {
        $this->apiKey = config('spendifiai.ai.api_key') ?? '';
        $this->model = config('spendifiai.ai.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Classify a tax document into one of 25 form types.
     *
     * @return array{category: string, confidence: float, reasoning: string}
     */
    public function classify(TaxDocument $document): array
    {
        $categories = collect(TaxDocumentCategory::cases())
            ->map(fn (TaxDocumentCategory $c) => "- \"{$c->value}\" ({$c->label()})")
            ->implode("\n");

        $systemPrompt = <<<PROMPT
You are a tax document classifier. Analyze the uploaded document and determine which tax form type it is.

Return ONLY valid JSON with these fields:
{
  "category": "the enum value from the list below",
  "confidence": 0.0 to 1.0,
  "reasoning": "brief explanation of why this classification"
}

VALID CATEGORIES (use the exact value string):
{$categories}

If the document does not clearly match any specific form type, use "other".
If it appears to be a receipt or collection of receipts, use "receipts".

CONFIDENCE SCORING:
- 0.90-1.00: Clear form title visible, standard IRS form layout
- 0.70-0.89: Form type identifiable but some ambiguity
- 0.50-0.69: Partially readable or non-standard format
- Below 0.50: Cannot reliably determine form type

Return ONLY valid JSON. No markdown fences. No explanation outside the JSON.
PROMPT;

        $content = $this->buildDocumentContent($document);
        $result = $this->callClaude($systemPrompt, $content);

        return [
            'category' => $result['category'] ?? 'other',
            'confidence' => (float) ($result['confidence'] ?? 0.0),
            'reasoning' => $result['reasoning'] ?? '',
        ];
    }

    /**
     * Extract structured fields from a classified tax document.
     *
     * @return array{fields: array, overall_confidence: float}
     */
    public function extract(TaxDocument $document): array
    {
        $schema = $this->getFieldSchema($document->category);
        $fieldList = implode(', ', $schema);

        $systemPrompt = <<<PROMPT
You are a tax document data extractor. Extract the following fields from the uploaded document.

FIELDS TO EXTRACT: {$fieldList}

CRITICAL SSN RULE: Return ONLY the last 4 digits of any SSN or Social Security Number. Never return a full SSN. For the "ssn_last4" field, return exactly 4 digits.

For EACH field, return a JSON object with this structure:
{
  "fields": {
    "field_name": {
      "value": "the extracted value",
      "confidence": 0.0 to 1.0
    }
  },
  "overall_confidence": 0.0 to 1.0
}

RULES:
- For monetary amounts, return as numbers without dollar signs or commas (e.g., 52000.00)
- For EINs/TINs, return the full number with hyphen (e.g., "12-3456789")
- For SSN, return ONLY last 4 digits (e.g., "1234")
- If a field is not found in the document, omit it from the response
- Set confidence based on readability: 0.95+ for clearly printed, 0.70-0.94 for partially visible, below 0.70 for guessed

Return ONLY valid JSON. No markdown fences. No explanation outside the JSON.
PROMPT;

        $content = $this->buildDocumentContent($document);
        $result = $this->callClaude($systemPrompt, $content);

        $sanitized = $this->sanitizeExtraction($result, $document->category);

        return [
            'fields' => $sanitized['fields'] ?? [],
            'overall_confidence' => (float) ($sanitized['overall_confidence'] ?? 0.0),
        ];
    }

    /**
     * Defense-in-depth SSN stripping and field normalization.
     */
    public function sanitizeExtraction(array $data, TaxDocumentCategory $category): array
    {
        $fields = $data['fields'] ?? [];
        $sanitized = [];

        // SSN-related field names to intercept and rename
        $ssnFieldNames = ['ssn', 'employee_ssn', 'social_security_number', 'recipient_ssn'];

        foreach ($fields as $fieldName => $fieldData) {
            $value = $fieldData['value'] ?? $fieldData ?? '';
            $confidence = $fieldData['confidence'] ?? 0.5;

            // Normalize to array structure if flat value
            if (! is_array($fieldData)) {
                $value = $fieldData;
                $confidence = 0.5;
            }

            // Intercept full SSN field names and rename to ssn_last4
            if (in_array($fieldName, $ssnFieldNames, true)) {
                $fieldName = 'ssn_last4';
            }

            // Strip any SSN-like value to last 4 digits for ssn/ssn_last4 fields
            if (str_contains(strtolower($fieldName), 'ssn') || str_contains(strtolower($fieldName), 'social_security')) {
                $digits = preg_replace('/\D/', '', (string) $value);
                if (strlen($digits) > 4) {
                    $value = substr($digits, -4);
                }
            }

            $sanitized[$fieldName] = [
                'value' => $value,
                'confidence' => (float) $confidence,
            ];
        }

        return [
            'fields' => $sanitized,
            'overall_confidence' => (float) ($data['overall_confidence'] ?? 0.0),
        ];
    }

    /**
     * Get the field schema for a given document category.
     */
    public function getFieldSchema(TaxDocumentCategory $category): array
    {
        return match ($category) {
            TaxDocumentCategory::W2 => self::W2_FIELDS,
            TaxDocumentCategory::NEC_1099 => self::NEC_1099_FIELDS,
            TaxDocumentCategory::INT_1099 => self::INT_1099_FIELDS,
            TaxDocumentCategory::Mortgage_1098 => self::MORTGAGE_1098_FIELDS,
            default => self::TIER2_FIELDS,
        };
    }

    /**
     * Build the multimodal content array for Claude API.
     */
    public function buildDocumentContent(TaxDocument $document): array
    {
        $disk = Storage::disk($document->disk);
        $fileContents = $disk->get($document->stored_path);

        if (! $fileContents) {
            throw new \RuntimeException("Could not read document file: {$document->stored_path}");
        }

        $base64 = base64_encode($fileContents);

        if ($document->mime_type === 'application/pdf') {
            return [
                [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $base64,
                    ],
                ],
            ];
        }

        // Images (JPEG, PNG)
        return [
            [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $document->mime_type,
                    'data' => $base64,
                ],
            ],
        ];
    }

    /**
     * Call Claude API with multimodal content and retry logic.
     */
    protected function callClaude(string $systemPrompt, array $content): array
    {
        $maxRetries = 2;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->timeout(90)->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 4096,
                    'system' => $systemPrompt,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => array_merge($content, [
                                ['type' => 'text', 'text' => 'Please analyze this document and respond with JSON only.'],
                            ]),
                        ],
                    ],
                ]);

                if (! $response->successful()) {
                    Log::warning('Claude API error during tax extraction', [
                        'status' => $response->status(),
                        'attempt' => $attempt + 1,
                        'body' => substr($response->body(), 0, 500),
                    ]);

                    if ($attempt < $maxRetries) {
                        sleep(2);

                        continue;
                    }

                    return ['error' => "API error: {$response->status()}"];
                }

                $text = $response->json('content.0.text');

                return $this->parseJsonResponse($text);

            } catch (\Exception $e) {
                Log::warning('Claude API exception during tax extraction', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);

                if ($attempt < $maxRetries) {
                    sleep(2);

                    continue;
                }

                return ['error' => $e->getMessage()];
            }
        }

        return ['error' => 'Max retries exceeded'];
    }

    /**
     * Parse JSON response from Claude, handling markdown fences and repair.
     */
    protected function parseJsonResponse(string $text): array
    {
        // Strip markdown fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```\s*$/m', '', $text);
        $text = trim($text);

        // Remove control characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Tax extraction JSON parse failed, attempting repair', [
                'error' => json_last_error_msg(),
                'text_length' => strlen($text),
                'first_100' => substr($text, 0, 100),
            ]);

            // Try to extract JSON object from response
            if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
                $extracted = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $matches[0]);
                $decoded = json_decode($extracted, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }

            Log::error('Tax extraction JSON parse failed completely', [
                'error' => json_last_error_msg(),
                'text_preview' => substr($text, 0, 500),
            ]);

            return ['error' => 'Invalid JSON response'];
        }

        return $decoded;
    }
}
