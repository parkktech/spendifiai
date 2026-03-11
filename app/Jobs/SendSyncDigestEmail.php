<?php

namespace App\Jobs;

use App\Mail\SyncDigestMail;
use App\Models\User;
use App\Services\AI\SyncSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSyncDigestEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public User $user,
        public array $syncResults,
    ) {}

    public function handle(SyncSummaryService $summaryService): void
    {
        // Guard: don't send if digest is disabled
        if (! config('spendifiai.sync_digest.enabled', true)) {
            return;
        }

        // Guard: require verified email
        if (is_null($this->user->email_verified_at)) {
            return;
        }

        // Guard: minimum interval between digests
        $minHours = config('spendifiai.sync_digest.min_interval_hours', 24);
        if ($this->user->last_sync_digest_at && $this->user->last_sync_digest_at->gt(now()->subHours($minHours))) {
            return;
        }

        // Guard: minimum transactions
        $minTransactions = config('spendifiai.sync_digest.min_transactions', 1);
        if (($this->syncResults['added'] ?? 0) < $minTransactions) {
            return;
        }

        try {
            $summary = $summaryService->generateSummary($this->user, $this->syncResults);

            Mail::to($this->user->email)->send(new SyncDigestMail($this->user, $summary));

            $this->user->updateQuietly(['last_sync_digest_at' => now()]);

            Log::info('Sync digest email sent', ['user_id' => $this->user->id]);
        } catch (\Exception $e) {
            Log::error('Failed to send sync digest email', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
