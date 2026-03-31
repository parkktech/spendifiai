<?php

namespace App\Jobs;

use App\Models\TaxDocument;
use App\Services\TaxVaultStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class MigrateStorageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $targetDisk,
    ) {}

    public function handle(TaxVaultStorageService $storageService): void
    {
        $documents = TaxDocument::where('disk', '!=', $this->targetDisk)->get();
        $total = $documents->count();
        $migrated = 0;

        Cache::forever('storage-migration-progress', [
            'total' => $total,
            'migrated' => 0,
            'status' => 'running',
        ]);

        // Process in chunks of 50 to avoid memory issues
        TaxDocument::where('disk', '!=', $this->targetDisk)
            ->chunkById(50, function ($chunk) use ($storageService, &$migrated, $total) {
                foreach ($chunk as $document) {
                    $storageService->migrateDocument($document, $this->targetDisk);
                    $migrated++;

                    Cache::forever('storage-migration-progress', [
                        'total' => $total,
                        'migrated' => $migrated,
                        'status' => 'running',
                    ]);
                }
            });

        Cache::forever('storage-migration-progress', [
            'total' => $total,
            'migrated' => $migrated,
            'status' => 'complete',
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Cache::forever('storage-migration-progress', [
            'total' => 0,
            'migrated' => 0,
            'status' => 'failed',
            'error' => $e->getMessage(),
        ]);
    }
}
