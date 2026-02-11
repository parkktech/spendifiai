<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconciliationService
{
    /**
     * Merchant name normalization map.
     * Bank statements use cryptic names — this maps them to the normalized
     * merchant names that come from email parsing.
     */
    protected array $merchantAliases = [
        'AMZN MKTP'      => 'Amazon',
        'AMAZON.COM'      => 'Amazon',
        'AMZN.COM'        => 'Amazon',
        'AMAZON PRIME'    => 'Amazon',
        'WMT GROCERY'     => 'Walmart',
        'WAL-MART'        => 'Walmart',
        'TARGET'          => 'Target',
        'TARG'            => 'Target',
        'COSTCO WHSE'     => 'Costco',
        'APPLE.COM/BILL'  => 'Apple',
        'GOOGLE *'        => 'Google',
        'PAYPAL *'        => 'PayPal',
        'SQ *'            => 'Square',
        'TST*'            => 'Toast',
        'SHOPIFY*'        => 'Shopify',
        // Add more as you encounter them
    ];

    /**
     * Match unreconciled bank transactions to parsed email orders.
     *
     * Strategy: Match on amount + date proximity + merchant similarity.
     * This is where vague "$127.43 AMZN MKTP US" becomes a fully itemized receipt.
     */
    public function reconcile(User $user): array
    {
        $unmatched_transactions = Transaction::where('user_id', $user->id)
            ->where('is_reconciled', false)
            ->orderBy('transaction_date', 'desc')
            ->get();

        $unmatched_orders = Order::where('user_id', $user->id)
            ->where('is_reconciled', false)
            ->orderBy('order_date', 'desc')
            ->get();

        $matches = [];
        $matched_order_ids = [];

        foreach ($unmatched_transactions as $transaction) {
            $bestMatch = null;
            $bestScore = 0;

            foreach ($unmatched_orders as $order) {
                // Skip already matched orders in this run
                if (in_array($order->id, $matched_order_ids)) {
                    continue;
                }

                $score = $this->calculateMatchScore($transaction, $order);

                if ($score > $bestScore && $score >= 0.6) {
                    $bestScore = $score;
                    $bestMatch = $order;
                }
            }

            if ($bestMatch) {
                $matches[] = [
                    'transaction' => $transaction,
                    'order' => $bestMatch,
                    'confidence' => $bestScore,
                ];
                $matched_order_ids[] = $bestMatch->id;
            }
        }

        // Apply confirmed matches
        $reconciled = 0;
        foreach ($matches as $match) {
            if ($match['confidence'] >= 0.8) {
                // Auto-reconcile high confidence matches
                $this->applyMatch($match['transaction'], $match['order']);
                $reconciled++;
            }
        }

        return [
            'total_unmatched_transactions' => $unmatched_transactions->count(),
            'total_unmatched_orders' => $unmatched_orders->count(),
            'matches_found' => count($matches),
            'auto_reconciled' => $reconciled,
            'needs_review' => array_filter($matches, fn($m) => $m['confidence'] < 0.8),
        ];
    }

    /**
     * Score how well a bank transaction matches an email order (0.0 - 1.0).
     */
    protected function calculateMatchScore(Transaction $transaction, Order $order): float
    {
        $score = 0.0;

        // Amount match (most important signal — exact match = 0.5 points)
        $amountDiff = abs($transaction->amount - $order->total);
        if ($amountDiff < 0.01) {
            $score += 0.50; // Exact match
        } elseif ($amountDiff < 1.00) {
            $score += 0.30; // Close (rounding, small fee differences)
        } elseif ($amountDiff < 5.00) {
            $score += 0.10; // Possible with tax/tip variations
        }

        // Date proximity (within 3 days = 0.3 points)
        $daysDiff = abs($transaction->transaction_date->diffInDays($order->order_date));
        if ($daysDiff <= 0) {
            $score += 0.30;
        } elseif ($daysDiff <= 1) {
            $score += 0.25;
        } elseif ($daysDiff <= 3) {
            $score += 0.15;
        } elseif ($daysDiff <= 7) {
            $score += 0.05; // Delayed charges happen
        }

        // Merchant name similarity (0.2 points)
        $normalizedBankMerchant = $this->normalizeMerchant($transaction->merchant_name);
        $normalizedOrderMerchant = strtolower($order->merchant_normalized ?? $order->merchant);

        if ($normalizedBankMerchant === $normalizedOrderMerchant) {
            $score += 0.20;
        } elseif (str_contains($normalizedBankMerchant, $normalizedOrderMerchant) ||
                  str_contains($normalizedOrderMerchant, $normalizedBankMerchant)) {
            $score += 0.15;
        } elseif (similar_text($normalizedBankMerchant, $normalizedOrderMerchant) / max(strlen($normalizedBankMerchant), strlen($normalizedOrderMerchant), 1) > 0.6) {
            $score += 0.10;
        }

        return min($score, 1.0);
    }

    /**
     * Normalize cryptic bank merchant names to readable ones.
     */
    protected function normalizeMerchant(?string $merchantName): string
    {
        if (!$merchantName) return '';

        $upper = strtoupper(trim($merchantName));

        foreach ($this->merchantAliases as $pattern => $normalized) {
            if (str_starts_with($upper, strtoupper($pattern))) {
                return strtolower($normalized);
            }
        }

        // Basic cleanup: remove trailing numbers, #, *, etc.
        $clean = preg_replace('/[#*0-9]+$/', '', $upper);
        return strtolower(trim($clean));
    }

    /**
     * Apply a match — link the bank transaction to the order.
     *
     * Uses Transaction model (not BankTransaction) and correct column names:
     * - Order.matched_transaction_id references Transaction.id
     * - Transaction.matched_order_id references Order.id
     */
    protected function applyMatch(Transaction $transaction, Order $order): void
    {
        DB::transaction(function () use ($transaction, $order) {
            $transaction->update([
                'matched_order_id' => $order->id,
                'is_reconciled' => true,
            ]);

            $order->update([
                'matched_transaction_id' => $transaction->id,
                'is_reconciled' => true,
            ]);
        });
    }

    /**
     * Get a tax summary grouped by category for a date range.
     */
    public function getTaxSummary(User $user, string $startDate, string $endDate): array
    {
        $items = OrderItem::where('user_id', $user->id)
            ->whereHas('order', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('order_date', [$startDate, $endDate]);
            })
            ->get();

        $summary = [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'total_spending' => $items->sum('total_price'),
            'total_deductible' => $items->where('tax_deductible', true)->sum('total_price'),
            'total_personal' => $items->where('expense_type', 'personal')->sum('total_price'),
            'total_business' => $items->where('expense_type', 'business')->sum('total_price'),
            'categories' => [],
        ];

        $grouped = $items->groupBy('ai_category');
        foreach ($grouped as $category => $categoryItems) {
            $summary['categories'][$category] = [
                'total' => $categoryItems->sum('total_price'),
                'item_count' => $categoryItems->count(),
                'deductible_total' => $categoryItems->where('tax_deductible', true)->sum('total_price'),
                'items' => $categoryItems->map(fn($item) => [
                    'product_name' => $item->product_name,
                    'total_price' => $item->total_price,
                    'tax_deductible' => $item->tax_deductible,
                    'expense_type' => $item->expense_type,
                ])->values()->toArray(),
            ];
        }

        // Sort categories by total spending descending
        arsort($summary['categories']);

        return $summary;
    }
}
