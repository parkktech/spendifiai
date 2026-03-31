<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorageConfigRequest;
use App\Jobs\MigrateStorageJob;
use App\Services\TaxVaultStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StorageConfigController extends Controller
{
    public function __construct(
        private readonly TaxVaultStorageService $storageService,
    ) {}

    /**
     * Show current storage configuration and statistics.
     */
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json([
            'driver' => $this->storageService->getActiveDisk(),
            'stats' => $this->storageService->getStorageStats(),
            'migration_progress' => Cache::get('storage-migration-progress'),
        ]);
    }

    /**
     * Update storage configuration.
     *
     * S3 credentials are stored encrypted in a cache key with no expiry.
     * This allows runtime configuration changes without modifying .env files.
     * On application boot, a service provider could hydrate the S3 config
     * from this cache key if needed.
     */
    public function update(StorageConfigRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Store S3 credentials encrypted in cache (no expiry)
        if ($validated['driver'] === 's3') {
            Cache::forever('vault_storage_settings', encrypt([
                's3_bucket' => $validated['s3_bucket'],
                's3_region' => $validated['s3_region'],
                's3_key' => $validated['s3_key'],
                's3_secret' => $validated['s3_secret'],
            ]));
        }

        // Update runtime config for the current request lifecycle
        config(['spendifiai.vault.storage_driver' => $validated['driver']]);

        // Persist driver preference in cache so it survives across requests
        Cache::forever('vault_storage_driver', $validated['driver']);

        return response()->json([
            'message' => 'Storage configuration updated.',
            'driver' => $validated['driver'],
        ]);
    }

    /**
     * Test S3 connection with current credentials.
     */
    public function testConnection(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        try {
            $this->storageService->testS3Connection();

            return response()->json(['success' => true, 'message' => 'S3 connection successful.']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Trigger storage migration to a target disk.
     */
    public function migrate(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $request->validate([
            'target_disk' => 'required|in:local,s3',
        ]);

        MigrateStorageJob::dispatch($request->input('target_disk'));

        return response()->json([
            'message' => 'Storage migration started.',
            'target_disk' => $request->input('target_disk'),
        ], 202);
    }

    /**
     * Get current migration progress.
     */
    public function migrationStatus(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json([
            'progress' => Cache::get('storage-migration-progress'),
        ]);
    }
}
