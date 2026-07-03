<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * OptimizationReportReadyNotification — Fix 3 (2026-07-02)
 *
 * Queued to the 'database' channel when GenerateOptimizationReport finishes.
 * Mail is intentionally omitted — the polling page and notification bell
 * (existing notification affordances) are sufficient for the MVP.
 *
 * Notification data shape:
 *   { tax_year: int, report_url: string, message: string }
 */
class OptimizationReportReadyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $taxYear,
    ) {}

    /** Only database — the existing notification bell reads this. No mail. */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tax_year' => $this->taxYear,
            'report_url' => '/optimize',
            'message' => "Your {$this->taxYear} income optimization report is ready.",
        ];
    }
}
