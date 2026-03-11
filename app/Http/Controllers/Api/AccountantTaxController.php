<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncBankTransactions;
use App\Models\AccountantActivityLog;
use App\Models\AccountantClient;
use App\Models\BankConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TaxExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountantTaxController extends Controller
{
    public function __construct(
        private readonly TaxExportService $taxExportService,
    ) {}

    /**
     * Get tax summary for a client.
     *
     * GET /api/v1/accountant/clients/{client}/tax/{year}
     */
    public function clientTaxSummary(Request $request, User $client, int $year): JsonResponse
    {
        $accountant = $request->user();
        $this->verifyAccess($accountant, $client);

        $this->logActivity($accountant, $client, 'view_tax_summary', [
            'year' => $year,
        ], $request->ip());

        // Reuse same logic as TaxController::summary but for the client user
        $categories = Transaction::where('user_id', $client->id)
            ->where('tax_deductible', true)
            ->whereYear('transaction_date', $year)
            ->toBase()
            ->select(
                DB::raw("COALESCE(tax_category, user_category, ai_category, 'Uncategorized') as category"),
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as item_count')
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $totalDeductible = $categories->sum('total');

        return response()->json([
            'year' => $year,
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
            ],
            'total_deductible' => (float) $totalDeductible,
            'categories' => $categories->map(fn ($cat) => [
                'category' => $cat->category,
                'total' => (float) $cat->total,
                'item_count' => (int) $cat->item_count,
            ]),
        ]);
    }

    /**
     * Download a client's tax export.
     *
     * GET /api/v1/accountant/clients/{client}/tax/{year}/download/{type}
     */
    public function downloadClientTax(Request $request, User $client, int $year, string $type): BinaryFileResponse|JsonResponse
    {
        $accountant = $request->user();
        $this->verifyAccess($accountant, $client);

        $validTypes = ['xlsx', 'pdf', 'csv', 'txf', 'qbo_csv', 'ofx'];
        if (! in_array($type, $validTypes)) {
            return response()->json(['message' => 'Invalid export type.'], 422);
        }

        $this->logActivity($accountant, $client, 'tax_download', [
            'year' => $year,
            'format' => $type,
        ], $request->ip());

        // Generate the tax export for this client
        $result = $this->taxExportService->generate($client, $year);

        $typeConfig = [
            'xlsx' => ['ext' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'suffix' => ''],
            'pdf' => ['ext' => 'pdf', 'mime' => 'application/pdf', 'suffix' => '_Summary'],
            'csv' => ['ext' => 'csv', 'mime' => 'text/csv', 'suffix' => '_Transactions'],
            'txf' => ['ext' => 'txf', 'mime' => 'application/octet-stream', 'suffix' => ''],
            'qbo_csv' => ['ext' => 'csv', 'mime' => 'text/csv', 'suffix' => '_QuickBooks'],
            'ofx' => ['ext' => 'ofx', 'mime' => 'application/x-ofx', 'suffix' => ''],
        ];

        $config = $typeConfig[$type];
        $dir = storage_path('app/private/tax-exports/'.$client->id);
        $pattern = "{$dir}/SpendifiAI_Tax_{$year}_*{$config['suffix']}.{$config['ext']}";
        $files = glob($pattern);

        if (empty($files)) {
            return response()->json(['message' => 'Tax export generation failed.'], 500);
        }

        $latestFile = end($files);
        $downloadName = "SpendifiAI_Tax_{$year}_{$client->name}{$config['suffix']}.{$config['ext']}";

        return response()->download(
            $latestFile,
            $downloadName,
            ['Content-Type' => $config['mime']]
        );
    }

    /**
     * Trigger bank sync for a client.
     *
     * POST /api/v1/accountant/clients/{client}/refresh
     */
    public function refreshClientData(Request $request, User $client): JsonResponse
    {
        $accountant = $request->user();
        $this->verifyAccess($accountant, $client);

        $connections = BankConnection::where('user_id', $client->id)
            ->where('status', 'active')
            ->get();

        if ($connections->isEmpty()) {
            return response()->json(['message' => 'No active bank connections for this client.'], 422);
        }

        $connections->each(fn ($conn) => SyncBankTransactions::dispatch($conn));

        $this->logActivity($accountant, $client, 'refresh_data', null, $request->ip());

        return response()->json(['message' => 'Sync initiated for '.$connections->count().' connection(s).']);
    }

    // ─── Helpers ───

    protected function verifyAccess(User $accountant, User $client): void
    {
        $exists = AccountantClient::where('accountant_id', $accountant->id)
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->exists();

        if (! $exists) {
            abort(403, 'You do not have access to this client.');
        }
    }

    protected function logActivity(User $accountant, User $client, string $action, ?array $metadata, ?string $ip): void
    {
        AccountantActivityLog::create([
            'accountant_id' => $accountant->id,
            'client_id' => $client->id,
            'action' => $action,
            'metadata' => $metadata,
            'ip_address' => $ip,
        ]);
    }
}
