<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcileOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        protected User $user
    ) {}

    public function handle(ReconciliationService $reconciler): void
    {
        $result = $reconciler->reconcile($this->user);

        Log::info('Order reconciliation complete', [
            'user_id' => $this->user->id,
            ...$result,
        ]);
    }
}
