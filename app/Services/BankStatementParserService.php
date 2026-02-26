<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BankStatementParserService
{
    public function parseFile(UploadedFile $file, string $bankName, string $accountType): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        Log::info('Statement parse started', [
            'bank' => $bankName,
            'type' => $accountType,
            'format' => $extension,
            'size_kb' => round($file->getSize() / 1024, 1),
            'filename' => $file->getClientOriginalName(),
        ]);

        return match ($extension) {
            'pdf' => $this->parsePdf($file->getRealPath(), $bankName),
            'csv', 'txt' => $this->parseCsv($file->getRealPath(), $bankName),
            default => throw new \InvalidArgumentException("Unsupported file type: {$extension}"),
        };
    }

    public function parsePdf(string $filePath, string $bankName): array
    {
        return $this->extractTransactionsFromPdfWithAI($filePath, $bankName);
    }

    public function parseCsv(string $filePath, string $bankName): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Could not open CSV file.');
        }

        $rows = [];
        $lineNum = 0;
        while (($row = fgetcsv($handle)) !== false && $lineNum < 5) {
            $rows[] = $row;
            $lineNum++;
        }

        // Detect columns with AI using the first 5 rows
        $columnMapping = $this->detectCsvColumns($rows, $bankName);

        // Now parse all rows
        rewind($handle);
        $allRows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $allRows[] = $row;
        }
        fclose($handle);

        return $this->parseCsvWithMapping($allRows, $columnMapping, $bankName);
    }

    public function detectDuplicates(array $transactions, int $userId, ?int $bankAccountId): array
    {
        $existingTransactions = \App\Models\Transaction::where('user_id', $userId)
            ->when($bankAccountId, fn ($q) => $q->where('bank_account_id', $bankAccountId))
            ->select('amount', 'transaction_date', 'merchant_normalized', 'merchant_name')
            ->get();

        Log::info('Duplicate detection', [
            'incoming' => count($transactions),
            'existing_in_account' => $existingTransactions->count(),
            'bank_account_id' => $bankAccountId,
        ]);

        $notes = [];
        $duplicateCount = 0;

        foreach ($transactions as &$tx) {
            $tx['is_duplicate'] = false;

            foreach ($existingTransactions as $existing) {
                $amountMatch = abs((float) $existing->amount - abs((float) $tx['amount'])) < 0.01;
                $dateMatch = $existing->transaction_date->format('Y-m-d') === $tx['date'];
                $merchantMatch = $this->merchantsSimilar(
                    $existing->merchant_normalized ?? $existing->merchant_name ?? '',
                    $tx['merchant_name'] ?? ''
                );

                if ($amountMatch && $dateMatch && $merchantMatch) {
                    $tx['is_duplicate'] = true;
                    $duplicateCount++;
                    break;
                }
            }
        }

        if ($duplicateCount > 0) {
            $notes[] = "Found {$duplicateCount} potential duplicate(s) already in your account.";
        }

        Log::info('Duplicate detection complete', [
            'duplicates_found' => $duplicateCount,
            'unique_transactions' => count($transactions) - $duplicateCount,
        ]);

        return [
            'transactions' => $transactions,
            'duplicates_found' => $duplicateCount,
            'notes' => $notes,
        ];
    }

    private function extractTransactionsFromPdfWithAI(string $filePath, string $bankName): array
    {
        $fileSize = filesize($filePath);
        if ($fileSize > 30 * 1024 * 1024) {
            throw new \RuntimeException('PDF file is too large (max 30MB for AI processing).');
        }

        Log::info('PDF AI extraction starting', [
            'bank' => $bankName,
            'file_size_kb' => round($fileSize / 1024, 1),
        ]);

        $pdfBase64 = base64_encode(file_get_contents($filePath));

        $prompt = <<<'PROMPT'
Extract every transaction from the attached bank statement PDF. Return ONLY a JSON array.

STRICT RULES — DO NOT VIOLATE:
1. Extract ONLY transactions that are explicitly printed on the statement. Do NOT invent, estimate, or infer any transaction.
2. Every date, amount, and description must come directly from the document text. If you cannot read a value clearly, skip that transaction entirely.
3. Do NOT include summary lines, totals, balance lines, fee disclosures, interest rate notices, or any non-transaction content.
4. Do NOT duplicate transactions. Each transaction appears once in the statement — extract it once.

For EACH transaction return exactly these fields:
- "date": YYYY-MM-DD format (use the transaction/posting date shown)
- "description": the full original text from the statement for this transaction
- "amount": positive number only, no $ sign, no commas (e.g. 42.99 not "$42.99")
- "merchant_name": cleaned merchant name — remove card numbers, reference IDs, city/state codes, "Card XXXX" suffixes, POS/ACH prefixes
- "is_income": true ONLY for deposits, credits, refunds, payments received. false for purchases, debits, withdrawals, payments made.

Return ONLY a valid JSON array. No markdown fences. No explanation. No text before or after the JSON.
PROMPT;

        $startTime = microtime(true);
        $result = $this->callClaudeWithPdf($pdfBase64, $prompt);
        $elapsed = round(microtime(true) - $startTime, 1);

        Log::info('PDF AI extraction response received', [
            'response_length' => strlen($result),
            'elapsed_seconds' => $elapsed,
        ]);

        $transactions = $this->parseJsonResponse($result);
        $notes = [];

        if (empty($transactions)) {
            Log::warning('PDF AI extraction returned no transactions', [
                'bank' => $bankName,
                'response_preview' => substr($result, 0, 1000),
            ]);

            return [
                'transactions' => [],
                'processing_notes' => ['Could not extract transactions from this PDF. The file may be image-based, encrypted, or in an unsupported format.'],
            ];
        }

        // Build parsed array with validation
        $parsed = [];
        $skipped = 0;
        foreach ($transactions as $i => $tx) {
            $date = $tx['date'] ?? '';
            $amount = (float) ($tx['amount'] ?? 0);
            $description = $tx['description'] ?? '';

            // Skip entries with missing critical fields
            if (empty($date) || $amount <= 0 || empty($description)) {
                $skipped++;

                continue;
            }

            // Validate date format
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $skipped++;

                continue;
            }

            $parsed[] = [
                'row_index' => count($parsed),
                'date' => $date,
                'description' => $description,
                'amount' => $amount,
                'merchant_name' => $tx['merchant_name'] ?? $description,
                'is_income' => (bool) ($tx['is_income'] ?? false),
                'is_duplicate' => false,
                'confidence' => 0.90,
                'original_text' => $description,
            ];
        }

        if ($skipped > 0) {
            $notes[] = "Skipped {$skipped} entries with missing or invalid data.";
        }

        Log::info('PDF AI extraction complete', [
            'bank' => $bankName,
            'raw_count' => count($transactions),
            'valid_count' => count($parsed),
            'skipped' => $skipped,
        ]);

        return [
            'transactions' => $parsed,
            'processing_notes' => $notes,
        ];
    }

    private function extractTransactionsWithAI(string $text, string $bankName, string $sourceType): array
    {
        // Truncate very long text to stay within token limits
        $maxChars = 50000;
        $truncated = false;
        if (strlen($text) > $maxChars) {
            $text = substr($text, 0, $maxChars);
            $truncated = true;
        }

        $prompt = <<<PROMPT
        You are a financial document parser. Extract all transactions from this {$bankName} bank statement ({$sourceType} format).

        For each transaction, return a JSON object with:
        - "date": the transaction date in YYYY-MM-DD format
        - "description": the original description text from the statement
        - "amount": the absolute dollar amount as a number (no \$ sign, no commas)
        - "merchant_name": a cleaned-up merchant name (remove card numbers, reference IDs, city/state suffixes, transaction codes)
        - "is_income": true if this is a deposit/credit/income, false if a purchase/debit/withdrawal

        Rules:
        - Include ALL transactions you can find
        - For amounts, always use positive numbers — use the is_income flag to distinguish credits vs debits
        - Clean merchant names: "AMAZON.COM*RT3K2 AMZN.COM/BIL WA" → "Amazon"
        - Skip non-transaction rows (balance summaries, interest rates, account numbers, headers)
        - If you cannot determine the date, skip that row

        Return ONLY a valid JSON array. No markdown, no explanation.

        Statement text:
        {$text}
        PROMPT;

        $result = $this->callClaude($prompt);

        $transactions = $this->parseJsonResponse($result);
        $notes = [];

        if ($truncated) {
            $notes[] = 'Statement was very long and may have been partially processed.';
        }

        // Add row indices and confidence
        $parsed = [];
        foreach ($transactions as $i => $tx) {
            $parsed[] = [
                'row_index' => $i,
                'date' => $tx['date'] ?? '',
                'description' => $tx['description'] ?? '',
                'amount' => (float) ($tx['amount'] ?? 0),
                'merchant_name' => $tx['merchant_name'] ?? $tx['description'] ?? '',
                'is_income' => (bool) ($tx['is_income'] ?? false),
                'is_duplicate' => false,
                'confidence' => 0.85,
                'original_text' => $tx['description'] ?? '',
            ];
        }

        return [
            'transactions' => $parsed,
            'processing_notes' => $notes,
        ];
    }

    private function detectCsvColumns(array $sampleRows, string $bankName): array
    {
        $csvSample = '';
        foreach ($sampleRows as $row) {
            $csvSample .= implode(',', $row)."\n";
        }

        $prompt = <<<PROMPT
        Analyze these first 5 rows of a CSV bank statement from {$bankName} and determine which column index (0-based) corresponds to each field.

        CSV rows:
        {$csvSample}

        Return a JSON object with these keys (use null if not found):
        - "date_col": column index for the transaction date
        - "description_col": column index for transaction description/merchant
        - "amount_col": column index for amount (if single column for both debit/credit)
        - "debit_col": column index for debit/withdrawal amount (if split)
        - "credit_col": column index for credit/deposit amount (if split)
        - "header_rows": number of header rows to skip (usually 0 or 1)
        - "date_format": the date format used (e.g., "MM/DD/YYYY", "YYYY-MM-DD", "MM-DD-YYYY")

        Return ONLY valid JSON. No markdown, no explanation.
        PROMPT;

        $result = $this->callClaude($prompt);

        return $this->parseJsonResponse($result) ?: [
            'date_col' => 0,
            'description_col' => 1,
            'amount_col' => 2,
            'debit_col' => null,
            'credit_col' => null,
            'header_rows' => 1,
            'date_format' => 'MM/DD/YYYY',
        ];
    }

    private function parseCsvWithMapping(array $allRows, array $mapping, string $bankName): array
    {
        $headerRows = (int) ($mapping['header_rows'] ?? 1);
        $dateCol = $mapping['date_col'] ?? 0;
        $descCol = $mapping['description_col'] ?? 1;
        $amountCol = $mapping['amount_col'] ?? null;
        $debitCol = $mapping['debit_col'] ?? null;
        $creditCol = $mapping['credit_col'] ?? null;

        $transactions = [];
        $notes = [];
        $skipped = 0;

        foreach ($allRows as $i => $row) {
            if ($i < $headerRows) {
                continue;
            }

            if (empty(array_filter($row))) {
                continue;
            }

            $date = $this->parseDate($row[$dateCol] ?? '', $mapping['date_format'] ?? 'MM/DD/YYYY');
            if (! $date) {
                $skipped++;

                continue;
            }

            $description = trim($row[$descCol] ?? '');
            if (empty($description)) {
                $skipped++;

                continue;
            }

            $amount = 0;
            $isIncome = false;

            if ($amountCol !== null && isset($row[$amountCol])) {
                $rawAmount = $this->cleanAmount($row[$amountCol]);
                $isIncome = $rawAmount < 0;
                $amount = abs($rawAmount);
            } elseif ($debitCol !== null || $creditCol !== null) {
                $debit = ($debitCol !== null && isset($row[$debitCol])) ? $this->cleanAmount($row[$debitCol]) : 0;
                $credit = ($creditCol !== null && isset($row[$creditCol])) ? $this->cleanAmount($row[$creditCol]) : 0;

                if ($credit > 0) {
                    $amount = abs($credit);
                    $isIncome = true;
                } else {
                    $amount = abs($debit);
                    $isIncome = false;
                }
            }

            if ($amount == 0) {
                $skipped++;

                continue;
            }

            $transactions[] = [
                'row_index' => count($transactions),
                'date' => $date,
                'description' => $description,
                'amount' => $amount,
                'merchant_name' => $this->cleanMerchantName($description),
                'is_income' => $isIncome,
                'is_duplicate' => false,
                'confidence' => 0.90,
                'original_text' => implode(' | ', $row),
            ];
        }

        if ($skipped > 0) {
            $notes[] = "Skipped {$skipped} rows that couldn't be parsed.";
        }

        return [
            'transactions' => $transactions,
            'processing_notes' => $notes,
        ];
    }

    private function callClaudeWithPdf(string $pdfBase64, string $prompt): string
    {
        $response = Http::withHeaders([
            'x-api-key' => config('spendifiai.ai.api_key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(180)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('spendifiai.ai.model'),
            'max_tokens' => 16384,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'application/pdf',
                                'data' => $pdfBase64,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ]);

        if (! $response->successful()) {
            Log::error('Claude API error during PDF parsing', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 2000),
            ]);
            throw new \RuntimeException('AI processing failed (HTTP '.$response->status().'). Please try again.');
        }

        $data = $response->json();

        if (empty($data['content'][0]['text'])) {
            Log::error('Claude API returned empty content for PDF', [
                'response_keys' => array_keys($data),
                'stop_reason' => $data['stop_reason'] ?? 'unknown',
            ]);
            throw new \RuntimeException('AI returned an empty response. The PDF may be unreadable.');
        }

        Log::info('Claude PDF API response metadata', [
            'stop_reason' => $data['stop_reason'] ?? 'unknown',
            'input_tokens' => $data['usage']['input_tokens'] ?? 0,
            'output_tokens' => $data['usage']['output_tokens'] ?? 0,
        ]);

        return $data['content'][0]['text'];
    }

    private function callClaude(string $prompt): string
    {
        $response = Http::withHeaders([
            'x-api-key' => config('spendifiai.ai.api_key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('spendifiai.ai.model'),
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (! $response->successful()) {
            Log::error('Claude API error during statement parsing', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('AI processing failed. Please try again.');
        }

        $data = $response->json();

        return $data['content'][0]['text'] ?? '';
    }

    private function parseJsonResponse(string $text): array
    {
        // Strip markdown fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```\s*$/m', '', $text);
        $text = trim($text);

        // Remove control characters that break json_decode (tabs, etc.)
        // Keep only printable ASCII + standard whitespace (space, newline, carriage return)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('JSON parse failed, attempting repair', [
                'error' => json_last_error_msg(),
                'text_length' => strlen($text),
                'first_100' => substr($text, 0, 100),
                'last_100' => substr($text, -100),
            ]);

            // Try to extract JSON array from response if there's extra text
            if (preg_match('/\[[\s\S]*\]/', $text, $matches)) {
                $extracted = $matches[0];
                $extracted = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $extracted);
                $decoded = json_decode($extracted, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    Log::info('JSON repair succeeded via array extraction', [
                        'count' => count($decoded),
                    ]);

                    return $decoded;
                }
            }

            Log::error('JSON parse failed completely', [
                'error' => json_last_error_msg(),
                'text_preview' => substr($text, 0, 500),
            ]);

            return [];
        }

        return $decoded;
    }

    private function parseDate(string $raw, string $format): ?string
    {
        $raw = trim($raw);
        if (empty($raw)) {
            return null;
        }

        // Try common date formats
        $formats = ['m/d/Y', 'n/j/Y', 'm/d/y', 'Y-m-d', 'm-d-Y', 'd/m/Y', 'M d, Y', 'M j, Y'];

        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $raw);
            if ($dt && $dt->format($fmt) === $raw) {
                return $dt->format('Y-m-d');
            }
        }

        // Fallback: try PHP's strtotime
        $ts = strtotime($raw);
        if ($ts !== false && $ts > strtotime('1990-01-01')) {
            return date('Y-m-d', $ts);
        }

        return null;
    }

    private function cleanAmount(string $raw): float
    {
        $raw = trim($raw);
        if (empty($raw)) {
            return 0;
        }

        // Handle parentheses as negative (common in accounting)
        $negative = str_contains($raw, '(') && str_contains($raw, ')');
        $raw = preg_replace('/[^0-9.\-]/', '', $raw);
        $value = (float) $raw;

        return $negative ? -abs($value) : $value;
    }

    private function cleanMerchantName(string $description): string
    {
        // Remove common suffixes: card numbers, reference IDs, location codes
        $cleaned = preg_replace('/\s+#\d+/', '', $description);
        $cleaned = preg_replace('/\s+\d{4,}$/', '', $cleaned);
        $cleaned = preg_replace('/\s+(XX+\d+|x+\d+)/', '', $cleaned);
        $cleaned = preg_replace('/\s+[A-Z]{2}\s*\d{5}(-\d{4})?$/', '', $cleaned);
        $cleaned = preg_replace('/\s+(PURCHASE|DEBIT|CREDIT|POS|ACH|CHECKCARD)\s*/i', ' ', $cleaned);

        return trim($cleaned) ?: $description;
    }

    private function merchantsSimilar(string $a, string $b): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        if ($a === $b) {
            return true;
        }

        if (empty($a) || empty($b)) {
            return false;
        }

        // Check if one contains the other
        if (str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        // Levenshtein for short strings
        if (strlen($a) < 30 && strlen($b) < 30) {
            $distance = levenshtein($a, $b);
            $maxLen = max(strlen($a), strlen($b));

            return ($distance / $maxLen) < 0.3;
        }

        return false;
    }
}
