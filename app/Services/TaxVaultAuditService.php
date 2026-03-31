<?php

namespace App\Services;

use App\Models\TaxDocument;
use App\Models\TaxVaultAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TaxVaultAuditService
{
    /**
     * Create an immutable audit log entry with hash chain integrity.
     */
    public function log(
        TaxDocument $document,
        User $user,
        string $action,
        ?Request $request = null,
        array $metadata = [],
    ): TaxVaultAuditLog {
        $previousEntry = TaxVaultAuditLog::where('tax_document_id', $document->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $previousHash = $previousEntry?->entry_hash;
        $now = now()->toISOString();

        $entryHash = hash(
            'sha256',
            ($previousHash ?? 'genesis').'|'.$document->id.'|'.$user->id.'|'.$action.'|'.$now,
        );

        return TaxVaultAuditLog::create([
            'tax_document_id' => $document->id,
            'user_id' => $user->id,
            'action' => $action,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => ! empty($metadata) ? $metadata : null,
            'previous_hash' => $previousHash,
            'entry_hash' => $entryHash,
            'created_at' => $now,
        ]);
    }

    /**
     * Verify the hash chain integrity for a document's audit trail.
     *
     * @return array{valid: bool, entries_checked: int, first_broken_at: ?int}
     */
    public function verifyChain(TaxDocument $document): array
    {
        $entries = TaxVaultAuditLog::where('tax_document_id', $document->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $previousHash = null;
        $entriesChecked = 0;

        foreach ($entries as $entry) {
            $entriesChecked++;

            $expectedHash = hash(
                'sha256',
                ($previousHash ?? 'genesis').'|'.$entry->tax_document_id.'|'.$entry->user_id.'|'.$entry->action.'|'.$entry->created_at->toISOString(),
            );

            if ($expectedHash !== $entry->entry_hash) {
                return [
                    'valid' => false,
                    'entries_checked' => $entriesChecked,
                    'first_broken_at' => $entry->id,
                ];
            }

            $previousHash = $entry->entry_hash;
        }

        return [
            'valid' => true,
            'entries_checked' => $entriesChecked,
            'first_broken_at' => null,
        ];
    }

    /**
     * Get audit log entries for a document.
     * Super Admin users can see ip_address and user_agent fields.
     */
    public function getLogForDocument(TaxDocument $document, ?User $viewer = null): Collection
    {
        $entries = TaxVaultAuditLog::where('tax_document_id', $document->id)
            ->orderByDesc('created_at')
            ->get();

        if ($viewer && $viewer->isAdmin()) {
            $entries->each(function (TaxVaultAuditLog $entry) {
                $entry->makeVisible(['ip_address', 'user_agent']);
            });
        }

        return $entries;
    }
}
