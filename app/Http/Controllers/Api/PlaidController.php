<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeTokenRequest;
use App\Jobs\CategorizePendingTransactions;
use App\Models\BankConnection;
use App\Services\PlaidService;
use Illuminate\Http\JsonResponse;

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

        // Sync initial transactions
        $this->plaidService->syncTransactions($connection);

        // Queue AI categorization
        CategorizePendingTransactions::dispatch(auth()->id());

        return response()->json([
            'message'     => 'Bank connected successfully',
            'institution' => $connection->institution_name,
            'accounts'    => $connection->accounts()->count(),
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

        // Categorize any new transactions
        if ($result['added'] > 0) {
            CategorizePendingTransactions::dispatch(auth()->id());
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
}
