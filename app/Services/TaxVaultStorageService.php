<?php

namespace App\Services;

use App\Models\TaxDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaxVaultStorageService
{
    /**
     * Store a file in the tax vault and return storage metadata.
     *
     * @return array{stored_path: string, disk: string, file_hash: string, file_size: int, mime_type: string}
     */
    public function store(UploadedFile $file, int $userId, int $taxYear, ?string $category = null): array
    {
        $this->validateFile($file);

        $disk = $this->getActiveDisk();
        $category = $category ?? 'uncategorized';
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid()->toString().'.'.$extension;
        $directory = "tax-vault/{$userId}/{$taxYear}/{$category}";
        $storedPath = "{$directory}/{$filename}";

        $fileHash = hash_file('sha256', $file->getRealPath());

        $this->getDisk($disk)->putFileAs($directory, $file, $filename);

        return [
            'stored_path' => $storedPath,
            'disk' => $disk,
            'file_hash' => $fileHash,
            'file_size' => (int) $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ];
    }

    /**
     * Generate a signed URL for secure document download.
     */
    public function getSignedUrl(TaxDocument $document): string
    {
        $expiry = config('spendifiai.vault.signed_url_expiry_minutes', 15);

        if ($document->disk === 's3') {
            return Storage::disk('s3')->temporaryUrl(
                $document->stored_path,
                now()->addMinutes($expiry),
            );
        }

        return URL::temporarySignedRoute(
            'tax-vault.download',
            now()->addMinutes($expiry),
            ['document' => $document->id],
        );
    }

    /**
     * Delete a document's file from storage.
     */
    public function delete(TaxDocument $document): void
    {
        $this->getDisk($document->disk)->delete($document->stored_path);
    }

    /**
     * Get the active storage driver name.
     */
    public function getActiveDisk(): string
    {
        return config('spendifiai.vault.storage_driver', 'local');
    }

    /**
     * Migrate a document from its current disk to a target disk.
     */
    public function migrateDocument(TaxDocument $document, string $targetDisk): void
    {
        $sourceDisk = $this->getDisk($document->disk);
        $targetDiskInstance = $this->getDisk($targetDisk);

        $contents = $sourceDisk->get($document->stored_path);
        $targetDiskInstance->put($document->stored_path, $contents);

        $sourceDisk->delete($document->stored_path);

        $document->disk = $targetDisk;
        $document->save();
    }

    /**
     * Test S3 connection by putting, reading, and deleting a test file.
     *
     * @throws \RuntimeException
     */
    public function testS3Connection(): bool
    {
        $testPath = 'tax-vault/.connection-test-'.Str::random(8);
        $disk = Storage::disk('s3');

        try {
            $disk->put($testPath, 'connection-test');
            $content = $disk->get($testPath);
            $disk->delete($testPath);

            if ($content !== 'connection-test') {
                throw new \RuntimeException('S3 read/write verification failed');
            }

            return true;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \RuntimeException('S3 connection failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get storage statistics from the database.
     *
     * @return array{total_documents: int, total_size_bytes: int, active_driver: string}
     */
    public function getStorageStats(): array
    {
        return [
            'total_documents' => TaxDocument::count(),
            'total_size_bytes' => (int) TaxDocument::sum('file_size'),
            'active_driver' => $this->getActiveDisk(),
        ];
    }

    /**
     * Get a filesystem disk instance for the given driver.
     */
    private function getDisk(string $driver): Filesystem
    {
        if ($driver === 's3') {
            return Storage::disk('s3');
        }

        return Storage::disk('local');
    }

    /**
     * Validate file MIME type and size against vault configuration.
     *
     * @throws ValidationException
     */
    private function validateFile(UploadedFile $file): void
    {
        $allowedMimes = config('spendifiai.vault.allowed_mimes', []);
        $maxSizeMb = config('spendifiai.vault.max_file_size_mb', 100);

        if (! in_array($file->getMimeType(), $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => "File type '{$file->getMimeType()}' is not allowed. Accepted types: ".implode(', ', $allowedMimes),
            ]);
        }

        $fileSizeMb = $file->getSize() / 1024 / 1024;
        if ($fileSizeMb > $maxSizeMb) {
            throw ValidationException::withMessages([
                'file' => "File size exceeds the maximum allowed size of {$maxSizeMb}MB.",
            ]);
        }
    }
}
