<?php

namespace App\Models;

use App\Traits\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * OptimizationCalendarEvent — stores predictive calendar entries (MON-02 / D15 / D16).
 *
 * Populated by ChangeMonitor::runCalendarWatchers():
 *   - event_type='bonus'               → bonus lead-time alert (D15)
 *   - event_type='year_end_purchase'   → year-end purchase timing item (D16)
 *   - event_type='income_shift_detected' → income shift persistence anchor (D14/A3)
 *
 * The metadata column MUST NOT contain money values (T-14-09-03):
 * it stores periods (pay_frequency, expected_month) and types (coverage_type,
 * business_type) only. Dollar figures live in OptimizationFinding.
 *
 * SECURITY: All queries must go through scopeForUser() (T-14-09-04).
 */
class OptimizationCalendarEvent extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'tax_year',
        'event_type',
        'expected_at',
        'lead_time_days',
        'alert_fired_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tax_year' => 'integer',
            'lead_time_days' => 'integer',
            'expected_at' => 'datetime',
            'alert_fired_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Items whose alert window has arrived (now >= expected_at - lead_time_days)
     * and have not yet been fired.
     */
    public function scopeAlertReady(Builder $query): Builder
    {
        return $query->whereNull('alert_fired_at')
            ->where(function (Builder $q) {
                // Fired when: expected_at - lead_time_days <= now
                $q->whereNull('expected_at')
                    ->orWhereRaw(
                        'expected_at - (lead_time_days * INTERVAL \'1 day\') <= NOW()'
                    );
            });
    }
}
