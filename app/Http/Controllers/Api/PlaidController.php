<?php

namespace App\Http\Controllers\Api;

use App\Events\BankConnected;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeTokenRequest;
use App\Jobs\CategorizePendingTransactions;
use App\Jobs\ReconcileOrders;
use App\Models\BankConnection;
use App\Models\PlaidStatement;
use App\Models\Transaction;
use App\Services\PlaidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaidController extends Controller
{
    public function __construct(
        private readonly PlaidService $plaidService,
    ) {}

    /**
     * Create a Plaid Link token for the frontend to initialize Plaid Link.
     */
    public function createLinkToken(): JsonResponse
    {
        $token = $this->plaidService->createLinkToken(auth()->user());

        return response()->json(['link_token' => $token['link_token']]);
    }

    /**
     * Exchange a public token (from Plaid Link success) for an access token.
     * Stores the connection and fetches initial account data + transactions.
     */
    public function exchangeToken(ExchangeTokenRequest $request): JsonResponse
    {
        $connection = $this->plaidService->exchangePublicToken(
            auth()->user(),
            $request->validated('public_token')
        );

        // Dispatch event — triggers sync -> categorization -> subscription detection chain
        BankConnected::dispatch($connection, $request->user());

        // Auto-trigger statements refresh (fail silently if unsupported)
        try {
            $startDate = now()->subYear()->startOfYear()->format('Y-m-d');
            $endDate = now()->format('Y-m-d');
            $this->plaidService->refreshStatements($connection, $startDate, $endDate);
            $connection->update(['statements_refresh_status' => 'refreshing']);
        } catch (\Throwable $e) {
            // Statements may not be supported for this institution — that's OK
            $connection->update(['statements_supported' => false, 'statements_refresh_status' => 'unsupported']);
        }

        return response()->json([
            'message' => 'Bank connected successfully',
            'institution' => $connection->institution_name,
            'accounts' => $connection->accounts()->count(),
        ]);
    }

    /**
     * Sync transactions for the user's active bank connection.
     *
     * Note: Syncs only the first active connection. Multi-connection sync
     * is handled by the webhook handler and scheduled tasks.
     */
    public function sync(): JsonResponse
    {
        $connection = BankConnection::where('user_id', auth()->id())
            ->where('status', 'active')
            ->firstOrFail();

        $result = $this->plaidService->syncTransactions($connection);

        // Categorize any new transactions synchronously so user sees results immediately
        if ($result['added'] > 0) {
            CategorizePendingTransactions::dispatchSync(auth()->id());

            // Reconcile new transactions against existing email orders
            ReconcileOrders::dispatch(auth()->user());
        }

        return response()->json([
            'message' => 'Sync complete',
            ...$result,
        ]);
    }

    /**
     * Disconnect a bank connection: revoke Plaid token + delete local records.
     */
    public function disconnect(BankConnection $connection): JsonResponse
    {
        $this->authorize('view', $connection);

        $institutionName = $connection->institution_name;
        $this->plaidService->disconnect($connection);

        return response()->json([
            'message' => "Disconnected from {$institutionName}",
        ]);
    }

    /**
     * Create an update-mode Link token with statements product.
     */
    public function statementsLinkToken(Request $request, BankConnection $connection): JsonResponse
    {
        $this->authorize('view', $connection);

        $startDate = $request->input('start_date', now()->subYear()->startOfYear()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->format('Y-m-d'));

        try {
            $token = $this->plaidService->createStatementsLinkToken(
                auth()->user(), $connection, $startDate, $endDate
            );

            return response()->json(['link_token' => $token['link_token']]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'PRODUCTS_NOT_SUPPORTED')) {
                $connection->update(['statements_supported' => false, 'statements_refresh_status' => 'unsupported']);

                return response()->json(['message' => 'Statements not supported for this institution'], 422);
            }
            throw $e;
        }
    }

    /**
     * Trigger an async statements refresh for a bank connection.
     */
    public function refreshStatements(Request $request, BankConnection $connection): JsonResponse
    {
        $this->authorize('view', $connection);

        $startDate = $request->input('start_date', now()->subYear()->startOfYear()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->format('Y-m-d'));

        // Enforce max 2-year range
        $start = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);
        if ($start->diffInDays($end) > 730) {
            return response()->json(['message' => 'Date range cannot exceed 2 years'], 422);
        }

        try {
            $this->plaidService->refreshStatements($connection, $startDate, $endDate);
            $connection->update(['statements_refresh_status' => 'refreshing']);

            return response()->json([
                'message' => 'Statements refresh initiated. You will be notified when ready.',
                'status' => 'refreshing',
            ]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'PRODUCTS_NOT_SUPPORTED')) {
                $connection->update(['statements_supported' => false, 'statements_refresh_status' => 'unsupported']);

                return response()->json(['message' => 'Statements not supported for this institution'], 422);
            }
            throw $e;
        }
    }

    /**
     * List downloaded statements for a bank connection.
     */
    public function listStatements(BankConnection $connection): JsonResponse
    {
        $this->authorize('view', $connection);

        $statements = PlaidStatement::where('bank_connection_id', $connection->id)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->map(fn (PlaidStatement $s) => [
                'id' => $s->id,
                'month' => $s->month,
                'year' => $s->year,
                'status' => $s->status,
                'total_extracted' => $s->total_extracted,
                'duplicates_found' => $s->duplicates_found,
                'transactions_imported' => $s->transactions_imported,
                'date_range_from' => $s->date_range_from?->format('Y-m-d'),
                'date_range_to' => $s->date_range_to?->format('Y-m-d'),
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        // Find the oldest transaction date across all accounts in this connection
        $accountIds = $connection->accounts()->pluck('id');
        $oldestTransactionDate = Transaction::whereIn('bank_account_id', $accountIds)
            ->min('transaction_date');

        // Also check oldest statement date
        $oldestStatementDate = PlaidStatement::where('bank_connection_id', $connection->id)
            ->where('status', 'complete')
            ->min('date_range_from');

        // Use whichever is oldest
        $oldestDate = null;
        if ($oldestTransactionDate && $oldestStatementDate) {
            $oldestDate = min($oldestTransactionDate, $oldestStatementDate);
        } else {
            $oldestDate = $oldestTransactionDate ?? $oldestStatementDate;
        }

        return response()->json([
            'statements' => $statements,
            'refresh_status' => $connection->statements_refresh_status,
            'statements_supported' => $connection->statements_supported,
            'last_refreshed_at' => $connection->statements_last_refreshed_at?->toIso8601String(),
            'oldest_data_date' => $oldestDate ? \Carbon\Carbon::parse($oldestDate)->format('Y-m-d') : null,
        ]);
    }
}
