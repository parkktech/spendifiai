<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlaidService
{
    protected string $clientId;

    protected string $secret;

    protected string $baseUrl;

    public function __construct()
    {
        $this->clientId = config('services.plaid.client_id') ?? '';
        $this->secret = config('services.plaid.secret') ?? '';
        $this->baseUrl = match (config('services.plaid.env')) {
            'production' => 'https://production.plaid.com',
            'development' => 'https://development.plaid.com',
            default => 'https://sandbox.plaid.com',
        };
    }

    /**
     * Create a Plaid Link token for the frontend to initialize Plaid Link.
     */
    public function createLinkToken(User $user): array
    {
        $params = [
            'user' => [
                'client_user_id' => (string) $user->id,
                'email_address' => $user->email,
                'email_address_verified_time' => $user->email_verified_at?->toIso8601String() ?? now()->toIso8601String(),
                'phone_number_verified_time' => now()->toIso8601String(),
            ],
            'client_name' => config('app.name', 'SpendifiAI'),
            'products' => config('spendifiai.plaid.products', ['transactions']),
            'country_codes' => config('spendifiai.plaid.country_codes', ['US']),
            'language' => 'en',
        ];

        // Webhook is required in production, optional in sandbox
        if ($webhookUrl = config('spendifiai.plaid.webhook_url')) {
            $params['webhook'] = $webhookUrl;
        }

        return $this->post('/link/token/create', $params);
    }

    /**
     * Exchange a public token (from Plaid Link success) for an access token.
     * Store the connection and fetch initial account data.
     */
    public function exchangePublicToken(User $user, string $publicToken): BankConnection
    {
        $response = $this->post('/item/public_token/exchange', [
            'public_token' => $publicToken,
        ]);

        $connection = BankConnection::create([
            'user_id' => $user->id,
            'plaid_item_id' => $response['item_id'],
            'plaid_access_token' => $response['access_token'],  // Model cast auto-encrypts
            'institution_name' => 'Pending', // Will be updated below
            'institution_id' => '',
            'status' => 'active',
        ]);

        // Fetch institution details
        $item = $this->post('/item/get', [
            'access_token' => $response['access_token'],
        ]);

        if (isset($item['item']['institution_id'])) {
            $inst = $this->post('/institutions/get_by_id', [
                'institution_id' => $item['item']['institution_id'],
                'country_codes' => ['US'],
            ]);
            $connection->update([
                'institution_name' => $inst['institution']['name'] ?? 'Unknown Bank',
                'institution_id' => $item['item']['institution_id'],
            ]);
        }

        // Fetch accounts
        $this->syncAccounts($connection, $response['access_token']);

        return $connection;
    }

    /**
     * Sync accounts from Plaid.
     */
    protected function syncAccounts(BankConnection $connection, string $accessToken): void
    {
        $response = $this->post('/accounts/get', [
            'access_token' => $accessToken,
        ]);

        foreach ($response['accounts'] ?? [] as $account) {
            BankAccount::updateOrCreate(
                ['plaid_account_id' => $account['account_id']],
                [
                    'user_id' => $connection->user_id,
                    'bank_connection_id' => $connection->id,
                    'name' => $account['name'],
                    'official_name' => $account['official_name'] ?? null,
                    'type' => $account['type'],
                    'subtype' => $account['subtype'] ?? null,
                    'mask' => $account['mask'] ?? null,
                    'current_balance' => $account['balances']['current'] ?? null,
                    'available_balance' => $account['balances']['available'] ?? null,
                ]
            );
        }
    }

    /**
     * Sync transactions using Plaid's transactions/sync endpoint.
     * This is the recommended approach — it returns only new/modified/removed transactions.
     */
    public function syncTransactions(BankConnection $connection): array
    {
        $accessToken = $connection->plaid_access_token;  // Model cast auto-decrypts
        $cursor = $connection->sync_cursor;
        $hasMore = true;

        $added = 0;
        $modified = 0;
        $removed = 0;

        while ($hasMore) {
            $params = ['access_token' => $accessToken, 'count' => 100];
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $response = $this->post('/transactions/sync', $params);

            // Process added transactions
            foreach ($response['added'] ?? [] as $tx) {
                $bankAccount = BankAccount::where('plaid_account_id', $tx['account_id'])->first();
                if (! $bankAccount) {
                    continue;
                }

                // Inherit the account's purpose so the AI categorizer knows context
                $accountPurpose = $bankAccount->purpose ?? 'personal';

                // Pre-set expense_type based on which account it came from
                $defaultExpenseType = match ($accountPurpose) {
                    'business' => 'business',
                    'mixed' => 'mixed',
                    default => 'personal',
                };

                // Business accounts: default tax_deductible to true
                $defaultDeductible = in_array($accountPurpose, ['business']);

                Transaction::updateOrCreate(
                    ['plaid_transaction_id' => $tx['transaction_id']],
                    [
                        'user_id' => $connection->user_id,
                        'bank_account_id' => $bankAccount->id,
                        'account_purpose' => $accountPurpose,
                        'merchant_name' => $tx['merchant_name'] ?? $tx['name'] ?? 'Unknown',
                        'description' => $tx['name'],
                        'amount' => $tx['amount'], // Plaid: positive = spend
                        'transaction_date' => $tx['date'],
                        'authorized_date' => $tx['authorized_date'] ?? null,
                        'payment_channel' => $tx['payment_channel'] ?? null,
                        'plaid_category' => $tx['personal_finance_category']['primary'] ?? null,
                        'plaid_detailed_category' => $tx['personal_finance_category']['detailed'] ?? null,
                        'expense_type' => $defaultExpenseType,
                        'tax_deductible' => $defaultDeductible,
                        'plaid_metadata' => [
                            'logo_url' => $tx['logo_url'] ?? null,
                            'website' => $tx['website'] ?? null,
                            'location' => $tx['location'] ?? null,
                            'iso_currency' => $tx['iso_currency_code'] ?? 'USD',
                        ],
                        'review_status' => 'pending_ai', // Will be categorized by AI
                    ]
                );
                $added++;
            }

            // Process modified transactions
            foreach ($response['modified'] ?? [] as $tx) {
                Transaction::where('plaid_transaction_id', $tx['transaction_id'])
                    ->update([
                        'merchant_name' => $tx['merchant_name'] ?? $tx['name'],
                        'amount' => $tx['amount'],
                        'description' => $tx['name'],
                    ]);
                $modified++;
            }

            // Process removed transactions
            foreach ($response['removed'] ?? [] as $tx) {
                Transaction::where('plaid_transaction_id', $tx['transaction_id'])->delete();
                $removed++;
            }

            $cursor = $response['next_cursor'];
            $hasMore = $response['has_more'] ?? false;
        }

        $connection->update([
            'sync_cursor' => $cursor,
            'last_synced_at' => now(),
        ]);

        return compact('added', 'modified', 'removed');
    }

    /**
     * Get account balances.
     */
    public function getBalances(BankConnection $connection): array
    {
        $response = $this->post('/accounts/balance/get', [
            'access_token' => $connection->plaid_access_token,  // Model cast auto-decrypts
        ]);

        $balances = [];
        foreach ($response['accounts'] ?? [] as $account) {
            BankAccount::where('plaid_account_id', $account['account_id'])
                ->update([
                    'current_balance' => $account['balances']['current'],
                    'available_balance' => $account['balances']['available'],
                ]);

            $balances[] = [
                'name' => $account['name'],
                'type' => $account['subtype'] ?? $account['type'],
                'current' => $account['balances']['current'],
                'available' => $account['balances']['available'],
            ];
        }

        return $balances;
    }

    /**
     * Remove a Plaid Item — revokes the access token and disconnects the bank.
     *
     * MUST be called when:
     * - User disconnects a bank account
     * - User deletes their account
     * - Access token is compromised
     *
     * After calling /item/remove, the access_token is permanently invalidated.
     */
    public function removeItem(BankConnection $connection): bool
    {
        try {
            $this->post('/item/remove', [
                'access_token' => $connection->plaid_access_token,  // Model cast auto-decrypts
            ]);

            Log::info('Plaid item removed', [
                'user_id' => $connection->user_id,
                'institution_name' => $connection->institution_name,
            ]);

            return true;
        } catch (\RuntimeException $e) {
            // Log but don't throw — we still want to delete our local record
            // even if Plaid's revocation fails (e.g., token already expired)
            Log::warning('Plaid item removal failed', [
                'user_id' => $connection->user_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Disconnect a bank: revoke Plaid token + delete local records.
     *
     * Cascading deletes via foreign keys handle:
     * - bank_accounts (FK → bank_connections)
     * - transactions are NOT deleted (retained for history)
     */
    public function disconnect(BankConnection $connection): void
    {
        // 1. Revoke Plaid access token (best-effort)
        $this->removeItem($connection);

        // 2. Soft-deactivate accounts (preserve transaction history references)
        $connection->accounts()->update(['is_active' => false]);

        // 3. Delete the connection record (removes encrypted access_token from our DB)
        $connection->delete();

        Log::info('Bank disconnected', [
            'user_id' => $connection->user_id,
            'institution_name' => $connection->institution_name,
        ]);
    }

    /**
     * Make a POST request to the Plaid API.
     */
    protected function post(string $endpoint, array $data): array
    {
        $data['client_id'] = $this->clientId;
        $data['secret'] = $this->secret;

        $response = Http::timeout(30)
            ->post($this->baseUrl.$endpoint, $data);

        if (! $response->successful()) {
            Log::error('Plaid API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'error' => $response->json(),
            ]);
            throw new \RuntimeException(
                'Plaid error: '.($response->json('error_message') ?? 'Unknown error')
            );
        }

        return $response->json();
    }
}
